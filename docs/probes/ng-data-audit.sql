-- ============================================================================
-- PROBE READ-ONLY: audit data NG sebelum Tahap 1 (detail NG multi-jenis)
-- ============================================================================
--
-- HANYA SELECT. Tidak ada INSERT/UPDATE/DELETE/ALTER di file ini.
-- Aman dijalankan di produksi. Jangan tambahkan statement penulisan ke sini.
--
-- Tujuan: mengubah "data lama mungkin kurang lengkap" menjadi angka, supaya
-- label peringatan di dashboard Tahap 2 bisa menyebut persentase sebenarnya.
--
-- Cara pakai (dari server prod, ganti nama DB bila berbeda):
--   mysql -u <user> -p <nama_db> < docs/probes/ng-data-audit.sql
--
-- Baris ter-soft-delete SENGAJA diikutkan di Q1–Q4 dan dipisah di Q2,
-- karena migrasi data nanti juga memakai withTrashed().
-- ============================================================================


-- ── Q1. Ringkasan: berapa besar masalahnya ──────────────────────────────────
-- Kolom "tanpa_jenis" inilah yang akan di-skip migrasi dan muncul sebagai
-- residual "Tanpa jenis" di Pareto Tahap 2.

SELECT 'transaksi_produksi' AS sumber,
       COUNT(*)                                                       AS total_baris,
       SUM(qty_ng > 0)                                                AS baris_ber_ng,
       SUM(qty_ng > 0 AND id_jenis_ng IS NOT NULL)                    AS ber_jenis,
       SUM(qty_ng > 0 AND id_jenis_ng IS NULL)                        AS tanpa_jenis,
       ROUND(100 * SUM(qty_ng > 0 AND id_jenis_ng IS NULL)
                 / NULLIF(SUM(qty_ng > 0), 0), 1)                     AS pct_tanpa_jenis,
       SUM(COALESCE(qty_ng, 0))                                       AS total_qty_ng,
       SUM(CASE WHEN id_jenis_ng IS NULL THEN COALESCE(qty_ng, 0) END) AS qty_ng_tanpa_jenis
FROM transaksi_produksi

UNION ALL

SELECT 'transaksi_qc',
       COUNT(*),
       SUM(qty_ng > 0),
       SUM(qty_ng > 0 AND id_jenis_ng IS NOT NULL),
       SUM(qty_ng > 0 AND id_jenis_ng IS NULL),
       ROUND(100 * SUM(qty_ng > 0 AND id_jenis_ng IS NULL)
                 / NULLIF(SUM(qty_ng > 0), 0), 1),
       SUM(COALESCE(qty_ng, 0)),
       SUM(CASE WHEN id_jenis_ng IS NULL THEN COALESCE(qty_ng, 0) END)
FROM transaksi_qc;


-- ── Q2. Tren per bulan: membaik atau memburuk? ──────────────────────────────
-- Kalau pct_tanpa_jenis turun dari bulan ke bulan, disiplin pencatatan sudah
-- membaik sendiri dan Tahap 2 bisa lebih percaya data belakangan.

SELECT 'produksi' AS sumber,
       DATE_FORMAT(tanggal_produksi, '%Y-%m')                      AS bulan,
       SUM(qty_ng > 0)                                             AS baris_ber_ng,
       SUM(qty_ng > 0 AND id_jenis_ng IS NULL)                     AS tanpa_jenis,
       ROUND(100 * SUM(qty_ng > 0 AND id_jenis_ng IS NULL)
                 / NULLIF(SUM(qty_ng > 0), 0), 1)                  AS pct_tanpa_jenis,
       SUM(deleted_at IS NOT NULL)                                 AS baris_soft_deleted
FROM transaksi_produksi
WHERE qty_ng > 0
GROUP BY DATE_FORMAT(tanggal_produksi, '%Y-%m')

UNION ALL

SELECT 'qc',
       DATE_FORMAT(tgl_pengecekan, '%Y-%m'),
       SUM(qty_ng > 0),
       SUM(qty_ng > 0 AND id_jenis_ng IS NULL),
       ROUND(100 * SUM(qty_ng > 0 AND id_jenis_ng IS NULL)
                 / NULLIF(SUM(qty_ng > 0), 0), 1),
       SUM(deleted_at IS NOT NULL)
FROM transaksi_qc
WHERE qty_ng > 0
GROUP BY DATE_FORMAT(tgl_pengecekan, '%Y-%m')

ORDER BY sumber, bulan;


