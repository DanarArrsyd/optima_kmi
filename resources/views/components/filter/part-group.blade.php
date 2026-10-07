{{-- Lihat App\View\Components\Filter\PartGroup. --}}
<div class="erp-grp">
    <b>Part &amp; Customer</b>

    @if($denganCustomer)
        <x-filter.cari :name="$customerName" :id="$idCustomer" placeholder="Nama Customer" :value="$customer"
                       :items="$dataJs()['_customersTA']" />
    @endif

    <div x-data="partGroupComp({{ Js::from($partId ?: '') }}, {{ Js::from($partLabel) }}, {{ Js::from($prosesId ?: '') }}, {{ Js::from($prosesLabel) }})"
         style="display:flex;flex-direction:column;gap:4px">

        <div @click.outside="partOpen = false" class="erp-ta">
            <input type="hidden" name="{{ $partName }}" :value="partVal" @if($idPart) id="{{ $idPart }}" @endif>
            <input type="text" x-model="partQuery" @focus="partOpen = true"
                   @input="partOpen = true; partVal = ''; prosesVal = ''; prosesQuery = ''"
                   @keydown.escape="partOpen = false" placeholder="Nama Part" aria-label="Nama Part" autocomplete="off"
                   class="erp-in" :class="partVal ? 'is-set' : ''">
            <button type="button" x-show="partVal" x-cloak aria-label="Hapus Nama Part"
                    @mousedown.prevent="partVal = ''; partQuery = ''; prosesVal = ''; prosesQuery = ''"
                    class="erp-clr">&times;</button>
            <div x-show="partOpen && filteredParts.length > 0" x-cloak class="erp-dd">
                <template x-for="item in filteredParts" :key="item.v">
                    <div @mousedown.prevent="pickPart(item)" class="erp-dd-item" :title="item.t">
                        <span style="font-weight:700" x-text="item.t"></span>
                        <span class="sub" x-text="item.sub"></span>
                    </div>
                </template>
            </div>
        </div>

        @if($denganProses)
            <div @click.outside="prosesOpen = false" class="erp-ta">
                <input type="hidden" name="{{ $prosesName }}" :value="prosesVal" @if($idProses) id="{{ $idProses }}" @endif>
                <input type="text" x-model="prosesQuery" @focus="prosesOpen = true"
                       @input="prosesOpen = true; prosesVal = ''" @keydown.escape="prosesOpen = false"
                       placeholder="{{ $labelProses }}" aria-label="{{ $labelProses }}" autocomplete="off"
                       class="erp-in" :class="prosesVal ? 'is-set' : ''">
                <button type="button" x-show="prosesVal" x-cloak aria-label="Hapus {{ $labelProses }}"
                        @mousedown.prevent="prosesVal = ''; prosesQuery = ''" class="erp-clr">&times;</button>
                <div x-show="prosesOpen && filteredProses.length > 0" x-cloak class="erp-dd">
                    <template x-for="item in filteredProses" :key="item.v">
                        <div @mousedown.prevent="prosesVal = item.v; prosesQuery = item.t; prosesOpen = false"
                             class="erp-dd-item" x-text="item.t"></div>
                    </template>
                </div>
            </div>
        @endif

        {{-- Nomor part tidak diketik: dia turunan dari Nama Part.
             Dua isian terpisah pernah bisa tidak sinkron. --}}
        <input type="text" class="erp-in" readonly tabindex="-1" aria-label="Nomor Part"
               :value="partNo || 'Nomor Part'" :title="partNo">
    </div>
</div>

@pushOnce('scripts')
<script>
/* Data kelompok Part & Customer — dipasang komponen filter part-group.
   Properti window, BUKAN const: halaman lama yang masih mendeklarasikan
   const bernama sama tidak memicu SyntaxError redeklarasi. */
@foreach($dataJs() as $nama => $isi)
window.{{ $nama }} = {{ Js::from($isi) }};
@endforeach
</script>
@endPushOnce
