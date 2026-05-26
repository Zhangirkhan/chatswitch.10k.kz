@extends('mail.layout')

@section('title', 'Ваш аккаунт Accel готов')
@section('preheader', 'Заявка одобрена — войдите в '.$company->name.' и смените пароль.')
@section('badge', 'Заявка одобрена')
@section('badge_tone', 'success')

@section('heading', 'Добро пожаловать, '.$owner->name.'!')
@section('subheading', 'Рабочее пространство «'.$company->name.'» создано и готово к работе.')

@section('content')
<p style="margin:0 0 16px;">
    Заявка на подключение к <strong>Accel</strong> одобрена. Ниже — ссылка для входа и временный пароль.
    После первого входа рекомендуем сменить пароль в настройках профиля.
</p>

@include('mail.partials.info-table', [
    'rows' => [
        ['label' => 'Компания', 'value' => $company->name],
        ['label' => 'Email', 'value' => $owner->email],
        ['label' => 'Временный пароль', 'value' => $temporaryPassword],
        ['label' => 'Триал', 'value' => $trialDays.' дней'],
    ],
])

@include('mail.partials.button', [
    'url' => $loginUrl,
    'label' => 'Войти в Accel',
])

<p style="margin:16px 0 0;font-size:13px;line-height:1.6;color:#5c6b73;">
    Или скопируйте ссылку:
    <a href="{{ $loginUrl }}" style="color:#01b964;word-break:break-all;">{{ $loginUrl }}</a>
</p>

@include('mail.partials.notice', [
    'text' => '<strong>Важно:</strong> никому не передавайте временный пароль. Если вы не оставляли заявку — просто проигнорируйте это письмо.',
])
@endsection

@section('footer')
@include('mail.partials.footer-inner', [
    'footerNote' => 'Если вы не оставляли заявку, проигнорируйте это письмо.',
])
@endsection
