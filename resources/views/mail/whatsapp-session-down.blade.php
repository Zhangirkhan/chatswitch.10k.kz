@extends('mail.layout')

@section('title', 'WhatsApp не отвечает')
@section('preheader', 'Подключение «' . ($session->display_name ?: $session->phone_number ?: $session->session_name) . '» недоступно уже ' . $downMinutes . ' мин — ' . $company->name)
@section('badge', 'WhatsApp')
@section('badge_tone', 'warning')

@section('heading', 'WhatsApp-сессия не восстановилась')
@section('subheading', 'Компания «' . $company->name . '».')

@section('content')
@include('mail.partials.info-table', [
    'rows' => array_filter([
        ['label' => 'Подключение', 'value' => $session->display_name ?: $session->phone_number ?: $session->session_name],
        ['label' => 'Номер', 'value' => $session->phone_number ?: '—'],
        ['label' => 'Статус в системе', 'value' => $session->status ?: 'unknown'],
        ['label' => 'Недоступна', 'value' => 'более ' . $downMinutes . ' мин'],
        $lastError ? ['label' => 'Последняя ошибка', 'value' => $lastError] : null,
        $forOps ? ['label' => 'Session ID', 'value' => $session->session_name] : null,
    ]),
])

<p style="margin:16px 0 0;font-size:14px;line-height:1.6;color:#5c6b73;">
    Система уже пытается поднять сессию автоматически. Если проблема не исчезнет,
    @if($forOps)
        проверьте whatsapp-service и логи heal/watchdog.
    @else
        откройте настройки подключений и при необходимости переподключите номер (QR).
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
    'note' => 'Это автоматическое уведомление Accel. Повтор придёт не чаще одного раза в сутки, пока сессия не восстановится.',
])
@endsection
