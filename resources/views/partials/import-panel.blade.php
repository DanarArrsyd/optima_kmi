{{-- Panel unggah impor Excel. Dibuka tombol toolbar
     onclick="document.getElementById('{{ $id }}').classList.toggle('d-none')".
     Tetap terbuka setelah unggah supaya hasil & galatnya langsung terlihat.
     Parameter: $id, $action, $template, $judul. --}}
<div id="{{ $id }}" class="erp-panel mb-3 {{ session('import_errors') !== null || session('import_success') ? '' : 'd-none' }}">
    <div class="erp-panel-header" style="display:flex;justify-content:space-between;align-items:center">
        <span><i class="bi bi-file-earmark-arrow-up me-1" aria-hidden="true"></i>{{ $judul }}</span>
        <a href="{{ $template }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-download me-1" aria-hidden="true"></i>Download Template
        </a>
    </div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ $action }}" enctype="multipart/form-data">
            @csrf
            <div class="row g-2 align-items-end">
                <div class="col-md-7">
                    <label class="form-label mb-1" for="{{ $id }}-file">File Excel (.xlsx / .xls / .csv)</label>
                    <input type="file" id="{{ $id }}-file" name="file" class="form-control" accept=".xlsx,.xls,.csv" required>
                    @error('file')<div class="erp-err" role="alert">{{ $message }}</div>@enderror
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="bi bi-upload me-1" aria-hidden="true"></i>Upload &amp; Import
                    </button>
                </div>
            </div>
            <div class="mt-2" style="color:#6c757d">
                Isi data mulai baris 3. Baris keterangan dan baris contoh yang belum diubah otomatis dilewati.
            </div>
        </form>
    </div>
</div>
@include('partials.import-errors')
