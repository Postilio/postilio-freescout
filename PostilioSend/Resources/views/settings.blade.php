@extends('layouts.app')

@section('title_full', __('Postilio sending').' - '.$mailbox->name)

@section('body_attrs')@parent data-mailbox_id="{{ $mailbox->id }}"@endsection

@section('sidebar')
    @include('partials/sidebar_menu_toggle')
    @include('mailboxes/sidebar_menu')
@endsection

@section('content')
    <div class="section-heading margin-bottom">
        {{ __('Postilio sending') }}
    </div>

    @include('partials/flash_messages')

    <div class="container form-container">
        <div class="row">
            <div class="col-xs-12">
                <form class="form-horizontal" method="POST" action="" autocomplete="off">
                    {{ csrf_field() }}

                    <div class="descr-block">
                        {{ __('Replies from this mailbox go out through the Postilio API instead of the method on the Connection Settings page. Postilio reports back when a reply bounced, and the conversation shows it as not sent.') }}
                    </div>
                    <hr/>

                    <div class="form-group">
                        <label for="enabled" class="col-sm-2 control-label">{{ __('Send through Postilio') }}</label>
                        <div class="col-sm-6">
                            <div class="controls">
                                <input type="checkbox" name="enabled" value="1" id="enabled" @if ($enabled) checked="checked" @endif>
                            </div>
                        </div>
                    </div>

                    <div class="form-group{{ $errors->has('api_key') ? ' has-error' : '' }}">
                        <label for="api_key" class="col-sm-2 control-label">{{ __('API key') }}</label>
                        <div class="col-sm-6">
                            <input id="api_key" type="password" class="form-control input-sized" name="api_key" value="{{ $has_key ? $mask : '' }}" maxlength="255" autocomplete="new-password">
                            <p class="form-help">{{ __('A key of the project with the sending domain of :email, with the emails:send scope.', ['email' => $mailbox->email]) }}</p>
                            @include('partials/field_error', ['field' => 'api_key'])
                        </div>
                    </div>

                    <div class="form-group{{ $errors->has('webhook_secret') ? ' has-error' : '' }}">
                        <label for="webhook_secret" class="col-sm-2 control-label">{{ __('Webhook signing secret') }}</label>
                        <div class="col-sm-6">
                            <input id="webhook_secret" type="password" class="form-control input-sized" name="webhook_secret" value="{{ $has_secret ? $mask : '' }}" maxlength="255" autocomplete="new-password">
                            <p class="form-help">
                                {{ __('Add a webhook endpoint in Postilio with this URL and the events delivered, bounced, expired, suppressed and complained, then paste its whsec_ secret here:') }}<br>
                                <code>{{ route('postiliosend.webhook', ['mailbox_id' => $mailbox->id]) }}</code>
                            </p>
                            @include('partials/field_error', ['field' => 'webhook_secret'])
                        </div>
                    </div>

                    <div class="form-group margin-top">
                        <div class="col-sm-6 col-sm-offset-2">
                            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
