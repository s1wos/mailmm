import { expect } from '@playwright/test'
import { openRealtimeMailbox, test } from './stack.mjs'

test.use({ forwardingEnabled: true, channel: process.env.PLAYWRIGHT_CHANNEL || undefined })

async function signIn(page, url, email, password) {
    await page.goto(url + '/admin/login')
    await page.getByLabel('Электронная почта').fill(email)
    await page.getByLabel('Пароль').fill(password)
    await page.getByRole('button', { name: 'Войти' }).click()
    await expect(page.getByRole('link', { name: 'Открыть почту' }).first()).toBeVisible()
}

test('a verified rule and a manual share deliver the original HTML and files to one chosen recipient', async ({ page, browser, stack }) => {
    test.setTimeout(160_000)
    await signIn(page, stack.url, stack.admin.email, stack.admin.password)
    await page.goto(stack.url + '/admin/mailboxes')
    await page.getByLabel('Имя до @').fill('forward-owner')
    await page.getByRole('button', { name: 'Создать ящик' }).click()
    const credentials = page.locator('.credentials code')
    await expect(credentials).toHaveCount(2)
    const [email, password] = await credentials.allTextContents()
    const context = await browser.newContext()
    try {
        const owner = await context.newPage()
        await signIn(owner, stack.url, email, password)
        await openRealtimeMailbox(owner, stack.url)
        await owner.getByRole('link', { name: 'Автопересылка' }).click()
        await expect(owner).toHaveURL(stack.url + '/admin#auto-forwarding')
        const rule = owner.getByRole('region', { name: 'Автопересылка' })
        await expect.poll(() => rule.evaluate(section => section.contains(document.activeElement))).toBe(true)
        const automatic = 'confirmed@reader.test'
        const manual = 'colleague@reader.test'
        await rule.getByRole('button', { name: 'Настроить' }).click()
        await rule.getByLabel('Адрес назначения').fill(automatic)
        await rule.getByLabel('Текущий пароль').fill(password)
        await rule.getByRole('button', { name: 'Получить код' }).click()
        await expect(rule.getByLabel('Код из письма')).toBeFocused()
        await expect.poll(() => stack.captures('manual').length, { timeout: 20_000 }).toBe(1)
        const [verification] = stack.captures('manual')
        expect(verification).toMatchObject({ envelope_to: [automatic], from: 'service@out.test' })
        const code = verification.text.match(/\b\d{6}\b/)?.[0]
        expect(code).toBeTruthy()
        await rule.getByLabel('Код из письма').fill(code)
        await rule.getByRole('button', { name: 'Подтвердить' }).click()
        await expect(rule.getByText('Включена', { exact: true })).toBeVisible()
        // The Postfix receipt time has one-second precision. Enter the first full second after activation.
        await expect.poll(() => stack.sql("SELECT date_trunc('second', clock_timestamp()) > activated_at FROM mailbox_forwarding_rules WHERE destination = :'destination'",
            ['-At', '-v', 'destination=' + automatic]).trim()).toBe('t')

        await openRealtimeMailbox(owner, stack.url)
        const html = '<p>Receipt <img src="cid:logo@shop.test"></p>'
        const pdf = Buffer.from([0x25, 0x50, 0x44, 0x46, 0, 0xfe, 0xff, 13, 10])
        const logo = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0, 0xff])
        const subject = 'Invoice for forwarding'
        const accepted = await stack.smtp.sendMail({ envelope: { from: 'store@shop.test', to: email },
            from: 'Store <store@shop.test>', to: 'visible@shop.test', cc: 'copy@shop.test', replyTo: 'replies@shop.test',
            subject, messageId: '<invoice-17@shop.test>', text: 'Receipt', html, attachments: [
                { filename: 'receipt.pdf', content: pdf, contentType: 'application/pdf' },
                { filename: 'logo.png', cid: 'logo@shop.test', content: logo, contentType: 'image/png' },
            ] })
        expect(accepted.accepted).toEqual([email])
        await owner.getByRole('button', { name: new RegExp(subject) }).click()
        await owner.getByRole('link', { name: 'Переслать' }).click()
        await expect(owner.getByLabel('Кому')).toBeFocused()
        await owner.setViewportSize({ width: 320, height: 740 })
        expect(await owner.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
        await owner.getByLabel('Кому').fill(manual)
        await owner.getByRole('button', { name: 'Отправить' }).click()
        await expect.poll(() => stack.captures('auto').length, { timeout: 25_000 }).toBe(2)
        const copies = stack.captures('auto')
        expect(stack.captures('manual')).toHaveLength(1)
        for (const destination of [automatic, manual]) {
            const received = copies.find(mail => mail.envelope_to.includes(destination))
            expect(received).toMatchObject({ envelope_to: [destination], from: 'store@shop.test', to: 'visible@shop.test',
                cc: 'copy@shop.test', reply_to: 'replies@shop.test', subject, message_id: 'invoice-17@shop.test' })
            expect(received.html.trim()).toBe(html)
            expect(received.text.trim()).toBe('Receipt')
            expect(received.files).toEqual({
                'receipt.pdf': { bytes: pdf.toString('base64'), cid: '' },
                'logo.png': { bytes: logo.toString('base64'), cid: 'logo@shop.test' },
            })
        }
        const status = owner.getByRole('listitem').filter({ hasText: manual })
        await expect(status).toContainText('Передано почтовому серверу', { timeout: 12_000 })
        await owner.reload()
        await expect(status).toContainText('Передано почтовому серверу')
    } finally {
        await context.close()
    }
})

