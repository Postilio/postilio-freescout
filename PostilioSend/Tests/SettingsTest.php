<?php

namespace Modules\PostilioSend\Tests;

use App\Mailbox;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The "Postilio sending" page of a mailbox. Needs the module active.
 */
class SettingsTest extends TestCase
{
    use DatabaseTransactions;

    const KEY = 'pk_live_0123456789abcdefghijklmnopqrstuv';
    const SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';

    private $mailbox;
    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        // Saving restarts the queue worker with a job; with the sync queue of the tests that job would stop PHPUnit.
        \Queue::fake();
        $this->mailbox = factory(Mailbox::class)->create();
        $this->admin = factory(User::class)->create(['role' => User::ROLE_ADMIN]);
    }

    private function url()
    {
        return '/mailbox/'.$this->mailbox->id.'/postilio-send';
    }

    public function testPage_ShowsTheWebhookUrlToRegisterAtPostilio()
    {
        $response = $this->actingAs($this->admin)->get($this->url());

        $response->assertStatus(200);
        $this->assertStringContainsString(route('postiliosend.webhook', ['mailbox_id' => $this->mailbox->id]), $response->getContent());
    }

    public function testSave_KeepsTheKeyAndSecretEncrypted()
    {
        $this->actingAs($this->admin)->post($this->url(), ['enabled' => '1', 'api_key' => self::KEY, 'webhook_secret' => self::SECRET])
            ->assertRedirect($this->url());

        $settings = $this->mailbox->fresh()->getMeta('postilio_send');
        $this->assertTrue($settings['enabled']);
        $this->assertNotSame(self::KEY, $settings['api_key']);
        $this->assertSame(self::KEY, \Helper::decrypt($settings['api_key']));
        $this->assertSame(self::SECRET, \Helper::decrypt($settings['webhook_secret']));
    }

    public function testSave_WithTheMaskedValues_KeepsTheStoredKeyAndSecret()
    {
        $this->actingAs($this->admin)->post($this->url(), ['enabled' => '1', 'api_key' => self::KEY, 'webhook_secret' => self::SECRET]);

        $this->actingAs($this->admin)->post($this->url(), ['api_key' => '********', 'webhook_secret' => '********']);

        $settings = $this->mailbox->fresh()->getMeta('postilio_send');
        $this->assertFalse($settings['enabled']);
        $this->assertSame(self::KEY, \Helper::decrypt($settings['api_key']));
        $this->assertSame(self::SECRET, \Helper::decrypt($settings['webhook_secret']));
    }

    public function testSave_ThePageNeverShowsTheKey()
    {
        $this->actingAs($this->admin)->post($this->url(), ['enabled' => '1', 'api_key' => self::KEY, 'webhook_secret' => self::SECRET]);

        $page = $this->actingAs($this->admin)->get($this->url())->getContent();

        $this->assertStringNotContainsString(self::KEY, $page);
        $this->assertStringNotContainsString(self::SECRET, $page);
    }

    public function testSave_RefusesSomethingThatIsNotAKeyOrSecret()
    {
        $this->actingAs($this->admin)->post($this->url(), ['enabled' => '1', 'api_key' => 'sk_live_wrong', 'webhook_secret' => 'secret'])
            ->assertSessionHasErrors(['api_key', 'webhook_secret']);

        $this->assertNull($this->mailbox->fresh()->getMeta('postilio_send'));
    }

    public function testSave_TurningItOnWithoutAKey_IsRefused()
    {
        $this->actingAs($this->admin)->post($this->url(), ['enabled' => '1'])->assertSessionHasErrors(['api_key']);
    }

    public function testPage_IsForAdminsOnly()
    {
        $user = factory(User::class)->create(['role' => User::ROLE_USER]);

        $this->actingAs($user)->get($this->url())->assertStatus(403);
        $this->actingAs($user)->post($this->url(), ['enabled' => '1', 'api_key' => self::KEY])->assertStatus(403);
        $this->assertNull($this->mailbox->fresh()->getMeta('postilio_send'));
    }
}
