<?php

// Postilio's webhook: no "web" middleware, so no session and no CSRF token; the signature authenticates it.
Route::post(\Helper::getSubdirectory().'/postilio-send/webhook/{mailbox_id}', 'Modules\PostilioSend\Http\Controllers\PostilioSendController@webhook')
    ->name('postiliosend.webhook');

Route::group(['middleware' => ['web', 'auth'], 'prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\PostilioSend\Http\Controllers'], function () {
    Route::get('/mailbox/{mailbox_id}/postilio-send', 'PostilioSendController@settings')->name('postiliosend.settings');
    Route::post('/mailbox/{mailbox_id}/postilio-send', 'PostilioSendController@saveSettings');
});
