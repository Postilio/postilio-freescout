<?php

namespace Modules\PostilioInbound\Providers;

use Illuminate\Support\ServiceProvider;

class PostilioInboundServiceProvider extends ServiceProvider
{
    const META = 'postilio_inbound';

    // A mailbox that receives through Postilio gets its own incoming protocol: FreeScout then counts it as connected
    // (filter mailbox.in_active) while fetch-emails, which only takes mailboxes on IMAP or POP3, leaves it alone.
    const IN_PROTOCOL = 80;

    public static function receives($mailbox)
    {
        $settings = $mailbox->getMeta(self::META, []);

        return !empty($settings['enabled']) && !empty($settings['api_key']) && !empty($settings['webhook_secret']);
    }

    public function boot()
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'postilioinbound');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'postilioinbound');
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        \Eventy::addFilter('mailbox.in_protocols', function ($protocols) {
            $protocols[self::IN_PROTOCOL] = 'postilio';

            return $protocols;
        });
        \Eventy::addFilter('mailbox.in_protocols.display_names', function ($names) {
            $names[self::IN_PROTOCOL] = 'Postilio';

            return $names;
        });
        \Eventy::addFilter('mailbox.in_active', function ($active, $mailbox) {
            return (int) $mailbox->in_protocol === self::IN_PROTOCOL ? self::receives($mailbox) : $active;
        }, 20, 2);
        \Eventy::addAction('mailbox.connection_incoming.after_default_settings', function ($mailbox) {
            echo \View::make('postilioinbound::connection_incoming', ['mailbox' => $mailbox])->render();
        });

        \Eventy::addAction('mailboxes.settings.menu', function ($mailbox) {
            if (auth()->user() && auth()->user()->isAdmin()) {
                echo \View::make('postilioinbound::menu', ['mailbox' => $mailbox])->render();
            }
        });
    }

    public function register()
    {
        $this->app->singleton('postilio.http', function () {
            return new \GuzzleHttp\Client(['timeout' => 30, 'connect_timeout' => 10]);
        });
    }
}
