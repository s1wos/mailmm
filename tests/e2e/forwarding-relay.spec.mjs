import { execFileSync, spawn, spawnSync } from 'node:child_process'
import { createHash, randomBytes, verify } from 'node:crypto'
import { once } from 'node:events'
import { setTimeout as delay } from 'node:timers/promises'
import { expect, test } from '@playwright/test'

function fields(header) {
    return header.split(/\r\n(?![ \t])/)
}

function headerName(field) {
    return field.slice(0, field.indexOf(':')).toLowerCase()
}

function headerValue(field) {
    return field.slice(field.indexOf(':') + 1).replace(/\r\n[ \t]+/g, ' ').trim()
}

function tagValues(field) {
    return Object.fromEntries(headerValue(field).split(';').filter(part => part.includes('=')).map(part => {
        const equals = part.indexOf('=')
        return [part.slice(0, equals).trim(), part.slice(equals + 1).replace(/\s/g, '')]
    }))
}

function relaxedHeader(field) {
    return headerName(field) + ':' + headerValue(field).replace(/[ \t]+/g, ' ')
}

function withoutSignature(field) {
    return field.replace(/([:;][\s]*b[ \t]*=)[^;]*/i, '$1')
}

function verifyMessageSignature(headers, body, signature, key) {
    const tags = tagValues(signature)
    expect(tags.a).toBe('rsa-sha256')
    const [headerMode, bodyMode] = (tags.c || 'simple/simple').split('/')
    const canonicalHeader = headerMode === 'relaxed' ? relaxedHeader : field => field
    let canonicalBody = body
    if (bodyMode === 'relaxed') {
        canonicalBody = canonicalBody.replace(/[ \t]+(?=\r\n)/g, '').replace(/[ \t]+/g, ' ')
    }
    canonicalBody = canonicalBody.replace(/(?:\r\n)+$/, '') + '\r\n'
    expect(createHash('sha256').update(canonicalBody).digest('base64')).toBe(tags.bh)

    // Select repeated fields from the bottom, as indicated by the signature's h= list.
    const available = headers.toReversed()
    const signed = tags.h.split(':').flatMap(name => {
        const index = available.findIndex(field => headerName(field) === name.toLowerCase())
        return index === -1 ? [] : [canonicalHeader(available.splice(index, 1)[0]) + '\r\n']
    }).join('') + canonicalHeader(withoutSignature(signature))
    expect(verify('RSA-SHA256', Buffer.from(signed), key, Buffer.from(tags.b, 'base64'))).toBe(true)
}

