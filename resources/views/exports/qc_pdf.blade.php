<!DOCTYPE html><html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: sans-serif; font-size: 11px; }
    h2 { text-align: center; margin-bottom: 4px; }
    p  { text-align: center; color: #666; margin-top: 0; }
    table { width: 100%; border-collapse: collapse; margin-top: 12px; }
    th { background: #1a1a2e; color: #fff; padding: 6px; text-align: left; }
    td { padding: 5px 6px; border-bottom: 1px solid #eee; }
    .bg-green  { background: #dcfce7; }
    .bg-yellow { background: #fef9c3; }
    .bg-red    { background: #fee2e2; }
</style>
</head>
<body>
<h2>VCB Quality — QC Inspector Achievement</h2>
<p>{{ $dateFrom }} s/d {{ $dateTo }}</p>
<table>
    <thead><tr><th>Inspector</th><th>Target QTY</th><th>Qty OK</th><th>Qty NG</th><th>Total Check</th><th>Achievement %</th><th>Avg SPH QC</th></tr></thead>
    <tbody>
    @foreach($rows as $row)
        @php $cls = $row['traffic_light'] === 'success' ? 'bg-green' : ($row['traffic_light'] === 'warning' ? 'bg-yellow' : 'bg-red'); @endphp
        <tr class="{{ $cls }}">
            <td>{{ $row['karyawan'] }}</td>
            <td>{{ number_format($row['total_target_qty']) }}</td>
            <td>{{ number_format($row['total_qty_ok']) }}</td>
            <td>{{ number_format($row['total_qty_ng']) }}</td>
            <td>{{ number_format($row['total_qty_check']) }}</td>
            <td>{{ $row['achievement_pct'] }}%</td>
            <td>{{ $row['avg_actual_sph'] }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
<p style="margin-top:20px;color:#999">Generated: {{ now()->format('d/m/Y H:i') }} | OPTIMA</p>
</body></html>
