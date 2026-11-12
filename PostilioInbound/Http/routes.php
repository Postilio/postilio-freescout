<?php

// Postilio's webhook: no "web" middleware, so no session and no CSRF token; the signature authenticates it.
Route::post(\Helper::getSubdirectory().'/postilio-inbound/webhook/{mailbox_id}', 'Modules\PostilioInbound\Http\Controllers\PostilioInboundController@webhook')
    ->name('postilioinbound.webhook');

Route::group(['middleware' => ['web', 'auth'], 'prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\PostilioInbound\Http\Controllers'], function () {
    Route::get('/mailbox/{mailbox_id}/postilio-inbound', 'PostilioInboundController@settings')->name('postilioinbound.settings');
    Route::post('/mailbox/{mailbox_id}/postilio-inbound', 'PostilioInboundController@saveSettings');
});