test('a message archived before originals were saved can be forwarded with its text and attachment', async ({ page, stack }) => {
    const subject = 'Archived invoice'
    const recipient = 'historical@mailmm.test'
    const destination = 'archived-invoice@reader.test'
    const bytes = Buffer.from([0xff, 0, 0x42, 10])
    const key = 'ab'.repeat(32)
    const path = 'mail/ab/' + key + '-0'
    const files = [{ filename: 'invoice.bin', path, mime: 'application/octet-stream', size: bytes.length }]
    stack.compose(['exec', '-T', 'app', 'php', '-r',
        '$path = "storage/app/private/".$argv[1]; if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);'
        + ' exit(file_put_contents($path, base64_decode($argv[2])) === false ? 1 : 0);', '--', path, bytes.toString('base64')])
    stack.sql(`INSERT INTO messages (recipient, delivery_key, "from", subject, body_text, attachments, received_at)
        VALUES (:'recipient', :'key', 'shop@archive.test', :'subject', 'Saved invoice text', :'files'::json, now())`,
        ['-v', 'recipient=' + recipient, '-v', 'key=' + key, '-v', 'subject=' + subject, '-v', 'files=' + JSON.stringify(files)])

    await signIn(page, stack.url, stack.admin.email, stack.admin.password)
    await page.goto(stack.url + '/admin/archive')
    await page.getByRole('link', { name: new RegExp(subject) }).click()
    await page.getByRole('button', { name: 'Переслать' }).click()
    await expect(page.getByText(/текст письма и сохранённые вложения/)).toBeVisible()
    await page.getByLabel('Кому').fill(destination)
    await page.getByRole('button', { name: 'Отправить' }).click()
    const captures = () => stack.captures('auto').filter(mail => mail.envelope_to.includes(destination))
    await expect.poll(() => captures().length, { timeout: 25_000 }).toBe(1)
    const [mail] = captures()
    expect(mail).toMatchObject({ envelope_to: [destination], from: 'shop@archive.test', to: destination, reply_to: null, subject })
    expect(mail.text).toContain('Saved invoice text')
    expect(mail.files).toEqual({ 'invoice.bin': { bytes: bytes.toString('base64'), cid: '' } })
})
