<?php

namespace Modules\PostilioSend;

/**
 * Checks a Postilio webhook (the Standard Webhooks scheme): HMAC-SHA256 over "{id}.{timestamp}.{body}" with the
 * base64-decoded part of the secret after "whsec_", compared in constant time, and a timestamp at most five minutes off.
 */
class WebhookSignature
{
    const TOLERANCE_SECONDS = 300;

    public static function verify($secret, $id, $timestamp, $signature, $body, $now = null)
    {
        $now = $now === null ? time() : $now;
        if (!ctype_digit((string) $timestamp) || abs($now - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }
        $key = base64_decode(preg_replace('/^whsec_/', '', (string) $secret), true);
        if ($key === false || $key === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $id.'.'.$timestamp.'.'.$body, $key, true);

        // During a secret rotation the header holds two signatures, separated by a space.
        foreach (preg_split('/\s+/', trim((string) $signature)) as $part) {
            $pieces = explode(',', $part, 2);
            if (count($pieces) === 2 && $pieces[0] === 'v1') {
                $given = base64_decode($pieces[1], true);
                if ($given !== false && hash_equals($expected, $given)) {
                    return true;
                }
            }
        }

        return false;
    }
}
