<?php

namespace Tests\Feature;

use App\Models\Email;
use App\Models\User;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SitePresenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => [
                'driver' => 'reverb', 'key' => 'test', 'secret' => 'test-secret', 'app_id' => 'mailmm',
            ],
        ]);
    }

    public function test_tabs_share_their_browser_channel_on_public_and_panel_pages_through_login_and_logout(): void
    {
        $first = $this->get('/about')->assertOk();
        $this->assertSame(1, preg_match('/name="site-visitor-channel" content="(site-visitor\.[a-f0-9]{32})"/', $first->getContent(), $match));
        $cookie = config('session.cookie').'_visitor';
        $channel = $match[1];
        $socket = '123.456';
        $sign = fn (string $name) => $this->postJson('/api/broadcasting/auth', [
            'socket_id' => $socket, 'channel_name' => 'private-'.$name,
        ]);

        $recovered = $sign('site-visitor.'.str_repeat('0', 32))->assertForbidden()->assertCookie($cookie);
        $this->assertSame('site-visitor.'.$recovered->getCookie($cookie)->getValue(), $recovered->json('visitorChannel'));
        $this->withCookie($cookie, $first->getCookie($cookie)->getValue())->withCredentials();
        $sign($channel)->assertExactJson(['auth' => 'test:'.hash_hmac('sha256', $socket.':private-'.$channel, 'test-secret')]);
        $sign('site-visitor.'.str_repeat('0', 32))->assertForbidden()->assertExactJson(['visitorChannel' => $channel]);
        $this->assertDatabaseCount('emails', 0);
        $admin = User::factory()->create(['is_admin' => true]);
        $this->postJson('/admin/login', ['email' => $admin->email, 'password' => 'password'])->assertOk();
        $this->get('/admin')->assertOk()->assertSee($channel);
        $this->postJson('/admin/logout')->assertOk();
        $this->get('/help')->assertOk()->assertSee($channel);
    }

    public function test_only_admin_sees_occupied_visitor_channel_counts_and_outages_remain_distinguishable_from_zero(): void
    {
        $requests = [];
        $handler = HandlerStack::create(new MockHandler([
            new Response(200, [], '{"channels":{"private-site-visitor.a":{},"private-site-visitor.b":{}}}'),
            new Response(200, [], '{"channels":[]}'),
            new Response(503),
        ]));
        $handler->push(Middleware::history($requests));
        config(['broadcasting.connections.reverb.client_options.handler' => $handler]);

        $this->getJson('/admin/active-users')->assertUnauthorized();
        $owner = User::factory()->create();
        Email::create(['email' => 'owner@example.test', 'owner_user_id' => $owner->id]);
        $this->actingAs($owner)->getJson('/admin/active-users')->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->getJson('/admin')->assertOk()->assertJsonPath('metrics.activeUsers', 2);
        $this->getJson('/admin/active-users')->assertOk()->assertExactJson(['activeUsers' => 0]);
        $this->getJson('/admin/active-users')->assertOk()->assertExactJson(['activeUsers' => null]);
        foreach ($requests as $exchange) {
            $uri = $exchange['request']->getUri();
            $this->assertSame('/apps/mailmm/channels', $uri->getPath());
            parse_str($uri->getQuery(), $params);
            $this->assertSame('private-site-visitor.', $params['filter_by_prefix']);
            $this->assertLessThanOrEqual(2, $exchange['options']['timeout']);
        }
    }
}
