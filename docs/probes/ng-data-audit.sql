-- ============================================================================
-- PROBE READ-ONLY: audit kesehatan data NG (Tahap 1 & 2 sudah live)
-- ============================================================================
--
-- HANYA SELECT. Tidak ada INSERT/UPDATE/DELETE/ALTER di file ini.
-- Aman dijalankan di produksi. Jangan tambahkan statement penulisan ke sini.
--
-- Tujuan: mengukur kesehatan data NG di produksi.
--
-- ⚠️ DIPERBAIKI 2026-07-30. Versi pertama berkas ini membaca kolom
-- `id_jenis_ng` di tabel transaksi. Kolom itu PENSIUN sejak Tahap 1 — jenis NG
-- sekarang hidup di `transaksi_produksi_ng` / `transaksi_qc_ng`.
--
-- Akibatnya versi lama menyesatkan dua arah:
--   * baris baru yang sah dilaporkan "tanpa jenis" (memang, kolomnya tak
--     ditulis lagi), dan
--   * jenis NG yang AKTIF dipakai dilaporkan "tak pernah terpakai" — EXSCRAP
--     tampil di daftar tak-terpakai padahal punya 20 pcs di tabel detail.
--     Soft-delete berdasarkan daftar itu akan menyembunyikan jenis yang
--     dibutuhkan operator.
--
-- Semua query di bawah sekarang membaca tabel detail.
--
-- Cara pakai (dari server prod, ganti nama DB bila berbeda):
--   mysql -u <user> -p <nama_db> < docs/probes/ng-data-audit.sql
--
-- Semua query menyaring `deleted_at IS NULL` — sesi yang dihapus tidak boleh
-- ikut terhitung, sama seperti yang dilakukan dashboard.
-- ============================================================================


-- ── Q1. Ringkasan NG ────────────────────────────────────────────────────────
-- "tanpa_detail" adalah indikator masalah yang sebenarnya: baris ber-NG yang
-- tidak punya rincian jenis. Nol = sehat.

SELECT 'transaksi_produksi' AS sumber,
       COUNT(*)                                       AS baris_ber_ng,
       SUM(t.qty_ng)                                  AS total_ng,
       SUM(CASE WHEN d.id IS NULL THEN 1 ELSE 0 END)  AS tanpa_detail
FROM transaksi_produksi t
LEFT JOIN transaksi_produksi_ng d ON d.id_transaksi_produksi = t.id
WHERE t.deleted_at IS NULL AND t.qty_ng > 0

UNION ALL

SELECT 'transaksi_qc',
       COUNT(*), SUM(t.qty_ng),
       SUM(CASE WHEN d.id IS NULL THEN 1 ELSE 0 END)
FROM transaksi_qc t
LEFT JOIN transaksi_qc_ng d ON d.id_transaksi_qc = t.id
WHERE t.deleted_at IS NULL AND t.qty_ng > 0;


-- ── Q2. Tren NG rate per bulan ──────────────────────────────────────────────
-- NG rate pabrik stamping berada di orde 0,00x% — kolom PPM yang terbaca.

SELECT LEFT(t.tanggal_produksi, 7)                                  AS bulan,
       SUM(t.qty_aktual)                                            AS baik,
       SUM(COALESCE(t.qty_ng, 0))                                   AS ng,
       ROUND(SUM(COALESCE(t.qty_ng,0)) / NULLIF(SUM(t.qty_aktual) + SUM(COALESCE(t.qty_ng,0)), 0) * 1000000) AS ppm
FROM transaksi_produksi t
WHERE t.deleted_at IS NULL
GROUP BY bulan
ORDER BY bulan;


-- ── Q3. Pareto jenis NG — sisi PRODUKSI (dari tabel detail) ─────────────────

SELECT j.kode, j.nama_ng,
       SUM(d.qty)  AS qty,
       COUNT(*)    AS baris
FROM transaksi_produksi_ng d
JOIN transaksi_produksi t ON t.id = d.id_transaksi_produksi
JOIN master_jenis_ng    j ON j.id = d.id_jenis_ng
WHERE t.deleted_at IS NULL
GROUP BY j.kode, j.nama_ng
ORDER BY qty DESC;


-- ── Q4. Pareto jenis NG — sisi QUALITY (dari tabel detail) ──────────────────

SELECT j.kode, j.nama_ng,
       SUM(d.qty)  AS qty,
       COUNT(*)    AS baris
