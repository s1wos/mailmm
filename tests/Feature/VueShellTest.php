<?php

namespace Tests\Feature;

use Illuminate\Foundation\Vite;
use Tests\TestCase;

class VueShellTest extends TestCase
{
    public function test_public_pages_and_panel_login_boot_the_same_application(): void
    {
        $this->withoutVite();
        foreach (['/', '/about', '/help', '/admin/login'] as $path) {
            $response = $this->get($path)->assertOk()->assertSee('<div id="app"></div>', false)
                ->assertSee('name="reverb-key"', false)->assertSee('name="csrf-token"', false)
                ->assertSee('id="panel-css"', false);
            $response->assertSee('name="robots" content="'.($path === '/admin/login' ? 'noindex' : 'index,follow').'"', false);
        }
    }

    public function test_the_dev_shell_loads_vite_and_the_runtime_reverb_key(): void
    {
        $hotFile = tempnam(sys_get_temp_dir(), 'mailmm-vite-');
        try {
            file_put_contents($hotFile, 'http://127.0.0.1:5173');
            app(Vite::class)->useHotFile($hotFile);
            config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'browser-test-key']);
            $this->get('/')->assertOk()
                ->assertSee('http://127.0.0.1:5173/@vite/client', false)
                ->assertSee('http://127.0.0.1:5173/resources/js/app.ts', false)
                ->assertSee('http://127.0.0.1:5173/resources/css/app.css', false)
                ->assertSee('name="reverb-key" content="browser-test-key"', false);
        } finally {
            unlink($hotFile);
        }
    }
}
