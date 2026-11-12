<?php

namespace Modules\PostilioSend\Http\Controllers;

use App\Mailbox;
use App\SendLog;
use App\Thread;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Modules\PostilioSend\Providers\PostilioSendServiceProvider as Provider;
use Modules\PostilioSend\WebhookSignature;

class PostilioSendController extends Controller
{
    // Postilio's events and how FreeScout's send log records them.
    const STATUSES = [
        'delivered'  => SendLog::STATUS_DELIVERY_SUCCESS,
        'bounced'    => SendLog::STATUS_DELIVERY_ERROR,
        'expired'    => SendLog::STATUS_DELIVERY_ERROR,
        'suppressed' => SendLog::STATUS_DELIVERY_ERROR,
        'complained' => SendLog::STATUS_COMPLAINED,
    ];

    const MASK = '********';

    public function settings($mailbox_id)
    {
        $mailbox = Mailbox::findOrFail($mailbox_id);
        $this->authorize('admin', $mailbox);
        $settings = $mailbox->getMeta(Provider::META, []);

        return view('postiliosend::settings', [
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
                $validator->errors()->add('api_key', __('An API key is needed to send through Postilio.'));
            }
        });
        if ($validator->fails()) {
            return redirect()->route('postiliosend.settings', ['mailbox_id' => $mailbox->id])->withErrors($validator);
        }

        $settings['enabled'] = $enabled;
        foreach ($input as $field => $value) {
            if ($value) {
                $settings[$field] = \Helper::encrypt($value);
            }
        }
        $mailbox->setMetaParam(Provider::META, $settings, true);
        // A queue worker keeps the mailer it built: make it start over with the new settings.
        \Helper::queueWorkerRestart();
        \Session::flash('flash_success_floating', __('Settings saved'));

        return redirect()->route('postiliosend.settings', ['mailbox_id' => $mailbox->id]);
    }

    /**
     * Email events from Postilio. Answers 2xx once handled (or not ours), so a failure (5xx) makes Postilio retry.
     */
    public function webhook($mailbox_id, Request $request)
    {
        $mailbox = Mailbox::find((int) $mailbox_id);
        $settings = $mailbox ? $mailbox->getMeta(Provider::META, []) : [];
        $secret = !empty($settings['enabled']) && !empty($settings['webhook_secret']) ? \Helper::decrypt($settings['webhook_secret']) : '';
        if (!$secret) {
            return response('', 404);
        }
        $body = $request->getContent();
        if (!WebhookSignature::verify($secret, (string) $request->header('webhook-id'), (string) $request->header('webhook-timestamp'),
            (string) $request->header('webhook-signature'), $body)
        ) {
            return response('', 401);
        }
        $event = json_decode($body, true);
        $data = is_array($event) && isset($event['data']) && is_array($event['data']) ? $event['data'] : null;
        if ($data === null || !is_string($data['emailId'] ?? null)
            || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $data['emailId'])
        ) {
            return response('', 400);
        }

        $status = self::STATUSES[$data['event'] ?? ''] ?? null;
        $thread = $status ? Thread::where('message_id', 'like', strtolower(str_replace('-', '', $data['emailId'])).'@%')->first() : null;
        // Only a reply of this mailbox: the webhook of one mailbox never touches another's.
        if (!$thread || !$thread->conversation || $thread->conversation->mailbox_id != $mailbox->id) {
            return response('', 204);
        }

        $message = mb_substr((string) ($data['response'] ?? $data['reason'] ?? $data['event']), 0, 255);
        if ($status === SendLog::STATUS_DELIVERY_ERROR) {
            $thread->send_status = $status;
            $thread->updateSendStatusData(['msg' => $message]);
            $thread->save();
        }
        SendLog::log($thread->id, $thread->message_id, (string) ($data['to'] ?? ''), SendLog::MAIL_TYPE_EMAIL_TO_CUSTOMER,
            $status, $thread->customer_id, null, $message);

        return response('', 204);
    }
}
