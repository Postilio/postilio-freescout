<?php

namespace Modules\PostilioSend;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * A SwiftMailer transport that sends through the Postilio API (POST /v1/emails) instead of SMTP.
 * Any refusal or network failure throws, so FreeScout marks the reply as not sent and retries it as with SMTP.
 */
class PostilioTransport implements \Swift_Transport
{
    // Postilio's limit for one header value.
    const MAX_HEADER = 900;

    private $http;
    private $apiUrl;
    private $apiKey;
    private $onSent;

    /**
     * @param callable|null $onSent called with the message and the ids Postilio gave it, after it was accepted
     */
    public function __construct(ClientInterface $http, $apiUrl, $apiKey, callable $onSent = null)
    {
        $this->http = $http;
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->apiKey = $apiKey;
        $this->onSent = $onSent;
    }

    public function isStarted()
    {
        return true;
    }

    public function start()
    {
    }

    public function stop()
    {
    }

    public function ping()
    {
        return true;
    }

    public function registerPlugin(\Swift_Events_EventListener $plugin)
    {
    }

    public function send(\Swift_Mime_SimpleMessage $message, &$failedRecipients = null)
    {
        $body = json_encode(self::payload($message), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        try {
            $response = $this->http->request('POST', $this->apiUrl.'/v1/emails', [
                'body'        => $body,
                'headers'     => [
                    'Authorization'   => 'Bearer '.$this->apiKey,
                    'Content-Type'    => 'application/json',
                    'Accept'          => 'application/json',
                    'Idempotency-Key' => substr($message->getId(), 0, 230).'-'.substr(hash('sha256', $body), 0, 16),
                ],
                'http_errors' => false,
                'timeout'     => 30,
            ]);
        } catch (GuzzleException $e) {
            throw new \Swift_TransportException('Postilio could not be reached: '.$e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $answer = json_decode((string) $response->getBody(), true);
        if ($status < 200 || $status >= 300) {
            throw new \Swift_TransportException(sprintf('Postilio refused the message (%d %s): %s',
                $status, $answer['error'] ?? 'error', $answer['message'] ?? substr((string) $response->getBody(), 0, 300)));
        }

        $ids = isset($answer['ids']) && is_array($answer['ids']) ? $answer['ids'] : [];
        if ($this->onSent) {
            call_user_func($this->onSent, $message, $ids);
        }

        return count($ids);
    }

    /**
     * The JSON body of POST /v1/emails for a SwiftMailer message.
     */
    public static function payload(\Swift_Mime_SimpleMessage $message)
    {
        $from = $message->getFrom() ?: [];
        $payload = [
            'from'    => self::mailbox(key($from), current($from)),
            'to'      => array_keys($message->getTo() ?: []),
            'subject' => (string) $message->getSubject(),
        ];
        if ($message->getCc()) {
            $payload['cc'] = array_keys($message->getCc());
        }
        if ($message->getBcc()) {
            $payload['bcc'] = array_keys($message->getBcc());
        }
        if ($message->getReplyTo()) {
            $payload['replyTo'] = (string) key($message->getReplyTo());
        }

        $parts = array_merge([$message], $message->getChildren());
        $attachments = [];
        foreach ($parts as $part) {
            if ($part instanceof \Swift_Mime_Attachment) {
                $attachment = [
                    'fileName'    => $part->getFilename(),
                    'contentType' => $part->getContentType(),
                    'content'     => base64_encode($part->getBody()),
                ];
                if ($part instanceof \Swift_Mime_EmbeddedFile) {
                    $attachment['contentId'] = $part->getId();
                }
                $attachments[] = $attachment;
            } elseif ($part->getBodyContentType() === 'text/html' && !isset($payload['html'])) {
                $payload['html'] = $part->getBody();
            } elseif ($part->getBodyContentType() === 'text/plain' && !isset($payload['text'])) {
                $payload['text'] = $part->getBody();
            }
        }

        $headers = [];
        foreach (['In-Reply-To', 'References'] as $name) {
            $header = $message->getHeaders()->get($name);
            if ($header && ($value = self::newestIds($header->getFieldBody())) !== '') {
                $headers[$name] = $value;
            }
        }
        if ($headers) {
            $payload['headers'] = $headers;
        }
        if ($attachments) {
            $payload['attachments'] = $attachments;
        }

        return $payload;
    }

    // Postilio refuses "@" and "," in a sender name.
    private static function mailbox($address, $name)
    {
        $name = trim(preg_replace('/\s+/', ' ', str_replace(['@', ',', '"', '<', '>'], ' ', (string) $name)));

        return $name === '' ? (string) $address : $name.' <'.$address.'>';
    }

    // Message ids that fit in one header value, the newest (last) ones first to stay.
    private static function newestIds($value)
    {
        $kept = '';
        foreach (array_reverse(preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY)) as $id) {
            $next = $kept === '' ? $id : $id.' '.$kept;
            if (strlen($next) > self::MAX_HEADER) {
                break;
            }
            $kept = $next;
        }

        return $kept;
    }
}
