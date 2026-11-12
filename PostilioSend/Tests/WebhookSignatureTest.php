<?php

namespace Modules\PostilioSend\Tests;

use Modules\PostilioSend\WebhookSignature;
use PHPUnit\Framework\TestCase;

/**
 * The Standard Webhooks test vector, the same one Postilio's own tests use.
 */
class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';
    private const ID = 'msg_p5jXN8AQM9LWM0D4loKWxJek';
    private const TS = '1614265330';
    private const BODY = '{"test": 2432232314}';
    private const SIG = 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=';
    private const OTHER = 'v1,Ceo8E9x1W7KLD9P2T1UVKF8ksGNWv0wlFfJ0CRsg4CQ=';

    public static function cases(): array
    {
        $now = (int) self::TS;

        return [
            'valid'                         => [true, self::SECRET, self::ID, self::TS, self::SIG, self::BODY, $now],
            'one of two during rotation'    => [true, self::SECRET, self::ID, self::TS, self::OTHER.' '.self::SIG, self::BODY, $now],
            'just inside five minutes'      => [true, self::SECRET, self::ID, self::TS, self::SIG, self::BODY, $now + 300],
            'body changed'                  => [false, self::SECRET, self::ID, self::TS, self::SIG, '{"test": 2432232315}', $now],
            'id changed'                    => [false, self::SECRET, 'msg_other', self::TS, self::SIG, self::BODY, $now],
            'other secret'                  => [false, 'whsec_'.base64_encode('another secret'), self::ID, self::TS, self::SIG, self::BODY, $now],
            'only the other signature'      => [false, self::SECRET, self::ID, self::TS, self::OTHER, self::BODY, $now],
            'too old'                       => [false, self::SECRET, self::ID, self::TS, self::SIG, self::BODY, $now + 301],
            'too far ahead'                 => [false, self::SECRET, self::ID, self::TS, self::SIG, self::BODY, $now - 301],
            'timestamp not a number'        => [false, self::SECRET, self::ID, '1614265330x', self::SIG, self::BODY, $now],
            'wrong version'                 => [false, self::SECRET, self::ID, self::TS, 'v2,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=', self::BODY, $now],
            'no signature'                  => [false, self::SECRET, self::ID, self::TS, '', self::BODY, $now],
            'empty secret'                  => [false, '', self::ID, self::TS, self::SIG, self::BODY, $now],
        ];
    }

    /**
     * @dataProvider cases
     */
    public function testVerify(bool $expected, string $secret, string $id, string $timestamp, string $signature, string $body, int $now)
    {
        $this->assertSame($expected, WebhookSignature::verify($secret, $id, $timestamp, $signature, $body, $now));
    }
}
