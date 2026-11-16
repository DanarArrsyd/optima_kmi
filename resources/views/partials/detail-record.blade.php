{{--
    Halaman "View Detail" satu baris — dipakai LHP Produksi, LHP Quality,
    Breakdown Mesin, Breakdown Dies. Murni server-side, nol Alpine/JS: tidak
    ada yang bisa mati diam-diam di browser.

    Variabel:
      $judul    string  judul halaman
      $ikon     string  kelas Bootstrap Icons (mis. bi-hammer)
      $kembali  string  URL kembali ke daftar (filter daftar ikut dibawa)
      $editUrl  ?string URL Edit — isi HANYA untuk role yang boleh edit
      $bagian   array   [ 'Nama Bagian' => [ [label, nilai, panjang?, url?], ... ], ... ]

    Nilai kosong/null tampil sebagai tanda pisah. Kunci 'panjang' = teks
    panjang, ditampilkan selebar panel tanpa dipotong dan tetap menjaga
    baris baru dari input.
--}}
@push('styles')
<style>
.detail-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px 20px;margin:0}
.detail-item{min-width:0}
.detail-item.is-panjang{grid-column:1/-1}
.detail-item dt{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--text-3,#6b7280);margin-bottom:2px}
.detail-item dd{margin:0;font-size:14px;color:var(--text,#111827);overflow-wrap:anywhere}
.detail-item.is-panjang dd{white-space:pre-wrap;line-height:1.5;border:1px solid var(--border,#e5e7eb);background:var(--surface-2,#f9fafb);padding:8px 10px;border-radius:2px}
.detail-item dd .detail-kosong{color:var(--text-4,#9ca3af)}
</style>
@endpush

<div class="page-header">
    <h1 class="page-title"><i class="bi {{ $ikon }}"></i>{{ $judul }}</h1>
    <div class="action-btns">
        @if(! empty($editUrl))
        <a href="{{ $editUrl }}" class="btn btn-warning btn-sm">Edit</a>
        @endif
        <a href="{{ $kembali }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Kembali
        </a>
    </div>
</div>

@foreach($bagian as $namaBagian => $baris)
<div class="erp-panel">
    <div class="erp-panel-header">{{ $namaBagian }}</div>
    <div class="erp-panel-body">
        <dl class="detail-grid">
            @foreach($baris as $item)
            @php
                $nilai = $item['nilai'] ?? null;
                $kosong = $nilai === null || (is_string($nilai) && trim($nilai) === '');
            @endphp
            <div class="detail-item {{ ! empty($item['panjang']) ? 'is-panjang' : '' }}">
                <dt>{{ $item['label'] }}</dt>
                <dd>@if($kosong)<span class="detail-kosong">&mdash;</span>@elseif(! empty($item['url']))<a href="{{ $item['url'] }}" target="_blank" rel="noopener">{{ $nilai }}</a>@else{{ $nilai }}@endif</dd>
            </div>
            @endforeach
        </dl>
    </div>
</div>
@endforeach
