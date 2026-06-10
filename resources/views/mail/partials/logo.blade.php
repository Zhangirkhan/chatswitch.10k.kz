@php
    $size = $size ?? 'md';
    $mark = $size === 'sm' ? 28 : 36;
    $font = $size === 'sm' ? 16 : 20;
    $gap = $size === 'sm' ? 8 : 10;
    $name = config('app.name', 'Accel');
    $logoUrl = asset('branding/logo-badge.png');
@endphp
<table role="presentation" cellspacing="0" cellpadding="0" border="0">
    <tr>
        <td style="width:{{ $mark }}px;height:{{ $mark }}px;vertical-align:middle;">
            <img
                src="{{ $logoUrl }}"
                width="{{ $mark }}"
                height="{{ $mark }}"
                alt="{{ $name }}"
                style="display:block;width:{{ $mark }}px;height:{{ $mark }}px;border:0;outline:none;text-decoration:none;border-radius:24%;"
            >
        </td>
        <td style="padding-left:{{ $gap }}px;vertical-align:middle;">
            <span style="font-size:{{ $font }}px;font-weight:700;letter-spacing:-0.03em;color:#111b21;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">{{ $name }}</span>
        </td>
    </tr>
</table>
