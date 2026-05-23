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
</style>
</head>
<body>
<h2>VCB Dandori — Loss Time Summary</h2>
<p>{{ $bulan ?? 'Semua Bulan' }}{{ $week ? ' | ' . $week : '' }}</p>
<table>
    <thead><tr><th>Mesin</th><th>Total Loss (menit)</th><th>Total Over (menit)</th></tr></thead>
    <tbody>
    @foreach($rows as $row)
        <tr>
            <td>{{ $row['mesin'] }}</td>
            <td>{{ $row['total_loss'] }}</td>
            <td>{{ $row['total_over'] }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
<p style="margin-top:20px;color:#999">Generated: {{ now()->format('d/m/Y H:i') }} | OPTIMA</p>
</body></html>
