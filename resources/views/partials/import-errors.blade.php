{{-- Daftar baris yang gagal diimpor (session import_errors). Satu markup untuk
     semua halaman berimpor — tanpa ini, galat per baris hilang tanpa jejak. --}}
@if(session('import_errors') && count(session('import_errors')) > 0)
    <div class="alert alert-warning alert-dismissible fade show mb-3" role="alert">
        <div class="d-flex align-items-start gap-2">
            <i class="bi bi-exclamation-triangle-fill fs-5 mt-1 flex-shrink-0" aria-hidden="true"></i>
            <div style="width:100%">
                <strong>{{ count(session('import_errors')) }} baris gagal diimpor:</strong>
                <div style="max-height:180px;overflow-y:auto;margin-top:6px">
                    <ul class="mb-0">
                        @foreach(session('import_errors') as $err)<li>{{ $err }}</li>@endforeach
                    </ul>
                </div>
            </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
    </div>
@endif
