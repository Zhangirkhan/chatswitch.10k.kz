@extends('mail.layout')

@section('title', 'WhatsApp разлогинил устройство')
@section('preheader', 'Подключение «' . ($session->display_name ?: $session->phone_number ?: $session->session_name) . '» отключено WhatsApp — ' . $company->name)
@section('badge', 'WhatsApp')
@section('badge_tone', 'warning')

@section('heading', 'WhatsApp разлогинил связанное устройство')
@section('subheading', 'Компания «' . $company->name . '».')

@section('content')
@include('mail.partials.info-table', [
    'rows' => array_filter([
        ['label' => 'Подключение', 'value' => $session->display_name ?: $session->phone_number ?: $session->session_name],
        ['label' => 'Номер', 'value' => $session->phone_number ?: '—'],
        ['label' => 'Причина', 'value' => 'LOGOUT (устройство отвязано в WhatsApp)'],
        $forOps ? ['label' => 'Session ID', 'value' => $session->session_name] : null,
    ]),
])

<p style="margin:16px 0 0;font-size:14px;line-height:1.6;color:#5c6b73;">
    @if($forOps)
        Система попытается переподключить сессию автоматически. Если появится QR — нужен скан в настройках тенанта.
    @else
        Номер отключился в WhatsApp (например, вы вышли из «Связанных устройств» или WhatsApp сбросил сессию).
        Откройте настройки подключений и при необходимости отсканируйте QR заново.
    @endif
</p>

@if(! $forOps)
@include('mail.partials.button', [
    'url' => $connectionsUrl,
    'label' => 'Открыть подключения',
])
@endif
@endsection

@section('footer')
@include('mail.partials.footer-inner', [
    'note' => 'Это автоматическое уведомление Accel. Повтор придёт не чаще одного раза в сутки при повторном LOGOUT.',
])
@endsection
