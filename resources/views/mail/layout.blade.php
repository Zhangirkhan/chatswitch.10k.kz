@php
    $text = '#111b21';
    $muted = '#5c6b73';
    $border = '#e3e8e6';
    $appName = config('app.name', 'Accel');
    $preheader = trim($__env->yieldContent('preheader'));
    $badge = trim($__env->yieldContent('badge'));
    $badgeTone = trim($__env->yieldContent('badge_tone')) ?: 'accent';
    $badgeColor = match ($badgeTone) {
        'warning' => '#9a6700',
        'neutral' => '#5c6b73',
        'success' => '#017a44',
        default => '#017a44',
    };
@endphp
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>@yield('title', $appName)</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
</head>
<body style="margin:0;padding:0;background-color:#ffffff;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased;">
@if($preheader !== '')
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;mso-hide:all;">
    {{ $preheader }}&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
</div>
@endif
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#ffffff;">
    <tr>
        <td align="center" style="padding:0;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:680px;width:100%;border:1px solid {{ $border }};border-collapse:collapse;background-color:#ffffff;">
                {{-- Шапка: логотип слева --}}
                <tr>
                    <td style="padding:28px 40px 24px;border-bottom:1px solid {{ $border }};">
                        @include('mail.partials.logo', ['size' => 'md'])
                    </td>
                </tr>
                {{-- Основной текст --}}
                <tr>
                    <td style="padding:32px 40px 28px;">
                        @if($badge !== '')
                        <p style="margin:0 0 12px;font-size:11px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:{{ $badgeColor }};">
                            {{ $badge }}
                        </p>
                        @endif
                        @hasSection('heading')
                        <h1 style="margin:0 0 8px;font-size:26px;font-weight:700;line-height:1.3;letter-spacing:-0.02em;color:{{ $text }};">
                            @yield('heading')
                        </h1>
                        @endif
                        @hasSection('subheading')
                        <p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:{{ $muted }};">
                            @yield('subheading')
                        </p>
                        @endif
                        <div style="font-size:15px;line-height:1.65;color:{{ $text }};">
                            @yield('content')
                        </div>
                    </td>
                </tr>
                {{-- Футер внутри контейнера --}}
                <tr>
                    <td style="padding:24px 40px 32px;border-top:1px solid {{ $border }};background-color:#fafbfa;">
                        @hasSection('footer')
                            @yield('footer')
                        @else
                            @include('mail.partials.footer-inner')
                        @endif
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
