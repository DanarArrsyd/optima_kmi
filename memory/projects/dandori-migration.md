# Dandori Migration — Google Sheets → MySQL

**Status:** 🔴 PENDING (belum dimulai implementasi)
**File Excel sumber:** `/Users/ekadanararrasyid/Downloads/vcb_dandori_db.xlsx`

---

## Tujuan
Migrasi data historis Dandori dari sistem lama (Google Sheets + Code.gs) ke OPTIMA MySQL.

## Struktur Excel (5 sheet)

| Sheet | Rows | Keterangan |
|-------|------|------------|
| `master_mesin` | 24 | Format: "A1 - AIDA 150T" (id + nama digabung) |
| `master_losstime` | 18 | Kode 1–18 |
| `master_dies` | 551 | Part/proses reference |
| `transaksi_dandori` | 6.441 | Header records, NO header row (row 1 = data) |
| `db_rincian` | 17.142 | **SUMBER UTAMA** — sudah normalized (1 baris = 1 loss time item) |

## Kolom `transaksi_dandori` (tanpa header)
col1=datetime, col2=shift(number), col3=nama_mesin(full "A1 - AIDA 150T"),
col4=line, col5=part_no, col6=part_name, col7=id_proses("1/2" format),
col8=nama_proses, col9=total_aktual_menit, col10=standard_time_menit,
col11=status, col12=rincian(JSON `[{"kode":"9","menit":"15"}]`), col13=operator_name

## Kolom `db_rincian` (ada header)
timestamp, tanggal, month, week, shift, operator, line, nama_mesin,
part_name, part_no, id_proses, nama_proses, kode_loss, jenis_loss,
menit_loss, menit_over

## Masalah Data yang Perlu Diselesaikan

### 1. Operator Names (CRITICAL)
90+ unique values dengan banyak typo/duplikat:
- "RAMAAAAA" → RAMA
- "KEVIINS" → KEVIN
- "B ARIFN" → BARIFIN
- "diki" vs "DIKI" (case)
- "operator", "TE", "S", "INNER LH" → placeholder tidak valid

**Perlu:** mapping operator name lama → id_karyawan OPTIMA

### 2. Format Mesin
- Sheets: `"A1 - AIDA 150T"` (gabung)
- OPTIMA: `id_mesin = "A1"`, `nama_mesin = "AIDA 150T"` (terpisah)
- Solusi: split by ` - ` (spasi dash spasi)

### 3. Format id_proses
- Sheets: `"1/2"` (urutan/total)
- OPTIMA: integer `id_proses` dari `master_part_proses`
- Perlu mapping dies → part_proses

### 4. Part No. dengan "-"
Beberapa baris `part_no = "-"` → skip atau default?

## Rencana Migrasi (Step by Step)

### Step 1 — Siapkan Mapping (Manual dulu)
1. Tarik daftar karyawan Produksi dari OPTIMA DB
2. Buat mapping: operator name lama → id_karyawan
3. Verify mesin 24 Sheets sudah match mesin di OPTIMA
4. Verify 18 losstime kode sudah ada di OPTIMA

### Step 2 — Import urutan
```
1. master_mesin (verify/insert jika ada yang kurang)
2. master_losstime (verify/insert)
3. master_part & master_part_proses (dari master_dies)
4. transaksi_dandori (header records)
5. transaksi_dandori_rincian (dari db_rincian)
```

### Step 3 — Script Python
Gunakan `db_rincian` sebagai sumber utama:
- Group by `(tanggal + shift + operator + nama_mesin + part_no + id_proses)`
  → satu `transaksi_dandori` header
- Tiap baris `db_rincian` = satu `transaksi_dandori_rincian`

## Tabel OPTIMA Target

**`transaksi_dandori`:** id, tgl_dandori, shift_id, id_mesin, id_karyawan,
id_part_proses, total_aktual_menit, standard_time_menit, status,
created_at, updated_at, deleted_at

**`transaksi_dandori_rincian`:** id, id_dandori, id_losstime,
menit_loss, created_at, updated_at

## Pertanyaan yang Belum Dijawab User
1. ✅ Struktur Sheets: jelas dari file Excel
2. ✅ Volume: > 2000 rows
3. ❌ Nama operator: belum diverifikasi mapping ke karyawan OPTIMA
4. ❌ Apakah part_no "-" di-skip atau ada default?

## Next Action
Saat session berikutnya:
1. Tarik daftar karyawan produksi OPTIMA + daftar mesin
2. Tampilkan ke user untuk confirm mapping operator
3. Baru buat script Python import
