@php
    $supportEmail = config('mail.support_address', 'support@accel.kz');
    $billingEmail = config('billing.seller.email', 'billing@accel.kz');
    $siteUrl = config('app.url', 'https://accel.kz');
    $siteHost = parse_url($siteUrl, PHP_URL_HOST) ?: 'accel.kz';
@endphp
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
    <tr>
        <td valign="top" style="padding:0 24px 0 0;width:140px;">
            @include('mail.partials.logo', ['size' => 'sm'])
        </td>
        <td valign="top" align="right" style="font-size:13px;line-height:1.7;color:#5c6b73;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
            <a href="mailto:{{ $supportEmail }}" style="color:#111b21;text-decoration:none;">{{ $supportEmail }}</a>
            <span style="color:#c5d0d6;"> · </span>
            <a href="mailto:{{ $billingEmail }}" style="color:#111b21;text-decoration:none;">{{ $billingEmail }}</a>
            <br>
            <a href="{{ $siteUrl }}" style="color:#01b964;text-decoration:none;">{{ $siteHost }}</a>
            <span style="color:#c5d0d6;"> · </span>
            <a href="{{ $siteUrl }}" style="color:#01b964;text-decoration:none;">Войти</a>
        </td>
    </tr>
    <tr>
        <td colspan="2" style="padding:16px 0 0;font-size:12px;line-height:1.55;color:#8a9aa3;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
            {{ $footerNote ?? ('Вы получили это письмо от '.config('app.name', 'Accel').'.') }}
        </td>
    </tr>
</table>
