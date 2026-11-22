import assert from 'node:assert/strict'
import { test } from 'node:test'
import { effectScope } from 'vue'
import { useActionGuard } from '../../resources/js/useActionGuard.ts'

test('a key is acquired before clipboard access, stays busy until it settles, and then cools down', async t => {
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'] })
    const scope = effectScope()
    const guard = scope.run(() => useActionGuard(() => false))!
    const write = Promise.withResolvers<void>()
    let writes = 0
    const first = guard.run('copy:1', 2000, () => { writes++; return write.promise })
    assert.equal(writes, 1)
    t.mock.timers.tick(5000)
    assert.equal(guard.run('copy:1', 2000, () => ++writes), undefined)
    assert.equal(guard.run('copy:2', 2000, () => 2), 2)
    write.resolve()
    await first
    t.mock.timers.tick(1999)
    assert.ok(guard.blocked('copy:1'))
    t.mock.timers.tick(1)
    assert.equal(guard.blocked('copy:1'), false)
    scope.stop()
})

test('administrators skip a pause but cannot start an identical pending operation; a reset discards late completions', async () => {
    const scope = effectScope()
    let admin = true
    const guard = scope.run(() => useActionGuard(() => admin))!
    const pending = Promise.withResolvers<void>()
    const first = guard.run('copy', 2000, () => pending.promise)
    assert.equal(guard.blocked('copy'), true)
    guard.reset()
    assert.equal(guard.run('copy', 2000, () => 1), 1)
    assert.equal(guard.run('copy', 2000, () => 2), 2)
    admin = false
    pending.resolve()
    await first
    assert.equal(guard.blocked('copy'), false)
    guard.pause('manual', 5000)
    assert.equal(guard.blocked('manual'), true)
    scope.stop()
})
