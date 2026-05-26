<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Счёт {{ $invoice->number }}</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem; color: #111; }
        h1 { font-size: 1.5rem; margin-bottom: 0.25rem; }
        .muted { color: #555; font-size: 0.875rem; }
        table.meta { width: 100%; margin: 1.5rem 0; border-collapse: collapse; }
        table.meta td { padding: 0.35rem 0; vertical-align: top; }
        table.meta td:first-child { width: 40%; color: #555; }
        .amount { font-size: 1.75rem; font-weight: 700; margin: 1rem 0; }
        .requisites { margin-top: 2rem; padding: 1rem; background: #f4f4f5; border-radius: 8px; font-size: 0.9rem; }
        @media print {
            body { margin: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <p class="no-print muted"><a href="javascript:window.print()">Печать / сохранить как PDF</a></p>

    <h1>Счёт на оплату</h1>
    <p class="muted">№ {{ $invoice->number }} · {{ $invoice->issued_at?->format('d.m.Y') ?? now()->format('d.m.Y') }}</p>

    <table class="meta">
        <tr>
            <td>Плательщик</td>
            <td><strong>{{ $company->name }}</strong><br>{{ $company->slug }}.accel.kz</td>
        </tr>
        @if($company->phone)
        <tr>
            <td>Телефон</td>
            <td>{{ $company->phone }}</td>
        </tr>
        @endif
        <tr>
            <td>Статус</td>
            <td>{{ $statusLabel }}</td>
        </tr>
    </table>

    <p class="amount">{{ $amountLabel }}</p>

    @if($invoice->notes)
    <p><span class="muted">Назначение:</span> {{ $invoice->notes }}</p>
    @endif

    <div class="requisites">
        <strong>Реквизиты получателя</strong><br>
        {{ $seller['name'] }}<br>
        @if($seller['bin']) БИН / ИИН: {{ $seller['bin'] }}<br> @endif
        @if($seller['bank']) {{ $seller['bank'] }}<br> @endif
        @if($seller['iban']) IBAN: {{ $seller['iban'] }}<br> @endif
        @if($seller['email']) {{ $seller['email'] }} @endif
    </div>
</body>
</html>
