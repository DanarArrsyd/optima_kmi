{{-- Lihat App\View\Components\Filter\Mesin. --}}
<div x-data="mesinFilterComp(window._mesinForJs, {{ Js::from($terpilih) }})" @click.outside="mesinOpen = false"
     class="erp-ta">
    <template x-for="id in selected" :key="id">
        <input type="hidden" name="{{ $name }}" :value="id">
    </template>

    <button type="button" @click="mesinOpen = !mesinOpen" class="erp-in is-mesin"
            :class="selected.length > 0 ? 'is-set' : ''" :aria-expanded="mesinOpen ? 'true' : 'false'"
            aria-label="Pilih mesin" style="text-align:left;cursor:pointer;display:flex;align-items:center;gap:5px">
        <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <span x-show="selected.length === 0">Semua Mesin</span>
            <span x-show="selected.length === 1" x-text="labelSingle"></span>
            <span x-show="selected.length > 1"><span x-text="selected.length"></span> mesin</span>
        </span>
        <i class="bi" :class="mesinOpen ? 'bi-chevron-up' : 'bi-chevron-down'" style="font-size:.6rem;opacity:.5"></i>
    </button>

    <div x-show="mesinOpen" x-cloak class="erp-mesin-pick">
        @foreach($mesin->groupBy('line') as $line => $grup)
            <div class="erp-mesin-line">Line {{ $line }}</div>
            <div class="erp-mesin-chips">
                @foreach($grup as $m)
                    <button type="button" @click="toggle({{ $m->id }})"
                            class="erp-chip" :class="isSelected({{ $m->id }}) ? 'is-on' : ''"
                            :aria-pressed="isSelected({{ $m->id }}) ? 'true' : 'false'"
                            title="{{ $m->id_mesin }} &mdash; {{ $m->nama_mesin }}">
                        <span class="kode">{{ $m->id_mesin }}</span>
                        <span class="nama">{{ $m->nama_mesin }}</span>
                        <i class="bi bi-check-lg tik" aria-hidden="true"></i>
                    </button>
                @endforeach
            </div>
        @endforeach
        <button type="button" x-show="selected.length > 0" @click="clearAll()"
                class="erp-mesin-clear">&times; Hapus semua pilihan</button>
    </div>
</div>

@pushOnce('scripts')
<script>
/* Dipasang komponen filter mesin. Properti window, bukan const — lihat part-group. */
window._mesinForJs = {{ Js::from($mesin->map(fn ($m) => ['id' => $m->id, 'code' => $m->id_mesin, 'name' => $m->nama_mesin, 'line' => $m->line])->values()) }};
</script>
@endPushOnce
