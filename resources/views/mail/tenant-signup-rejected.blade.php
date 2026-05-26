@extends('mail.layout')

@section('title', 'Заявка на Accel')
@section('preheader', 'Решение по заявке компании «'.$signupRequest->company_name.'».')
@section('badge', 'Заявка не одобрена')
@section('badge_tone', 'warning')

@section('heading', 'Здравствуйте, '.$signupRequest->contact_name.'!')
@section('subheading', 'Спасибо за интерес к Accel.')

@section('content')
<p style="margin:0 0 16px;">
    К сожалению, заявка на подключение компании
    <strong>{{ $signupRequest->company_name }}</strong> на данный момент не одобрена.
</p>

@if ($signupRequest->desired_slug)
@include('mail.partials.info-table', [
    'rows' => [
        ['label' => 'Компания', 'value' => $signupRequest->company_name],
        ['label' => 'Поддомен', 'value' => $signupRequest->desired_slug.'.'.config('tenancy.root_domain', 'accel.kz')],
    ],
])
@endif

<p style="margin:0 0 16px;">
    Если хотите уточнить данные или задать вопросы — напишите нам, мы на связи.
</p>

@include('mail.partials.button', [
    'url' => 'mailto:support@accel.kz',
    'label' => 'Написать в поддержку',
])

<p style="margin:16px 0 0;font-size:14px;line-height:1.6;color:#5c6b73;">
    Вы также можете повторно отправить заявку с
    <a href="https://accel.kz" style="color:#01b964;text-decoration:none;">лендинга accel.kz</a>,
    когда будете готовы.
</p>
@endsection

@section('footer')
@include('mail.partials.footer-inner', [
    'footerNote' => 'Это автоматическое уведомление по вашей заявке.',
])
@endsection
