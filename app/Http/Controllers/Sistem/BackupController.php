<?php

namespace App\Http\Controllers\Sistem;

use App\Exceptions\BackupSedangBerjalanException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class BackupController extends Controller
{
    // Nama berkas dari URL tidak pernah dipercaya — direktori di atasnya memuat
    // .env server. Polanya dipakai bersama BackupService::hapus(); satu definisi
    // saja supaya keduanya tidak bisa menyimpang.

    public function __construct(private BackupService $backup) {}

    public function index()
    {
        $daftar = $this->backup->daftar();

        $idPengguna = array_filter(array_unique(array_column($daftar, 'dipicu_oleh')));
        $nama = $idPengguna === [] ? collect() : User::whereIn('id', $idPengguna)->pluck('name', 'id');

        foreach ($daftar as &$b) {
            // Em dash untuk jadwal terjadwal (dipicu_oleh null) DAN untuk id
            // yang sudah tak lagi resolve (akun terhapus) — dua kasus, satu
            // tampilan, karena pengguna halaman tidak perlu membedakannya.
            $b['dipicu_oleh_nama'] = $b['dipicu_oleh'] ? ($nama[$b['dipicu_oleh']] ?? null) : null;
        }
        unset($b);

        return view('sistem.backup.index', [
            'daftar' => $daftar,
            'umurJam' => $this->backup->umurJamTerakhir(),
        ]);
    }

    public function jalankan()
    {
        // Jalur manual ini berjalan SINKRON di dalam request web, bukan lewat
        // artisan seperti jadwal terjadwal. Tanpa dua baris ini, PHP-FPM/LSAPI
        // di shared hosting mematikan request pada batas waktunya sendiri
        // (jauh sebelum timeout 600s mysqldump di MysqldumpPembuatDump) begitu
        // admin menutup tab atau koneksinya lambat — dan blok `finally` yang
        // melepas lock 'backup-berjalan' di BackupService::jalankan() TIDAK
        // PERNAH sampai jalan karena prosesnya dibunuh dari luar PHP. Lock itu
        // lalu bertahan sampai TTL_LOCK penuh (1800 detik) dan menolak jadwal
        // terjadwal 23:00 WIB malam itu dengan BackupSedangBerjalanException.
        // Kalau ini tetap kejadian, `backup:buka-kunci` adalah klep daruratnya.
        set_time_limit(0);
        ignore_user_abort(true);

        try {
            $nama = $this->backup->jalankan('manual', auth()->id());

            return back()->with('success', "Backup {$nama} berhasil dibuat.");
        } catch (BackupSedangBerjalanException) {
            return back()->with('error', 'Backup sedang berjalan. Tunggu sampai selesai.');
        } catch (Throwable $e) {
            // Pesan aslinya memuat path absolut server (BackupService menyebut
            // berkas tujuan). Itu masuk log, BUKAN ke layar.
            Log::error('Backup manual gagal: '.$e->getMessage());

            return back()->with('error', 'Backup gagal. Periksa storage/logs/laravel.log untuk sebabnya.');
        }
    }

    public function unduh(string $berkas)
    {
        $this->pastikanNamaWajar($berkas);

        $path = BackupService::DIREKTORI.'/'.$berkas;
        if (! Storage::disk('local')->exists($path)) {
            throw new NotFoundHttpException;
        }

        return response()->download(Storage::disk('local')->path($path), $berkas);
    }

    public function hapus(string $berkas)
    {
        $this->pastikanNamaWajar($berkas);
        $this->backup->hapus($berkas);

        return back()->with('success', "Backup {$berkas} dihapus.");
    }

    private function pastikanNamaWajar(string $berkas): void
    {
        if (preg_match(BackupService::POLA_NAMA, $berkas) !== 1) {
            throw new NotFoundHttpException;
        }
    }
}
