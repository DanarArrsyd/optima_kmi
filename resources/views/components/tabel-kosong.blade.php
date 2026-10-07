{{--
    Baris "tabel kosong" baku. Tiga keadaan yang dulu dicampur jadi satu
    kalimat "Belum ada data …" — padahal cari "zzz" di Master Mesin bilang
    "Belum ada data mesin." walau datanya ada, dan pengguna mengira datanya
    hilang:
      1. filter aktif   → "Tidak ada … yang cocok dengan filter." + Reset filter
      2. periode bawaan → "Belum ada … pada periode ini." (halaman bertanggal)
      3. benar kosong   → "Belum ada …." + tautan Tambah (kalau ada)
    Filter aktif = ada parameter query selain penanda teknis.
--}}
@props(['kolom', 'entitas', 'periode' => false, 'tambah' => null])
@php
    $penanda = ['page', 'highlight_id', 'only_open_notes', 'filter_apply', 'pakai_tanggal'];
    $saring = collect(request()->except($penanda))->filter(fn ($v) => filled($v))->isNotEmpty();
@endphp
<tr class="erp-kosong">
    <td colspan="{{ $kolom }}">
        @if($saring)
            <b>Tidak ada {{ $entitas }} yang cocok dengan filter.</b>
            <a href="{{ url()->current() }}" class="erp-btn">Reset filter</a>
        @elseif($periode)
            <b>Belum ada {{ $entitas }} pada periode ini.</b>
            <span>Ubah rentang tanggal lewat tombol Filter.</span>
        @else
            <b>Belum ada {{ $entitas }}.</b>
            @if($tambah)<a href="{{ $tambah }}" class="erp-btn">Tambah</a>@endif
        @endif
    </td>
</tr>
