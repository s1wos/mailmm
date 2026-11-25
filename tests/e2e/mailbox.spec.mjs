import { randomUUID } from 'node:crypto'
import { expect } from '@playwright/test'
import { expectRealtimeMailbox, openRealtimeMailbox, test } from './stack.mjs'

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
    await expect(page.getByRole('link', { name: 'Открыть почту' }).first()).toBeVisible({ timeout: 15_000 })
}

// Warm the disposable proxy cache so the real authenticated image route can serve an
// offline sender. The browser must never try to contact that sender directly.
function cacheTestImage(stack, url, mime, bytes) {
    return stack.compose(['exec', '-T', '-u', 'www-data', 'app', 'php', '-r',
        `require 'vendor/autoload.php';
         $app = require 'bootstrap/app.php';
         $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
         $digest = hash('sha256', $argv[1]);
         $cache = Illuminate\\Support\\Facades\\Cache::store('file');
         $key = 'mail-image:'.(hexdec(substr($digest, 0, 2)) % 64);
         $cache->put($key, ['digest' => $digest, 'image' => ['mime' => $argv[2], 'bytes' => base64_decode($argv[3])]], 120);
         echo $cache->getStore()->path($key);`,
        '--', url, mime, bytes.toString('base64')]).trim()
}

test('blocked image requests occupy only the image pool and leave the inbox API responsive', async ({ page, stack }) => {
    const imageUrl = 'https://shop.example.test/pool-test.gif'
    const cachePath = cacheTestImage(stack, imageUrl, 'image/gif', Buffer.from('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', 'base64'))
    await openRealtimeMailbox(page, stack.url)
    const recipient = await page.locator('.address-value').textContent()
    await deliver(stack.smtp, recipient, { subject: 'Image pool test', html: `<img src="${imageUrl}">` })
    await expect(page.getByRole('button', { name: /Image pool test/ })).toBeVisible()
    const id = stack.sql("SELECT id FROM messages WHERE subject = 'Image pool test'", ['-At']).trim()
    const preview = await (await page.request.get(`${stack.url}/api/messages/${id}`)).json()
    const proxyPath = preview.data.html_preview.match(/\/mail-images\/\d+\/[A-Za-z0-9_-]+/)[0]

    // Hold cache reads at a deterministic point inside the FPM request, without contacting an external host.
    const signal = '/tmp/mailmm-image-pool-' + randomUUID()
    const app = (script, ...args) => stack.compose(['exec', '-T', '-u', 'www-data', 'app', 'php', '-r', script, '--', ...args])
    stack.compose(['exec', '-d', '-T', '-u', 'www-data', 'app', 'php', '-r', `
        $file = fopen($argv[1], 'rb');
        if (!$file || !flock($file, LOCK_EX)) exit(1);
        touch($argv[2].'.ready');
        $deadline = microtime(true) + 30;
        while (microtime(true) < $deadline && !file_exists($argv[2].'.release')) usleep(50000);
        fclose($file);`, '--', cachePath, signal])
    let images
    try {
        await expect.poll(() => app('echo (int) file_exists($argv[1].".ready");', signal)).toBe('1')
        images = Promise.allSettled(Array.from({ length: 3 }, () => page.request.get(stack.url + proxyPath, { timeout: 18_000 })))
        const snapshot = () => JSON.parse(app(String.raw`
            $inode = fileinode($argv[1]);
            $pools = static function () use ($inode): array {
                $blocked = [];
                foreach (file('/proc/locks') as $line) {
                    if (preg_match('/-> FLOCK\s+ADVISORY\s+READ\s+(\d+)\s+\S+:'.$inode.'\s/', $line, $match)) {
                        $name = @file_get_contents('/proc/'.$match[1].'/cmdline');
                        if ($name !== false) $blocked[] = trim(str_replace("\0", '', $name));
                    }
                }
                return $blocked;
            };
            $before = $pools();
            $inbox = count($before) === 3 ? @file_get_contents('http://nginx/api/messages', false,
                stream_context_create(['http' => ['timeout' => 3]])) : false;
            echo json_encode(['before' => $before, 'after' => $pools(),
                'inboxResponded' => is_array(json_decode((string) $inbox, true)['data'] ?? null)]);`, cachePath))
        const imageWorkers = Array(3).fill('php-fpm: pool mail-images')
        await expect.poll(snapshot, { timeout: 9_000 }).toEqual({ before: imageWorkers, after: imageWorkers, inboxResponded: true })
    } finally {
        app('touch($argv[1].".release");', signal)
        await images
    }
})

