<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Menyelesaikan staff note dari dalam aksi update, supaya admin tak perlu menyimpan lalu
 * kembali ke index cuma untuk menekan "Tandai Selesai".
 *
 * Dikendalikan checkbox, bukan otomatis penuh. "Admin mengedit baris ini" tidak sama dengan
 * "admin mengerjakan yang diminta note" — admin bisa mengedit untuk alasan lain, atau baru
 * selesai separuh. Kalau resolve ikut otomatis, staff menerima notifikasi "selesai" untuk
 * sesuatu yang belum tentu disentuh, dan sinyalnya berhenti berarti. Checkbox default
 * tercentang (kasus paling umum) tapi bisa dibatalkan.
 *
 * Dipakai controller Input yang punya properti NotificationService $notifSvc.
 */
trait ResolvesStaffNote
{
    /**
     * @param  object  $record  Punya kolom staff_note* (transaksi produksi/qc/dandori).
     * @param  string  $tanggal  Y-m-d, untuk deep-link di notifikasi.
     */
    protected function resolveStaffNoteIfRequested(
        Request $request,
        object $record,
        string $notifiableType,
        string $tanggal,
    ): void {
        if (! $request->boolean('resolve_note')) {
            return;
        }

        // Tak ada note, atau sudah diselesaikan orang lain: jangan stempel ulang — penyelesai
        // & waktu aslinya adalah catatan siapa yang benar-benar menangani.
        if (! $record->staff_note || $record->staff_note_resolved_at) {
            return;
        }

        // Kolom staff_note_resolved_* pernah belum ada di DB hosting — pola defensif yang
        // sama dipakai resolveNote(). Notifikasi hanya dikirim kalau resolve-nya BENAR-BENAR
        // tersimpan; memberi tahu staff "sudah selesai" padahal update gagal itu justru
        // kebohongan senyap.
        try {
            $record->update([
                'staff_note_resolved_at' => now(),
                'staff_note_resolved_by' => auth()->id(),
            ]);
            $record->refresh();
        } catch (\Throwable) {
            return;
        }

        try {
            $this->notifSvc->insertNoteResolved($record, $notifiableType, $tanggal);
        } catch (\Throwable) {
            // Gagal notifikasi tak boleh menggagalkan simpan — note-nya sudah ter-resolve.
        }
    }
}
