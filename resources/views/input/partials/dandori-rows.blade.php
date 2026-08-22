{{--
    Baris Dandori berulang — dipakai form create dan laci index LHP Produksi.

    Bentuk input, typeahead, dan pratinjau Std/Over sengaja dibuat SAMA PERSIS
    dengan halaman Dandori (`input/dandori/index.blade.php`). Operator yang
    sama mengisi keduanya; dua cara mengetik untuk data yang sama adalah beban
    yang tidak perlu.

    Parameter:
      $losstimes : Collection MasterLosstime
      $karyawan  : Collection MasterKaryawan (untuk operator dandori)
      $initial   : array [['id_losstime' => int, 'actual_menit' => float], ...]
--}}
@php
    $_ltData = $losstimes->map(fn ($l) => [
        'id' => $l->id,
        'kode' => $l->kode,
        'jenis_losstime' => $l->jenis_losstime,
        'std_menit_default' => (float) ($l->std_menit_default ?? 0),
        'std_menit_progressive' => (float) ($l->std_menit_progressive ?? 0),
    ])->values();

    $_ltLabel = $losstimes->mapWithKeys(fn ($l) => [$l->id => "{$l->kode} — {$l->jenis_losstime}"]);

    /* Baris lama dari old() dibawa lengkap dengan teksnya, supaya setelah
       validasi gagal kotak typeahead tidak tampil kosong padahal id-nya ada. */
    $_initial = collect($initial ?? [])->map(fn ($r) => [
        'id_losstime' => $r['id_losstime'] ?? '',
        'ltQuery' => $_ltLabel[$r['id_losstime'] ?? null] ?? '',
        'actual_menit' => $r['actual_menit'] ?? '',
    ])->values();

    $_oldKarId = old('dandori_karyawan_id');
    $_oldKarTxt = $_oldKarId
        ? (optional($karyawan->firstWhere('id', (int) $_oldKarId))->nama_karyawan ?? '')
        : '';