-- ── Q3. Pareto versi sekarang (BIAS — untuk pembanding, bukan kesimpulan) ───
-- Ini yang akan dilihat dashboard kalau dibangun tanpa Tahap 1. Simpan
-- hasilnya; setelah beberapa bulan data multi-jenis terkumpul, bandingkan
-- urutannya. Selisih urutan = ukuran nyata dari bias yang diperbaiki.

SELECT COALESCE(j.kode, '(tanpa jenis)')  AS kode,
       COALESCE(j.nama_ng, '—')           AS nama_ng,
       COUNT(*)                           AS jml_sesi,
       SUM(t.qty_ng)                      AS total_qty_ng
FROM transaksi_produksi t
LEFT JOIN master_jenis_ng j ON j.id = t.id_jenis_ng
WHERE t.qty_ng > 0
GROUP BY j.kode, j.nama_ng
ORDER BY total_qty_ng DESC;


-- ── Q4. Sama, sisi QC ───────────────────────────────────────────────────────

SELECT COALESCE(j.kode, '(tanpa jenis)')  AS kode,
       COALESCE(j.nama_ng, '—')           AS nama_ng,
       COUNT(*)                           AS jml_sesi,
       SUM(t.qty_ng)                      AS total_qty_ng
FROM transaksi_qc t
LEFT JOIN master_jenis_ng j ON j.id = t.id_jenis_ng
WHERE t.qty_ng > 0
GROUP BY j.kode, j.nama_ng
ORDER BY total_qty_ng DESC;


-- ── Q5. Kebersihan master_jenis_ng ──────────────────────────────────────────
-- Kandidat duplikat: nama sama setelah dinormalisasi huruf & spasi.
-- Tiap baris yang muncul di sini memecah satu masalah nyata menjadi beberapa
-- batang Pareto. Rapikan SEBELUM tabel detail terisi.

SELECT LOWER(TRIM(nama_ng))                    AS nama_ternormalisasi,
       COUNT(*)                                AS jml_baris,
       GROUP_CONCAT(CONCAT(kode, '=', nama_ng)
                    ORDER BY id SEPARATOR ' | ') AS varian
FROM master_jenis_ng
WHERE deleted_at IS NULL
GROUP BY nama_ternormalisasi
HAVING COUNT(*) > 1
ORDER BY jml_baris DESC;


-- ── Q6. Jenis NG yang tidak pernah terpakai ────────────────────────────────
-- Kandidat dihapus/digabung. Master yang panjang tapi 80% tak terpakai bikin
-- typeahead operator lambat dan salah pilih.

SELECT j.id, j.kode, j.nama_ng
FROM master_jenis_ng j
WHERE j.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM transaksi_produksi p WHERE p.id_jenis_ng = j.id)
  AND NOT EXISTS (SELECT 1 FROM transaksi_qc       q WHERE q.id_jenis_ng = j.id)
ORDER BY j.kode;


-- ── Q7. Angka mustahil ─────────────────────────────────────────────────────
-- Nol di semua kolom = sehat. Bukan nol = ada jalur input yang bocor, dan
-- perlu dibereskan sebelum angkanya ikut terbawa ke tabel detail.

SELECT 'produksi: qty_ng > qty_aktual'       AS pemeriksaan,
       COUNT(*)                              AS jml
FROM transaksi_produksi WHERE qty_ng > qty_aktual

UNION ALL
SELECT 'produksi: id_jenis_ng terisi tapi qty_ng kosong/0',
       COUNT(*)
FROM transaksi_produksi WHERE id_jenis_ng IS NOT NULL AND COALESCE(qty_ng, 0) = 0

UNION ALL
SELECT 'produksi: id_jenis_ng nunjuk master yang hilang',
       COUNT(*)
FROM transaksi_produksi t
LEFT JOIN master_jenis_ng j ON j.id = t.id_jenis_ng
WHERE t.id_jenis_ng IS NOT NULL AND j.id IS NULL

UNION ALL
SELECT 'qc: id_jenis_ng terisi tapi qty_ng kosong/0',
       COUNT(*)
FROM transaksi_qc WHERE id_jenis_ng IS NOT NULL AND COALESCE(qty_ng, 0) = 0

UNION ALL
SELECT 'qc: id_jenis_ng nunjuk master yang hilang',
       COUNT(*)
FROM transaksi_qc t
LEFT JOIN master_jenis_ng j ON j.id = t.id_jenis_ng
WHERE t.id_jenis_ng IS NOT NULL AND j.id IS NULL

UNION ALL
SELECT 'qc: qty_ok + qty_ng = 0 (sesi tanpa isi)',
       COUNT(*)
FROM transaksi_qc WHERE COALESCE(qty_ok, 0) + COALESCE(qty_ng, 0) = 0;