FROM transaksi_qc_ng d
JOIN transaksi_qc    t ON t.id = d.id_transaksi_qc
JOIN master_jenis_ng j ON j.id = d.id_jenis_ng
WHERE t.deleted_at IS NULL
GROUP BY j.kode, j.nama_ng
ORDER BY qty DESC;


-- ── Q5. Kandidat duplikat nama master ───────────────────────────────────────
-- Tiap baris di sini memecah satu masalah nyata menjadi beberapa batang Pareto.

SELECT LOWER(TRIM(nama_ng))                    AS nama_ternormalisasi,
       COUNT(*)                                AS jml_baris,
       GROUP_CONCAT(CONCAT(kode, ' = ', nama_ng)
                    ORDER BY id SEPARATOR ' | ') AS varian
FROM master_jenis_ng
WHERE deleted_at IS NULL
GROUP BY nama_ternormalisasi
HAVING COUNT(*) > 1
ORDER BY jml_baris DESC;


-- ── Q6. Jenis NG yang tidak pernah terpakai ────────────────────────────────
-- Membaca TABEL DETAIL, bukan kolom id_jenis_ng yang pensiun. Versi lama
-- melaporkan jenis aktif sebagai tak-terpakai; soft-delete berdasarkan itu
-- menyembunyikan jenis yang dibutuhkan operator.
--
-- Kolom id_jenis_ng lama tetap diperiksa supaya jenis yang hanya dipakai data
-- pra-Tahap-1 tidak salah dianggap mati.

SELECT j.id, j.kode, j.nama_ng
FROM master_jenis_ng j
WHERE j.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM transaksi_produksi_ng d WHERE d.id_jenis_ng = j.id)
  AND NOT EXISTS (SELECT 1 FROM transaksi_qc_ng       d WHERE d.id_jenis_ng = j.id)
  AND NOT EXISTS (SELECT 1 FROM transaksi_produksi    p WHERE p.id_jenis_ng = j.id)
  AND NOT EXISTS (SELECT 1 FROM transaksi_qc          q WHERE q.id_jenis_ng = j.id)
ORDER BY j.kode;


-- ── Q7. Angka mustahil ─────────────────────────────────────────────────────
-- Nol di semua baris = sehat. Bukan nol = ada jalur input yang bocor.

SELECT 'produksi: qty_ng > qty_aktual' AS pemeriksaan, COUNT(*) AS jml
FROM transaksi_produksi WHERE deleted_at IS NULL AND qty_ng > qty_aktual

UNION ALL
SELECT 'produksi: baris detail qty <= 0', COUNT(*)
FROM transaksi_produksi_ng WHERE qty <= 0

UNION ALL
SELECT 'qc: baris detail qty <= 0', COUNT(*)
FROM transaksi_qc_ng WHERE qty <= 0

UNION ALL
SELECT 'qc: qty_ok + qty_ng = 0 (sesi tanpa isi)', COUNT(*)
FROM transaksi_qc WHERE deleted_at IS NULL AND COALESCE(qty_ok,0) + COALESCE(qty_ng,0) = 0

UNION ALL
SELECT 'produksi: detail nunjuk master hilang', COUNT(*)
FROM transaksi_produksi_ng d
LEFT JOIN master_jenis_ng j ON j.id = d.id_jenis_ng
WHERE j.id IS NULL

UNION ALL
SELECT 'qc: detail nunjuk master hilang', COUNT(*)
FROM transaksi_qc_ng d
LEFT JOIN master_jenis_ng j ON j.id = d.id_jenis_ng
WHERE j.id IS NULL

UNION ALL
-- Invarian yang dijanjikan Tahap 1: qty_ng induk = SUM(qty detail).
SELECT 'produksi: qty_ng induk != SUM(detail)', COUNT(*)
FROM (
    SELECT t.id
    FROM transaksi_produksi t
    LEFT JOIN transaksi_produksi_ng d ON d.id_transaksi_produksi = t.id
    WHERE t.deleted_at IS NULL
    GROUP BY t.id, t.qty_ng
    HAVING t.qty_ng IS NOT NULL AND t.qty_ng <> COALESCE(SUM(d.qty), 0)
) x

UNION ALL
SELECT 'qc: qty_ng induk != SUM(detail)', COUNT(*)
FROM (
    SELECT t.id
    FROM transaksi_qc t
    LEFT JOIN transaksi_qc_ng d ON d.id_transaksi_qc = t.id
    WHERE t.deleted_at IS NULL
    GROUP BY t.id, t.qty_ng
    HAVING t.qty_ng IS NOT NULL AND t.qty_ng <> COALESCE(SUM(d.qty), 0)
) y;
