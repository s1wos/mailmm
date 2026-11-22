import { execFileSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { test as base, expect } from '@playwright/test'
import nodemailer from 'nodemailer'

async function connectSmtp(address, options) {
    const transport = nodemailer.createTransport({
        host: '127.0.0.1', port: Number(address.trim().split(':').at(-1)),
        secure: false, connectionTimeout: 5000, greetingTimeout: 5000, ...options,
    })
    await expect.poll(() => transport.verify().catch(() => false), { timeout: 60_000 }).toBe(true)
    return transport
}

export const test = base.extend({
    stack: [async ({}, use) => {
        const project = 'mailmm-e2e-' + randomBytes(8).toString('hex')
        const secret = () => randomBytes(32).toString('hex')
        const env = {
            ...process.env,
            HTTP_BIND: '127.0.0.1:', HTTPS_BIND: '127.0.0.1:', SMTP_BIND: '127.0.0.1:',
            DB_DATABASE: 'mailmm',
            MAILMM_TAG: 'local', MAILBOX_DOMAIN: 'mailmm.test', MX_HOST: 'mx.mailmm.test', MX_PREVIOUS_HOSTS: '',
            APP_KEY: 'base64:' + randomBytes(32).toString('base64'),
            PG_ADMIN_PASSWORD: secret(), DB_PASSWORD: secret(), SMTP_LOOKUP_PASSWORD: secret(),
            REVERB_APP_KEY: secret(), REVERB_APP_SECRET: secret(),
        }
        const compose = (args, options) => execFileSync('docker', [
            'compose', '--env-file', '.env.example', '-f', 'docker-compose.yml', '-p', project, ...args,
        ], { env, encoding: 'utf8', ...options })
        const sql = (input, variables = []) => compose(['exec', '-T', 'db', 'psql', '-U', 'postgres',
            '-d', env.DB_DATABASE, '-v', 'ON_ERROR_STOP=1', ...variables], { input })
        const localSmtp = () => connectSmtp(compose(['port', 'smtp', '25']), { ignoreTLS: true })
        const admin = { email: 'admin@e2e.test', password: secret() }
        try {
            const images = compose(['config', '--images']).trim().split(/\r?\n/)
            for (const service of ['app', 'smtp', 'web']) {
                expect(images).toContain(project + '-' + service + ':local')
            }
            compose(['up', '-d', '--build'], { stdio: 'inherit' })
            const url = 'http://' + compose(['port', 'nginx', '80']).trim()
            let smtp = await localSmtp()
            await expect.poll(() => fetch(url).then(r => r.status).catch(() => 0), { timeout: 30_000 }).toBe(200)
            compose(['exec', '-T', 'app', 'php', 'artisan', 'mail:admin', admin.email], {
                input: admin.password + '\n' + admin.password + '\n',
            })
            await use({
                url, admin, compose, sql, mxHost: env.MX_HOST,
                get smtp() { return smtp },
                async recreateSmtp(host, limit = '10485760') {
                    compose(['up', '-d', '--no-deps', '--force-recreate', 'smtp'], {
                        env: { ...env, MX_HOST: host, MAILBOX_MAX_MESSAGE_BYTES: limit },
                    })
                    smtp = await localSmtp()
                },
                seedInbox: (email, count, label) => sql(`INSERT INTO messages (email_id, recipient, "from", subject, received_at)
                           SELECT e.id, e.email, 'fixture@example.test', :'label' || ' ' || n, statement_timestamp()
                           FROM emails e CROSS JOIN generate_series(1, :'count'::int) n WHERE e.email = :'recipient';`,
                    ['-v', 'recipient=' + email, '-v', 'count=' + count, '-v', 'label=' + label]),
                closeMailbox: email => sql("UPDATE emails SET closed_at = now() WHERE email = :'recipient'", ['-v', 'recipient=' + email]),
                resetTestData: () => sql(`BEGIN;
                    DELETE FROM messages;
                    DELETE FROM cache WHERE key LIKE '%guest-mailbox-issue:%';
                    COMMIT;`),
                stopRealtime: () => compose(['stop', '-t', '3', 'reverb']),
                startRealtime: () => compose(['start', 'reverb']),
            })
        } finally {
            compose(['--profile', 'acme', 'down', '--volumes'], { stdio: 'inherit' })
            for (const service of ['app', 'smtp', 'web']) {
                const tag = project + '-' + service + ':local'
                if (execFileSync('docker', ['image', 'ls', '-q', tag], { encoding: 'utf8' }).trim()) {
                    execFileSync('docker', ['image', 'rm', '--no-prune', tag])
                }
            }
        }
    }, { scope: 'worker', timeout: 15 * 60_000 }],
    tlsStack: [async ({ stack }, use) => {
        const compose = args => stack.compose(['-f', 'docker-compose.tls.yml', ...args])
        const config = JSON.parse(compose(['config', '--format', 'json']))
        for (const port of [80, 443]) {
            const binding = config.services.nginx.ports.find(binding => binding.target === port)
            expect(binding?.host_ip).toBe('127.0.0.1')
            expect(binding.published).toBeUndefined()
        }
        const ca = compose(['run', '--rm', '--no-deps', '-T',
            '-v', config.volumes.certificates.name + ':/certificates', '--entrypoint', 'sh', 'smtp', '-ec',
            `mkdir -p /certificates/live/mailmm
             cd /certificates/live/mailmm
             openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=$MX_HOST" \
                 -addext "subjectAltName=DNS:$MX_HOST,IP:127.0.0.1" -keyout privkey.pem -out fullchain.pem
             cat fullchain.pem`])
        await use({ compose, ca })
    }, { scope: 'worker' }],
    tlsUrl: [async ({ tlsStack }, use) => {
        let container
        try {
            container = tlsStack.compose(['run', '-d', '--rm', '--no-deps', '--service-ports', 'nginx']).trim()
            const endpoint = execFileSync('docker', ['port', container, '443/tcp'], { encoding: 'utf8' }).trim()
            await use('https://' + endpoint)
        } finally {
            if (container) execFileSync('docker', ['stop', container])
        }
    }, { scope: 'worker' }],
    tlsSmtp: [async ({ stack, tlsStack }, use) => {
        let container
        try {
            container = tlsStack.compose(['run', '-d', '--rm', '--no-deps', '-p', '127.0.0.1::25',
                '-v', '/var/spool/postfix', 'smtp']).trim()
            const address = execFileSync('docker', ['port', container, '25/tcp'], { encoding: 'utf8' })
            await use(await connectSmtp(address, {
                requireTLS: true, tls: { ca: tlsStack.ca, servername: stack.mxHost, rejectUnauthorized: true },
            }))
        } finally {
            if (container) execFileSync('docker', ['stop', container])
        }
    }, { scope: 'worker' }],
})

test.beforeEach(async ({ stack }) => { stack.resetTestData() })

export async function openRealtimeMailbox(page, url) {
    await page.goto(url)
    await expect(page.getByText('Автообновление включено', { exact: true })).toBeVisible({ timeout: 15_000 })
}
