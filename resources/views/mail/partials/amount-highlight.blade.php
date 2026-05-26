<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 24px;border:1px solid #111b21;border-collapse:collapse;">
    <tr>
        <td style="padding:20px 24px;background-color:#111b21;">
            @if(!empty($label))
            <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:#9fb0b8;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
                {{ $label }}
            </p>
            @endif
            <p style="margin:0;font-size:30px;font-weight:700;letter-spacing:-0.02em;color:#ffffff;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
                {{ $amount }}
            </p>
            @if(!empty($meta))
            <p style="margin:8px 0 0;font-size:14px;color:#c5d0d6;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
                {{ $meta }}
            </p>
            @endif
        </td>
    </tr>
</table>
