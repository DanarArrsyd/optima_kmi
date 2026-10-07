{{-- Satu kelompok di dock filter: judul tebal kecil + kontrol di bawahnya. --}}
@props(['judul'])
<div {{ $attributes->merge(['class' => 'erp-grp']) }}>
    <b>{{ $judul }}</b>
    {{ $slot }}
</div>
