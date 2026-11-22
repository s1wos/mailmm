import { randomUUID } from 'node:crypto'
import { expect } from '@playwright/test'
import { openRealtimeMailbox, test } from './stack.mjs'

test.use({
    channel: process.env.PLAYWRIGHT_CHANNEL || undefined,
    launchOptions: { ignoreDefaultArgs: ['--hide-scrollbars'] },
})
test.setTimeout(90_000)

async function deliver(smtp, to, mail) {
    const from = 'sender@example.test'
    const result = await smtp.sendMail({ envelope: { from, to }, from, to, ...mail })
    expect(result.accepted).toEqual([to])
    expect(result.response).toMatch(/^250\b/)
    return result.response
}

async function downloadBytes(page, filename) {
    const download = page.waitForEvent('download')
    await page.getByRole('link', { name: filename }).click()
    const file = await download
    expect(file.suggestedFilename()).toBe(filename)
    const chunks = []
    for await (const chunk of await file.createReadStream()) chunks.push(chunk)
    return Buffer.concat(chunks)
}

async function traceMailbox(page, { holdDelivery = false, holdRevocation = false } = {}) {
    const sent = []
    const received = []
    const http = []
    const sockets = []
    let dropNextConfirmation = false
    let releaseRevocation
    function recordSend(raw) {
        const frame = JSON.parse(raw)
        const data = typeof frame.data === 'string' ? JSON.parse(frame.data) : frame.data
        sent.push({ event: frame.event, reason: data?.reason, deliveryId: data?.deliveryId })
    }
    if (holdDelivery || holdRevocation) {
        await page.routeWebSocket(/\/app\//, client => {
            sockets.push(client.url())
            const server = client.connectToServer()
            client.onMessage(raw => { recordSend(raw); server.send(raw) })
            server.onMessage(raw => {
                const { event } = JSON.parse(raw)
                received.push(event)
                if (event === 'mailbox.checked' && dropNextConfirmation) {
                    dropNextConfirmation = false
                    return
                }
                if (holdRevocation && event === 'mailbox.access-revoked') {
                    releaseRevocation = () => client.send(raw)
                } else if (!holdDelivery || event !== 'message.received') {
                    client.send(raw)
                }
            })
        })
    } else {
        page.on('websocket', socket => {
            sockets.push(socket.url())
            socket.on('framesent', ({ payload }) => recordSend(payload))
            socket.on('framereceived', ({ payload }) => received.push(JSON.parse(payload).event))
        })
    }
    page.on('request', request => {
        if (['xhr', 'fetch'].includes(request.resourceType())) http.push(new URL(request.url()).pathname)
    })
    return { sent, received, http, sockets, releaseRevocation: () => releaseRevocation(),
        withholdNextConfirmation: () => { dropNextConfirmation = true } }
}

async function login(page, url, credentials) {
    await page.goto(url + '/admin/login')
    await page.getByLabel('Электронная почта').fill(credentials.email)
    await page.getByLabel('Пароль').fill(credentials.password)
    await page.getByRole('button', { name: 'Войти' }).click()
    await expect(page.getByRole('link', { name: 'Открыть почту' }).first()).toBeVisible()
}

test('the dashboard counts browsers with visible tabs and drops the last hidden tab', async ({ page, browser, stack }) => {
    await login(page, stack.url, stack.admin)
    const online = page.locator('.metric').filter({ hasText: 'Пользователи онлайн' }).locator('.metric-value')
    await expect(online).toHaveText('1', { timeout: 20_000 })
    const visitor = await browser.newContext()
    try {
        const first = await visitor.newPage()
        await first.goto(stack.url + '/about')
        await expect(online).toHaveText('2', { timeout: 20_000 })
        const second = await visitor.newPage()
        await second.goto(stack.url + '/help')
        const count = async () => (await (await page.request.get(stack.url + '/admin/active-users')).json()).activeUsers
        await second.waitForLoadState('networkidle')
        expect(await count()).toBe(2)
        await first.close()
        await expect.poll(count).toBe(2)
        await second.evaluate(() => {
            Object.defineProperty(document, 'hidden', { configurable: true, value: true })
            document.dispatchEvent(new Event('visibilitychange'))
        })
        await expect(online).toHaveText('1', { timeout: 20_000 })
        await second.evaluate(() => {
            delete document.hidden
            document.dispatchEvent(new Event('visibilitychange'))
        })
        await expect.poll(count).toBe(2)
    } finally {
        await visitor.close()
    }
    await expect.poll(async () => (await (await page.request.get(stack.url + '/admin/active-users')).json()).activeUsers).toBe(1)
})

test('the open error journal collects service errors and keeps their history across container recreation', async ({ page, stack }) => {
    test.setTimeout(150_000)
    const marker = 'journal-' + randomUUID()
    const messages = { app: marker + '-laravel', smtp: marker + '-postfix', nginx: marker + '-nginx' }
    const logPostfix = message => stack.compose(['exec', '-T', 'smtp', 'postlog', '-p', 'error', '-t', 'postfix/mailmm-test', message])
    async function expectEntry(service, message) {
        const row = page.getByRole('listitem').filter({ hasText: message })
        await expect(row.getByText(service, { exact: true })).toBeVisible({ timeout: 45_000 })
        await expect(row.getByText('error', { exact: true })).toBeVisible()
    }

    await login(page, stack.url, stack.admin)
    await page.getByRole('link', { name: 'Ошибки', exact: true }).first().click()
    await expect(page.getByRole('heading', { name: 'Ошибки' })).toBeVisible()
    stack.compose(['exec', '-T', '-u', 'root', 'app', 'sh', '-c', 'exec php 2>/proc/1/fd/2'], {
        input: `<?php
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
            logger()->error('${messages.app}');`,
    })
    logPostfix(messages.smtp)
    expect((await fetch(stack.url + '/.well-known/acme-challenge/' + messages.nginx)).status).toBe(404)
    await Promise.all(Object.entries(messages).map(([service, message]) => expectEntry(service, message)))

    await stack.recreateSmtp(stack.mxHost)
    const after = marker + '-new-container'
    logPostfix(after)
    await expectEntry('smtp', after)
    await expectEntry('smtp', messages.smtp)
    try {
        stack.compose(['stop', '-t', '2', 'log-reader'])
        await expect(page.getByText(/Сборщик недоступен/)).toBeVisible({ timeout: 45_000 })
        await expectEntry('smtp', after)
    } finally {
        stack.compose(['start', 'log-reader'])
    }
    await expect(page.getByText(/Сборщик недоступен|Нет данных от источников/)).toHaveCount(0, { timeout: 60_000 })
    expect(Number(stack.sql("SELECT count(*) FROM admin_error_logs WHERE message LIKE '%' || :'marker' || '%'",
        ['-At', '-v', 'marker=' + marker]))).toBe(4)
})

