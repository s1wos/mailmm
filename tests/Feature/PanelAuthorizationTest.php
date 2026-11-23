<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authenticated_login_request_redirects_even_without_credentials(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $this->postJson('/admin/login')->assertOk()->assertExactJson(['redirect' => '/admin']);
        $this->post('/admin/login')->assertRedirect('/admin');
    }

    public function test_a_password_change_checks_the_stored_password_after_validating_the_new_one(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'password' => 'old-password']);
        $this->actingAs($admin);
        User::findOrFail($admin->id)->update(['password' => 'already-changed']);

        $data = ['current_password' => 'old-password', 'password' => 'new-password', 'password_confirmation' => 'new-password'];
        $this->putJson('/admin/settings/password', [...$data, 'password_confirmation' => 'mismatch'])
            ->assertUnprocessable()->assertJsonValidationErrors('password')->assertJsonMissingValidationErrors('current_password');
        $this->putJson('/admin/settings/password', $data)
            ->assertUnprocessable()->assertJsonPath('errors.current_password.0', 'Текущий пароль указан неверно.');
    }

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
