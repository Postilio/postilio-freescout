<div data-in-protocol="{{ \Modules\PostilioInbound\Providers\PostilioInboundServiceProvider::IN_PROTOCOL }}">
    <div class="form-group">
        <div class="col-sm-6 col-sm-offset-2">
            <p class="form-help">
                {{ __('Postilio delivers the mail of this mailbox by webhook: there is nothing to fetch, and nothing to save on this page. The API key and the signing secret are on the Postilio inbound page, where you can also turn it off to fetch by IMAP or POP3 again.') }}
                <a href="{{ route('postilioinbound.settings', ['mailbox_id' => $mailbox->id]) }}">{{ __('Postilio inbound') }}</a>
            </p>
        </div>
    </div>
</div>