test('the error journal reports a blocked Nginx write and recovers after PostgreSQL releases it', async ({ page, stack }) => {
    test.setTimeout(120_000)
    const marker = 'journal-lock-' + randomUUID()
    const journal = async () => {
        const response = await page.request.get(stack.url + '/admin/errors', { headers: { Accept: 'application/json' } })
        expect(response.ok()).toBe(true)
        return response.json()
    }
    const hasEntry = snapshot => snapshot.entries.data.some(row => row.service === 'nginx' && row.message.includes(marker))
    const locker = "SELECT pid FROM pg_stat_activity WHERE application_name = :'marker'"
    const sql = query => stack.sql(query, ['-At', '-v', 'marker=' + marker]).trim()
    await login(page, stack.url, stack.admin)
    await expect.poll(async () => {
        const { sources } = (await journal()).collection
        return sources['nginx/1']?.available && sources['worker/1']?.available
    }, { timeout: 30_000 }).toBe(true)

    let lastNginxSuccess
    try {
        stack.compose(['exec', '-d', '-T', '-e', 'PGAPPNAME=' + marker, 'db', 'psql', '-U', 'postgres', '-d', 'mailmm',
            '-v', 'ON_ERROR_STOP=1', '-c', 'BEGIN; LOCK admin_error_logs IN SHARE MODE; SELECT pg_sleep(90); ROLLBACK;'])
        await expect.poll(() => sql(`SELECT EXISTS (SELECT FROM pg_locks WHERE pid IN (${locker}) AND granted
            AND relation = 'admin_error_logs'::regclass AND mode = 'ShareLock')`)).toBe('t')
        expect((await fetch(stack.url + '/.well-known/acme-challenge/' + marker)).status).toBe(404)
        await expect.poll(() => sql(`SELECT EXISTS (SELECT FROM pg_locks WHERE NOT granted
            AND relation = 'admin_error_logs'::regclass AND mode = 'RowExclusiveLock'
            AND (${locker}) = ANY(pg_blocking_pids(pid)))`), { timeout: 15_000 }).toBe('t')

        const before = (await journal()).collection.sources
        lastNginxSuccess = before['nginx/1'].last_success_at
        let during
        await expect.poll(async () => {
            during = await journal()
            return during.collection.sources['worker/1'].last_success_at
        }, { timeout: 30_000 }).not.toBe(before['worker/1'].last_success_at)
        expect(during.collection.online).toBe(true)
        expect(during.collection.sources['nginx/1']).toMatchObject({ available: false, last_success_at: lastNginxSuccess })
        expect(hasEntry(during)).toBe(false)
    } finally {
        sql(`SELECT pg_terminate_backend(pid) FROM (${locker}) AS holder`)
    }

    await expect.poll(async () => {
        const after = await journal()
        const nginx = after.collection.sources['nginx/1']
        return hasEntry(after) && nginx.available && nginx.last_success_at > lastNginxSuccess
    }, { timeout: 30_000 }).toBe(true)
})

test('checking ten times reads a changed inbox by WebSocket with zero HTTP, including unchanged recheck', async ({ page, stack }) => {
    const trace = await traceMailbox(page, { holdDelivery: true })
    await openRealtimeMailbox(page, stack.url)
    const address = await page.locator('.address-value').textContent()
    await deliver(stack.smtp, address, { subject: 'Delivered before the manual check', text: 'Check the stored inbox.' })
    await expect.poll(() => trace.received.includes('message.received'), { timeout: 15_000 }).toBe(true)
    const letter = page.getByRole('button', { name: /Delivered before the manual check/ })
    await expect(letter).toHaveCount(0)
    trace.http.length = 0
    trace.sent.length = 0
    trace.received.length = 0
    const button = page.locator('.check-mail')
    await button.evaluate(element => { for (let i = 0; i < 10; i++) element.click() })
    await expect(letter).toBeVisible()
    await expect(button).toContainText('Проверено')
    await button.evaluate(element => element.click())
    expect(trace.sent.filter(frame => frame.event === 'mailbox.check')).toHaveLength(1)
    expect(trace.received).toContain('mailbox.checked')
    expect(trace.http).toEqual([])
    await button.click({ timeout: 9_000 })
    await expect.poll(() => trace.received.filter(e => e === 'mailbox.checked').length).toBe(2)
    expect(trace.http).toEqual([])
    trace.withholdNextConfirmation()
    await button.click({ timeout: 9_000 })
    await expect(page.getByRole('alert').filter({ hasText: 'Сервер не подтвердил' })).toBeVisible({ timeout: 20_000 })
    await expect(button).not.toContainText('Проверено')
    await expect(letter).toBeVisible()
    expect(trace.http).toEqual([])
})

test('a manual check keeps an expanded inbox and reads every required page through the socket', async ({ page, stack }) => {
    const trace = await traceMailbox(page)
    await openRealtimeMailbox(page, stack.url)
    const email = await page.locator('.address-value').textContent()
    stack.seedInbox(email, 35, 'Initial letter')
    const check = page.locator('.check-mail')
    await page.evaluate(() => {
        Object.defineProperty(document, 'hidden', { configurable: true, value: true })
        document.dispatchEvent(new Event('visibilitychange'))
        delete document.hidden
        document.dispatchEvent(new Event('visibilitychange'))
    })
    await check.click()
    await expect(page.locator('.letter-list button')).toHaveCount(30)
    await page.getByRole('button', { name: 'Показать ещё' }).click()
    await expect(page.locator('.letter-list button')).toHaveCount(35)
    stack.seedInbox(email, 1, 'Newer letter')
    trace.http.length = 0
    trace.sent.length = 0
    await check.click({ timeout: 9_000 })
    await expect(page.locator('.letter-list button')).toHaveCount(36)
    expect(trace.sent.filter(f => f.event === 'mailbox.check')).toHaveLength(1)
    expect(trace.sent.filter(f => f.event === 'mailbox.page')).toHaveLength(1)
    expect(trace.http).toEqual([])
})

