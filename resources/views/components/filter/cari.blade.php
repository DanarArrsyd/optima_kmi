{{--
    Kotak ketik-cari satu pilihan di dock filter (filterSearchComp di layout).

    :items  daftar [['v' => nilai, 't' => teks, 'sub' => opsional], ...] dari PHP.
            Dioper lewat Js::from — aman di atribut HTML (kutip di-hex-escape).
            JANGAN ganti ke @json: kutipnya bentrok dengan atribut x-data dan
            Alpine mati diam-diam (lihat memori komponen JS bersama).
    :value  nilai terpilih saat ini (dari request). Label dicari dari :items.
    id      opsional — dipasang di input hidden pembawa nilai, untuk halaman
            AJAX yang membaca nilainya lewat getElementById.
--}}
@props(['name', 'items' => [], 'value' => null, 'placeholder' => '', 'id' => null])
@php
    $items = collect($items)->values();
    $terpilih = ($value === null || $value === '' || $value === 0 || $value === '0') ? null : $value;
    $label = $terpilih !== null ? (string) ($items->first(fn ($i) => (string) $i['v'] === (string) $terpilih)['t'] ?? '') : '';
@endphp
<div x-data="filterSearchComp({{ Js::from($items) }}, {{ Js::from($terpilih) }}, {{ Js::from($label) }})"
     @click.outside="open = false" class="erp-ta">
    <input type="hidden" name="{{ $name }}" :value="val" @if($id) id="{{ $id }}" @endif>
    <input type="text" x-model="query" @focus="open = true" @input="onInput()" @keydown="onKey($event)"
           placeholder="{{ $placeholder }}" aria-label="{{ $placeholder }}" autocomplete="off"
           role="combobox" :aria-expanded="open ? 'true' : 'false'"
           class="erp-in" :class="val ? 'is-set' : ''">
    <button type="button" x-show="val" x-cloak @mousedown.prevent="clear()" class="erp-clr"
            aria-label="Hapus {{ $placeholder }}">&times;</button>
    <div x-show="open && filtered.length > 0" x-cloak class="erp-dd" role="listbox">
        <template x-for="(item, i) in filtered" :key="item.v">
            <div @mousedown.prevent="pick(item)" class="erp-dd-item" :class="i === idx ? 'is-act' : ''"
                 role="option" :aria-selected="item.v == val ? 'true' : 'false'">
                <span x-text="item.t"></span>
                <span class="sub" x-show="item.sub" x-text="item.sub"></span>
            </div>
        </template>
    </div>
</div>
