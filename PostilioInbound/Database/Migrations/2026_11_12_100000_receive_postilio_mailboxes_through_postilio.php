<?php

use App\Mailbox;
use Illuminate\Database\Migrations\Migration;
use Modules\PostilioInbound\Providers\PostilioInboundServiceProvider as Provider;

/**
 * 0.1.1: a mailbox turned on in 0.1.0 still had its IMAP protocol, so FreeScout showed it as not connected. It moves to
 * the Postilio protocol, as saving its Postilio inbound page now does.
 */
class ReceivePostilioMailboxesThroughPostilio extends Migration
{
    public function up()
    {
        foreach (Mailbox::all() as $mailbox) {
            if (Provider::receives($mailbox) && (int) $mailbox->in_protocol !== Provider::IN_PROTOCOL) {
                $settings = $mailbox->getMeta(Provider::META, []);
                $settings['in_protocol_before'] = (int) $mailbox->in_protocol;
                $mailbox->in_protocol = Provider::IN_PROTOCOL;
                $mailbox->setMetaParam(Provider::META, $settings, true);
            }
        }
    }

    public function down()
    {
        foreach (Mailbox::where('in_protocol', Provider::IN_PROTOCOL)->get() as $mailbox) {
            $mailbox->in_protocol = $mailbox->getMeta(Provider::META, [])['in_protocol_before'] ?? Mailbox::IN_PROTOCOL_IMAP;
            $mailbox->save();
        }
    }
}
