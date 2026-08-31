{{--
    Baris NG berulang — dipakai 4 form (produksi/qc × create/edit).
    Mengikuti pola baris berulang form Dandori.

    Parameter:
      $jenisNg : Collection MasterJenisNg
      $initial : array [['id_jenis_ng' => int, 'qty' => int], ...]
--}}
<div x-data="ngRows(@js($initial ?? []))" class="ng-rows">

    {{--
        Wajib evaluasi binding Alpine. Kalau Alpine gagal boot, :value tidak
        pernah dievaluasi dan input ini mengirim string kosong — yang ditolak
        aturan `required|integer` di StoreQcBatchRequest/UpdateQcRequest (dan
        StoreProduksiBatchRequest/UpdateProduksiRequest). Tanpa ini, ng[] yang tak pernah dirender
        (hidup di dalam x-for) akan lolos validasi dan syncQc/syncProduksi
        menghapus semua detail NG + menulis qty_ng = 0 tanpa error.
    --}}
    <input type="hidden" name="ng_count" :value="rows.length">

    <template x-if="rows.length === 0">
        <div class="text-muted small mb-2">Belum ada jenis NG. Klik tombol di bawah untuk menambah.</div>
    </template>

    <template x-for="(row, i) in rows" :key="row.uid">
        <div class="row g-2 mb-2 align-items-center">
            <div class="col-7">
                <select class="form-select form-select-sm"
                        :name="`ng[${i}][id_jenis_ng]`"
                        x-model="row.id_jenis_ng" required>
                    <option value="">— pilih jenis NG —</option>
                    @foreach($jenisNg as $j)
                        <option value="{{ $j->id }}">{{ $j->kode }} — {{ $j->nama_ng }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-3">
                <input type="number" min="1" step="1"
                       class="form-control form-control-sm"
                       :name="`ng[${i}][qty]`"
                       x-model.number="row.qty"
                       placeholder="Qty" required>
            </div>
            <div class="col-2">
                <button type="button" class="btn btn-sm btn-outline-danger w-100"
                        @click="remove(i)" title="Hapus baris">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>
    </template>

    <button type="button" class="btn btn-sm btn-outline-secondary" @click="add()">
        <i class="bi bi-plus-lg"></i> Tambah jenis NG
    </button>

    <div class="mt-2 small">
        Total NG: <strong x-text="total"></strong>
    </div>

    @error('ng')<div class="text-danger mt-1" style="font-size:0.8rem">{{ $message }}</div>@enderror
    @error('ng.*.id_jenis_ng')<div class="text-danger mt-1" style="font-size:0.8rem">{{ $message }}</div>@enderror
    @error('ng.*.qty')<div class="text-danger mt-1" style="font-size:0.8rem">{{ $message }}</div>@enderror
</div>

@once
@push('scripts')
<script>
function ngRows(initial) {
    let seq = 0;
    const baris = (r = {}) => ({
        uid: seq++,
        id_jenis_ng: String(r.id_jenis_ng ?? ''),
        qty: r.qty ?? null,
    });
    return {
        rows: (initial || []).map(baris),
        add() {
            this.rows.push({ uid: seq++, id_jenis_ng: '', qty: null });
        },
        /* Dipakai sebar() (edit baris draft) — reuse konstruksi baris() yang sama
           dengan constructor, supaya bentuk baris nol pernah menyimpang antara jalur
           inisialisasi awal dan jalur restore edit. */
        setRows(list) {
            this.rows = (list || []).map(r => ({ ...baris(r), uid: `e${Date.now()}_${seq++}` }));
        },
        remove(i) {
            this.rows.splice(i, 1);
        },
        get total() {
            return this.rows.reduce((s, r) => s + (parseInt(r.qty) || 0), 0);
        },
    };
}
</script>
@endpush
@endonce