test('a shared manual limit and a revoked mailbox both respond over the socket without HTTP', async ({ page, context, stack }) => {
    await openRealtimeMailbox(page, stack.url)
    const email = await page.locator('.address-value').textContent()
    const second = await context.newPage()
    const trace = await traceMailbox(second)
    await openRealtimeMailbox(second, stack.url)
    await expect(second.locator('.address-value')).toHaveText(email)
    trace.http.length = 0
    await page.locator('.check-mail').click()
    await expect(page.locator('.check-mail')).toContainText('Проверено')
    const check = second.locator('.check-mail')
    await check.click()
    await expect(second.getByRole('alert').filter({ hasText: 'Почту недавно проверяли' })).toBeVisible()
    await expect(check).not.toContainText('Проверено')
    expect(trace.http).toEqual([])
    stack.closeMailbox(email)
    await check.click({ timeout: 9_000 })
    await expect(second.locator('.address-copy')).toHaveCount(0)
    expect(trace.received.filter(e => e === 'mailbox.error')).toHaveLength(2)
    expect(trace.http).toEqual([])
})

test('one delivery reaches sixteen confirmed tabs on their original sockets', async ({ page, context, stack }) => {
    test.setTimeout(120_000)
    const tabs = []
    async function subscribe(tab) {
        const frames = []
        tab.on('websocket', socket => {
            socket.on('framereceived', ({ payload }) => {
                const { event, channel } = JSON.parse(payload)
                if (channel?.startsWith('private-mailbox.') && ['pusher_internal:subscription_succeeded', 'message.received'].includes(event)) {
                    frames.push({ socket, channel, event })
                }
            })
        })
        await openRealtimeMailbox(tab, stack.url)
        tabs.push({ tab, frames })
    }
    await subscribe(page)
    const email = await page.locator('.address-value').textContent()
    for (let batch = 0; batch < 5; batch++) {
        await Promise.all(Array.from({ length: 3 }, async () => subscribe(await context.newPage())))
    }
    const subscriptions = tabs.map(({ tab, frames }) => {
        const confirmations = frames.filter(frame => frame.event === 'pusher_internal:subscription_succeeded' && !frame.socket.isClosed())
        expect(confirmations).toHaveLength(1)
        const [confirmed] = confirmations
        frames.length = 0
        return { tab, frames, confirmed }
    })
    const subject = 'Mail for sixteen tabs'
    await deliver(stack.smtp, email, { subject, text: 'One broadcast for the shared mailbox' })
    await Promise.all(subscriptions.map(({ tab }) =>
        expect(tab.getByRole('button', { name: new RegExp(subject) })).toBeVisible({ timeout: 25_000 })))
    for (const { confirmed, frames } of subscriptions) {
        expect(confirmed.socket.isClosed()).toBe(false)
        expect(frames.map(frame => frame.event)).toEqual(['message.received'])
        expect(frames[0].channel).toBe(confirmed.channel)
        expect(frames[0].socket === confirmed.socket).toBe(true)
    }
})

test('an administrator can check and copy again immediately on the public mailbox', async ({ page, stack }) => {
    await login(page, stack.url, stack.admin)
    await expect(page.getByRole('heading', { name: 'Обзор почты' })).toBeVisible()
    const trace = await traceMailbox(page)
    await openRealtimeMailbox(page, stack.url)
    trace.http.length = 0
    const button = page.locator('.check-mail')
    trace.received.length = 0
    for (let i = 1; i <= 2; i++) {
        await button.evaluate(el => el.click())
        await expect.poll(() => trace.received.filter(e => e === 'mailbox.checked').length).toBe(i)
        await expect(button).toContainText('Проверено')
    }
    expect(trace.sent.filter(frame => frame.reason === 'manual')).toHaveLength(2)
    expect(trace.http).toEqual([])
    await page.evaluate(() => {
        window.copies = 0
        Object.defineProperty(navigator, 'clipboard', { value: { writeText: async () => { window.copies++ } } })
    })
    await page.locator('.copy-btn').click()
    await expect(page.locator('.copy-btn')).toHaveText('Скопировано')
    await page.locator('.address-copy').evaluate(el => el.click())
    expect(await page.evaluate(() => window.copies)).toBe(2)
})

