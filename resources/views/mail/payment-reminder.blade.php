@php
    use App\Models\BillingReminderLog;

    $isTrial = $kind === BillingReminderLog::KIND_TRIAL_ENDING;
    $dueLabel = $dueAt->locale('ru')->translatedFormat('j F Y');
    $daysWord = match (true) {
        $daysBefore % 10 === 1 && $daysBefore % 100 !== 11 => 'день',
        $daysBefore % 10 >= 2 && $daysBefore % 10 <= 4 && ($daysBefore % 100 < 10 || $daysBefore % 100 >= 20) => 'дня',
        default => 'дней',
    };
@endphp
@extends('mail.layout')

@section('title', $isTrial ? 'Окончание пробного периода' : 'Напоминание об оплате')
@section('preheader', ($isTrial ? 'Пробный период заканчивается' : 'Оплата подписки') . ' через ' . $daysBefore . ' ' . $daysWord . ' — ' . $company->name)
@section('badge', $isTrial ? 'Пробный период' : 'Подписка')
@section('badge_tone', 'warning')

@section('heading', $isTrial ? 'Скоро закончится пробный период' : 'Напоминание об оплате подписки')
@section('subheading', 'Компания «' . $company->name . '».')

@section('content')
@include('mail.partials.amount-highlight', [
    'label' => $isTrial ? 'Оплата потребуется' : 'Следующий платёж',
    'amount' => $dueLabel,
    'meta' => 'через '.$daysBefore.' '.$daysWord,
])

@include('mail.partials.info-table', [
    'rows' => array_filter([
        ['label' => 'Тариф', 'value' => $company->plan?->name ?? 'Стандарт'],
        ['label' => 'Сумма', 'value' => $amountLabel],
        $isTrial
            ? ['label' => 'Что дальше', 'value' => 'После окончания пробного периода для продолжения работы нужно оплатить подписку.']
            : ['label' => 'Что дальше', 'value' => 'Продлите подписку до указанной даты, чтобы сервис работал без перерыва.'],
    ]),
])

@include('mail.partials.button', [
    'url' => $loginUrl,
    'label' => 'Войти в Accel',
])

@if($invoicePrintUrl)
<p style="margin:16px 0 0;font-size:14px;line-height:1.6;color:#5c6b73;">
    У вас есть выставленный счёт:
    <a href="{{ $invoicePrintUrl }}" style="color:#01b964;text-decoration:none;">открыть счёт для оплаты</a>
</p>
@endif

<p style="margin:16px 0 0;font-size:13px;line-height:1.6;color:#5c6b73;">
    По вопросам оплаты:
    <a href="mailto:{{ config('billing.seller.email', 'billing@accel.kz') }}" style="color:#01b964;text-decoration:none;">
        {{ config('billing.seller.email', 'billing@accel.kz') }}
    </a>
</p>
@endsection

@section('footer')
@include('mail.partials.footer-inner', [
    'footerNote' => 'Автоматическое напоминание. Если оплата уже проведена — можете проигнорировать письмо.',
])
@endsection
