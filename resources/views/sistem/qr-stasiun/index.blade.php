@extends('layouts.app')
@section('title', 'Generate QR Stasiun')

@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-qr-code"></i>Generate QR Stasiun</h1>
</div>

<div style="font-size:12.5px;color:var(--text-3);margin-bottom:18px">
    QR di bawah statis — sekali dicetak dan ditempel di stasiun, tidak perlu digenerate ulang.
    Nambah stasiun baru dilakukan lewat kode (`config/qr_stasiun.php`), bukan dari halaman ini.
</div>

<div style="display:flex;flex-wrap:wrap;gap:16px">
    @foreach($stasiun as $s)
        <div class="qr-kartu erp-panel" id="qr-kartu-{{ $loop->index }}" style="width:280px">
            <div class="erp-panel-header">{{ $s['label'] }}</div>
            <div class="erp-panel-body" style="text-align:center">
                <div class="qr-gambar">{!! $s['svg'] !!}</div>
                <div style="font-size:11px;color:var(--text-3);word-break:break-all;margin:10px 0">{{ $s['url'] }}</div>
                <button type="button" class="btn btn-outline-secondary btn-sm qr-tombol-cetak"
                        onclick="cetakKartuQr('qr-kartu-{{ $loop->index }}')">
                    <i class="bi bi-printer me-1"></i>Cetak
                </button>
            </div>
        </div>
    @endforeach
</div>

@push('styles')
<style>
    /* Saat mencetak, cuma kartu QR yang tampak — shell (topbar/sidebar) dan
       kartu lain disembunyikan supaya satu print job = satu QR di satu lembar. */
    @media print {
        #topbar, #sidebar, #sb-overlay, .page-header, .qr-tombol-cetak { display: none !important; }
        #main { padding: 0 !important; margin: 0 !important; }
        .qr-kartu { display: none !important; }
        .qr-kartu.qr-cetak { display: block !important; border: none !important; }
    }
</style>
@endpush

@push('scripts')
<script>
function cetakKartuQr(id) {
    document.querySelectorAll('.qr-kartu').forEach(el => el.classList.remove('qr-cetak'));
    document.getElementById(id).classList.add('qr-cetak');
    window.print();
}
</script>
@endpush
@endsection