test('a permanent mailbox owner is limited and their open letter disappears after a password reset', async ({ browser, page, stack }) => {
    const ownerContext = await browser.newContext()
    const renewedContext = await browser.newContext()
    let disconnectedContext
    let resumeConnection
    let workerStopped = false
    try {
        await login(page, stack.url, stack.admin)
        await page.goto(stack.url + '/admin/mailboxes')
        await page.getByLabel('Имя до @').fill('socket-owner')
        await page.getByRole('button', { name: 'Создать ящик' }).click()
        const credentials = page.locator('.credentials code')
        await expect(credentials).toHaveCount(2)
        const [email, password] = await credentials.allTextContents()
        const owner = await ownerContext.newPage()
        await login(owner, stack.url, { email, password })
        const trace = await traceMailbox(owner, { holdRevocation: true })
        await openRealtimeMailbox(owner, stack.url)
        await deliver(stack.smtp, email, { subject: 'Owner mailbox test', text: 'Private text in the owner browser' })
        await owner.getByRole('button', { name: /Owner mailbox test/ }).click()
        const body = owner.getByText('Private text in the owner browser', { exact: true })
        await expect(body).toBeVisible()
        disconnectedContext = await browser.newContext({ storageState: await ownerContext.storageState() })
        const disconnected = await disconnectedContext.newPage()
        const forwarded = []
        const requests = []
        let activeConnection
        let paused = false
        const resume = Promise.withResolvers()
        resumeConnection = resume.resolve
        await disconnected.routeWebSocket(/\/app\//, async client => {
            if (paused) await resume.promise
            if (disconnected.isClosed()) return
            const server = client.connectToServer()
            activeConnection = { client, server }
            server.onMessage(raw => {
                forwarded.push(JSON.parse(raw).event)
                client.send(raw)
            })
        })
        disconnected.on('request', request => requests.push(new URL(request.url()).pathname))
        await openRealtimeMailbox(disconnected, stack.url)
        const cachedLetter = disconnected.getByRole('button', { name: /Owner mailbox test/ })
        await cachedLetter.click()
        const cachedBody = disconnected.getByText('Private text in the owner browser', { exact: true })
        await expect(cachedBody).toBeVisible()
        requests.length = 0
        paused = true
        await Promise.all([activeConnection.client.close({ code: 4200 }), activeConnection.server.close({ code: 4200 })])
        await expect(disconnected.locator('.check-mail')).toBeDisabled()
        const check = owner.locator('.check-mail')
        await check.click()
        await expect(check).toContainText('Проверено')
        await check.evaluate(el => el.click())
        expect(trace.sent.filter(frame => frame.reason === 'manual')).toHaveLength(1)
        trace.http.length = 0
        const box = page.locator('.box-list li').filter({ hasText: email })
        await box.locator('summary').click()
        const oldDeliveries = trace.received.filter(event => event === 'message.received').length
        const oldLeaves = trace.sent.filter(frame => frame.event === 'pusher:unsubscribe').length
        stack.compose(['stop', '-t', '3', 'worker'])
        workerStopped = true
        await deliver(stack.smtp, email, { subject: 'Queued before reset', text: 'Pending delivery notification' })
        await expect.poll(() => Number(stack.sql("SELECT count(*) FROM messages WHERE recipient = :'email' AND subject = 'Queued before reset'",
            ['-At', '-v', 'email=' + email]))).toBe(1)
        await box.getByRole('button', { name: 'Перевыпустить пароль' }).click()
        await expect(credentials.nth(1)).not.toHaveText(password)
        const renewed = await renewedContext.newPage()
        await login(renewed, stack.url, { email, password: await credentials.nth(1).textContent() })
        const renewedTrace = await traceMailbox(renewed)
        await openRealtimeMailbox(renewed, stack.url)
        stack.compose(['exec', '-T', 'app', 'php', 'artisan', 'queue:work', '--once'])
        await expect.poll(() => renewedTrace.received.includes('message.received'), { timeout: 15_000 }).toBe(true)
        expect(trace.received.filter(event => event === 'message.received')).toHaveLength(oldDeliveries)
        expect(trace.received).not.toContain('mailbox.access-revoked')
        stack.compose(['start', 'worker'])
        workerStopped = false
        await expect.poll(() => trace.received.includes('mailbox.access-revoked'), { timeout: 15_000 }).toBe(true)
        expect(renewedTrace.received).not.toContain('mailbox.access-revoked')
        await deliver(stack.smtp, email, { subject: 'After credential renewal', text: 'Mail for the renewed session' })
        await expect(renewed.getByRole('button', { name: /After credential renewal/ })).toBeVisible()
        await expect(body).toBeVisible()
        expect(trace.sent.filter(frame => frame.event === 'pusher:unsubscribe')).toHaveLength(oldLeaves)
        expect(trace.received.filter(event => event === 'message.received')).toHaveLength(oldDeliveries)
        trace.releaseRevocation()
        await expect(body).toHaveCount(0)
        await expect(owner.getByRole('button', { name: /Owner mailbox test/ })).toHaveCount(0)
        await expect(owner.getByText('Доступ к ящику завершён', { exact: true })).toBeVisible()
        expect(trace.http).not.toContain('/api/email')

        await expect(cachedBody).toBeVisible()
        const denied = disconnected.waitForResponse(response =>
            new URL(response.url()).pathname === '/api/broadcasting/auth' && [401, 403, 419].includes(response.status()))
        paused = false
        resume.resolve()
        await denied
        await expect(disconnected.getByText('Доступ к ящику завершён', { exact: true })).toBeVisible()
        await expect(cachedBody).toHaveCount(0)
        await expect(cachedLetter).toHaveCount(0)
        await expect(disconnected.locator('.address-copy')).toHaveCount(0)
        expect(forwarded).not.toContain('mailbox.access-revoked')
        expect(requests).not.toContain('/api/email')
    } finally {
        if (workerStopped) stack.compose(['start', 'worker'])
        resumeConnection?.()
        await disconnectedContext?.close()
        await renewedContext.close()
        await ownerContext.close()
    }
})

test('the admin exemption follows login and logout in another tab', async ({ context, page, stack }) => {
    const trace = await traceMailbox(page)
    await openRealtimeMailbox(page, stack.url)
    const panel = await context.newPage()
    await login(panel, stack.url, stack.admin)
    await expect(page.getByRole('link', { name: 'Вернуться в панель' })).toBeVisible()
    await expect(page.getByText('Автообновление включено', { exact: true })).toBeVisible()
    const button = page.locator('.check-mail')
    for (let i = 1; i <= 2; i++) {
        await button.evaluate(el => el.click())
        await expect.poll(() => trace.sent.filter(f => f.reason === 'manual').length).toBe(i)
        await expect(button).toContainText('Проверено')
    }
    await page.getByRole('link', { name: 'Помощь' }).click()
    await expect(page.getByRole('heading', { name: 'Помощь' })).toBeVisible()
    await panel.getByRole('button', { name: 'Выйти' }).first().click()
    await expect(panel.getByRole('button', { name: 'Войти' })).toBeVisible()
    const resume = Promise.withResolvers()
    const requested = Promise.withResolvers()
    await page.route('**/api/email', async route => {
        requested.resolve()
        await resume.promise
        await route.continue()
    }, { times: 1 })
    try {
        await page.getByRole('link', { name: 'Почта', exact: true }).click()
        await requested.promise
        await expect(page.locator('.address-copy')).toHaveCount(0)
        await expect(page.getByRole('link', { name: 'Вернуться в панель' })).toHaveCount(0)
    } finally {
        resume.resolve()
    }
    await expect(page.getByText('Автообновление включено', { exact: true })).toBeVisible()
    await button.click()
    await expect(button).toContainText('Проверено')
    await button.evaluate(el => el.click())
    expect(trace.sent.filter(f => f.reason === 'manual')).toHaveLength(3)
})

test('WSS stays idle for 65 seconds and catches up after Reverb restarts', async ({ browser, stack, tlsUrl }) => {
    test.slow()
    const context = await browser.newContext({ ignoreHTTPSErrors: true })
    try {
        const page = await context.newPage()
        const trace = await traceMailbox(page)
        await openRealtimeMailbox(page, tlsUrl)
        expect(new URL(trace.sockets[0]).protocol).toBe('wss:')
        expect(trace.received).toContain('mailbox.checked')
        trace.http.length = 0
        trace.sent.length = 0
        const button = page.locator('.check-mail')
        await page.waitForTimeout(65_000)
        expect(trace.http).toEqual([])
        expect(trace.sent.filter(frame => frame.event === 'mailbox.check')).toEqual([])
        const email = await page.locator('.address-value').textContent()
        stack.stopRealtime()
        try {
            await expect(button).toBeDisabled()
            await deliver(stack.smtp, email, { subject: 'Mail during restart', text: 'Catch up after recovery.' })
        } finally {
            stack.startRealtime()
        }
        await expect(page.getByRole('button', { name: /Mail during restart/ })).toBeVisible({ timeout: 30_000 })
        await expect(page.getByText('Автообновление включено', { exact: true })).toBeVisible()
        expect(trace.sockets.length).toBeGreaterThan(1)
    } finally {
        await context.close()
    }
})

