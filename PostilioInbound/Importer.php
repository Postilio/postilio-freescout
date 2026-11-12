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

    public function __construct()
    {
        parent::__construct();
        $this->output = new \Illuminate\Console\OutputStyle(new ArrayInput([]), new NullOutput());
    }

    /**
     * @return bool whether FreeScout took the message (a message it had already counts as taken)
     */
    public function import($raw, Mailbox $mailbox)
    {
        $this->errors = [];
        $this->extra_import = [];
        $this->mailbox = $mailbox;
        $this->mailboxes = Mailbox::get();

        // Loads FreeScout's IMAP options (attachment dispositions, decoding) into the parser, as connecting would.
        new \Webklex\PHPIMAP\ClientManager(config('imap'));
        $message = Message::fromString($raw);
        $this->processMessage($message, (string) $message->getMessageId(), $mailbox, $this->mailboxes);
        // Mail to several mailboxes at once, as fetch-emails does after its loop.
        foreach ($this->extra_import as $extra) {
            $this->processMessage($extra['message'], $extra['message_id'], $extra['mailbox'], [], true);
        }
        // fetch-emails does this too: commands (and this route) do not run the middleware that sends notifications.
        \App\Subscription::processEvents();

        return !$this->errors;
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
