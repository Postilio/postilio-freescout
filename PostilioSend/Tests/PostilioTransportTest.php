<?php

namespace Modules\PostilioSend\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Modules\PostilioSend\PostilioTransport;
use PHPUnit\Framework\TestCase;

class PostilioTransportTest extends TestCase
{
    private $history = [];

    private function transport(array $responses, &$sent = null)
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $sent = [];

        return new PostilioTransport(new Client(['handler' => $stack]), 'https://api.example.test', 'pk_live_key',
            function ($message, array $ids) use (&$sent) {
                $sent[] = [$message->getId(), $ids];
            });
    }

    /** A reply as FreeScout builds it: html with a text alternative, an attachment, an inline image, thread headers. */
    private function reply()
    {
        $message = new \Swift_Message('Re: Order 4821');
        $message->setId('fs-reply-17-0123456789abcdef@support.example.com');
        $message->setFrom(['support@support.example.com' => 'Acme, Support']);
        $message->setTo(['ada@example.org' => 'Ada Lovelace']);
        $message->setCc(['bob@example.org']);
        $message->setBcc(['archive@example.com']);
        $message->setReplyTo(['help@support.example.com' => 'Help']);
        $cid = $message->embed(new \Swift_Image('PNGDATA', 'logo.png', 'image/png'));
        $message->setBody('<p>Hello <img src="'.$cid.'"></p>', 'text/html');
        $message->addPart('Hello', 'text/plain');
        $message->attach(new \Swift_Attachment('%PDF-1.4', 'invoice.pdf', 'application/pdf'));
        $headers = $message->getHeaders();
        $headers->addIdHeader('In-Reply-To', 'CAF4x9@mail.gmail.com');
        $headers->addIdHeader('References', ['first@mail.gmail.com', 'CAF4x9@mail.gmail.com']);

        return [$message, substr($cid, 4)];
    }

    public function testSend_Reply_PostsTheMessageAsJson()
    {
        list($message, $contentId) = $this->reply();
        $transport = $this->transport([new Response(202, [], '{"ids":["01a1527c-8e14-7b2a-9c51-3f0e2d6a4b10","x","y"],"suppressed":[]}')]);

        $count = $transport->send($message);

        $this->assertSame(3, $count);
        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.example.test/v1/emails', (string) $request->getUri());
        $this->assertSame('Bearer pk_live_key', $request->getHeaderLine('Authorization'));
        // The Message-ID plus a hash of the body: a retry is never sent twice, an edited reply is not refused as a reuse.
        $this->assertSame('fs-reply-17-0123456789abcdef@support.example.com-'.substr(hash('sha256', (string) $request->getBody()), 0, 16), $request->getHeaderLine('Idempotency-Key'));
        $this->assertEquals([
            'from'        => 'Acme Support <support@support.example.com>',
            'to'          => ['ada@example.org'],
            'cc'          => ['bob@example.org'],
            'bcc'         => ['archive@example.com'],
            'replyTo'     => 'help@support.example.com',
            'subject'     => 'Re: Order 4821',
            'html'        => '<p>Hello <img src="cid:'.$contentId.'"></p>',
            'text'        => 'Hello',
            'headers'     => [
                'In-Reply-To' => '<CAF4x9@mail.gmail.com>',
                'References'  => '<first@mail.gmail.com> <CAF4x9@mail.gmail.com>',
            ],
            'attachments' => [
                ['fileName' => 'logo.png', 'contentType' => 'image/png', 'content' => base64_encode('PNGDATA'), 'contentId' => $contentId],
                ['fileName' => 'invoice.pdf', 'contentType' => 'application/pdf', 'content' => base64_encode('%PDF-1.4')],
            ],
        ], json_decode((string) $request->getBody(), true));
    }

    public function testSend_Accepted_ReportsTheIdsOfTheMessage()
    {
        list($message) = $this->reply();
        $transport = $this->transport([new Response(202, [], '{"ids":["01a1527c-8e14-7b2a-9c51-3f0e2d6a4b10"],"suppressed":[]}')], $sent);

        $transport->send($message);

        $this->assertSame([['fs-reply-17-0123456789abcdef@support.example.com', ['01a1527c-8e14-7b2a-9c51-3f0e2d6a4b10']]], $sent);
    }

    public function testSend_LongReferences_KeepsTheNewestIdsWithin900Characters()
    {
        $message = new \Swift_Message('Re: long thread', 'text');
        $message->setFrom('support@support.example.com');
        $message->setTo('ada@example.org');
        $ids = [];
        for ($i = 0; $i < 40; $i++) {
            $ids[] = sprintf('message-%02d-0123456789@mail.example.org', $i);
        }
        $message->getHeaders()->addIdHeader('References', $ids);
        $transport = $this->transport([new Response(202, [], '{"ids":["a"]}')]);

        $transport->send($message);

        $references = json_decode((string) $this->history[0]['request']->getBody(), true)['headers']['References'];
        $this->assertLessThanOrEqual(900, strlen($references));
        $this->assertStringEndsWith('<message-39-0123456789@mail.example.org>', $references);
        $this->assertStringStartsWith('<message-', $references);
        $this->assertStringNotContainsString('<message-00-', $references);
    }

    public function testSend_Refused_ThrowsWithTheApisErrorSoFreeScoutMarksItNotSent()
    {
        list($message) = $this->reply();
        $transport = $this->transport([new Response(422, [], '{"error":"unverified_sender_domain","message":"The domain of from is not verified."}')], $sent);

        try {
            $transport->send($message);
            $this->fail('No exception');
        } catch (\Swift_TransportException $e) {
            $this->assertSame('Postilio refused the message (422 unverified_sender_domain): The domain of from is not verified.', $e->getMessage());
        }
        $this->assertSame([], $sent);
    }

    public function testSend_Unreachable_Throws()
    {
        list($message) = $this->reply();
        $transport = $this->transport([new \GuzzleHttp\Exception\ConnectException('timed out', new \GuzzleHttp\Psr7\Request('POST', 'x'))]);

        $this->expectException(\Swift_TransportException::class);
        $this->expectExceptionMessage('Postilio could not be reached: timed out');

        $transport->send($message);
    }
}