test('the panel navigation stays in place while switching sections with different heights', async ({ page, stack }) => {
    await page.setViewportSize({ width: 1536, height: 722 })
    await page.goto(stack.url + '/admin')
    await page.getByLabel('Электронная почта').fill(stack.admin.email)
    await page.getByLabel('Пароль').fill(stack.admin.password)
    await page.getByRole('button', { name: 'Войти' }).click()
    await expect(page.getByRole('heading', { name: 'Обзор почты' })).toBeVisible()
    await page.evaluate(() => document.fonts.ready)

    const navigation = page.getByRole('navigation', { name: 'Разделы панели' })
    const initial = await navigation.boundingBox()
    const hasVerticalOverflow = () => page.evaluate(() => document.documentElement.scrollHeight > innerHeight)
    expect(await hasVerticalOverflow()).toBe(true)

    const response = Promise.withResolvers()
    await page.route('**/admin/archive', async route => {
        await response.promise
        await route.continue()
    }, { times: 1 })
    try {
        await navigation.getByRole('link', { name: 'Архив писем' }).click()
        await expect(page).toHaveURL(/\/admin\/archive$/)
        expect(await navigation.boundingBox()).toEqual(initial)
    } finally {
        response.resolve()
    }
    await expect(page.getByRole('heading', { name: 'Архив писем' })).toBeVisible()
    expect(await hasVerticalOverflow()).toBe(false)
    expect(await navigation.boundingBox()).toEqual(initial)

    await navigation.getByRole('link', { name: 'Обзор' }).click()
    await expect(page.getByRole('heading', { name: 'Обзор почты' })).toBeVisible()
    expect(await navigation.boundingBox()).toEqual(initial)
})

test('the temporary mailboxes tab shows guest details and replaces a closed address', async ({ page, browser, stack }) => {
    const visitor = await browser.newContext()
    try {
        const inbox = await visitor.newPage()
        const issuance = inbox.waitForResponse(response => new URL(response.url()).pathname === '/api/email' && response.ok())
        await openRealtimeMailbox(inbox, stack.url)
        const { data: mailbox } = await (await issuance).json()
        const subject = 'Temporary mailbox listing'
        await deliver(stack.smtp, mailbox.email, { subject, text: 'Message for the temporary address.' })
        await expect(inbox.getByRole('button', { name: new RegExp(subject) })).toBeVisible({ timeout: 15_000 })

        await login(page, stack.url, stack.admin)
        const temporaryTab = page.getByRole('link', { name: 'Временные ящики', exact: true }).first()
        await temporaryTab.click()
        const row = page.getByRole('listitem').filter({ has: page.getByRole('link', { name: mailbox.email, exact: true }) })
        await expect(row.locator('time').first()).toHaveAttribute('datetime', /^\d{4}-\d{2}-\d{2}T/)
        await expect(row.locator('time').last()).toHaveAttribute('datetime', mailbox.expires_at)
        await expect(row.locator('dl > div').filter({ hasText: 'Писем' }).locator('dd')).toHaveText('1')
        await row.getByRole('link').click()
        await expect(page.getByLabel('Поиск по получателю')).toHaveValue(mailbox.email)
        await expect(page.getByText(subject, { exact: true })).toBeVisible()
        await temporaryTab.click()
        await expect(row).toBeVisible()

        await inbox.getByRole('button', { name: 'Сменить адрес', exact: true }).click()
        await inbox.getByRole('dialog').getByRole('button', { name: 'Сменить адрес' }).click()
        await expect(inbox.getByRole('dialog')).toBeHidden()
        const address = inbox.getByRole('button', { name: /^Скопировать адрес / })
        await expect(address).not.toHaveText(mailbox.email)
        const replacement = await address.textContent()
        await page.getByRole('button', { name: 'Обновить', exact: true }).click()
        await expect(page.getByRole('link', { name: replacement, exact: true })).toBeVisible()
        await expect(row).toHaveCount(0)
    } finally {
        await visitor.close()
    }
})

test('an administrator can correct a stale domain and retry a lost receipt on a receive-only domain', async ({ page, stack }) => {
    await login(page, stack.url, stack.admin)
    const domain = page.getByLabel('Домен', { exact: true })
    const create = page.getByRole('button', { name: 'Создать ящик' })
    const credentials = page.locator('.credentials code')
    const replacement = 'issuance.e2e.test'
    const variables = ['-At', '-v', 'replacement=' + replacement]
    stack.sql(`INSERT INTO mailbox_domains (name, issuance_enabled)
        VALUES ('stale.e2e.test', true), (:'replacement', false);`, variables)
    try {
        await page.goto(stack.url + '/admin/mailboxes')
        await expect(domain.locator(`option[value="${replacement}"]`)).toHaveCount(1)
        await domain.selectOption('stale.e2e.test')
        stack.sql("DELETE FROM mailbox_domains WHERE name = 'stale.e2e.test'")
        await create.click()
        await expect(domain).toHaveAttribute('aria-invalid', 'true')
        await expect(domain).toHaveValue('')
        await expect(domain.locator('option[value="stale.e2e.test"]')).toHaveCount(0)
        await domain.selectOption(replacement)

        const counts = () => stack.sql(`SELECT (SELECT count(*) FROM users WHERE split_part(email, '@', 2) = :'replacement'),
            (SELECT count(*) FROM emails WHERE split_part(email, '@', 2) = :'replacement')`, variables).trim()
        const replies = []
        await page.route('**/admin/mailboxes', async route => {
            const responses = await Promise.all([route.fetch(), route.fetch()])
            for (const response of responses) replies.push({ status: response.status(), result: await response.json() })
            await route.abort('connectionreset')
        }, { times: 1 })
        await create.click()
        await expect(page.getByRole('alert').filter({ hasText: 'Проверьте соединение' })).toBeVisible()
        expect(replies.map(reply => reply.status)).toEqual([200, 200])
        const receipt = replies[0].result.redirect
        expect(replies[1].result.redirect).toBe(receipt)
        expect(counts()).toBe('1|1')

        await create.click()
        await expect(credentials).toHaveCount(2)
        await expect(page).toHaveURL(new URL(receipt, stack.url).href)
        const [email, password] = await credentials.allTextContents()
        expect(email.endsWith('@' + replacement)).toBe(true)
        expect(password).toHaveLength(32)
        expect(counts()).toBe('1|1')

        await domain.selectOption(replacement)
        await create.click()
        await expect(credentials.first()).not.toHaveText(email)
        await expect(credentials.first()).toContainText('@' + replacement)
        expect(counts()).toBe('2|2')
    } finally {
        stack.sql("DELETE FROM mailbox_domains WHERE name IN ('stale.e2e.test', :'replacement')", variables)
    }
})

