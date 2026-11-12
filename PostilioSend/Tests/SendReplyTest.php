<?php

namespace Modules\PostilioSend\Tests;

use App\Conversation;
use App\Customer;
use App\Jobs\SendReplyToCustomer;
use App\Mailbox;
use App\Thread;
use App\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\PostilioSend\PostilioTransport;
use Tests\TestCase;

/**
 * A reply sent by FreeScout's own job from a mailbox that sends through Postilio. Needs the module active.
 */
class SendReplyTest extends TestCase
{
    use DatabaseTransactions;

    private $history = [];

    private function postilio(array $responses)
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        app()->instance('postilio.http', new Client(['handler' => $stack]));
    }

    private function reply(Mailbox $mailbox)
    {
        $user = factory(User::class)->create(['role' => User::ROLE_ADMIN]);
        $customer = Customer::create('ada@example.org', ['first_name' => 'Ada']);
        $conversation = factory(Conversation::class)->create([
            'mailbox_id'         => $mailbox->id,
            'created_by_user_id' => $user->id,
            'customer_id'        => $customer->id,
            'customer_email'     => 'ada@example.org',
            'subject'            => 'Order 4821',
        ]);
        $thread = factory(Thread::class)->create([
            'conversation_id'    => $conversation->id,
            'type'               => Thread::TYPE_MESSAGE,
            'customer_id'        => $customer->id,
            'created_by_user_id' => $user->id,
            'source_via'         => Thread::PERSON_USER,
            'to'                 => json_encode(['ada@example.org']),
            'cc'                 => json_encode([]),
            'bcc'                => json_encode([]),
            'body'               => '<p>Your parcel is on its way.</p>',
        ]);

        return [$conversation, $thread, $customer];
    }

    private function postilioMailbox()
    {
        $mailbox = factory(Mailbox::class)->create(['email' => 'support@support.example.com']);
        $mailbox->setMetaParam('postilio_send', ['enabled' => true, 'api_key' => \Helper::encrypt('pk_live_key')], true);

        return $mailbox;
    }

    public function testReply_FromAPostilioMailbox_GoesThroughTheApiAndKeepsPostiliosMessageId()
    {
        $this->postilio([new Response(202, [], '{"ids":["01a1527c-8e14-7b2a-9c51-3f0e2d6a4b10"],"suppressed":[]}')]);
        list($conversation, $thread, $customer) = $this->reply($this->postilioMailbox());

        (new SendReplyToCustomer($conversation, collect([$thread]), $customer))->handle();

        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $this->assertSame('Bearer pk_live_key', $request->getHeaderLine('Authorization'));
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame(['ada@example.org'], $body['to']);
        $this->assertStringContainsString('Your parcel is on its way.', $body['html']);
        // The customer's reply will name Postilio's Message-ID, so the thread carries it for FreeScout's threading.
        $this->assertSame('01a1527c8e147b2a9c513f0e2d6a4b10@support.example.com', $thread->fresh()->message_id);
    }

    public function testSystemMail_AfterAPostilioReply_DoesNotUseThePostilioTransport()
    {
        $this->postilio([]);
        \MailHelper::setMailDriver($this->postilioMailbox());
        $this->assertInstanceOf(PostilioTransport::class, \Mail::getSwiftMailer()->getTransport());

        \MailHelper::setMailDriver(null);

        $this->assertNotInstanceOf(PostilioTransport::class, \Mail::getSwiftMailer()->getTransport());
    }

    public function testSystemMail_AfterTheSameMailboxWasSetTwice_DoesNotUseThePostilioTransport()
    {
        $this->postilio([]);
        $mailbox = $this->postilioMailbox();
        \MailHelper::setMailDriver($mailbox);
        // Same settings again: FreeScout keeps its mailer and does not rebuild it.
        \MailHelper::setMailDriver($mailbox);

        \MailHelper::setMailDriver(null);

        $this->assertNotInstanceOf(PostilioTransport::class, \Mail::getSwiftMailer()->getTransport());
    }

    public function testMailbox_WithPostilioOff_DoesNotUseThePostilioTransport()
    {
        $this->postilio([]);
        $mailbox = $this->postilioMailbox();
        $mailbox->setMetaParam('postilio_send', ['enabled' => false, 'api_key' => \Helper::encrypt('pk_live_key')], true);

        \MailHelper::setMailDriver($mailbox);

        $this->assertNotInstanceOf(PostilioTransport::class, \Mail::getSwiftMailer()->getTransport());
    }
}