@endphp
<div x-data="dandoriRows(@js($_initial), @js($_ltData))" class="dandori-rows">

    {{--
        Wajib mengevaluasi binding Alpine. Kalau Alpine gagal boot, baris
        dandori[] yang hidup di dalam x-for tidak pernah dirender, dan tanpa
        hitungan ini payload lolos validasi lalu menyimpan NOL baris dandori
        tanpa satu pun error — operator mengira sudah tercatat.
    --}}
    <input type="hidden" name="dandori_count" :value="rows.length">

    <div class="mb-2" x-data="taField(taKaryawan, '{{ $_oldKarTxt }}', {{ $_oldKarId ?: 'null' }})">
        <label class="form-label small fw-semibold mb-1">Operator Dandori</label>
        <div class="ta-wrap">
            <input type="text"
                   class="form-control form-control-sm ta-input"
                   x-model="query"
                   @focus="openDd()"
                   @blur="closeDd()"
                   @keydown="onKey($event)"
                   @input="onInput()"
                   placeholder="Kosongkan = sama dengan operator LHP">
            <input type="hidden" name="dandori_karyawan_id" :value="val">
            <div class="ta-dropdown" x-show="open" x-cloak>
                <template x-if="filtered.length === 0">
                    <div class="ta-empty">Tidak ditemukan</div>
                </template>
                <template x-for="(item, i) in filtered" :key="item.v">
                    <div class="ta-item" :class="{ 'ta-active': i === idx }"
                         @mousedown.prevent="pick(item)" x-text="item.t"></div>
                </template>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-1">
        <span class="small text-muted" x-show="progressive" x-cloak>
            Proses <strong>PROGRESSIVE</strong> — standar waktu pakai <code>std_menit_progressive</code>.
        </span>
        <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" @click="add()">
            <i class="bi bi-plus-lg me-1"></i>Tambah Baris
        </button>
    </div>

    <div class="table-responsive mb-2">
        <table class="table table-bordered table-sm align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Jenis Loss Time</th>
                    <th style="width:110px">Aktual (mnt) <span class="text-danger">*</span></th>
                    <th style="width:85px;text-align:center">Std (mnt)</th>
                    <th style="width:85px;text-align:center">Over (mnt)</th>
                    <th style="width:36px"></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="(row, i) in rows" :key="row.uid">
                    <tr>
                        <td>
                            <div class="ta-wrap">
                                <input type="text"
                                       class="form-control form-control-sm ta-input"
                                       x-model="row.ltQuery"
                                       @focus="row.ltOpen = true; row.ltIdx = -1"
                                       @blur="ltClose(row)"
                                       @keydown="ltKey(row, $event)"
                                       @input="row.id_losstime = ''; row.ltOpen = true; row.ltIdx = -1; recompute(row)"
                                       placeholder="Ketik kode atau jenis...">
                                <input type="hidden"
                                       :name="`dandori[${i}][id_losstime]`"
                                       :value="row.id_losstime">
                                <div class="ta-dropdown" x-show="row.ltOpen" x-cloak>
                                    <template x-if="ltFiltered(row).length === 0">
                                        <div class="ta-empty">Tidak ditemukan</div>
                                    </template>
                                    <template x-for="(item, j) in ltFiltered(row)" :key="item.v">
                                        <div class="ta-item"
                                             :class="{ 'ta-active': j === row.ltIdx }"
                                             @mousedown.prevent="ltPick(row, item)"
                                             x-text="item.t"></div>
                                    </template>
                                </div>
                            </div>
                        </td>
                        <td>
                            <input type="number"
                                   :name="`dandori[${i}][actual_menit]`"
                                   class="form-control form-control-sm"
                                   x-model.number="row.actual_menit"
                                   @input="recompute(row)"
                                   step="0.1" min="0.1"
                                   placeholder="0.0" required>
                        </td>
                        <td class="text-center text-muted"
                            x-text="row.std_menit > 0 ? row.std_menit.toFixed(1) : '—'"></td>
                        <td class="text-center fw-semibold"
                            :class="row.over_menit > 0 ? 'text-danger' : (row.std_menit > 0 ? 'text-success' : 'text-muted')"
                            x-text="row.std_menit > 0
                                ? (row.over_menit > 0 ? '+' + row.over_menit.toFixed(1) : 'OK')
                                : '—'"></td>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm btn-outline-danger"
                                    @click="remove(i)" title="Hapus baris">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <div class="small">
        Total aktual: <strong x-text="totalActual.toFixed(1)"></strong> mnt ·
        Total over: <strong :class="totalOver > 0 ? 'text-danger' : 'text-success'"
                            x-text="totalOver.toFixed(1)"></strong> mnt
    </div>

    @error('dandori')<div class="text-danger mt-1" style="font-size:0.8rem">{{ $message }}</div>@enderror
    @error('dandori_count')<div class="text-danger mt-1" style="font-size:0.8rem">{{ $message }}</div>@enderror
    @error('dandori.0.id_losstime')<div class="text-danger mt-1" style="font-size:0.8rem">{{ $message }}</div>@enderror
</div>
<!-- /dandori-rows -->

@once
@push('scripts')
<script>
/*
 * Dipanggil saat centang "Ada Dandori" berubah. Melepas centang MENGOSONGKAN
 * baris, bukan sekadar menyembunyikannya, supaya dandori_count selalu sama
 * dengan dandori[] yang terkirim. Menyalakan centang menyiapkan satu baris —
 * sama seperti halaman Dandori yang selalu membuka dengan satu baris siap isi.
 */
function dandoriSync(el, aktif) {
    const wrap = el.closest('[x-data]').querySelector('.dandori-rows');
    if (!wrap) return;

    const data = Alpine.$data(wrap);
    if (aktif) {
        if (data.rows.length === 0) data.add();
    } else {
        data.rows = [];
    }
}

