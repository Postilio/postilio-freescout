<?php

namespace Modules\PostilioInbound;

use App\Console\Commands\FetchEmails;
use App\Mailbox;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Webklex\PHPIMAP\Message;

/**
 * Imports one raw message the way FreeScout's own IMAP fetching does (fetch-emails' processMessage): customer,
 * conversation or reply by Message-ID / In-Reply-To / References, attachments, bounces and repeats.
 */
class Importer extends FetchEmails
{
    /** @var string[] the errors processMessage() reported */
    public $errors = [];

    // Where processMessage() refuses a message for good: fetching by IMAP marks it seen then, so it is never tried again.
    const REFUSALS = [
        'Invalid hash in the Message-ID',
        'User not found',
        'User is not active',
        'Skipping an auto-reply',
        'Sender address ',
        "Support agent's reply",
        'Support agent (ID',
    ];

    public function __construct()
    {
        parent::__construct();
        $this->output = new \Illuminate\Console\OutputStyle(new ArrayInput([]), new NullOutput());
    }

    /**
     * @param array $envelope the event's data.envelope (mailFrom, recipients, helo), for a message without a From
     *
     * @return bool whether FreeScout took the message (a message it had already counts as taken)
     */
    public function import($raw, Mailbox $mailbox, array $envelope = [])
    {
        $this->errors = [];
        $this->extra_import = [];
        $this->mailbox = $mailbox;
        $this->mailboxes = Mailbox::get();

        // Loads FreeScout's IMAP options (attachment dispositions, decoding) into the parser, as connecting would.
        new \Webklex\PHPIMAP\ClientManager(config('imap'));
        $message = Message::fromString($raw);
        if (!$this->hasSender($message)) {
            $raw = $this->withSender($raw, $envelope, $mailbox);
            $message = Message::fromString($raw);
        }
        // Reading a From without an address leaves errors in the imap extension, which PHP reports when it shuts down.
        if (function_exists('imap_errors')) {
            imap_errors();
        }
        $this->processMessage($message, (string) $message->getMessageId(), $mailbox, $this->mailboxes);
        // Mail to several mailboxes at once, as fetch-emails does after its loop.
        foreach ($this->extra_import as $extra) {
            $this->processMessage($extra['message'], $extra['message_id'], $extra['mailbox'], [], true);
        }
        // fetch-emails does this too: commands (and this route) do not run the middleware that sends notifications.
        \App\Subscription::processEvents();

        return !$this->errors;
    }

    /**
     * @return bool whether every error is one where FreeScout refused the message for good
     */
    public function refused()
    {
        foreach ($this->errors as $error) {
            $known = false;
            foreach (self::REFUSALS as $refusal) {
                $known = $known || strpos($error, $refusal) === 0;
            }
            if (!$known) {
                return false;
            }
        }

        return (bool) $this->errors;
    }

    // FreeScout refuses mail without a From. "x@unknown" is what the parser makes of a From without an address.
    private function hasSender(Message $message)
    {
        $from = $this->formatEmailList($message->getFrom());

        return !empty($from[0]) && !preg_match('/@unknown$/', $from[0]);
    }

    /**
     * Gives a message without a usable From one (a bounce or a DSN often has none): the envelope sender, else the
     * Return-Path, else mailer-daemon at the sending host. A From that was there is kept as X-Postilio-Original-From.
     */
    private function withSender($raw, array $envelope, Mailbox $mailbox)
    {
        $eol = strpos($raw, "\r\n") !== false ? "\r\n" : "\n";
        $parts = explode($eol.$eol, $raw, 2);
        $head = preg_replace('/^From:/mi', 'X-Postilio-Original-From:', $parts[0]);
        preg_match('/^Return-Path:\s*<([^>]*)>/mi', $head, $returnPath);

        $name = '';
        $address = '';
        foreach ([$envelope['mailFrom'] ?? '', $returnPath[1] ?? ''] as $candidate) {
            if (!$address && is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                $address = $candidate;
            }
        }
        if (!$address) {
            $helo = is_string($envelope['helo'] ?? null) ? $envelope['helo'] : '';
            $recipient = is_string($envelope['recipients'][0] ?? null) ? $envelope['recipients'][0] : $mailbox->email;
            $host = preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/i', $helo) ? $helo : substr(strrchr($recipient, '@'), 1);
            $name = '"Unknown sender" ';
            $address = 'mailer-daemon@'.$host;
        }

        return 'From: '.$name.'<'.$address.'>'.$eol.'X-Postilio-From-Synthesized: 1'.$eol.$head.(isset($parts[1]) ? $eol.$eol.$parts[1] : '');
    }

    // There is no IMAP server to flag the message on.
    public function setSeen($message, $mailbox)
    {
    }

    public function logError($message)
    {
        $this->errors[] = $message;
        parent::logError($message);
    }
}