test('formatted mail keeps the sender’s layout and opens its links in both readers', async ({ page, context, stack }) => {
    await openRealtimeMailbox(page, stack.url)
    const recipient = await page.locator('.address-value').textContent()
    const subject = 'Квитанция за заказ'
    const destination = 'https://shop.example.test/order?item=1&view=receipt'
    const imageUrl = 'https://shop.example.test/banner.png'
    const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aquoAAAAASUVORK5CYII=', 'base64')
    let imageRequests = 0
    let proxyRequests = 0
    await context.route(imageUrl, route => {
        imageRequests++
        return route.abort()
    })
    cacheTestImage(stack, imageUrl, 'image/png', png)
    context.on('response', response => {
        if (response.url().startsWith(stack.url + '/mail-images/') && response.ok()) proxyRequests++
    })
    await context.route(destination, route => route.fulfill({ contentType: 'text/plain', body: 'Receipt' }))
    await deliver(stack.smtp, recipient, {
        subject, text: 'Ваш заказ оплачен.',
        html: '<style>.total { color: #285ca0; font-weight: bold }</style>'
            + '<table style="width:480px;max-width:100%;background:#f2f6fb" cellpadding="16"><tr>'
            + '<td><img src="cid:logo" alt="Логотип"><h2>Заказ оплачен</h2></td></tr>'
            + '<tr><td class="total">Итого 900 ₽</td></tr><tr><td>'
            + '<a style="display:inline-block;padding:10px 18px;background:#285ca0;color:white;border-radius:6px" '
            + 'href="https://shop.example.test/order?item=1&amp;view=receipt">Открыть квитанцию</a>'
            + ' <a href="https://shop.example.test/order?item=1&amp;view=receipt">Если не видно кнопки, нажмите сюда</a>'
            + '</td></tr></table><p>Помощь: https://shop.example.test/help</p>'
            + '<a href="https://shop.example.test/catalog"><img src="' + imageUrl + '" alt="Баннер"></a>',
        attachments: [{ filename: 'logo.png', cid: 'logo', content: png }],
    })
    await page.getByRole('button', { name: new RegExp(subject) }).click()
    const mail = page.frameLocator('iframe[title="Содержимое письма"]')
    const button = mail.getByRole('link', { name: 'Открыть квитанцию' })
    await expect(button).toHaveAttribute('href', destination)
    await expect(button).toHaveCSS('background-color', 'rgb(40, 92, 160)')
    await expect(mail.getByRole('link', { name: /нажмите сюда/ })).toHaveAttribute('href', destination)
    await expect(mail.getByRole('link', { name: 'https://shop.example.test/help' })).toHaveAttribute('href', 'https://shop.example.test/help')
    await expect(mail.getByRole('cell', { name: 'Итого 900 ₽' })).toHaveCSS('color', 'rgb(40, 92, 160)')
    await expect.poll(() => mail.getByAltText('Логотип').evaluate(img => img.naturalWidth)).toBe(1)
    await expect.poll(() => mail.getByAltText('Баннер').evaluate(img => img.naturalWidth)).toBe(1)
    await expect(mail.getByAltText('Баннер')).toHaveAttribute('src', /^\/mail-images\/\d+\//)
    expect(proxyRequests).toBe(1)
    expect(imageRequests).toBe(0)
    const opened = page.waitForEvent('popup')
    await button.click()
    const receipt = await opened
    await expect(receipt).toHaveURL(destination)
    await receipt.close()
    await expect(mail.getByRole('link', { name: 'Баннер' })).toHaveAttribute('href', 'https://shop.example.test/catalog')

    await login(page, stack.url, stack.admin)
    await page.getByRole('link', { name: 'Архив писем', exact: true }).first().click()
    await page.getByRole('link', { name: new RegExp(subject) }).click()
    await expect(button).toHaveAttribute('href', destination)
    await expect.poll(() => mail.getByAltText('Баннер').evaluate(img => img.naturalWidth)).toBe(1)
    expect(proxyRequests).toBe(2)
    expect(imageRequests).toBe(0)
})

test('the login and about cards keep each icon beside its heading', async ({ page, stack }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' })
    for (const [path, cards, count] of [['/admin/login', '.login-form-block', 1], ['/about', '.info-card', 3]]) {
        await page.goto(stack.url + path)
        const sections = page.locator(cards)
        await expect(sections).toHaveCount(count)
        await page.evaluate(() => document.fonts.ready)
        for (const width of [320, 1280]) {
            await page.setViewportSize({ width, height: 800 })
            for (const section of await sections.all()) {
                const icon = await section.locator('svg').first().boundingBox()
                const text = await section.getByRole('heading').evaluate(heading => {
                    const range = document.createRange()
                    range.selectNodeContents(heading.lastChild)
                    return range.getBoundingClientRect().toJSON()
                })
                expect(icon.x + icon.width).toBeLessThan(text.left)
                expect(Math.abs(icon.y + icon.height / 2 - (text.top + text.height / 2))).toBeLessThan(3)
            }
        }
    }
})

test('formatted mail automatically loads a background-only banner through the proxy and fits its reading pane', async ({ page, stack }) => {
    const banner = 'https://shop.example.test/hero.gif'
    let requests = 0
    let directRequests = 0
    await page.route(banner, route => {
        directRequests++
        return route.abort()
    })
    cacheTestImage(stack, banner, 'image/gif', Buffer.from('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', 'base64'))
    page.on('response', response => {
        if (response.url().startsWith(stack.url + '/mail-images/') && response.ok()) requests++
    })
    await openRealtimeMailbox(page, stack.url)
    const recipient = await page.locator('.address-value').textContent()
    await deliver(stack.smtp, recipient, {
        subject: 'Письмо с баннером',
        html: '<div style="min-height:100vh;padding:40px 0;background-image:url(' + banner + ')">Баннер</div>',
    })
    await page.getByRole('button', { name: /Письмо с баннером/ }).click()
    await expect.poll(() => requests).toBe(1)
    expect(directRequests).toBe(0)
    const iframe = page.getByTitle('Содержимое письма')
    const heights = await iframe.evaluate(async frame => {
        const samples = []
        for (let i = 0; i < 10; i++) {
            await new Promise(requestAnimationFrame)
            samples.push(frame.clientHeight)
        }
        return samples
    })
    expect(new Set(heights.slice(-4)).size).toBe(1)
})

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

for (const width of [390, 1280]) {
    test(`browser forward restores the public inbox position after visiting the about page at ${width}px`, async ({ page, stack }) => {
        await page.setViewportSize({ width, height: 480 })
        await page.goto(stack.url)
        const address = await page.getByRole('button', { name: /^Скопировать адрес / }).textContent()
        stack.seedInbox(address, 30, 'History letter')
        await page.goto(stack.url + '/about')
        await page.getByRole('link', { name: 'Открыть почту' }).click()
        const row = page.locator('.letter-list button').nth(24)
        await expect(row).toBeVisible()
        await page.evaluate(() => document.fonts.ready)
        await row.evaluate(button => button.scrollIntoView({ block: 'center' }))
        const position = await page.evaluate(() => scrollY)
        const listPosition = await page.locator('.letter-list').evaluate(list => list.scrollTop)
        expect(position).toBeGreaterThan(100)
        await page.goBack()
        await expect(page.getByRole('heading', { name: 'О сервисе' })).toBeVisible()
        await page.goForward()
        await expect(row).toBeVisible()
        await expect.poll(() => page.evaluate(() => scrollY)).toBe(position)
        await expect.poll(() => page.locator('.letter-list').evaluate(list => list.scrollTop)).toBe(listPosition)
        await expect(row).toBeInViewport()
    })
}

test('a manual check adds a letter to an expanded inbox in one socket reply', async ({ page, stack }) => {
    const trace = await traceMailbox(page)
    await openRealtimeMailbox(page, stack.url)
    const email = await page.locator('.address-value').textContent()
    stack.seedInbox(email, 330, 'Initial letter')
    const check = page.locator('.check-mail')
    await page.evaluate(() => {
        Object.defineProperty(document, 'hidden', { configurable: true, value: true })
        document.dispatchEvent(new Event('visibilitychange'))
        delete document.hidden
        document.dispatchEvent(new Event('visibilitychange'))
    })
    await check.click()
    const letters = page.locator('.letter-list button')
    const more = page.getByRole('button', { name: 'Показать ещё' })
    await expect(letters).toHaveCount(30)
    for (let count = 60; count <= 300; count += 30) {
        await more.click()
        await expect(letters).toHaveCount(count)
    }
    stack.seedInbox(email, 1, 'Newer letter')
    trace.http.length = 0
    trace.sent.length = 0
    await check.click({ timeout: 9_000 })
    await expect(letters).toHaveCount(301)
    expect(trace.sent.filter(f => f.event === 'mailbox.check')).toHaveLength(1)
    expect(trace.sent.filter(f => f.event === 'mailbox.page')).toHaveLength(0)
    expect(trace.http).toEqual([])
    await more.click()
    await expect(letters).toHaveCount(331)
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
    await expect(page.locator('.copy-btn')).toHaveAccessibleName('Скопировано')
    await page.locator('.address-copy').evaluate(el => el.click())
    expect(await page.evaluate(() => window.copies)).toBe(2)
})

test('a permanent mailbox owner can save and cancel a forwarding address while sending is paused', async ({ browser, page, stack }) => {
    await login(page, stack.url, stack.admin)
    await page.goto(stack.url + '/admin/mailboxes')
    await page.getByLabel('Имя до @').fill('paused-forward-owner')
    await page.getByRole('button', { name: 'Создать ящик' }).click()
    const credentials = page.locator('.credentials code')
    await expect(credentials).toHaveCount(2)
    const [email, password] = await credentials.allTextContents()
    const owner = await browser.newPage()
    try {
        await login(owner, stack.url, { email, password })
        await openRealtimeMailbox(owner, stack.url)
        await owner.getByRole('link', { name: 'Автопересылка' }).click()
        const rule = owner.getByRole('region', { name: 'Автопересылка' })
        await rule.getByRole('button', { name: 'Настроить' }).click()
        const destination = 'paused-owner@reader.test'
        await rule.getByLabel('Адрес назначения').fill(destination)
        await rule.getByLabel('Текущий пароль').fill(password)
        await rule.getByRole('button', { name: 'Сохранить адрес' }).click()
        await expect(rule.getByText('Адрес сохранён', { exact: true })).toBeVisible()

        await owner.reload()
        await expect(rule.getByRole('status').filter({ hasText: destination })).toContainText('Адрес сохранён')
        await expect(rule.getByText(/Когда отправка станет доступна, запросите код/)).toBeVisible()
        await expect(rule.getByLabel('Код из письма')).toHaveCount(0)
        await expect(rule.getByRole('button', { name: 'Получить код' })).toHaveCount(0)
        await rule.getByRole('button', { name: 'Отменить' }).click()
        await expect(rule.getByText('Выключена', { exact: true })).toBeVisible()
        await owner.reload()
        await expect(rule.getByRole('button', { name: 'Настроить' })).toBeVisible()
    } finally {
        await owner.close()
    }
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
        const retryCachedLetter = disconnected.getByRole('button', { name: 'Повторить загрузку письма' })
        await expect(async () => {
            // Opening this letter in the owner tab shares its cooldown with this tab.
            if (await retryCachedLetter.isVisible()) await retryCachedLetter.click()
            await expect(cachedBody).toBeVisible({ timeout: 750 })
        }).toPass({ intervals: [1000], timeout: 8000 })
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
        const renewedDeliveries = renewedTrace.received.filter(event => event === 'message.received').length
        await deliver(stack.smtp, email, { subject: 'After credential renewal', text: 'Mail for the renewed session' })
        await expect.poll(() => renewedTrace.received.filter(event => event === 'message.received').length, { timeout: 15_000 })
            .toBeGreaterThan(renewedDeliveries)
        await expect(renewed.getByRole('button', { name: /After credential renewal/ })).toBeVisible({ timeout: 15_000 })
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
    await expectRealtimeMailbox(page)
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
    await expectRealtimeMailbox(page)
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

test('the filtered archive keeps its place when paging and returning from a letter', async ({ page, stack }) => {
    const recipient = 'reader@scroll.test'
    stack.sql(`INSERT INTO messages (recipient, "from", subject, received_at)
        SELECT :'recipient', 'sender@scroll.test', 'Письмо ' || n, statement_timestamp()
        FROM generate_series(1, 60) AS n`, ['-v', 'recipient=' + recipient])
    await login(page, stack.url, stack.admin)
    await page.setViewportSize({ width: 850, height: 480 })
    await page.goto(stack.url + '/admin/archive?recipient=' + recipient)
    const pager = page.getByRole('navigation', { name: 'Страницы писем' })
    const next = pager.getByRole('link', { name: 'Вперёд' })
    const scrollY = () => page.evaluate(() => window.scrollY)
    await expect(next).toBeVisible()
    await page.evaluate(() => document.fonts.ready)
    await next.evaluate(link => link.scrollIntoView({ block: 'center' }))
    expect(await scrollY()).toBeGreaterThan(100)
    await next.click()
    await expect(pager).toContainText('Страница 2')
    await expect(next).toBeInViewport()

    const letter = page.locator('.mail-row').nth(19)
    const subject = await letter.locator('strong').textContent()
    for (const method of ['link', 'browser']) {
        await letter.evaluate(link => link.scrollIntoView({ block: 'center' }))
        const position = await scrollY()
        expect(position).toBeGreaterThan(100)
        await letter.click()
        await expect(page.getByRole('heading', { name: subject, exact: true })).toBeVisible()
        if (method === 'link') await page.getByRole('link', { name: 'К списку писем' }).click()
        else await page.goBack()
        await expect(pager).toContainText('Страница 2')
        await expect.poll(scrollY).toBe(position)
        await expect(letter).toBeInViewport()
    }
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
    await page.setViewportSize({ width: 1280, height: 400 })
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

        await create.scrollIntoViewIfNeeded()
        expect(await page.evaluate(() => window.scrollY)).toBeGreaterThan(0)
        await create.click()
        await expect(credentials).toHaveCount(2)
        await expect(page).toHaveURL(new URL(receipt, stack.url).href)
        await expect(create).toBeInViewport()
        expect(await page.evaluate(() => window.scrollY)).toBeGreaterThan(0)
        await expect(page.locator('#create-mailbox .credentials code')).toHaveCount(2)
        const [email, password] = await credentials.allTextContents()
        expect(email.endsWith('@' + replacement)).toBe(true)
        expect(password).toHaveLength(32)
        expect(counts()).toBe('1|1')

        await domain.selectOption(replacement)
        await create.click()
        await expect(credentials.first()).not.toHaveText(email)
        await expect(credentials.first()).toContainText('@' + replacement)
        expect(counts()).toBe('2|2')
        const secondReceipt = page.url()
        await page.goto(stack.url + '/admin/settings')
        await page.goBack()
        await expect(page).toHaveURL(secondReceipt)
        await expect(create).toBeVisible()
        await expect(credentials).toHaveCount(0)
    } finally {
        stack.sql("DELETE FROM mailbox_domains WHERE name IN ('stale.e2e.test', :'replacement')", variables)
    }
})

test('creating on the last mailbox page keeps the mobile button and one-time credentials in view', async ({ page, stack }) => {
    await login(page, stack.url, stack.admin)
    const path = stack.url + '/admin/mailboxes'
    const snapshot = await page.request.get(path, { headers: { Accept: 'application/json' } })
    const { total } = (await snapshot.json()).mailboxes
    const added = 13 - total % 12
    const last = Math.ceil((total + added) / 12)
    const prefix = 'mobile-' + randomUUID().slice(0, 8)
    const variables = ['-v', 'prefix=' + prefix, '-v', 'added=' + added]
    stack.sql(`WITH owners AS (
        INSERT INTO users (name, email, password)
        SELECT 'Mobile fixture', :'prefix' || '-' || n || '@mailmm.test', 'disabled' FROM generate_series(1, :added) n
        RETURNING id, email
    ) INSERT INTO emails (email, owner_user_id, created_at, updated_at) SELECT email, id, now(), now() FROM owners`, variables)
    try {
        await page.setViewportSize({ width: 390, height: 844 })
        await page.goto(path + '?page=' + last)
        await expect(page.locator('.box-list li')).toHaveCount(1)
        await page.getByLabel('Имя до @').fill(prefix + '-created')
        const create = page.getByRole('button', { name: 'Создать ящик' })
        await page.evaluate(() => document.fonts.ready)
        await create.scrollIntoViewIfNeeded()
        const top = (await create.boundingBox()).y
        await create.click()
        const credentials = page.locator('#create-mailbox .credentials code')
        await expect(credentials).toHaveCount(2)
        await expect(page).toHaveURL(url => url.searchParams.has('ticket') && url.searchParams.get('page') === String(last))
        await expect(page.locator('.box-list li')).toHaveCount(2)
        await expect.poll(async () => Math.abs((await create.boundingBox()).y - top)).toBeLessThanOrEqual(2)
        await expect(credentials.first()).toBeInViewport()
        await expect(credentials.nth(1)).toBeInViewport()

        await page.getByLabel('Имя до @').fill(prefix + '-second')
        await create.evaluate(button => button.scrollIntoView({ block: 'end' }))
        const beforeRepeat = (await create.boundingBox()).y
        await create.click()
        await expect(credentials.first()).toContainText(prefix + '-second@')
        await expect(page.locator('.box-list li')).toHaveCount(3)
        await expect.poll(async () => Math.abs((await create.boundingBox()).y - beforeRepeat)).toBeLessThanOrEqual(2)

        const receipt = page.url()
        await page.goto(stack.url + '/admin/settings')
        await page.goBack()
        await expect(page).toHaveURL(receipt)
        await expect(create).toBeVisible()
        await expect(page.locator('.credentials code')).toHaveCount(0)
    } finally {
        stack.sql(`BEGIN;
            DELETE FROM emails WHERE email LIKE :'prefix' || '-%';
            DELETE FROM users WHERE email LIKE :'prefix' || '-%';
            COMMIT;`, variables)
    }
})

test('reissuing a mailbox password keeps the expanded row and the creation draft in place', async ({ page, stack }) => {
    await page.setViewportSize({ width: 1280, height: 350 })
    await login(page, stack.url, stack.admin)
    const path = stack.url + '/admin/mailboxes'
    await page.goto(path)
    await page.getByRole('button', { name: 'Создать ящик' }).click()
    const credentials = page.locator('.credentials code')
    await expect(credentials).toHaveCount(2)
    const [email, originalPassword] = await credentials.allTextContents()

    await page.goto(path)
    const draft = page.getByLabel('Имя до @')
    await draft.fill('next-mailbox')
    const row = page.locator('.box-list li').filter({ hasText: email })
    await row.locator('summary').click()
    const reset = row.getByRole('button', { name: 'Перевыпустить пароль' })
    await reset.evaluate(button => button.scrollIntoView({ block: 'center' }))
    expect(await page.evaluate(() => window.scrollY)).toBeGreaterThan(0)
    const resume = Promise.withResolvers()
    await page.route('**/admin/mailboxes/*/password', async route => {
        await resume.promise
        await route.continue()
    }, { times: 1 })
    try {
        await reset.click()
        await expect(reset).toBeDisabled()
        await expect(page.getByText('Загрузка…', { exact: true })).toBeHidden()
        await expect(draft).toHaveValue('next-mailbox')
        await expect(row).toBeVisible()
    } finally {
        resume.resolve()
    }

    await expect(credentials.first()).toHaveText(email)
    await expect(credentials.nth(1)).toHaveText(/\S+/)
    expect(await credentials.nth(1).textContent()).not.toBe(originalPassword)
    await expect(reset).toBeInViewport()
    expect(await page.evaluate(() => window.scrollY)).toBeGreaterThan(0)
    await expect(row.locator('.credentials code')).toHaveCount(2)
    await expect(draft).toHaveValue('next-mailbox')
    await expect(page).toHaveURL(path)
    await page.reload()
    await expect(row).toBeVisible()
    await expect(credentials).toHaveCount(0)
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

async function openSettings(page, stack, account = stack.admin) {
    await page.setViewportSize({ width: 1280, height: 400 })
    await login(page, stack.url, account)
    await page.goto(stack.url + '/admin/settings')
}

async function submitSettingsInPlace(page, button, message) {
    await button.evaluate(element => element.scrollIntoView({ block: 'center' }))
    expect(await page.evaluate(() => window.scrollY)).toBeGreaterThan(0)
    const settingsReloads = []
    function trackSettingsRequest(request) {
        if (request.method() === 'GET' && new URL(request.url()).pathname === '/admin/settings') {
            settingsReloads.push(request.url())
        }
    }
    page.on('request', trackSettingsRequest)
    try {
        await button.click()
        await expect(page.getByRole('status').filter({ hasText: message })).toBeVisible()
        await expect(button).toBeInViewport()
        expect(await page.evaluate(() => window.scrollY)).toBeGreaterThan(0)
        expect(settingsReloads).toEqual([])
    } finally {
        page.off('request', trackSettingsRequest)
    }
}

test('adding a mail domain keeps the settings form and neighbouring draft in place', async ({ page, stack }) => {
    await openSettings(page, stack)
    const card = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Почтовые домены' }) })
    const name = card.getByLabel('Новый домен')
    const guest = page.getByLabel('Выдавать гостевые адреса')
    const guestDraft = !(await guest.isChecked())
    await guest.setChecked(guestDraft)
    await name.fill('New.Settings.Test.')
    try {
        await submitSettingsInPlace(page, card.getByRole('button', { name: 'Добавить домен' }), 'Домен добавлен')
        await expect(name).toHaveValue('')
        await expect(guest).toBeChecked({ checked: guestDraft })
        await expect(card.getByRole('status')).toContainText('Домен добавлен')
        await expect(card.getByRole('listitem').filter({ hasText: 'new.settings.test' })).toContainText('Только приём')
        await page.reload()
        await expect(card.getByText('new.settings.test', { exact: true })).toBeVisible()
    } finally {
        stack.sql("DELETE FROM mailbox_domains WHERE name = 'new.settings.test'")
    }
})

test('switching a mail domain keeps the edited row and neighbouring draft in place', async ({ page, stack }) => {
    stack.sql("INSERT INTO mailbox_domains (name, issuance_enabled) VALUES ('switch.settings.test', false)")
    try {
        await openSettings(page, stack)
        const name = page.getByLabel('Новый домен')
        await name.fill('domain-draft.test')
        const row = page.getByRole('listitem').filter({ hasText: 'switch.settings.test' })
        const button = row.getByRole('button')
        for (const [action, mode] of [['Включить выдачу', 'Приём и выдача'], ['Остановить выдачу', 'Только приём']]) {
            await expect(button).toHaveAccessibleName(action + ' на switch.settings.test')
            await submitSettingsInPlace(page, button, 'Режим домена сохранён')
            await expect(row).toContainText(mode)
            await expect(name).toHaveValue('domain-draft.test')
            await expect(row.getByRole('status')).toContainText('Режим домена сохранён')
            const snapshot = await page.request.get(stack.url + '/admin/settings', { headers: { Accept: 'application/json' } })
            const domain = (await snapshot.json()).domains.find(domain => domain.name === 'switch.settings.test')
            expect(domain.issuance_enabled).toBe(action === 'Включить выдачу')
        }
    } finally {
        stack.sql("DELETE FROM mailbox_domains WHERE name = 'switch.settings.test'")
    }
})

test('changing the administrator password stays at the form and permits another authenticated save', async ({ page, stack }) => {
    const account = { email: 'password-change@settings.test', password: stack.admin.password }
    stack.sql(`INSERT INTO users (name, email, password, is_admin)
        SELECT 'Password test', 'password-change@settings.test', password, true FROM users WHERE email = :'admin'`,
        ['-v', 'admin=' + stack.admin.email])
    try {
        await openSettings(page, stack, account)
        const draft = page.getByLabel('Новый домен')
        await draft.fill('domain-draft.test')
        const card = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Пароль администратора' }) })
        const button = card.getByRole('button', { name: 'Изменить пароль' })
        const current = card.getByLabel('Текущий пароль')
        const next = card.getByLabel('Новый пароль', { exact: true })
        const confirmation = card.getByLabel('Повторите новый пароль')
        const changed = randomUUID()
        for (const [from, to] of [[account.password, changed], [changed, account.password]]) {
            await current.fill(from)
            await next.fill(to)
            await confirmation.fill(to)
            await submitSettingsInPlace(page, button, 'Пароль администратора изменён')
            await expect(current).toHaveValue('')
            await expect(next).toHaveValue('')
            await expect(confirmation).toHaveValue('')
            await expect(draft).toHaveValue('domain-draft.test')
            await expect(card.getByRole('status')).toContainText('Пароль администратора изменён')
        }
    } finally {
        stack.sql("DELETE FROM users WHERE email = 'password-change@settings.test'")
    }
})

test('keyboard navigation in settings shows focus without an outer ring', async ({ page, stack }) => {
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
    const archive = main.getByRole('link', { name: 'архиве' })
    await expect(archive).toBeFocused()
    await expect(archive).toHaveCSS('outline-style', 'none')
    await expect(archive).toHaveCSS('text-decoration-line', 'underline')
    await page.getByRole('link', { name: 'К содержимому' }).press('Enter')
    await expect(main).toBeFocused()
    await expect(main).toHaveCSS('outline-style', 'none')
    await expect(heading).toHaveCSS('text-decoration-line', 'underline')
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
    await expect(page.locator('.copy-btn')).toHaveAccessibleName('Скопировать')

    await address.press('Shift+Tab')
    await page.keyboard.press('Tab')
    await expect(address).toBeFocused()
    await page.keyboard.press('Enter')
    await expect.poll(() => page.evaluate(() => window.writes)).toEqual([email, email])
    await expect(page.locator('.copy-btn')).toHaveAccessibleName('Скопировать')
    await page.keyboard.press('Space')
    await expect.poll(() => page.evaluate(() => window.writes)).toEqual([email, email, email])
})

test('copy feedback animates in both directions while both address buttons stay in place', async ({ page, stack }) => {
    await page.emulateMedia({ reducedMotion: 'no-preference' })
    await page.context().grantPermissions(['clipboard-write'], { origin: stack.url })
    await page.goto(stack.url)
    const copy = page.locator('.copy-btn')
    const change = page.getByRole('button', { name: 'Сменить адрес', exact: true })
    const boxes = () => Promise.all([copy.boundingBox(), change.boundingBox()])
    const finishMotion = element => Promise.all(element.getAnimations().map(animation => animation.finished))
    await expect(copy).toBeEnabled()
    await page.evaluate(() => document.fonts.ready)
    await page.locator('.address-card').evaluate(finishMotion)
    const initial = await boxes()

    await page.getByRole('button', { name: /^Скопировать адрес / }).focus()
    await page.keyboard.press('Tab')
    await expect(copy).toBeFocused()
    expect.soft(await copy.evaluate(button => getComputedStyle(button).outlineStyle)).toBe('none')
    await copy.hover()
    await copy.evaluate(finishMotion)
    expect.soft(await boxes()).toEqual(initial)
    await page.mouse.down()
    await copy.evaluate(finishMotion)
    expect.soft(await boxes()).toEqual(initial)

    await copy.evaluate(button => {
        window.animatedCopyLabels = new Set()
        const record = event => {
            if (event.elapsedTime > 0 && event.target !== button && (event.target.closest('svg') || event.target.querySelector('svg'))) {
                window.animatedCopyLabels.add(button.innerText.trim())
            }
        }
        button.addEventListener('transitionend', record)
        button.addEventListener('animationend', record)
    })
    await page.mouse.up()
    for (const label of ['Скопировано', 'Скопировать']) {
        await expect(copy).toHaveAccessibleName(label)
        await copy.evaluate(finishMotion)
        expect.soft(await boxes()).toEqual(initial)
    }
    await expect.poll(() => page.evaluate(() => [...window.animatedCopyLabels])).toEqual(['Скопировано', 'Скопировать'])
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

test('reopening the address dialog reuses the loaded domain choices', async ({ page, stack }) => {
    await page.clock.install()
    let requests = 0
    await page.route('**/api/email/options', route => {
        requests++
        return route.continue()
    })
    await page.goto(stack.url)
    const open = page.getByRole('button', { name: 'Сменить адрес', exact: true })
    const dialog = page.getByRole('dialog')
    const confirm = dialog.getByRole('button', { name: 'Сменить адрес', exact: true })
    await open.click()
    await expect(confirm).toBeEnabled()
    await dialog.getByRole('button', { name: 'Отмена' }).click()
    await page.clock.fastForward(2000)

    await open.click()
    expect(await dialog.getByText('Загружаем домены…').isVisible()).toBe(false)
    expect(await confirm.isEnabled()).toBe(true)
    expect(requests).toBe(1)
})

test('the address dialog can be dismissed without changing the mailbox', async ({ page, stack }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' })
    await page.goto(stack.url)
    const address = page.locator('.address-value')
    await expect(address).toHaveText(/@/)
    const original = await address.textContent()
    const rotations = []
    page.on('request', request => {
        if (request.method() === 'POST' && new URL(request.url()).pathname === '/api/emails') rotations.push(request)
    })
    const open = page.getByRole('button', { name: 'Сменить адрес', exact: true })
    const dialog = page.getByRole('dialog')
    const cancel = dialog.getByRole('button', { name: 'Отмена', exact: true })
    const close = dialog.getByRole('button', { name: /^Закрыть/ })

    async function clickBackdrop() {
        const box = await dialog.boundingBox()
        await page.mouse.click(box.x + 4, box.y + box.height / 2)
        await expect(dialog).toBeVisible()
        await page.mouse.down()
        await page.mouse.move(4, 4)
        await page.mouse.up()
        await expect(dialog).toBeVisible()
        await page.mouse.click(4, 4)
    }

    for (const dismiss of [clickBackdrop, () => close.click(), () => cancel.click(), () => page.keyboard.press('Escape')]) {
        await open.click()
        await expect(cancel).toBeFocused()
        await dismiss()
        await expect(dialog).toBeHidden()
        await expect(open).toBeFocused()
    }
    await expect(address).toHaveText(original)
    expect(rotations).toHaveLength(0)
})

for (const width of [280, 1280]) {
    test(`the address change button stays in place at ${width}px while loading domains and changing the address`, async ({ page, stack }) => {
        await page.setViewportSize({ width, height: 800 })
        const options = Promise.withResolvers()
        const rotation = Promise.withResolvers()
        await page.route('**/api/email/options', async route => { await options.promise; await route.continue() })
        await page.route('**/api/emails', async route => { await rotation.promise; await route.continue() })
        await page.goto(stack.url)
        await page.getByRole('button', { name: 'Сменить адрес', exact: true }).click()

        const dialog = page.getByRole('dialog')
        const confirm = dialog.getByRole('button', { name: 'Сменить адрес', exact: true })
        const pending = dialog.getByRole('button', { name: 'Меняем адрес…', exact: true })
        const finishAnimations = async element => {
            await Promise.all(element.getAnimations().map(animation => animation.finished))
        }
        try {
            await expect(dialog.getByText('Загружаем домены…')).toBeVisible()
            await page.evaluate(() => document.fonts.ready)
            await dialog.evaluate(finishAnimations)
            const initial = await confirm.boundingBox()
            options.resolve()
            await expect(confirm).toBeEnabled()
            const ready = await confirm.boundingBox()
            expect.soft(ready).toEqual(initial)

            await confirm.hover()
            await page.mouse.down()
            await confirm.evaluate(finishAnimations)
            expect.soft(await confirm.boundingBox()).toEqual(ready)
            await page.mouse.up()
            await expect(pending).toBeDisabled()
            await pending.evaluate(finishAnimations)
            expect.soft(await pending.boundingBox()).toEqual(ready)
            await expect(dialog.getByRole('button', { name: /^Закрыть/ })).toBeDisabled()
            await expect(dialog.getByRole('button', { name: 'Отмена' })).toBeDisabled()
            await page.mouse.click(4, 4)
            await page.keyboard.press('Escape')
            await expect(dialog).toBeVisible()
        } finally {
            options.resolve()
            rotation.resolve()
        }
        await expect(dialog).toBeHidden()
    })
}

test('a guest recovers after a failed rotation and mailbox read without repeating the POST', async ({ page, stack }) => {
    await openRealtimeMailbox(page, stack.url)
    const address = page.locator('.address-value')
    const original = await address.textContent()
    let posts = 0
    let reads = 0
    await page.route('**/api/emails', route => { posts++; return route.abort() })
    await page.route('**/api/email', route => ++reads === 1 ? route.abort() : route.continue())

    await page.getByRole('button', { name: 'Сменить адрес', exact: true }).click()
    await page.getByRole('dialog').getByRole('button', { name: 'Сменить адрес' }).click()
    await expect.poll(() => reads).toBe(2)
    await expect(address).toHaveText(original)
    await expect(page.getByText('Автообновление включено')).toBeVisible()
    expect(posts).toBe(1)
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
    await page.locator('.mailbox-warning').filter({ has: warning })
        .getByRole('button', { name: 'Закрыть предупреждение' }).click()
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

test('a permanent mailbox owner can choose a password and sign in again', async ({ page, stack }) => {
    await login(page, stack.url, stack.admin)
    await page.goto(stack.url + '/admin/mailboxes')
    await page.getByRole('button', { name: 'Создать ящик' }).click()
    const issued = page.locator('.credentials code')
    await expect(issued).toHaveCount(2)
    const [email, password] = await issued.allTextContents()
    await page.getByRole('button', { name: 'Выйти' }).first().click()
    await expect(page.getByLabel('Электронная почта')).toBeVisible()

    await login(page, stack.url, { email, password })
    const change = page.getByText('Сменить пароль', { exact: true })
    await expect(change).toBeVisible()
    await change.click()
    const chosen = randomUUID()
    await page.getByLabel('Текущий пароль').fill(password)
    await page.getByLabel('Новый пароль', { exact: true }).fill(chosen)
    await page.getByLabel('Повторите новый пароль').fill(chosen)
    await page.getByRole('button', { name: 'Изменить пароль' }).click()
    await expect(page.locator('.owner-password').getByRole('status')).toHaveText('Пароль ящика изменён.')

    await page.getByRole('button', { name: 'Выйти' }).first().click()
    await expect(page.getByLabel('Электронная почта')).toBeVisible()
    await login(page, stack.url, { email, password: chosen })
    await expect(page.getByRole('heading', { name: 'Моя почта' })).toBeVisible()
})
