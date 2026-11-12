<?php

namespace Modules\PostilioInbound\Tests;

use App\Mailbox;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * A mailbox that receives through Postilio counts as connected in FreeScout, while fetch-emails leaves it alone.
 * Needs the module active.
 */
class ConnectionTest extends TestCase
{
    use DatabaseTransactions;

    const KEY = 'pk_live_0123456789abcdefghijklmnopqrstuv';
    const SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        \Queue::fake();
        $this->admin = factory(User::class)->create(['role' => User::ROLE_ADMIN]);
    }

    private function save(Mailbox $mailbox, array $input)
    {
        $this->actingAs($this->admin)->post('/mailbox/'.$mailbox->id.'/postilio-inbound', $input);

        return $mailbox->fresh();
    }

    private function inactiveCardOnDashboard(Mailbox $mailbox)
    {
        $page = $this->actingAs($this->admin)->get('/')->getContent();

        return (bool) preg_match('/dash-card-inactive\s*"\s+data-mailbox-id="'.$mailbox->id.'"/', $page);
    }

    private function fetchEmails(Mailbox $mailbox)
    {
        \Artisan::call('freescout:fetch-emails', ['--mailboxes' => (string) $mailbox->id]);

        return \Artisan::output();
    }

    public function testTurningItOn_TheMailboxIsConnected_AndFetchEmailsSkipsIt()
    {
        // No IMAP settings at all, as for a mailbox that only ever received through Postilio.
        $mailbox = factory(Mailbox::class)->create();

        $mailbox = $this->save($mailbox, ['enabled' => '1', 'api_key' => self::KEY, 'webhook_secret' => self::SECRET]);

        $this->assertTrue($mailbox->isInActive());
        $this->assertTrue($mailbox->isConnected());
        $this->assertFalse($this->inactiveCardOnDashboard($mailbox));
        $this->assertStringNotContainsString('Mailbox: '.$mailbox->name, $this->fetchEmails($mailbox));
    }

    public function testTurningItOff_TheMailboxGoesBackToItsOwnProtocol()
    {
        $mailbox = factory(Mailbox::class)->create(['in_protocol' => Mailbox::IN_PROTOCOL_POP3]);
        $this->save($mailbox, ['enabled' => '1', 'api_key' => self::KEY, 'webhook_secret' => self::SECRET]);

        $mailbox = $this->save($mailbox, ['api_key' => '********', 'webhook_secret' => '********']);

        $this->assertSame(Mailbox::IN_PROTOCOL_POP3, (int) $mailbox->in_protocol);
        $this->assertFalse($mailbox->isInActive());
        $this->assertTrue($this->inactiveCardOnDashboard($mailbox));
    }

    public function testAMailboxWithoutPostilio_IsLeftToFreeScout()
    {
        $other = factory(Mailbox::class)->create();
        $postilio = factory(Mailbox::class)->create();

        $this->save($postilio, ['enabled' => '1', 'api_key' => self::KEY, 'webhook_secret' => self::SECRET]);

        $other = $other->fresh();
        $this->assertSame(Mailbox::IN_PROTOCOL_IMAP, (int) $other->in_protocol);
        $this->assertFalse($other->isInActive());
        $this->assertTrue($this->inactiveCardOnDashboard($other));
    }

    public function testAMailboxTurnedOnBefore011_IsMovedOverByTheMigration()
    {
        $mailbox = factory(Mailbox::class)->create();
        $mailbox->setMetaParam('postilio_inbound', [
            'enabled'        => true,
            'api_key'        => \Helper::encrypt(self::KEY),
            'webhook_secret' => \Helper::encrypt(self::SECRET),
        ], true);

        require_once __DIR__.'/../Database/Migrations/2026_11_12_100000_receive_postilio_mailboxes_through_postilio.php';
        (new \ReceivePostilioMailboxesThroughPostilio())->up();

        $this->assertTrue($mailbox->fresh()->isConnected());
    }
}
