<?php
namespace App\Services\Chatbot;

/**
 * Single source of truth untuk daftar tabel bisnis yang boleh ditelusuri chatbot
 * (describeSchema + queryDatabase). Dipakai bersama oleh ChatbotTools & SqlGuard,
 * supaya whitelist tabel tidak duplikat / bisa lepas sinkron.
 */
class BusinessTableCatalog
{
    /** Tabel bisnis yang boleh ditelusuri (whitelist) => deskripsi singkat. */
    private const TABLES = [
        'master_karyawan'            => 'Data karyawan/operator/inspector (nik, nama_karyawan, departemen, is_aktif).',
        'master_mesin'               => 'Master mesin press (id_mesin, nama_mesin, line, tipe_mesin, kapasitas_max_stroke).',
        'master_part'                => 'Master part/dies (part_no, part_name, customer, tanggal_instalasi).',
        'master_part_proses'         => 'Proses per part + standar SPH (nama_proses, sph_standar_produksi, sph_standar_qc).',
        'master_part_proses_sph_log' => 'Riwayat perubahan standar SPH per proses.',
        'master_shift'               => 'Master shift (nama_shift, jam_start, jam_finish).',
        'master_shift_istirahat'     => 'Jadwal istirahat per shift.',
        'master_jenis_ng'            => 'Master jenis defect/NG.',
        'master_losstime'            => 'Master jenis losstime dandori + standar menit.',
        'transaksi_produksi'         => 'LHP produksi (tanggal_produksi, id_karyawan, id_mesin, id_part_proses, id_shift, qty_aktual, qty_ng).',
        'transaksi_qc'               => 'LHP quality (tgl_pengecekan, id_karyawan_qc, id_part_proses, qty_ok, qty_ng).',
        'transaksi_dandori'          => 'Header dandori (ganti setup/part).',
        'transaksi_dandori_rincian'  => 'Rincian dandori per losstime (tanggal, id_mesin, id_losstime, actual_menit, std_menit, over_menit).',
        'transaksi_breakdown_mesin'  => 'Breakdown mesin (tanggal_breakdown, id_mesin, problem_mesin, penyebab_breakdown, tindakan_perbaikan, repair_time, total_downtime).',
        'transaksi_breakdown_dies'   => 'Breakdown dies (tanggal_breakdown, id_part_proses, problem_dies, penyebab_breakdown, tindakan_perbaikan, repair_time, total_downtime).',
    ];

    /** @return array<string,string> nama_tabel => deskripsi */
    public function all(): array
    {
        return self::TABLES;
    }

    public function has(string $table): bool
    {
        return array_key_exists($table, self::TABLES);
    }

    public function describe(string $table): ?string
    {
        return self::TABLES[$table] ?? null;
    }
}
