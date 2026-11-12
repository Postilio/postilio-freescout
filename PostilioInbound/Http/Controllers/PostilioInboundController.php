<?php

namespace Modules\PostilioInbound\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mailbox;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Modules\PostilioInbound\Importer;
use Modules\PostilioInbound\Providers\PostilioInboundServiceProvider as Provider;
use Modules\PostilioInbound\WebhookSignature;

class PostilioInboundController extends Controller
{
    const TABLE = 'postilio_inbound_messages';

    // A claim older than this belongs to an import that died; the next delivery takes it over.
    const STALE_MINUTES = 5;

    const MASK = '********';

    public function settings($mailbox_id)
    {
        $mailbox = Mailbox::findOrFail($mailbox_id);
        $this->authorize('admin', $mailbox);
        $settings = $mailbox->getMeta(Provider::META, []);

        return view('postilioinbound::settings', [
            'mailbox'    => $mailbox,
            'enabled'    => !empty($settings['enabled']),
            'has_key'    => !empty($settings['api_key']),
            'has_secret' => !empty($settings['webhook_secret']),
            'mask'       => self::MASK,
        ]);
    }

    public function saveSettings($mailbox_id, Request $request)
    {
        $mailbox = Mailbox::findOrFail($mailbox_id);
        $this->authorize('admin', $mailbox);
        $settings = $mailbox->getMeta(Provider::META, []);

        // The page shows a mask for a stored value; sending the mask back keeps it.
        $input = [];
        foreach (['api_key', 'webhook_secret'] as $field) {
            $value = trim((string) $request->input($field));
            $input[$field] = $value === self::MASK ? null : $value;
        }
        $enabled = (bool) $request->input('enabled');
        $validator = \Validator::make($input, [
            'api_key'        => ['nullable', 'regex:/^pk_(live|test)_[A-Za-z0-9]+$/'],
            'webhook_secret' => ['nullable', 'regex:/^whsec_[A-Za-z0-9+\/=]+$/'],
        ]);
        $validator->after(function ($validator) use ($enabled, $input, $settings) {
            if ($enabled && !$input['api_key'] && empty($settings['api_key'])) {
                $validator->errors()->add('api_key', __('An API key is needed to fetch the messages from Postilio.'));
            }
            if ($enabled && !$input['webhook_secret'] && empty($settings['webhook_secret'])) {
                $validator->errors()->add('webhook_secret', __('The signing secret is needed to accept Postilio\'s webhook.'));
            }
        });
        if ($validator->fails()) {
            return redirect()->route('postilioinbound.settings', ['mailbox_id' => $mailbox->id])->withErrors($validator);
        }

        $settings['enabled'] = $enabled;
        foreach ($input as $field => $value) {
            if ($value) {
                $settings[$field] = \Helper::encrypt($value);
            }
        }
        // Receiving through Postilio replaces fetching; turned off, the mailbox goes back to the protocol it had.
        if ($enabled && (int) $mailbox->in_protocol !== Provider::IN_PROTOCOL) {
            $settings['in_protocol_before'] = (int) $mailbox->in_protocol;
            $mailbox->in_protocol = Provider::IN_PROTOCOL;
        } elseif (!$enabled && (int) $mailbox->in_protocol === Provider::IN_PROTOCOL) {
            $mailbox->in_protocol = $settings['in_protocol_before'] ?? Mailbox::IN_PROTOCOL_IMAP;
        }
        $mailbox->setMetaParam(Provider::META, $settings, true);
        \Session::flash('flash_success_floating', __('Settings saved'));

        return redirect()->route('postilioinbound.settings', ['mailbox_id' => $mailbox->id]);
    }

