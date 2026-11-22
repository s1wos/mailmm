<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stale_panel_reports_the_account_change_before_checking_the_new_users_role(): void
    {
        config(['app.debug' => false]);
        $admin = User::factory()->create(['is_admin' => true]);
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $this->patchJson('/admin/settings/issuance', [], ['X-Panel-User' => (string) $admin->id])
            ->assertConflict()->assertExactJson(['code' => 'panel_user_changed']);
        $this->patchJson('/admin/settings/issuance', [], ['X-Panel-User' => (string) $owner->id])
            ->assertForbidden()->assertExactJson(['message' => '']);
        $this->get('/admin/settings')->assertForbidden()->assertSee('Forbidden');
    }
}
