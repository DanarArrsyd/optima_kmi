{{-- Peringatan duplikat (App\Services\PenjagaKembar). Dipasang di dalam
     komponen batchDraft(), tepat di bawah daftar "Draft ditolak server".

     Server menolak batch karena ada baris yang identik dengan data tersimpan
     (atau dengan baris lain di draft yang sama). Draft tetap utuh dan baris
     kembar sudah ditandai merah. Operator memilih: hapus baris kembar lalu
     SUBMIT DRAFT biasa (diperiksa ulang), atau kirim apa adanya lewat tombol di
     bawah — izinkan_kembar=1 melewati pemeriksaan untuk kiriman ini saja.

     Panel ikut hilang begitu draft diubah (barisGagal dikosongkan batchDraft),
     karena peringatannya tidak lagi berlaku untuk draft yang baru. --}}
@if (session('kembar_bisa_dilewati'))
    <div class="alert alert-warning py-2" x-show="barisGagal.length > 0" role="alert">
        <div class="fw-semibold mb-1">
            <i class="bi bi-exclamation-triangle me-1"></i>Kemungkinan data dobel — belum ada yang disimpan.
        </div>
        <div class="small mb-2">
            Baris merah di tabel draft sama persis dengan data yang sudah masuk.
            Hapus baris itu lalu SUBMIT DRAFT, atau simpan tetap kalau memang data berbeda.
        </div>
        <button type="submit" form="{{ $form ?? 'formBatch' }}" name="izinkan_kembar" value="1"
                class="btn btn-sm btn-outline-dark" :disabled="rows.length === 0 || editIndex !== null">
            <i class="bi bi-check2-all me-1"></i>Tetap simpan semua baris
        </button>
    </div>
@endif
