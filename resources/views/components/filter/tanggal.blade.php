{{--
    Kelompok "Filter by Date" baku: From / To + pintasan Bulan.

    :pakai  bool  → halaman DAFTAR: centang yang mematikan rentang tanggal
                    (name="pakai_tanggal", berpasangan dengan filter_apply di
                    x-filter.dock). Tanpa centang tidak ada cara mencari
                    lintas seluruh riwayat.
            null  → halaman ANALITIK: tanpa centang — grafik seluruh riwayat
                    berat dan tidak bermakna, rentang selalu wajib.

    Pintasan Bulan MENGISI From/To (awal bulan s.d. akhir bulan atau hari ini,
    mana yang lebih dulu) lalu langsung mengirim form — perilaku dropdown Bulan
    lama di halaman analitik. :kirim="false" untuk form yang punya isian wajib
    lain (VCB Mode 2: operator). Ia tidak mengirim parameter sendiri. Parameter 'bulan' lama diam-diam menang atas tanggal
    yang diketik manual — dua sumber kebenaran untuk satu rentang.

    idFrom/idTo dipertahankan untuk halaman AJAX yang membaca nilainya
    lewat getElementById. Atribut value dari server WAJIB ada di samping
    x-model: pemuat data halaman AJAX jalan sebelum Alpine hidup.
--}}
@props([
    'from' => null,
    'to' => null,
    'pakai' => null,
    'idFrom' => 'date_from',
    'idTo' => 'date_to',
    'nameFrom' => 'date_from',
    'nameTo' => 'date_to',
    'bulan' => true,
    'kirim' => true,
])
@php $adaCentang = $pakai !== null; @endphp
<div class="erp-grp"
     x-data="{
        pakaiTgl: {{ Js::from($adaCentang ? (bool) $pakai : true) }},
        dari: {{ Js::from((string) $from) }},
        sampai: {{ Js::from((string) $to) }},
        bulan: '',
        isiBulan() {
            if (! this.bulan) return;
            const [y, m] = this.bulan.split('-').map(Number);
            const akhir = `${this.bulan}-${String(new Date(y, m, 0).getDate()).padStart(2, '0')}`;
            const t = new Date();
            const hariIni = `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}-${String(t.getDate()).padStart(2, '0')}`;
            this.dari = `${this.bulan}-01`;
            this.sampai = akhir > hariIni ? hariIni : akhir;
            this.pakaiTgl = true;
            this.$nextTick(() => {
                this.$root.querySelectorAll('input[type=date]').forEach(
                    el => el.dispatchEvent(new Event('change', { bubbles: true })));
                if ({{ Js::from((bool) $kirim) }}) this.$root.closest('form')?.requestSubmit();
            });
        },
     }">
    @if($adaCentang)
        <label class="erp-chk">
            <input type="checkbox" name="pakai_tanggal" value="1" x-model="pakaiTgl">
            <b>Filter by Date</b>
        </label>
    @else
        <b>Filter by Date</b>
    @endif
    <div class="erp-dock-row">
        <span>From</span>
        <input type="date" name="{{ $nameFrom }}" id="{{ $idFrom }}" value="{{ $from }}" x-model="dari" class="erp-in"
               :disabled="!pakaiTgl" aria-label="Tanggal dari">
    </div>
    <div class="erp-dock-row">
        <span>To</span>
        <input type="date" name="{{ $nameTo }}" id="{{ $idTo }}" value="{{ $to }}" x-model="sampai" :min="dari" class="erp-in"
               :disabled="!pakaiTgl" aria-label="Tanggal sampai">
    </div>
    @error($nameFrom)<div class="erp-err" role="alert">{{ $message }}</div>@enderror
    @error($nameTo)<div class="erp-err" role="alert">{{ $message }}</div>@enderror
    @if($bulan)
        <div class="erp-dock-row" title="Isi From/To dengan satu bulan penuh">
            <span>Bulan</span>
            <input type="month" x-model="bulan" @change="isiBulan()" class="erp-in" :disabled="!pakaiTgl"
                   aria-label="Pintasan satu bulan penuh">
        </div>
    @endif
</div>
