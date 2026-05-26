@php
    $size = $size ?? 'md';
    $mark = $size === 'sm' ? 28 : 36;
    $font = $size === 'sm' ? 16 : 20;
    $gap = $size === 'sm' ? 8 : 10;
    $name = config('app.name', 'Accel');
@endphp
<table role="presentation" cellspacing="0" cellpadding="0" border="0">
    <tr>
        <td style="width:{{ $mark }}px;height:{{ $mark }}px;background-color:#01b964;text-align:center;vertical-align:middle;">
            <span style="display:block;font-size:{{ $size === 'sm' ? 14 : 18 }}px;font-weight:700;line-height:{{ $mark }}px;color:#111b21;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">A</span>
        </td>
        <td style="padding-left:{{ $gap }}px;vertical-align:middle;">
            <span style="font-size:{{ $font }}px;font-weight:700;letter-spacing:-0.03em;color:#111b21;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">{{ $name }}</span>
        </td>
    </tr>
</table>
