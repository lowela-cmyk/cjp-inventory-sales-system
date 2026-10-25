{{-- Part 11: Dashboard and Analytics report (PDF via dompdf). Plain tables and inline CSS: dompdf has no grid/flex. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Dashboard and Analytics Report</title>
    <style>
        @page { margin: 28px 32px 40px; }
        body { font-family: DejaVu Sans, sans-serif; color: #111; font-size: 11px; }
        .brand { border-bottom: 3px solid #e8453c; padding-bottom: 8px; margin-bottom: 14px; }
        .brand h1 { margin: 0; font-size: 18px; }
        .brand p { margin: 2px 0 0; color: #555; font-size: 10px; letter-spacing: 0.5px; }
        h2 { font-size: 13px; margin: 18px 0 8px; padding-bottom: 4px; border-bottom: 1px solid #d0d5dd; }
        table { width: 100%; border-collapse: collapse; }
        .meta td { padding: 3px 6px; border: 1px solid #e4e7ec; }
        .meta td.label { width: 38%; color: #555; font-weight: bold; background: #f7f7f8; }
        .cards td { width: 33.33%; padding: 8px 10px; border: 1px solid #d0d5dd; vertical-align: top; }
        .cards span { display: block; color: #555; font-size: 9px; font-weight: bold; }
        .cards strong { display: block; font-size: 13px; margin-top: 3px; }
        .chart { page-break-inside: avoid; margin-bottom: 14px; border: 1px solid #d0d5dd; padding: 8px; }
        .chart h3 { margin: 0 0 6px; font-size: 11px; }
        .chart img { width: 100%; }
        .insight { border: 1px solid #d0d5dd; padding: 10px; white-space: pre-wrap; line-height: 1.5; }
        .note { border-left: 3px solid #e28a22; background: #fff7ed; padding: 8px 10px; }
        .muted { color: #555; font-size: 9px; }
        .footer { position: fixed; bottom: -24px; left: 0; right: 0; text-align: center; color: #888; font-size: 9px; }
    </style>
</head>
<body>
    <div class="footer">CJP Southern Star OPC · Dashboard and Analytics Report · Generated {{ $generatedAt }}</div>

    <div class="brand">
        <h1>CJP Southern Star OPC — Dashboard and Analytics Report</h1>
        <p>INVENTORY AND SALES MANAGEMENT SYSTEM</p>
    </div>

    <table class="meta">
        <tr><td class="label">Date generated</td><td>{{ $generatedAt }}</td></tr>
        @foreach ($filters as $label => $value)
            <tr><td class="label">{{ $label }}</td><td>{{ $value }}</td></tr>
        @endforeach
    </table>

    @foreach (['Current Inventory' => $inventoryCards, 'Sales Trend' => $salesCards] as $section => $cards)
        <h2>{{ $section }}</h2>
        <table class="cards">
            @foreach (array_chunk($cards, 3) as $row)
                <tr>
                    @foreach ($row as [$label, $value])
                        <td><span>{{ $label }}</span><strong>{{ $value }}</strong></td>
                    @endforeach
                    @for ($i = count($row); $i < 3; $i++)
                        <td></td>
                    @endfor
                </tr>
            @endforeach
        </table>
    @endforeach

    <h2>Charts</h2>
    @forelse ($charts as $chart)
        <div class="chart">
            <h3>{{ $chart['title'] }}</h3>
            <img src="{{ $chart['src'] }}" alt="{{ $chart['title'] }}">
        </div>
    @empty
        <p class="note">No charts were captured: the dashboard had no chart data for these filters.</p>
    @endforelse

    <h2>Insights and A.I Reporting</h2>
    @if ($insight)
        @if ($insight['note'])
            <p class="note">{{ $insight['note'] }}</p>
        @endif
        <div class="insight">{{ $insight['text'] }}</div>
        <p class="muted">{{ $insight['source'] }}@if ($insight['generated_at']) · Generated {{ $insight['generated_at'] }}@endif</p>
    @else
        <p class="note">No AI insight was generated before this export. On the dashboard, click "Generate Insight and Reports", then export again to include it.</p>
    @endif
</body>
</html>
