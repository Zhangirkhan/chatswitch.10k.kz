<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:20px 0;border:1px solid #e3e8e6;border-collapse:collapse;background-color:#fafbfa;">
    @foreach ($rows as $index => $row)
    <tr>
        <td style="padding:12px 16px;@if($index < count($rows) - 1) border-bottom:1px solid #e3e8e6; @endif font-size:13px;color:#5c6b73;width:36%;vertical-align:top;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
            {{ $row['label'] }}
        </td>
        <td style="padding:12px 16px;@if($index < count($rows) - 1) border-bottom:1px solid #e3e8e6; @endif font-size:14px;font-weight:600;color:#111b21;vertical-align:top;word-break:break-word;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
            {{ $row['value'] }}
        </td>
    </tr>
    @endforeach
</table>