test('saving guest issuance keeps the settings page at the form', async ({ page, stack }) => {
    await page.setViewportSize({ width: 1280, height: 500 })
    await login(page, stack.url, stack.admin)
    await page.goto(stack.url + '/admin/settings')
    const draft = page.getByLabel('Новый домен')
    const toggle = page.getByLabel('Выдавать гостевые адреса')
    const save = page.getByRole('button', { name: 'Сохранить', exact: true })
    const enabled = await toggle.isChecked()
    try {
        await draft.fill('draft.example.test')
        await toggle.setChecked(!enabled)
        await save.scrollIntoViewIfNeeded()
        const scrollY = await page.evaluate(() => window.scrollY)
        expect(scrollY).toBeGreaterThan(0)
        const navigations = []
        page.on('request', request => { if (request.isNavigationRequest()) navigations.push(request.url()) })
        await save.click()
        await expect(page.getByRole('status').filter({ hasText: 'Настройки выдачи адресов сохранены' })).toBeVisible()
        expect(navigations).toEqual([])
        expect(await page.evaluate(() => window.scrollY)).toBe(scrollY)
        await expect(draft).toHaveValue('draft.example.test')

        await page.reload()
        await expect(toggle).toBeChecked({ checked: !enabled })
    } finally {
        stack.sql('UPDATE mailbox_settings SET guest_issuance_enabled = ' + (enabled ? 'TRUE' : 'FALSE') + ' WHERE id = 1')
    }
})

test('reloading settings only shows a focus outline after keyboard navigation', async ({ page, stack }) => {
    await page.goto(stack.url + '/admin/settings')
    await page.getByLabel('Электронная почта').fill(stack.admin.email)
    await page.getByLabel('Пароль').fill(stack.admin.password)
    await page.getByRole('button', { name: 'Войти' }).click()
    const heading = page.getByRole('heading', { name: 'Настройки', exact: true })
    await expect(heading).toBeVisible()

    await page.reload()
    await expect(heading).toBeVisible()
    const main = page.getByRole('main')
    await expect(main).toBeFocused()
    await expect(main).toHaveCSS('outline-style', 'none')

    await page.keyboard.press('Tab')
    await expect(main.locator(':focus-visible')).toHaveCSS('outline-style', 'solid')
    await page.getByRole('link', { name: 'К содержимому' }).press('Enter')
    await expect(main).toBeFocused()
    await expect(main).toHaveCSS('outline-style', 'solid')
})

test('both copy controls share one pending operation and keyboard activation works after feedback', async ({ page, stack }) => {
    await page.goto(stack.url)
    await page.evaluate(() => {
        window.writes = []
        Object.defineProperty(navigator, 'clipboard', { value: { writeText: value => {
            window.writes.push(value)
            if (window.writes.length === 1) return new Promise(resolve => { window.releaseClipboard = resolve })
            return Promise.resolve()
        } } })
    })

    const address = page.getByRole('button', { name: /Скопировать.*@/ })
    await expect(address).toHaveText(/@/)
    const email = await address.textContent()
    const padding = await address.evaluate(button => parseFloat(getComputedStyle(button).paddingLeft))
    expect(padding).toBeGreaterThan(0)
    const { height } = await address.boundingBox()
    await address.click({ position: { x: padding / 2, y: height / 2 } })
    const burst = () => page.evaluate(() => {
        const controls = [document.querySelector('.address-copy'), document.querySelector('.copy-btn')]
        for (let i = 0; i < 20; i++) controls[i % 2].click()
        return window.writes
    })
    expect(await burst()).toEqual([email])
    await page.evaluate(() => window.releaseClipboard())
    const confirmation = page.getByRole('button', { name: 'Скопировано', exact: true })
    await expect(confirmation).toBeVisible()
    expect(await burst()).toEqual([email])
    await expect(confirmation).toBeVisible()
    await expect(page.locator('.copy-btn')).toHaveText('Скопировать')

    await address.press('Shift+Tab')
    await page.keyboard.press('Tab')
    await expect(address).toBeFocused()
    await page.keyboard.press('Enter')
    await expect.poll(() => page.evaluate(() => window.writes)).toEqual([email, email])
    await expect(page.locator('.copy-btn')).toHaveText('Скопировать')
    await page.keyboard.press('Space')
    await expect.poll(() => page.evaluate(() => window.writes)).toEqual([email, email, email])
})

test('the mailbox address alone is selected when copying requires the browser menu', async ({ page, stack }) => {
    await page.goto(stack.url)
    await page.evaluate(() => {
        Object.defineProperty(navigator, 'clipboard', {
            value: { writeText: async () => { throw new Error('Clipboard permission denied') } },
        })
        document.execCommand = () => false
    })

    const address = page.getByRole('button', { name: /Скопировать.*@/ })
    await expect(address).toHaveText(/@/)
    const email = await address.textContent()
    await address.click()
    await expect(page.getByText('Адрес выделен. Скопируйте его через меню браузера.', { exact: true })).toBeVisible()
    expect(await page.evaluate(() => window.getSelection().toString())).toBe(email)
})

test('a quota warning disappears automatically and can be closed on the next attempt', async ({ page, stack }) => {
    await page.route('**/api/emails', route => route.fulfill({
        status: 429, json: { code: 'guest_issuance_quota_exceeded' },
    }))
    await page.goto(stack.url)
    const changeAddress = page.getByRole('button', { name: 'Сменить адрес', exact: true })
    const confirm = page.getByRole('dialog').getByRole('button', { name: 'Сменить адрес' })
    const warning = page.getByRole('alert').filter({ hasText: 'Лимит выдачи адресов исчерпан' })

    await changeAddress.click()
    await confirm.click()
    await expect(warning).toBeVisible()
    await expect(warning).toBeHidden({ timeout: 7000 })

    await page.getByRole('link', { name: 'Помощь' }).click()
    await expect(page.getByRole('heading', { name: 'Помощь' })).toBeVisible()
    await page.getByRole('link', { name: 'Почта', exact: true }).click()
    await expect(changeAddress).toBeEnabled()
    await expect(warning).toBeHidden({ timeout: 1000 })

    await changeAddress.click()
    await confirm.click()
    await expect(warning).toBeVisible()
    await page.getByRole('button', { name: 'Закрыть предупреждение' }).click()
    await expect(warning).toBeHidden({ timeout: 1000 })
})

