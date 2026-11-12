<?php

namespace Modules\PostilioSend\Providers;

use App\Mailbox;
use Illuminate\Support\ServiceProvider;
use Modules\PostilioSend\PostilioTransport;

class PostilioSendServiceProvider extends ServiceProvider
{
    const META = 'postilio_send';

    // The mailbox FreeScout is configuring the mailer for; set by a filter that setMailDriver() passes on its way.
    private static $mailbox;

    public function boot()
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'postiliosend');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'postiliosend');
        $this->hooks();
    }

    public function register()
    {
        $this->app->singleton('postilio.http', function () {
            return new \GuzzleHttp\Client(['timeout' => 30, 'connect_timeout' => 10]);
        });
    }

    public function hooks()
    {
        \Eventy::addFilter('mailbox.get_mail_from_address', function ($address, $user = null, $conversation = null, $thread = null, $mailbox = null) {
            self::$mailbox = $mailbox;

            return $address;
        }, 20, 5);

        // FreeScout has just rebuilt the mailer from the mailbox's settings: swap in Postilio when the mailbox uses it.
        \Eventy::addAction('mail.reapply_mail_config', function () {
            $mailbox = self::$mailbox;
            self::$mailbox = null;
            if (!$mailbox || \Config::get('mail.from.address') !== $mailbox->email || !($apiKey = self::apiKey($mailbox))) {
                return;
            }
            $transport = new PostilioTransport(app('postilio.http'), config('postiliosend.api_url'), $apiKey, function ($message, array $ids) {
                self::recordSent($message, $ids);
            });
            app('mailer')->setSwiftMailer(new \Swift_Mailer($transport));
        });

        \Eventy::addAction('mailboxes.settings.menu', function ($mailbox) {
            if (auth()->user() && auth()->user()->isAdmin()) {
                echo \View::make('postiliosend::menu', ['mailbox' => $mailbox])->render();
            }
        });
    }

    /**
     * The API key of a mailbox that sends through Postilio, or null.
     */
    public static function apiKey(Mailbox $mailbox)
    {
        $settings = $mailbox->getMeta(self::META, []);
        if (empty($settings['enabled']) || empty($settings['api_key'])) {
            return null;
        }

        return \Helper::decrypt($settings['api_key']) ?: null;
    }

    /**
     * Postilio writes its own Message-ID, "<{id without dashes}@{sender domain}>", so the customer's reply names that
     * one. Keeping it on the thread lets FreeScout's own threading find the conversation, and lets the status webhook
     * find the thread by the email id.
     */
    public static function recordSent($message, array $ids)
    {
        if (!$ids || !preg_match('/^'.preg_quote(\MailHelper::MESSAGE_ID_PREFIX_REPLY_TO_CUSTOMER, '/').'-(\d+)-/', $message->getId(), $m)) {
            return;
        }
        $from = array_keys($message->getFrom() ?: []);
        $domain = $from ? substr(strrchr($from[0], '@'), 1) : '';
        \App\Thread::where('id', $m[1])->update(['message_id' => str_replace('-', '', $ids[0]).'@'.$domain]);
    }
}
