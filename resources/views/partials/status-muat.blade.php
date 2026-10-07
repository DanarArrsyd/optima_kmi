{{-- Overlay memuat + pesan galat untuk halaman AJAX, dipakai muatDenganStatus()
     di layout. Dipasang sebagai anak pertama .erp-scroll-pad (position:relative).
     Parameter: $id (awalan id elemen), $ulang (ekspresi JS untuk tombol Coba lagi). --}}
<div class="erp-loading-overlay" id="{{ $id }}Overlay">
    <div class="spinner-border" role="status"><span class="visually-hidden">Memuat...</span></div>
</div>
<div class="erp-galat-muat" id="{{ $id }}Galat" role="alert" style="display:none">
    <i class="bi bi-exclamation-octagon-fill" aria-hidden="true"></i>
    <span>Gagal memuat data. Periksa koneksi lalu coba lagi.</span>
    <button type="button" class="erp-btn" onclick="{{ $ulang ?? 'loadData()' }}">Coba lagi</button>
</div>
