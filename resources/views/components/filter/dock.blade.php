{{--
    Dock filter baku OPTIMA — SATU-SATUNYA cangkang dock. Acuannya LHP Produksi.

    Susunan isi (lihat memori konvensi dock): Find → Part & Customer →
    Filter by Date → tombol Tampilkan + Reset. Isinya diisi lewat slot pakai
    x-filter.grup, x-filter.cari, x-filter.part-group, x-filter.mesin,
    x-filter.tanggal.

    Dua mode, tampilan identik:
      - :action terisi  → form GET biasa (halaman daftar). Membawa penanda
        filter_apply supaya centang tanggal yang dilepas bisa dibedakan dari
        "halaman baru dibuka".
      - :action null    → form tanpa action; JS halaman menangkap submit lalu
        memuat data lewat fetch (halaman analitik/VCB).

    Reset SELALU tautan ke URL halaman tanpa query: semua kontrol kembali ke
    bawaan server, dan halaman AJAX memuat ulang datanya sendiri saat dibuka.

    Membuka/menutup dock memakai `filterOpen` dari scope Alpine induk
    (<div x-data="{ filterOpen: false }"> pembungkus .erp-ws).
--}}
@props([
    'action' => null,
    'formId' => 'filterForm',
    'show' => 'filterOpen',
    'tombol' => 'Tampilkan',
    'reset' => true,
])
<aside class="erp-dock" x-show="{{ $show }}" x-cloak>
    <div class="erp-dock-hd">
        <span>Filter</span>
        <button type="button" class="erp-dock-x" @click="filterOpen = false" title="Tutup filter" aria-label="Tutup filter">&times;</button>
    </div>

    {{-- Isian kosong dimatikan sesaat sebelum kirim supaya URL hasil filter
         tetap pendek dan bisa dibagikan (tanpa deretan "param=&"). Ditandai
         data-kosong lalu dihidupkan lagi di pageshow: tombol Back memulihkan
         halaman dari bfcache dengan isian masih mati. --}}
    <form id="{{ $formId }}" @if($action) method="GET" action="{{ $action }}"
          @submit="$el.querySelectorAll('input[name], select[name]').forEach(i => { if (i.value === '' && i.type !== 'checkbox' && ! i.disabled) { i.disabled = true; i.dataset.kosong = '1' } })"
          @pageshow.window="$el.querySelectorAll('[data-kosong]').forEach(i => { i.disabled = false; delete i.dataset.kosong })" @endif
          {{ $attributes->merge(['class' => 'erp-dock-body']) }}>
        {{ $slot }}

        <div class="erp-grp">
            @if($action)
                <input type="hidden" name="filter_apply" value="1">
            @endif
            <button type="submit" class="erp-btn is-pri"><i class="bi bi-funnel"></i>{{ $tombol }}</button>
            @if($reset)
                <a href="{{ url()->current() }}" class="erp-btn">Reset</a>
            @endif
        </div>
    </form>
</aside>
