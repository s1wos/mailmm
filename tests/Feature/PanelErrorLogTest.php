<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PanelErrorLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_administrator_can_open_the_error_journal_or_read_its_data(): void
    {
        $this->withoutVite();
        $this->get('/admin/errors')->assertRedirect('/admin/login');
        $this->getJson('/admin/errors')->assertUnauthorized();

        $this->actingAs(User::factory()->create());
        $this->get('/admin/errors')->assertForbidden();
        $this->getJson('/admin/errors')->assertForbidden();

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get('/admin/errors')->assertOk()->assertSee('id="app"', false)
            ->assertHeaderContains('Vary', 'Accept');
        $this->getJson('/admin/errors')->assertOk()->assertJsonPath('screen', 'errors')
            ->assertJsonPath('entries.data', [])->assertHeaderContains('Cache-Control', 'no-store');
    }

    public function test_new_entries_appear_on_refresh_without_shifting_the_cursor_into_older_entries(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->getJson('/admin/errors')->assertOk();
        DB::table('admin_error_logs')->insert(array_map($this->entry(...), range(1, 51)));

        $first = $this->getJson('/admin/errors')->assertOk()->assertJsonCount(50, 'entries.data')
            ->assertJsonPath('entries.prev_page_url', null)->assertJsonPath('entries.data.49.id', 2);
        $this->assertSame([
            'id' => 51,
            'occurred_at' => '2026-11-22T01:00:00.123456Z',
            'service' => 'nginx',
            'level' => 'error',
            'message' => 'Journal entry 51',
        ], $first->json('entries.data.0'));

        DB::table('admin_error_logs')->insert($this->entry(52));
        DB::table('admin_error_logs')->insert($this->entry(53, '2026-11-22 06:00:00.123455+05:00'));
        DB::table('admin_error_logs')->insert($this->entry(54, '2026-11-22 06:00:00.123457+05:00'));
        $this->getJson('/admin/errors')->assertOk()->assertJsonPath('entries.data.0.id', 54)
            ->assertJsonPath('entries.data.1.id', 52);
        $older = $this->getJson($first->json('entries.next_page_url'))->assertOk()
            ->assertJsonCount(2, 'entries.data')->assertJsonPath('entries.data.0.id', 1)
            ->assertJsonPath('entries.data.1.id', 53)
            ->assertJsonPath('entries.next_page_url', null);
        $this->getJson($older->json('entries.prev_page_url'))->assertOk()
            ->assertJsonPath('entries.data.0.id', 51);
    }

    public function test_a_damaged_cursor_is_reported_as_invalid_input(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $cursor = base64_encode('{"_pointsToNextItems":true}');
        foreach ([$cursor, ['unexpected']] as $invalid) {
            $url = '/admin/errors?'.http_build_query(['cursor' => $invalid]);
            $this->get($url)->assertOk()->assertSee('id="app"', false);
            $this->getJson($url)->assertUnprocessable()
                ->assertJsonPath('errors.cursor.0', 'Некорректная страница журнала.');
        }
        $this->json('GET', '/admin/errors', ['cursor' => $cursor])->assertUnprocessable()
            ->assertJsonPath('errors.cursor.0', 'Некорректная страница журнала.');
    }

    private function entry(int $id, string $occurredAt = '2026-11-22 06:00:00.123456+05:00'): array
    {
        return [
            'id' => $id,
            'event_key' => hash('sha256', (string) $id),
            'occurred_at' => $occurredAt,
            'service' => 'nginx',
            'level' => 'error',
            'message' => 'Journal entry '.$id,
        ];
    }
}
