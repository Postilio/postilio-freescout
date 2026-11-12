<?php

namespace Modules\PostilioInbound\Tests;

use App\Conversation;
use App\Mailbox;
use App\Thread;
use App\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Postilio's inbound webhook into a FreeScout mailbox. Needs the module active.
 */
class InboundWebhookTest extends TestCase
{
    use DatabaseTransactions;

    const SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';
    const INBOUND_ID = '01a1527c-8e14-7b2a-9c51-3f0e2d6a4b99';

    private $mailbox;
    private $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailbox = factory(Mailbox::class)->create(['email' => 'support@inbound.northwind.test']);
        $this->mailbox->setMetaParam('postilio_inbound', [
            'enabled'        => true,
            'api_key'        => \Helper::encrypt('pk_live_key'),
            'webhook_secret' => \Helper::encrypt(self::SECRET),
        ], true);
        // The mailbox's own users, as FreeScout needs them for its folders.
        factory(User::class)->create(['role' => User::ROLE_ADMIN]);
    }

    private function postilio(array $responses)
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        app()->instance('postilio.http', new Client(['handler' => $stack]));
    }

    private function raw($fixture)
    {
        return new Response(200, ['Content-Type' => 'message/rfc822'], file_get_contents(__DIR__.'/fixtures/'.$fixture));
    }

    private function event($inboundId = self::INBOUND_ID, $type = 'inbound.received.v1')
    {
        return json_encode(['type' => $type, 'timestamp' => '2026-10-21T13:52:50.102+00:00', 'data' => [
            'inboundId' => $inboundId,
            'from'      => ['name' => 'Marie Curie', 'address' => 'marie@example.org'],
            'subject'   => 'The parcel arrived damaged',
        ]]);
    }

    private function deliver($body, $secret = self::SECRET, $mailboxId = null)
    {
        $data = json_decode($body, true);
        $id = $data['data']['inboundId'] ?? 'msg_1';
        $timestamp = (string) time();
        $signature = 'v1,'.base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$body, base64_decode(substr($secret, 6)), true));

        return $this->call('POST', '/postilio-inbound/webhook/'.($mailboxId ?: $this->mailbox->id), [], [], [], [
            'HTTP_WEBHOOK_ID'        => $id,
            'HTTP_WEBHOOK_TIMESTAMP' => $timestamp,
            'HTTP_WEBHOOK_SIGNATURE' => $signature,
            'CONTENT_TYPE'           => 'application/json',
        ], $body);
    }

    private function conversations()
    {
        return Conversation::where('mailbox_id', $this->mailbox->id)->get();
    }

    public function testNewMail_BecomesAConversationAsIfFetchedByImap()
    {
        $this->postilio([$this->raw('new.eml')]);

        $this->deliver($this->event())->assertStatus(204);

        $request = $this->history[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('https://api.postilio.eu/v1/inbound/messages/'.self::INBOUND_ID.'/raw', (string) $request->getUri());
        $this->assertSame('Bearer pk_live_key', $request->getHeaderLine('Authorization'));
        $conversations = $this->conversations();
        $this->assertCount(1, $conversations);
        $conversation = $conversations[0];
        $this->assertSame('The parcel arrived damaged', $conversation->subject);
        $this->assertSame('marie@example.org', $conversation->customer_email);
        $thread = $conversation->threads()->first();
        $this->assertSame('CAF4x9-new@mail.example.org', $thread->message_id);
        $this->assertStringContainsString('the parcel arrived damaged', $thread->body);
        $this->assertSame(['photo.jpg'], $thread->attachments->pluck('file_name')->all());
    }

    public function testReply_ToAReplySentThroughPostilio_LandsInThatConversation()
    {
        $user = User::first();
        $conversation = factory(Conversation::class)->create(['mailbox_id' => $this->mailbox->id, 'created_by_user_id' => $user->id]);
        // The Message-ID Postilio gave our reply, as PostilioSend keeps it on the thread.
        factory(Thread::class)->create([
            'conversation_id'    => $conversation->id,
            'type'               => Thread::TYPE_MESSAGE,
            'created_by_user_id' => $user->id,
            'message_id'         => '01a1527c8e147b2a9c513f0e2d6a4b10@inbound.northwind.test',
        ]);
        $this->postilio([$this->raw('reply.eml')]);

        $this->deliver($this->event())->assertStatus(204);

        $this->assertCount(1, $this->conversations());
        $this->assertTrue($conversation->threads()->where('message_id', 'CAF4x9-reply@mail.example.org')->exists());
    }

    public function testRepeat_OfADeliveredMessage_IsTakenWithoutASecondConversation()
    {
        $this->postilio([$this->raw('new.eml')]);
        $this->deliver($this->event())->assertStatus(204);

        $this->deliver($this->event())->assertStatus(204);

        $this->assertCount(1, $this->conversations());
        $this->assertCount(1, $this->history);
    }

    public function testPostilioUnreachable_AnswersRetryLater_AndTheRetryImportsItOnce()
    {
        $this->postilio([
            new \GuzzleHttp\Exception\ConnectException('timed out', new \GuzzleHttp\Psr7\Request('GET', 'x')),
            new Response(500, [], '{"error":"internal_error"}'),
            $this->raw('new.eml'),
        ]);

        $this->deliver($this->event())->assertStatus(503);
        $this->deliver($this->event())->assertStatus(503);
        $this->assertCount(0, $this->conversations());

        $this->deliver($this->event())->assertStatus(204);
        $this->deliver($this->event())->assertStatus(204);

        $this->assertCount(1, $this->conversations());
    }

    public function testKeyRefused_AnswersRetryLater_AndTheFetchErrorNamesPostiliosReason()
    {
        $this->postilio([new Response(403, ['Content-Type' => 'application/json'], '{"error":"insufficient_scope"}')]);

        $this->deliver($this->event())->assertStatus(503);

        $error = \App\ActivityLog::where('log_name', \App\ActivityLog::NAME_EMAILS_FETCHING)->orderBy('id', 'desc')->first()->properties['error'];
        $this->assertStringContainsString('403 insufficient_scope', $error);
    }

    public function testContentGone_IsTakenAndNeverAskedForAgain()
    {
        $this->postilio([new Response(410, [], '{"error":"inbound_content_deleted"}')]);

        $this->deliver($this->event())->assertStatus(204);
        $this->deliver($this->event())->assertStatus(204);

        $this->assertCount(0, $this->conversations());
        $this->assertCount(1, $this->history);
    }

    public function testDelivery_WhileTheSameMessageIsStillBeingImported_AnswersRetryLater()
    {
        \DB::table('postilio_inbound_messages')->insert([
            'inbound_id' => self::INBOUND_ID, 'mailbox_id' => $this->mailbox->id, 'status' => 'processing',
            'created_at' => now()->subMinute(), 'updated_at' => now()->subMinute(),
        ]);
        $this->postilio([$this->raw('new.eml')]);

        $this->deliver($this->event())->assertStatus(503);

        $this->assertCount(0, $this->history);
    }

    public function testDelivery_OfAnImportThatDiedHalfway_ImportsItAgain()
    {
        \DB::table('postilio_inbound_messages')->insert([
            'inbound_id' => self::INBOUND_ID, 'mailbox_id' => $this->mailbox->id, 'status' => 'processing',
            'created_at' => now()->subMinutes(10), 'updated_at' => now()->subMinutes(10),
        ]);
        $this->postilio([$this->raw('new.eml')]);

        $this->deliver($this->event())->assertStatus(204);

        $this->assertCount(1, $this->conversations());
    }

    public function testMessage_FreeScoutCannotImport_AnswersAnErrorSoPostilioKeepsIt()
    {
        $this->postilio([new Response(200, [], "Subject: no sender\r\n\r\nbody\r\n"), $this->raw('new.eml')]);

        $this->deliver($this->event())->assertStatus(500);

        $this->assertCount(0, $this->conversations());
        // Released: a redelivery is tried again.
        $this->deliver($this->event())->assertStatus(204);
    }

    public function testTestEvent_IsTakenAndIgnored()
    {
        $this->postilio([]);

        $this->deliver(json_encode(['type' => 'webhook.test.v1', 'data' => ['endpointId' => 'e', 'projectId' => 'p', 'test' => true]]))->assertStatus(204);

        $this->assertCount(0, $this->history);
    }

    public function testRefusals()
    {
        $this->postilio([]);

        $this->deliver($this->event(), 'whsec_'.base64_encode('another secret'))->assertStatus(401);
        $this->deliver($this->event(), self::SECRET, 999999)->assertStatus(404);
        $this->deliver('{not json')->assertStatus(400);
        $this->deliver($this->event('../../v1/emails'))->assertStatus(400);

        $this->assertCount(0, $this->history);
        $this->assertCount(0, $this->conversations());
    }

    public function testWebhook_OfAMailboxWithPostilioOff_IsNotFound()
    {
        $this->mailbox->setMetaParam('postilio_inbound', ['enabled' => false, 'webhook_secret' => \Helper::encrypt(self::SECRET)], true);

        $this->deliver($this->event())->assertStatus(404);
    }
}
