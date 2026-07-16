{{--
    Kotak note staff yang belum ditangani, di dalam form edit.

    Checkbox default TERCENTANG: kasus yang paling umum adalah admin membuka halaman ini
    justru karena note-nya, jadi default-nya mengerjakan yang diinginkan tanpa klik tambahan.
    Tetap sebuah checkbox, bukan otomatis penuh — admin bisa mengedit untuk alasan lain atau
    baru selesai separuh, dan menandai "selesai" untuk sesuatu yang tak disentuh membuat
    sinyalnya berhenti berarti bagi staff.

    Butuh: $note, $noteBy, $noteAt
--}}
<div class="mt-4 p-3" style="border:1px solid #f59e0b;background:#fffbeb;border-radius:4px">
    <div class="d-flex align-items-center gap-2 mb-2" style="color:#92400e;font-weight:600;font-size:0.85rem">
        <i class="bi bi-chat-left-text-fill"></i>
        Note dari staff
        @if ($noteBy)
            <span style="font-weight:400">— {{ $noteBy }}</span>
        @endif
        @if ($noteAt)
            <span style="font-weight:400;color:#b45309">
                {{ $noteAt->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB
            </span>
        @endif
    </div>

    <div style="white-space:pre-wrap;color:#1f2937;font-size:0.88rem">{{ $note }}</div>

    <div class="form-check mt-3">
        <input class="form-check-input" type="checkbox" value="1"
               name="resolve_note" id="resolve_note" checked>
        <label class="form-check-label" for="resolve_note" style="font-size:0.85rem">
            Tandai note ini selesai setelah disimpan
            <span class="d-block" style="color:#78716c;font-size:0.78rem">
                Hilangkan centang kalau perubahan ini belum menjawab note-nya.
            </span>
        </label>
    </div>
</div>