test('the deployed relay signs copies and wakes up for immediate and delayed forwarding jobs', async () => {
    test.setTimeout(12 * 60_000)
    const project = 'mailmm-relay-' + randomBytes(8).toString('hex')
    const secret = () => randomBytes(32).toString('hex')
    const env = {
        ...process.env,
        COMPOSE_PROFILES: '',
        MAILMM_TAG: 'local',
        DB_DATABASE: 'mailmm', DB_USERNAME: 'mailmm',
        PG_ADMIN_PASSWORD: secret(), DB_PASSWORD: secret(), SMTP_LOOKUP_PASSWORD: secret(),
        APP_ENV: 'production', APP_KEY: 'base64:' + randomBytes(32).toString('base64'),
        REVERB_APP_KEY: secret(), REVERB_APP_SECRET: secret(),
        MAILBOX_DOMAIN: 'relay.test', MX_HOST: 'mx.relay.test',
        FORWARD_SIGNING_DOMAIN: 'relay.test', FORWARD_CONFIG_DIR: './deploy/forwarding', FORWARD_KEYS_DIR: 'relay-keys',
        FORWARD_MANUAL_ENABLED: 'true', FORWARD_AUTO_ENABLED: 'false',
        FORWARD_MANUAL_ENABLED_AT: '2000-01-01T00:00:00Z',
        FORWARD_SERVICE_ADDRESS: 'forwarding@relay.test', FORWARD_BOUNCE_DOMAIN: 'bounce.relay.test',
        FORWARD_MANUAL_DSN: 'smtp://mx.relay.test:2465', FORWARD_AUTO_DSN: 'smtp://mx.relay.test:2466',
    }
    const composeArgs = [
        'compose', '--env-file', '.env.example', '-f', 'docker-compose.yml', '-f', 'docker-compose.forwarding.yml',
        '-f', 'tests/e2e/relay/compose.yml', '-p', project,
    ]
    const compose = (args, options) => execFileSync('docker', [...composeArgs, ...args],
        { env, encoding: 'utf8', timeout: 10 * 60_000, ...options })
    const listening = (service, host, port) => spawnSync('docker', [...composeArgs, 'exec', '-T', service, 'php', '-r',
        `exit(@fsockopen('${host}', ${port}, $errno, $error, 1) ? 0 : 1);`], { env, timeout: 5000 }).status === 0
    const sql = query => compose(['exec', '-T', 'db', 'psql', '-XAtq', '-v', 'ON_ERROR_STOP=1',
        '-U', 'postgres', '-d', 'mailmm', '-c', query]).trim()
    const queueCalls = () => Number(sql("select coalesce(sum(calls), 0) from pg_stat_statements where query ~* '\\mjobs\\M'"))
    const queue = (scenario = 'startup', options) => compose(['exec', '-T', 'app', 'php', '/relay-queue-copies.php', scenario], options).trim()
    const captures = () => compose(['exec', '-T', 'relay-sink', 'sh', '-c',
        'if test -f /tmp/relay-captures.jsonl; then cat /tmp/relay-captures.jsonl; fi'])
        .trim().split('\n').filter(Boolean).map(line => JSON.parse(line))
    const emptyQueue = () => sql("select count(*) from mail_forwards where status in ('queued', 'preparing', 'submitting')") === '0'

    try {
        compose(['build', 'app', 'smtp'], { stdio: 'inherit' })
        const material = compose(['run', '--rm', '-T', 'relay-material'], { stdio: ['ignore', 'pipe', 'pipe'] })
        const keys = material.match(/-----BEGIN PUBLIC KEY-----[\s\S]*?-----END PUBLIC KEY-----/g)
        expect(keys).toHaveLength(2)
        compose(['up', '-d', 'app', 'outbound', 'forward-signer', 'relay-sink'], { stdio: 'inherit' })
        await expect.poll(() => listening('outbound', '127.0.0.1', 11332), { timeout: 35_000 }).toBe(true)
        sql('create extension pg_stat_statements')
        queue()
        compose(['up', '-d', 'forward-worker'], { stdio: 'inherit' })
        await expect.poll(() => listening('forward-worker', 'mx.relay.test', 2465), { timeout: 25_000 }).toBe(true)
        await expect.poll(() => captures().map(mail => mail.recipients).sort(), { timeout: 35_000 })
            .toEqual([['archive@reader.test'], ['original@reader.test']])

        for (const capture of captures()) {
            const wire = Buffer.from(capture.raw, 'base64').toString('utf8')
            const boundary = wire.indexOf('\r\n\r\n')
            expect(boundary).toBeGreaterThan(0)
            const headers = fields(wire.slice(0, boundary))
            const body = wire.slice(boundary + 4)
            const singleHeader = name => {
                const matched = headers.filter(field => headerName(field) === name)
                expect(matched, name).toHaveLength(1)
                return matched[0]
            }
            if (capture.recipients[0] === 'archive@reader.test') {
                expect(headerValue(singleHeader('from'))).toBe('MailMM <forwarding@relay.test>')
                expect(headerValue(singleHeader('reply-to'))).toBe('author@sender.test')
                const signature = singleHeader('dkim-signature')
                expect(tagValues(signature)).toMatchObject({ d: 'relay.test', s: 'mmfwd1' })
                verifyMessageSignature(headers, body, signature, keys[0])
            } else {
                expect(headerValue(singleHeader('from'))).toBe('Author <author@sender.test>')
                expect(body).toBe('Original body.\r\n')
                const results = singleHeader('arc-authentication-results')
                expect(headerValue(results)).toMatch(/i=1;\s*mx\.relay\.test;\s*dkim=none/)
                const signature = singleHeader('arc-message-signature')
                expect(tagValues(signature)).toMatchObject({ i: '1', d: 'relay.test', s: 'mmarc1' })
                verifyMessageSignature(headers, body, signature, keys[1])
                const seal = singleHeader('arc-seal')
                const tags = tagValues(seal)
                expect(tags).toMatchObject({ i: '1', cv: 'none', d: 'relay.test', s: 'mmarc1' })
                const sealed = [results, signature, withoutSignature(seal)].map(relaxedHeader).join('\r\n')
                expect(verify('RSA-SHA256', Buffer.from(sealed), keys[1], Buffer.from(tags.b, 'base64'))).toBe(true)
            }
        }

        await test.step('an empty forwarding queue causes no job-table SQL', async () => {
            await expect.poll(emptyQueue).toBe(true)
            const before = queueCalls()
            expect(before).toBeGreaterThan(0)
            await delay(2_000)
            expect(queueCalls()).toBe(before)
        })

        await test.step('a forward enqueued in an open transaction only wakes the worker after commit', async () => {
            const process = spawn('docker', [...composeArgs, 'exec', '-T', 'app', 'php', '/relay-queue-copies.php', 'transaction'], { env })
            const exited = once(process, 'close')
            process.stdin.on('error', () => {})
            let output = ''
            process.stdout.on('data', chunk => { output += chunk })
            process.stderr.on('data', chunk => { output += chunk })
            let id
            try {
                await expect.poll(() => output.trim(), { timeout: 10_000 }).toMatch(/^[a-f0-9-]{36}$/)
                id = output.trim()
                const before = queueCalls()
                await delay(1_500)
                expect(sql("select count(*) from mail_forwards where id = '" + id + "'")).toBe('0')
                expect(captures()).toHaveLength(2)
                expect(queueCalls()).toBe(before)
            } finally {
                process.stdin.end('commit\n')
                expect((await exited)[0], output).toBe(0)
            }
            await expect.poll(() => captures().length).toBe(3)
            await expect.poll(() => sql("select status from mail_forwards where id = '" + id + "'")).toBe('accepted')
        })

        await test.step('a delayed retry stays in the queue until its persisted due time', async () => {
            const due = Number(queue('retry'))
            expect(due).toBeGreaterThan(0)
            await expect.poll(() => captures().length, { timeout: 15_000 }).toBe(4)
            expect(captures()[3].received_at).toBeGreaterThanOrEqual(due)
            await expect.poll(() => sql("select count(*) from mail_forwards where status = 'accepted' and attempts = 2")).toBe('1')
        })

        await test.step('a reserved job wakes the worker when its reservation expires', async () => {
            const due = Number(queue('reservation'))
            expect(due).toBeGreaterThan(0)
            await expect.poll(() => captures().length, { timeout: 15_000 }).toBe(5)
            expect(captures()[4].received_at).toBeGreaterThanOrEqual(due)
            await expect.poll(() => sql("select count(*) from mail_forwards where status = 'accepted' and attempts = 2")).toBe('2')
        })

        await test.step('the forwarding worker resumes after losing its PostgreSQL session', async () => {
            const backend = () => sql("select pid from pg_stat_activity where datname = current_database() and usename = 'mailmm' and state = 'idle'")
            await expect.poll(backend).toMatch(/^\d+$/)
            const pid = Number(backend())
            expect(pid).toBeGreaterThan(0)
            expect(sql('select pg_terminate_backend(' + pid + ')')).toBe('t')
            const id = queue('transaction', { input: 'commit\n' })
            expect(id).toMatch(/^[a-f0-9-]{36}$/)
            await expect.poll(() => captures().length, { timeout: 20_000 }).toBe(6)
            await expect.poll(() => sql("select status from mail_forwards where id = '" + id + "'")).toBe('accepted')
        })

        await test.step('restart signals before LISTEN and buffered on the listener both stop the worker', async () => {
            compose(['stop', 'forward-worker'])
            for (const scenario of ['restart-before-listen', 'restart-buffered']) {
                expect(compose(['exec', '-T', 'app', 'timeout', '8', 'php', '/relay-queue-copies.php', scenario]).trim())
                    .toBe('restart_signal')
            }
        })

        await test.step('the next forwarding deadline uses indexes for both job states', async () => {
            const plan = compose(['exec', '-T', 'app', 'timeout', '8', 'php', '/relay-queue-copies.php', 'deadline-indexes'])
            expect(plan).toContain('jobs_unreserved_available_index')
            expect(plan).toContain('jobs_reserved_at_index')
        })
    } catch (error) {
        console.error(compose(['logs', '--tail=60', 'forward-worker', 'outbound', 'forward-signer', 'relay-sink'],
            { stdio: ['ignore', 'pipe', 'pipe'] }))
        throw error
    } finally {
        compose(['down', '--volumes', '--rmi', 'local'], { stdio: 'inherit' })
    }
})