function dandoriRows(initial, ltData) {
    let seq = 0;
    const baris = (r = {}) => ({
        uid: seq++,
        id_losstime: r.id_losstime ?? '',
        ltQuery: r.ltQuery ?? '',
        ltOpen: false,
        ltIdx: -1,
        actual_menit: r.actual_menit ?? '',
        std_menit: 0,
        over_menit: 0,
    });

    return {
        ltData,
        ltItems: ltData.map(lt => ({ v: lt.id, t: `${lt.kode} — ${lt.jenis_losstime}` })),
        rows: (initial || []).map(baris),
        progressive: false,

        init() {
            /*
             * Std/Over bergantung pada proses yang dipilih di form LHP, dan
             * pemilih proses itu hidup di komponen Alpine lain. Yang dibaca di
             * sini penanda `data-progressive` pada <option>-nya — bukan teks
             * pilihannya, yang formatnya bisa berubah kapan saja.
             */
            const form = this.$el.closest('form');
            this.prosesEl = form ? form.querySelector('[name="id_part_proses"]') : null;

            /* Ganti part mengosongkan proses tanpa memancarkan change pada
               select-nya, jadi yang didengar perubahan apa pun di form. */
            if (form) form.addEventListener('change', () => this.syncProgressive());

            this.syncProgressive();
        },

        syncProgressive() {
            const opt = this.prosesEl ? this.prosesEl.selectedOptions[0] : null;
            this.progressive = !!(opt && opt.dataset.progressive === '1');
            this.rows.forEach(r => this.recompute(r));
        },

        ltFiltered(row) {
            if (!row.ltQuery.trim()) return this.ltItems.slice(0, 60);
            const q = row.ltQuery.toLowerCase();
            return this.ltItems.filter(i => i.t.toLowerCase().includes(q)).slice(0, 60);
        },

        ltPick(row, item) {
            row.ltQuery = item.t;
            row.id_losstime = item.v;
            row.ltOpen = false;
            row.ltIdx = -1;
            this.recompute(row);
        },

        ltClose(row) {
            setTimeout(() => {
                row.ltOpen = false;
                if (row.id_losstime && !this.ltItems.find(i => i.v == row.id_losstime && i.t === row.ltQuery)) {
                    row.id_losstime = '';
                    row.ltQuery = '';
                    this.recompute(row);
                }
            }, 180);
        },

        ltKey(row, e) {
            const f = this.ltFiltered(row);
            if (e.key === 'ArrowDown') {
                e.preventDefault(); row.ltOpen = true;
                row.ltIdx = Math.min(row.ltIdx + 1, f.length - 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                row.ltIdx = Math.max(row.ltIdx - 1, 0);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (row.ltIdx >= 0 && f[row.ltIdx]) this.ltPick(row, f[row.ltIdx]);
            } else if (e.key === 'Escape') {
                row.ltOpen = false;
            }
        },

        add() { this.rows.push(baris()); },

        /* Baris terakhir dipertahankan, sama seperti halaman Dandori. Blok ini
           hanya muncul saat centangnya menyala, dan melepas centang yang
           mengosongkan seluruh baris lewat dandoriSync(). */
        remove(i) { if (this.rows.length > 1) this.rows.splice(i, 1); },

        /*
         * Pratinjau saja. Angka yang disimpan tetap dihitung ulang di server
         * oleh DandoriRincianService — satu definisi std_menit/over_menit.
         */
        recompute(row) {
            const lt = this.ltData.find(l => l.id == row.id_losstime);
            if (!lt) { row.std_menit = 0; row.over_menit = 0; return; }

            row.std_menit = this.progressive
                ? (lt.std_menit_progressive || lt.std_menit_default || 0)
                : (lt.std_menit_default || 0);

            row.over_menit = Math.max(0, (parseFloat(row.actual_menit) || 0) - row.std_menit);
        },

        get totalActual() {
            return this.rows.reduce((s, r) => s + (parseFloat(r.actual_menit) || 0), 0);
        },
        get totalOver() {
            return this.rows.reduce((s, r) => s + (r.over_menit || 0), 0);
        },
    };
}
</script>
@endpush
@endonce
