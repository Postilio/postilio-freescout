<?php

namespace Modules\PostilioInbound\Providers;

use Illuminate\Support\ServiceProvider;

class PostilioInboundServiceProvider extends ServiceProvider
{
    const META = 'postilio_inbound';

    public function boot()
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'postilioinbound');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'postilioinbound');
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

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
