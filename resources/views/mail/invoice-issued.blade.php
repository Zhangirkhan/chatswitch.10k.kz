@extends('mail.layout')

@section('title', 'Счёт '.$invoice->number)
@section('preheader', 'Счёт '.$invoice->number.' на сумму '.$amountLabel.' для '.$company->name.'.')
@section('badge', 'Счёт '.$invoice->number)
@section('badge_tone', 'neutral')

@section('heading', 'Выставлен счёт')
@section('subheading', 'Для компании «'.$company->name.'».')

@section('content')
@include('mail.partials.amount-highlight', [
    'label' => 'Сумма к оплате',
    'amount' => $amountLabel,
])

@include('mail.partials.info-table', [
    'rows' => array_filter([
        ['label' => 'Компания', 'value' => $company->name],
        ['label' => 'Номер счёта', 'value' => $invoice->number],
        $invoice->notes ? ['label' => 'Примечание', 'value' => $invoice->notes] : null,
    ]),
])

@include('mail.partials.button', [
    'url' => $printUrl,
    'label' => 'Открыть счёт (PDF / печать)',
])

<p style="margin:16px 0 0;font-size:13px;line-height:1.6;color:#5c6b73;">
    По вопросам оплаты:
    <a href="mailto:{{ config('billing.seller.email', 'billing@accel.kz') }}" style="color:#01b964;text-decoration:none;">{{ config('billing.seller.email', 'billing@accel.kz') }}</a>
</p>
@endsection

@section('footer')
@include('mail.partials.footer-inner', [
    'footerNote' => (config('billing.seller.name', config('app.name', 'Accel'))).(config('billing.seller.bin') ? ' · БИН '.config('billing.seller.bin') : '').' — документ сформирован автоматически.',
])
@endsection
