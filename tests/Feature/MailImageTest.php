<?php

namespace Tests\Feature;

use App\Models\Email;
use App\Models\Message;
use App\Models\User;
use App\Services\MailImageUrl;
use App\Services\RemoteMailImage;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class MailImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.stores.file' => ['driver' => 'array']]);
    }

    public function test_an_owner_and_admin_get_the_same_cached_raster_through_their_own_message_preview(): void
    {
        $url = 'https://pictures.example.test/a.gif?code=private';
        $owner = User::factory()->create();
        $box = $owner->mailbox()->create(['email' => $owner->email]);
        $message = $box->messages()->create(['recipient' => $box->email, 'from' => 'shop@example.test', 'received_at' => now(), 'body_html' => '<img src="'.$url.'">']);
        $bytes = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');
        $this->mock(RemoteMailImage::class)->shouldReceive('fetch')->once()->with($url)->andReturnUsing(function () use ($bytes, $url) {
            $lock = Cache::lock('session:'.request()->session()->getId(), 1);
            self::assertTrue($lock->get(), 'An image fetch must leave the mailbox session available to concurrent page requests.');
            $lock->release();
            $slot = 'mail-image:'.(hexdec(substr(hash('sha256', $url), 0, 2)) % 64);
            self::assertFalse(Cache::store('file')->lock($slot.':fill', 1)->get(), 'Only the cache slot owner may fetch a missing image.');

            return ['mime' => 'image/gif', 'bytes' => $bytes];
        });

        $admin = User::factory()->create(['is_admin' => true]);
        foreach ([[$owner, 'api', 'data'], [$admin, 'admin', 'letter']] as [$user, $prefix, $field]) {
            $html = $this->actingAs($user)->getJson("/$prefix/messages/$message->id")->assertOk()->json("$field.html_preview");
            $path = HTMLDocument::createFromString('<!doctype html>'.$html)->querySelector('img')->getAttribute('src');
            self::assertStringStartsWith("/mail-images/$message->id/", $path);
            self::assertStringNotContainsString('pictures.example.test', $path);
            $this->get($path)->assertOk()->assertHeaderMissing('Set-Cookie')->assertHeader('Content-Type', 'image/gif')
                ->assertHeader('X-Content-Type-Options', 'nosniff')->assertContent($bytes);
        }
        $this->actingAs(User::factory()->create())->get($path)->assertNotFound();
    }

    public function test_guest_proxy_access_checks_the_mailbox_message_token_and_retention_before_fetching(): void
    {
        $guest = Email::create(['email' => 'visitor@mail.test', 'expires_at' => now()->addDay()]);
        $other = Email::create(['email' => 'other@mail.test', 'expires_at' => now()->addDay()]);
        $fields = ['recipient' => $guest->email, 'from' => 'shop@example.test', 'received_at' => now()];
        $message = $guest->messages()->create($fields);
        $second = $guest->messages()->create($fields);
        $url = 'https://example.test/image.png';
        $path = MailImageUrl::forMessage($message->id)($url);
        $unrelated = strtr(Crypt::encryptString($message->id."\n".$url), '+/', '-_');
        self::assertNull(MailImageUrl::destination($message->id, $unrelated));
        $fetcher = $this->mock(RemoteMailImage::class);
        $fetcher->shouldReceive('fetch')->once()->with($url)->andReturn(null);
        $this->withSession(['email_id' => $other->id])->get($path)->assertNotFound();
        $this->withSession(['email_id' => $guest->id])->get(str_replace("/$message->id/", "/$second->id/", $path))->assertNotFound();
        $this->get($path.'x')->assertNotFound();
        $this->get($path)->assertNotFound();
        $this->get($path)->assertNotFound();
        $message->update(['received_at' => now()->subDays(Message::RETENTION_DAYS)]);
        $this->get($path)->assertNotFound();
    }
}
