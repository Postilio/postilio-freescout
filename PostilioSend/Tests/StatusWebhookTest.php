<?php

namespace Modules\PostilioSend\Tests;

use App\Conversation;
use App\Mailbox;
use App\SendLog;
use App\Thread;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Postilio's email events for a reply that went out through the API. Needs the module active.
 */
class StatusWebhookTest extends TestCase
{
    use DatabaseTransactions;

    const SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';
    const EMAIL_ID = '01a1527c-8e14-7b2a-9c51-3f0e2d6a4b10';

    private $mailbox;
    private $thread;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailbox = factory(Mailbox::class)->create(['email' => 'support@support.example.com']);
        $this->mailbox->setMetaParam('postilio_send', [
            'enabled'        => true,
            'api_key'        => \Helper::encrypt('pk_live_key'),
            'webhook_secret' => \Helper::encrypt(self::SECRET),
        ], true);
        $user = factory(User::class)->create();
        $conversation = factory(Conversation::class)->create(['mailbox_id' => $this->mailbox->id, 'created_by_user_id' => $user->id]);
        $this->thread = factory(Thread::class)->create([
            'conversation_id'    => $conversation->id,
            'type'               => Thread::TYPE_MESSAGE,
            'created_by_user_id' => $user->id,
            'message_id'         => '01a1527c8e147b2a9c513f0e2d6a4b10@support.example.com',
            'send_status'        => SendLog::STATUS_ACCEPTED,
        ]);
    }

    private function event($event, array $extra = [])
    {
        return json_encode([
            'type'      => 'email.'.$event.'.v1',
            'timestamp' => '2026-11-12T14:07:45.102+00:00',
            'data'      => array_merge(['emailId' => self::EMAIL_ID, 'to' => 'ada@example.org', 'event' => $event], $extra),
        ]);
    }

    private function deliver($body, $secret = self::SECRET, $mailboxId = null, $id = 'msg_1')
    {
        $timestamp = (string) time();
        $key = base64_decode(substr($secret, 6));
        $signature = 'v1,'.base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$body, $key, true));

        return $this->call('POST', '/postilio-send/webhook/'.($mailboxId ?: $this->mailbox->id), [], [], [], [
            'HTTP_WEBHOOK_ID'        => $id,
            'HTTP_WEBHOOK_TIMESTAMP' => $timestamp,
            'HTTP_WEBHOOK_SIGNATURE' => $signature,
            'CONTENT_TYPE'           => 'application/json',
        ], $body);
    }

    public function testBounced_MarksTheReplyNotDeliveredWithPostiliosReason()
    {
        $response = $this->deliver($this->event('bounced', [
            'reason'   => 'recipient_rejected',
            'response' => '550 5.1.1 The email account that you tried to reach does not exist',
        ]));

        $response->assertStatus(204);
        $thread = $this->thread->fresh();
        $this->assertSame(SendLog::STATUS_DELIVERY_ERROR, (int) $thread->send_status);
        $this->assertTrue($thread->isSendStatusError());
        $this->assertSame('550 5.1.1 The email account that you tried to reach does not exist', $thread->getSendStatusData()['msg']);
        $this->assertTrue(SendLog::where('thread_id', $thread->id)->where('status', SendLog::STATUS_DELIVERY_ERROR)
            ->where('email', 'ada@example.org')->exists());
    }

    public function testDelivered_IsLoggedAndLeavesAnEarlierBounceStanding()
    {
        $this->deliver($this->event('bounced', ['reason' => 'recipient_rejected']), self::SECRET, null, 'msg_1');

        $this->deliver($this->event('delivered'), self::SECRET, null, 'msg_2')->assertStatus(204);

        $this->assertSame(SendLog::STATUS_DELIVERY_ERROR, (int) $this->thread->fresh()->send_status);
        $this->assertTrue(SendLog::where('thread_id', $this->thread->id)->where('status', SendLog::STATUS_DELIVERY_SUCCESS)->exists());
    }

    public function testEvent_ForAnEmailFreeScoutDoesNotKnow_IsTakenAndIgnored()
    {
        $body = str_replace(self::EMAIL_ID, '01a1527c-0000-7b2a-9c51-3f0e2d6a4b10', $this->event('bounced'));

        $this->deliver($body)->assertStatus(204);

        $this->assertSame(SendLog::STATUS_ACCEPTED, (int) $this->thread->fresh()->send_status);
    }

    public function testEvent_ForAThreadOfAnotherMailbox_IsIgnored()
    {
        $other = factory(Mailbox::class)->create();
        $other->setMetaParam('postilio_send', ['enabled' => true, 'webhook_secret' => \Helper::encrypt(self::SECRET)], true);

        $this->deliver($this->event('bounced'), self::SECRET, $other->id)->assertStatus(204);

        $this->assertSame(SendLog::STATUS_ACCEPTED, (int) $this->thread->fresh()->send_status);
    }

    public function testRefusals()
    {
        $this->deliver($this->event('bounced'), 'whsec_'.base64_encode('another secret'))->assertStatus(401);
        $this->deliver($this->event('bounced'), self::SECRET, 999999)->assertStatus(404);
        $this->deliver('{not json')->assertStatus(400);
        $this->deliver(json_encode(['type' => 'email.bounced.v1', 'data' => ['emailId' => "x' OR 1=1"]]))->assertStatus(400);
        $this->assertSame(SendLog::STATUS_ACCEPTED, (int) $this->thread->fresh()->send_status);
    }

    public function testWebhook_OfAMailboxWithPostilioOff_IsNotFound()
    {
        $this->mailbox->setMetaParam('postilio_send', ['enabled' => false, 'webhook_secret' => \Helper::encrypt(self::SECRET)], true);

        $this->deliver($this->event('bounced'))->assertStatus(404);
    }
}