    /**
     * Postilio's inbound.received.v1. 2xx only once the message is in FreeScout (or will never be): any other answer
     * makes Postilio deliver it again, for up to three days, and it keeps the content until then.
     */
    public function webhook($mailbox_id, Request $request)
    {
        $mailbox = Mailbox::find((int) $mailbox_id);
        $settings = $mailbox ? $mailbox->getMeta(Provider::META, []) : [];
        $secret = !empty($settings['enabled']) && !empty($settings['webhook_secret']) ? \Helper::decrypt($settings['webhook_secret']) : '';
        $apiKey = !empty($settings['api_key']) ? \Helper::decrypt($settings['api_key']) : '';
        if (!$secret || !$apiKey) {
            return response('', 404);
        }
        $body = $request->getContent();
        if (!WebhookSignature::verify($secret, (string) $request->header('webhook-id'), (string) $request->header('webhook-timestamp'),
            (string) $request->header('webhook-signature'), $body)
        ) {
            return response('', 401);
        }
        $event = json_decode($body, true);
        if (!is_array($event) || !is_string($event['type'] ?? null)) {
            return response('', 400);
        }
        if ($event['type'] !== 'inbound.received.v1') {
            return response('', 204);
        }
        $id = $event['data']['inboundId'] ?? null;
        if (!is_string($id) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
            return response('', 400);
        }
        $id = strtolower($id);

        $claim = $this->claim($id, $mailbox);
        if ($claim !== true) {
            return $claim;
        }

        try {
            $response = app('postilio.http')->request('GET', rtrim(config('postilioinbound.api_url'), '/').'/v1/inbound/messages/'.$id.'/raw', [
                'headers'     => ['Authorization' => 'Bearer '.$apiKey, 'Accept' => 'message/rfc822'],
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            return $this->release($id, 503, 'Postilio could not be reached: '.$e->getMessage());
        }
        if ($response->getStatusCode() === 410) {
            // The content is gone for good: asking again never helps.
            \DB::table(self::TABLE)->where('inbound_id', $id)->update(['status' => 'gone', 'updated_at' => now()]);
            $this->log($mailbox, 'Postilio deleted the content of inbound message '.$id.' before it was imported');

            return response('', 204);
        }
        if ($response->getStatusCode() !== 200) {
            return $this->release($id, 503, 'Postilio answered '.$response->getStatusCode().' for the raw message '.$id);
        }

        $importer = new Importer();
        if (!$importer->import((string) $response->getBody(), $mailbox)) {
            return $this->release($id, 500, 'Inbound message '.$id.' was not imported: '.implode('; ', $importer->errors));
        }
        \DB::table(self::TABLE)->where('inbound_id', $id)->update(['status' => 'done', 'updated_at' => now()]);

        return response('', 204);
    }

    /**
     * @return true|\Illuminate\Http\Response true when this delivery may import the message, else the answer to give
     */
    private function claim($id, Mailbox $mailbox)
    {
        $now = now();
        try {
            \DB::table(self::TABLE)->insert([
                'inbound_id' => $id, 'mailbox_id' => $mailbox->id, 'status' => 'processing', 'created_at' => $now, 'updated_at' => $now,
            ]);

            return true;
        } catch (QueryException $e) {
            // 23xxx: integrity constraint, the id is there already. Anything else (database down) is an error: 500.
            if (strpos((string) $e->getCode(), '23') !== 0) {
                throw $e;
            }
        }
        $row = \DB::table(self::TABLE)->where('inbound_id', $id)->first();
        if ($row && $row->status !== 'processing') {
            return response('', 204);
        }
        $takenOver = \DB::table(self::TABLE)->where('inbound_id', $id)->where('status', 'processing')
            ->where('updated_at', '<', $now->copy()->subMinutes(self::STALE_MINUTES))
            ->update(['updated_at' => $now]);

        return $takenOver ? true : response('', 503, ['Retry-After' => 60]);
    }

    private function release($id, $status, $error)
    {
        \DB::table(self::TABLE)->where('inbound_id', $id)->where('status', 'processing')->delete();
        $this->log(null, $error);

        return response('', $status);
    }

    private function log($mailbox, $error)
    {
        \Helper::log(\App\ActivityLog::NAME_EMAILS_FETCHING, \App\ActivityLog::DESCRIPTION_EMAILS_FETCHING_ERROR, [
            'error'   => $error,
            'mailbox' => $mailbox ? $mailbox->name : '',
        ]);
    }
}
