{{--
    Baris Dandori berulang — dipakai form create dan laci index LHP Produksi.
    Mengikuti pola input/partials/ng-rows.

    Parameter:
      $losstimes : Collection MasterLosstime
      $karyawan  : Collection MasterKaryawan (untuk operator dandori)
      $initial   : array [['id_losstime' => int, 'actual_menit' => float], ...]
--}}
<div x-data="dandoriRows(@js($initial ?? []))" class="dandori-rows">

    {{--
        Wajib mengevaluasi binding Alpine. Kalau Alpine gagal boot, baris
        dandori[] yang hidup di dalam x-for tidak pernah dirender, dan tanpa
        hitungan ini payload lolos validasi lalu menyimpan NOL baris dandori
        tanpa satu pun error — operator mengira sudah tercatat.
    --}}
    <input type="hidden" name="dandori_count" :value="rows.length">

    <div class="mb-2">
        <label class="form-label small fw-semibold">Operator Dandori</label>
        <select class="form-select form-select-sm" name="dandori_karyawan_id">
            <option value="">— sama dengan operator LHP —</option>
            @foreach($karyawan as $k)
                <option value="{{ $k->id }}" @selected(old('dandori_karyawan_id') == $k->id)>{{ $k->nama_karyawan }}</option>
            @endforeach
        </select>
    </div>

    <template x-if="rows.length === 0">
        <div class="text-muted small mb-2">Belum ada aktivitas dandori. Klik tombol di bawah untuk menambah.</div>
    </template>

    <template x-for="(row, i) in rows" :key="row.uid">
        <div class="row g-2 mb-2 align-items-center">
            <div class="col-7">
                <select class="form-select form-select-sm"
                        :name="`dandori[${i}][id_losstime]`"
                        x-model="row.id_losstime" required>
                    <option value="">— pilih jenis loss time —</option>
                    @foreach($losstimes as $l)
                        <option value="{{ $l->id }}">{{ $l->kode }} — {{ $l->jenis_losstime }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-3">
                <input type="number" min="0.01" step="0.01"
                       class="form-control form-control-sm"
                       :name="`dandori[${i}][actual_menit]`"
                       x-model.number="row.actual_menit"
                       placeholder="Menit" required>
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
        <i class="bi bi-plus-lg"></i> Tambah aktivitas dandori
    </button>

    <div class="mt-2 small">
        Total menit: <strong x-text="total"></strong>
    </div>

    @error('dandori')<div class="text-danger mt-1" style="font-size:0.8rem">{{ $message }}</div>@enderror
    @error('dandori_count')<div class="text-danger mt-1" style="font-size:0.8rem">{{ $message }}</div>@enderror
    @error('dandori.0.id_losstime')<div class="text-danger mt-1" style="font-size:0.8rem">{{ $message }}</div>@enderror
</div>

@once
@push('scripts')
<script>
function dandoriRows(initial) {
    let seq = 0;
    return {
        rows: (initial || []).map(r => ({
            uid: seq++,
            id_losstime: String(r.id_losstime ?? ''),
            actual_menit: r.actual_menit ?? null,
        })),
        add() {
            this.rows.push({ uid: seq++, id_losstime: '', actual_menit: null });
        },
        remove(i) {
            this.rows.splice(i, 1);
        },
        get total() {
            return this.rows.reduce((s, r) => s + (Number(r.actual_menit) || 0), 0);
        },
    };
}
</script>
@endpush
@endonce