test('a guest keeps their letter until they confirm the address change', async ({ page, stack }) => {
    const trace = await traceMailbox(page)
    await openRealtimeMailbox(page, stack.url)
    const address = page.locator('.address-value')
    await expect(address).toHaveText(/@/)
    const original = await address.textContent()
    const subject = 'Guest delivery before rotation'
    const body = 'The original mailbox still opens this letter.'
    trace.sent.length = 0
    trace.http.length = 0
    await deliver(stack.smtp, original, { subject, text: body })
    const letter = page.getByRole('button', { name: new RegExp(subject) })
    await expect(letter).toBeVisible({ timeout: 15_000 })
    expect(trace.received).toContain('message.received')
    expect(trace.sent.filter(frame => frame.event === 'mailbox.check')).toEqual([
        { event: 'mailbox.check', reason: 'auto', deliveryId: expect.any(Number) },
    ])
    expect(trace.http).toEqual([])

    const requests = []
    page.on('request', request => {
        if (request.method() === 'POST' && new URL(request.url()).pathname === '/api/emails') requests.push(request)
    })
    const open = page.getByRole('button', { name: 'Сменить адрес', exact: true })
    const dialog = page.getByRole('dialog')
    await open.click()
    await expect(dialog.getByRole('button', { name: 'Сменить адрес' })).toBeEnabled()
    expect(requests).toHaveLength(0)
    await dialog.getByRole('button', { name: 'Отмена' }).click()
    await expect(dialog).toBeHidden()
    await expect(address).toHaveText(original)
    await letter.click()
    await expect(page.getByText(body, { exact: true })).toBeVisible()
    expect(requests).toHaveLength(0)

    await open.click()
    await dialog.getByRole('button', { name: 'Сменить адрес' }).click()
    await expect(dialog).toBeHidden()
    expect(requests).toHaveLength(1)
    await expect(address).toHaveText(/@/)
    await expect(address).not.toHaveText(original)
    const replacement = await address.textContent()
    await page.reload()
    await expect(address).toHaveText(replacement)
    await expect(page.getByText('Писем пока нет', { exact: true })).toBeVisible()
})

test('Postfix rejects an unconfigured domain at RCPT TO', async ({ stack }) => {
    const to = 'recipient@unconfigured.invalid'
    await expect(stack.smtp.sendMail({ from: 'sender@example.test', to, text: 'Domain policy fixture' }))
        .rejects.toMatchObject({
            command: 'RCPT TO', code: 'EENVELOPE', responseCode: 554,
            response: expect.stringMatching(/5\.7\.1 .*Relay access denied/i), rejected: [to],
        })
})

test('Postfix delivers a receive-only domain over verified STARTTLS to the administrator archive', async ({ page, stack, tlsSmtp }) => {
    await page.goto(stack.url + '/admin/login')
    await page.getByLabel('Электронная почта').fill(stack.admin.email)
    await page.getByLabel('Пароль').fill(stack.admin.password)
    await page.getByRole('button', { name: 'Войти' }).click()
    await page.getByRole('link', { name: 'Настройки', exact: true }).click()
    const domain = 'archive.e2e.test'
    await page.getByLabel('Новый домен').fill(domain)
    await page.getByRole('button', { name: 'Добавить домен' }).click()
    await expect(page.getByRole('listitem').filter({ hasText: domain })).toContainText('Только приём')

    const recipient = 'unassigned@' + domain
    const subject = 'Receive-only delivery'
    const body = 'Delivered through the SQL domain map.'
    const bundle = [
        'Content-Type: multipart/mixed; boundary=docs',
        'Content-Disposition: attachment; filename=documents.mime', '',
        '--docs', 'Content-Type: text/plain; name=one.txt', '', 'First document',
        '--docs', 'Content-Type: application/octet-stream; name=two.bin',
        'Content-Transfer-Encoding: base64', '', 'AP8K', '--docs--',
    ].join('\r\n')
    const signature = Buffer.from([0, 255, 10, 13, 10, 1])
    const raw = [
        'From: sender@example.test', 'To: ' + recipient, 'Subject: ' + subject, 'MIME-Version: 1.0',
        'Content-Type: multipart/signed; protocol="application/pkcs7-signature"; boundary=signed', '',
        '--signed', 'Content-Type: multipart/mixed; boundary=content', '',
        '--content', 'Content-Type: text/plain', '', body, '--content', bundle, '--content--',
        '--signed', 'Content-Type: application/pkcs7-signature',
        'Content-Disposition: attachment; filename=smime.p7s', 'Content-Transfer-Encoding: base64', '',
        signature.toString('base64'), '--signed--', '',
    ].join('\r\n')
    await deliver(tlsSmtp, recipient, { raw })
    await page.getByRole('link', { name: 'Архив писем', exact: true }).click()
    await page.getByLabel('Поиск по получателю').fill(recipient)
    await page.getByRole('button', { name: 'Найти' }).click()
    await expect(page).toHaveURL(/recipient=/)
    const letter = page.getByRole('link', { name: new RegExp(subject) })
    await expect(async () => {
        await page.reload()
        await expect(letter).toBeVisible({ timeout: 2000 })
    }).toPass({ timeout: 45_000 })
    await letter.click()
    await expect(page.getByRole('heading', { name: subject })).toBeVisible()
    await expect(page.getByText(body, { exact: true })).toBeVisible()
    await expect(page.getByText(recipient, { exact: true })).toBeVisible()
    expect(await downloadBytes(page, 'documents.mime')).toEqual(Buffer.from(bundle))
    expect(await downloadBytes(page, 'smime.p7s')).toEqual(signature)
})

test('a guest can read and rotate a replacement mailbox after the original session cookies expire', async ({ context, page, stack }) => {
    await openRealtimeMailbox(page, stack.url)
    const address = page.locator('.address-value')
    const rotate = async () => {
        const previous = await address.textContent()
        await page.getByRole('button', { name: 'Сменить адрес', exact: true }).click()
        await page.getByRole('dialog').getByRole('button', { name: 'Сменить адрес' }).click()
        await expect(page.getByRole('dialog')).toBeHidden()
        await expect(address).not.toHaveText(previous)
        await expect(page.getByText('Автообновление включено', { exact: true })).toBeVisible()
        return address.textContent()
    }
    const replacement = await rotate()
    const body = 'Still in the replacement mailbox'
    await deliver(stack.smtp, replacement, { subject: 'Before cookie expiry', text: body })
    const letter = page.getByRole('button', { name: /Before cookie expiry/ })
    await expect(letter).toBeVisible({ timeout: 15_000 })
    const sessionCookies = /^(?:mailmm_session|XSRF-TOKEN)$/
    const sessionNames = async () => (await context.cookies(stack.url))
        .map(cookie => cookie.name).filter(name => sessionCookies.test(name)).sort()
    expect(await sessionNames()).toEqual(['XSRF-TOKEN', 'mailmm_session'])
    await context.clearCookies({ name: sessionCookies })
    expect(await sessionNames()).toEqual([])

    await letter.click()
    await expect(page.getByText(body, { exact: true })).toBeVisible()
    expect(await sessionNames()).toEqual(['XSRF-TOKEN', 'mailmm_session'])
    await expect(address).toHaveText(replacement)
    const next = await rotate()
    await expect(letter).toHaveCount(0)
    await deliver(stack.smtp, next, { subject: 'After cookie recovery', text: 'New subscription' })
    await expect(page.getByRole('button', { name: /After cookie recovery/ })).toBeVisible({ timeout: 15_000 })
})

