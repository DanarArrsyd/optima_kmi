<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><style>body{font-family:Arial,sans-serif;font-size:11px}</style></head>
<body>
@if (! $schedule)
    <p>Belum ada jadwal untuk {{ $bulan->translatedFormat('F Y') }}. Klik "Generate Ulang" di halaman Control Stroke.</p>
@else
    <h1>{{ $schedule->nomor_dokumen }}</h1>
    <p>{{ $schedule->items->count() }} item terjadwal.</p>
@endif
</body>
</html>
