@extends('layouts.app')
@section('title', 'Edit Dandori')

@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-pencil-square"></i>Edit Dandori</h1>
    <a href="{{ route('input.dandori.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if($errors->any())
<div class="alert alert-danger">
    <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<div class="erp-panel" style="max-width:700px">
    <div class="erp-panel-header">
        Form Edit Dandori
        <span style="font-size:0.75rem;font-weight:400;color:#9ca3af;margin-left:8px">
            ID #{{ $dandori->id }}
        </span>
    </div>

    <form method="POST" action="{{ route('input.dandori.update', $dandori) }}" style="padding:24px">
        @csrf @method('PUT')
        {{-- Dibawa dari query string link Edit di index — dipakai controller
             buat balik ke rentang tanggal/filter yang sama setelah simpan,
             bukan reset ke default bulan berjalan. --}}
        <input type="hidden" name="back_query" value="{{ request()->query('back_query', '') }}">

        <div class="row g-3">

            {{-- Tanggal --}}
            <div class="col-6">
                <label class="form-label" style="font-size:0.92rem">Tanggal <span class="text-danger">*</span></label>
                <input type="date" name="tanggal" class="form-control @error('tanggal') is-invalid @enderror"
                       value="{{ old('tanggal', $dandori->tanggal?->format('Y-m-d')) }}" required>
                @error('tanggal')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            {{-- Shift --}}
            <div class="col-6">
                <label class="form-label" style="font-size:0.92rem">Shift <span class="text-danger">*</span></label>
                <select name="id_shift" class="form-select @error('id_shift') is-invalid @enderror" required>
                    <option value="">— Pilih Shift —</option>
                    @foreach($shifts as $s)
                    <option value="{{ $s->id }}" {{ old('id_shift', $dandori->id_shift) == $s->id ? 'selected' : '' }}>
                        {{ $s->nama_shift }} ({{ $s->jam_start }}–{{ $s->jam_finish }})
                    </option>
                    @endforeach
                </select>
                @error('id_shift')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            {{-- Operator --}}
            <div class="col-6">
                <label class="form-label" style="font-size:0.92rem">Operator <span class="text-danger">*</span></label>
                <select name="id_karyawan" class="form-select @error('id_karyawan') is-invalid @enderror" required>
                    <option value="">— Pilih Operator —</option>
                    @foreach($karyawan as $k)
                    <option value="{{ $k->id }}" {{ old('id_karyawan', $dandori->id_karyawan) == $k->id ? 'selected' : '' }}>
                        {{ $k->nama_karyawan }}
                    </option>
                    @endforeach
                </select>
                @error('id_karyawan')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            {{-- Mesin --}}
            <div class="col-6">
                <label class="form-label" style="font-size:0.92rem">Mesin <span class="text-danger">*</span></label>
                <select name="id_mesin" class="form-select @error('id_mesin') is-invalid @enderror" required>
                    <option value="">— Pilih Mesin —</option>
                    @foreach($mesin as $m)
                    <option value="{{ $m->id }}" {{ old('id_mesin', $dandori->id_mesin) == $m->id ? 'selected' : '' }}>
                        {{ $m->id_mesin }} — {{ $m->nama_mesin }} (Line {{ $m->line }})
                    </option>
                    @endforeach
                </select>
                @error('id_mesin')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            {{-- Part & Proses --}}
            <div class="col-6">
                <label class="form-label" style="font-size:0.92rem">Part <span class="text-danger">*</span></label>
                <select id="editPartSelect" class="form-select" onchange="loadProses(this.value)">
                    <option value="">— Pilih Part —</option>
                    @foreach($parts as $p)
                    <option value="{{ $p->id }}"
                            {{ $dandori->partProses?->id_part == $p->id ? 'selected' : '' }}
                            data-proses='@json($p->proses->map(fn($pr) => ["id"=>$pr->id,"label"=>"{$pr->id_proses} — {$pr->nama_proses}"]))'>
                        {{ $p->part_no }} — {{ $p->part_name }}
                    </option>
                    @endforeach
                </select>
            </div>

            <div class="col-6">
                <label class="form-label" style="font-size:0.92rem">Proses <span class="text-danger">*</span></label>
                <select name="id_part_proses" id="editProsesSelect"
                        class="form-select @error('id_part_proses') is-invalid @enderror" required>
                    <option value="">— Pilih Proses —</option>
                    @if($dandori->partProses)
                    @foreach($dandori->partProses->part?->proses ?? [] as $pr)
                    <option value="{{ $pr->id }}" {{ $dandori->id_part_proses == $pr->id ? 'selected' : '' }}>
                        {{ $pr->id_proses }} — {{ $pr->nama_proses }}
                    </option>
                    @endforeach
                    @endif
                </select>
                @error('id_part_proses')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            {{-- Loss Time --}}
            <div class="col-6">
                <label class="form-label" style="font-size:0.92rem">Loss Time <span class="text-danger">*</span></label>
                <select name="id_losstime" class="form-select @error('id_losstime') is-invalid @enderror" required>
                    <option value="">— Pilih Loss Time —</option>
                    @foreach($losstimes as $lt)
                    <option value="{{ $lt->id }}" {{ old('id_losstime', $dandori->id_losstime) == $lt->id ? 'selected' : '' }}>
                        {{ $lt->kode }} — {{ $lt->jenis_losstime }}
                    </option>
                    @endforeach
                </select>
                @error('id_losstime')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            {{-- Actual Menit --}}
            <div class="col-6">
                <label class="form-label" style="font-size:0.92rem">Actual Menit <span class="text-danger">*</span></label>
                <input type="number" name="actual_menit" step="0.01" min="0"
                       class="form-control @error('actual_menit') is-invalid @enderror"
                       value="{{ old('actual_menit', $dandori->actual_menit) }}" required>
                @error('actual_menit')<div class="invalid-feedback">{{ $message }}</div>@enderror
                @if($dandori->std_menit > 0)
                <div style="font-size:0.78rem;color:#6b7280;margin-top:4px">
                    Std: {{ number_format($dandori->std_menit, 1) }} mnt ·
                    Over sekarang: {{ number_format($dandori->over_menit, 1) }} mnt
                </div>
                @endif
            </div>

        </div>

        @if ($dandori->staff_note && !$dandori->staff_note_resolved_at)
            @include('input.partials.note-resolve-box', [
                'note'   => $dandori->staff_note,
                'noteBy' => $dandori->noteBy?->name,
                'noteAt' => $dandori->staff_note_at,
            ])
        @endif

        <hr style="margin:24px 0 20px">
        <div style="display:flex;gap:10px">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg me-1"></i>Simpan Perubahan
            </button>
            <a href="{{ route('input.dandori.index') }}" class="btn btn-outline-secondary">Batal</a>
        </div>
    </form>
</div>

<script>
/* Cegah stored XSS: label proses berasal dari data master. */
function escHtml(str) {
    if (str === null || str === undefined) return "";
    return String(str).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#39;" }[c]));
}
function loadProses(partId) {
    const sel = document.getElementById('editPartSelect');
    const opt = sel.options[sel.selectedIndex];
    const prosesData = opt ? JSON.parse(opt.dataset.proses || '[]') : [];
    const proseSel = document.getElementById('editProsesSelect');
    proseSel.innerHTML = '<option value="">— Pilih Proses —</option>';
    prosesData.forEach(p => {
        // p.label berasal dari master_part_proses — escape agar markup tak dieksekusi.
        proseSel.innerHTML += `<option value="${Number(p.id)}">${escHtml(p.label)}</option>`;
    });
}
</script>
@endsection