test('a held SMTP message survives recreation under a new MX with its attachment intact', async ({ page, stack }) => {
    test.setTimeout(150_000)
    await openRealtimeMailbox(page, stack.url)
    const recipient = await page.locator('.address-value').textContent()
    const count = () => Number(stack.sql("SELECT count(*) FROM messages WHERE recipient = :'recipient'",
        ['-At', '-v', 'recipient=' + recipient]))
    const postfix = (...args) => stack.compose(['exec', '-T', 'smtp', ...args]).trim()
    const queued = id => postfix('postqueue', '-j').split(/\r?\n/).filter(Boolean)
        .map(line => JSON.parse(line)).find(entry => entry.queue_id === id)
    const subject = 'Mail accepted by the previous MX'
    const bytes = Buffer.from([0, 255, 13, 10, 19])
    const newHost = 'renamed-mx.mailmm.test'
    try {
        postfix('postconf', '-e', 'header_checks = static:HOLD')
        postfix('postfix', 'reload')
        const response = await deliver(stack.smtp, recipient, {
            subject, text: 'Queued for delivery', attachments: [{ filename: 'queued.bin', content: bytes }],
        })
        const id = response.match(/\bqueued as ([A-Za-z0-9]+)\b/i)?.[1]
        expect(id).toBeTruthy()
        await expect.poll(() => queued(id)?.queue_name).toBe('hold')
        expect(postfix('postcat', '-qh', id)).toContain('by ' + stack.mxHost + ' (Postfix)')
        expect(count()).toBe(0)

        expect(() => stack.compose(['run', '--rm', '--no-deps', '-T', '-e', 'MAILBOX_MAX_MESSAGE_BYTES=0',
            '--entrypoint', 'php', 'smtp', 'artisan', 'mail:parse', '--check-limit']))
            .toThrow(expect.objectContaining({ status: 75, stdout: expect.stringContaining('MAILBOX_MAX_MESSAGE_BYTES') }))
        expect(queued(id)?.queue_name).toBe('hold')
        await stack.recreateSmtp(newHost, '512')
        expect(postfix('postconf', '-h', 'message_size_limit')).toBe('512')
        expect(postfix('postconf', '-h', 'myhostname')).toBe(newHost)
        expect(queued(id)?.queue_name).toBe('hold')
        postfix('postsuper', '-H', id)
        postfix('postqueue', '-f')
        const letter = page.getByRole('button', { name: new RegExp(subject) })
        await expect(letter).toBeVisible({ timeout: 30_000 })
        await expect.poll(() => queued(id)).toBeUndefined()
        expect(count()).toBe(1)
        await letter.click()
        expect(await downloadBytes(page, 'queued.bin')).toEqual(bytes)
    } finally {
        await stack.recreateSmtp(stack.mxHost)
    }
})

test('the scheduler removes expired mail and its file while preserving a fresh delivery', async ({ stack }) => {
    test.setTimeout(120_000)
    const recipient = 'retention@mailmm.test'
    const subjects = ['expired', 'fresh']
    for (const subject of subjects) {
        await deliver(stack.smtp, recipient, {
            subject, text: subject, attachments: [{ filename: 'sample.txt', content: subject }],
        })
    }
    const variables = ['-At', '-v', 'recipient=' + recipient]
    const files = () => JSON.parse(stack.sql(`SELECT coalesce(json_object_agg(subject, attachments->0->>'path'), '{}')
        FROM messages WHERE recipient = :'recipient' AND NOT is_pending`, variables))
    await expect.poll(() => Object.keys(files()).sort(), { timeout: 20_000 }).toEqual(subjects)
    const paths = files()
    const privateFile = subject => '/var/www/storage/app/private/' + paths[subject]
    for (const subject of subjects) {
        expect(stack.compose(['exec', '-T', 'app', 'cat', privateFile(subject)])).toBe(subject)
    }
    stack.sql("UPDATE messages SET received_at = now() - interval '10 years' WHERE recipient = :'recipient' AND subject = 'expired'", variables)

    await expect.poll(() => Number(stack.sql("SELECT count(*) FROM messages WHERE recipient = :'recipient'", variables)), {
        timeout: 80_000, intervals: [1000, 3000],
    }).toBe(1)
    expect(files()).toEqual({ fresh: paths.fresh })
    stack.compose(['exec', '-T', 'app', 'test', '!', '-e', privateFile('expired')])
    expect(stack.compose(['exec', '-T', 'app', 'cat', privateFile('fresh')])).toBe('fresh')
})

test('concurrent confirmations from the same guest charge one rotation', async ({ page, stack }) => {
    await page.goto(stack.url + '/help')
    const { data: box } = await page.evaluate(async () => (await fetch('/api/email')).json())
    const issued = () => Number(stack.compose(['exec', '-T', 'db', 'psql', '-U', 'postgres', '-d', 'mailmm', '-Atc',
        "SELECT COALESCE(SUM(substring(value FROM '^i:([0-9]+);$')::integer), 0) FROM cache WHERE key ~ 'guest-mailbox-issue:[a-f0-9]{64}$'"]))
    const before = issued()
    const result = await page.evaluate(async id => {
        const headers = {
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'X-Expected-Mailbox-Id': String(id),
        }
        async function rotate() {
            const response = await fetch('/api/emails', { method: 'POST', headers })
            return { status: response.status, body: await response.json() }
        }
        return Promise.all([rotate(), rotate()])
    }, box.id)
    expect(result.map(r => r.status).sort()).toEqual([201, 409])
    expect(result.find(r => r.status === 409).body.code).toBe('mailbox_changed')
    const current = await page.evaluate(async () => (await fetch('/api/email')).json())
    expect(current.data.id).toBe(result.find(r => r.status === 201).body.data.id)
    expect(issued()).toBe(before + 1)
})
