# Desain: Sidebar Satu Sumbu (Domain-First)

**Tanggal:** 2026-08-22
**Status:** Disetujui, siap diimplementasikan

## Latar Belakang

Sidebar OPTIMA sekarang mengelompokkan menu dengan **dua sumbu sekaligus**. Grup
"Input Data" dikelompokkan menurut **kata kerja** (apa yang dilakukan pengguna), sedangkan
"Produksi", "Quality", "MTC Mesin", dan "MTC Dies" dikelompokkan menurut **domain** (soal
apa). Akibatnya halaman yang dipakai satu orang dalam satu pekerjaan terpecah ke dua grup:

| Peran | Buka grup ini | Tapi juga harus buka |
|---|---|---|
| `staff_produksi` | Input Data → LHP Produksi, Dandori | Produksi → VCB Produksi, VCB Mesin, … |
| `staff_qc` | Input Data → LHP Quality | Quality → VCB Quality, … |
| `staff_maintenance` | Input Data → Breakdown Mesin, Breakdown Dies | MTC Mesin **dan** MTC Dies |

Maintenance paling mahal: **tiga grup untuk satu pekerjaan.**

Masalah kedua yang mendesak sekarang: **plafon sidebar sudah mentok pas**, bukan hampir.
Untuk role `admin` — satu-satunya peran yang melihat semuanya — angkanya 9 dari plafon 9
baris tingkat atas, dan 9 + 7 = 16 dari plafon 16 saat satu grup terbuka. Keduanya dijaga
`SidebarMenuTest`.

Konsekuensinya sudah terasa: halaman **Backup Sistem** (live PR #101/#102) terpaksa
ditempatkan di dropdown topbar, bukan karena itu tempat terbaiknya melainkan karena tidak
ada slot tersisa. Halaman **Audit Trail** yang direncanakan akan menabrak tembok yang sama.

Menambal tidak bisa:

| Tambalan | Hasil |
|---|---|
| Tambah grup "Sistem" saja | 10 baris tingkat atas > 9 ❌ |
| Taruh Backup di Master Data | 9 + 8 = 17 > 16 ❌ |
| Naikkan plafonnya | Sisa tinggi sidebar 28px, satu baris anak butuh 36px ❌ |

Harus ada yang turun. Spec ini memakai keharusan itu sekalian untuk membereskan sumbu ganda
di atas, bukan sekadar mencari satu slot kosong.

## Keputusan yang Sudah Diambil

| Pertanyaan | Keputusan |
|---|---|
| Arah penataan | **Domain penuh** — "Input Data" dibubarkan, tiap input pulang ke domainnya |
| Nama grup baru | **Sistem** — sejalan dengan prefiks route `/sistem/backup` yang sudah live |
| Users | Pindah dari Master Data → Sistem |
| Anomali LHP | Turun dari baris tingkat atas → Sistem |
| Audit Trail | **Belum ditambahkan** — halamannya belum ada |
| MTC Mesin & MTC Dies | **Tetap terpisah** — meleburnya jadi satu grup 10 anak melewati plafon |

## Prinsip

**Satu sumbu, bukan dua.** Menu dikelompokkan menurut domain — soal apa halaman itu — dan
tidak pernah menurut kata kerja. Konsekuensi langsungnya: **halaman input tinggal serumah
dengan dashboard yang membacanya.** Orang yang menginput LHP Produksi adalah orang yang
membaca VCB Produksi; keduanya satu grup.

**Plafon bukan angka yang dinegosiasikan.** `tingkat_atas ≤ 9` dan
`tingkat_atas + anak_terbesar ≤ 16` berasal dari tinggi sidebar yang nyata, bukan dari
selera. Struktur baru harus muat **untuk setiap peran**, bukan cuma untuk admin.

**Menu tidak pernah menunjuk halaman yang belum ada.** Entri mati lebih buruk daripada
entri yang belum ada: yang pertama membohongi pembacanya.

## 1. Struktur Baru

Urutan disengaja: pekerjaan harian di atas, administrasi di bawah.

| # | Tingkat atas | `key` | Anak |
|---|---|---|---|
| 1 | Overview (tautan) | `overview` | — |
| 2 | AI Assistant (tautan) | `chatbot` | — |
| 3 | **Produksi** | `produksi` | 7 |
| 4 | **Quality** | `quality` | 4 |
| 5 | **MTC Mesin** | `mtcmesin` | 5 |
| 6 | **MTC Dies** | `mtcdies` | 5 |
| 7 | **Master Data** | `master` | 6 |
| 8 | **Sistem** | `sistem` | 3 |

### Isi tiap grup

**Produksi** (`bi-box-seam`) — input dulu, lalu dashboard yang membacanya:

| Label | Route | `match` |
|---|---|---|
| LHP Produksi | `input.produksi.index` | `input.produksi.*` |
| Dandori | `input.dandori.index` | `input.dandori.*` |
| VCB Produksi | `vcb.produksi` | `vcb.produksi` |
| VCB Mesin | `vcb.mesin` | `vcb.mesin` |
| VCB Dandori | `vcb.dandori` | `vcb.dandori` |
| VCB NG Proses | `vcb.ng-proses` | `vcb.ng-proses` |
| Daftar NG Proses | `vcb.ng-proses.daftar` | `vcb.ng-proses.daftar` |

**Quality** (`bi-shield-check`): LHP Quality (`input.qc.index`) · VCB Quality · VCB NG Total
· Daftar NG Total.

**MTC Mesin** (`bi-tools`): Breakdown Mesin (`maintenance.breakdown.index`) · MTTR · MTBF ·
Availability · Control Stroke.

**MTC Dies** (`bi-hammer`): Breakdown Dies (`maintenance.breakdown-dies.index`) · MTTR ·
MTBF · Availability · Control Stroke.

**Master Data** (`bi-database`): Karyawan · Mesin · Part / Dies · Loss Time · Shift ·
Jenis NG. **Tanpa Users.**

**Sistem** (`bi-sliders`): Anomali LHP (`admin.anomali`) · Users (`master.users.index`) ·
Backup (`sistem.backup.index`). Seluruh anaknya `admin`, jadi grupnya hanya muncul untuk
admin — peran grup diturunkan dari gabungan peran anaknya, seperti grup lain.

⚠️ **Pola `match` disalin apa adanya dari config sekarang, termasuk yang tanpa wildcard.**
`vcb.ng-proses` WAJIB persis: pola `vcb.ng-proses*` akan ikut menelan `vcb.ng-proses.daftar`
dan menyalakan dua entri sekaligus. Route berpindah grup tidak mengubah pola apa pun.

## 2. Plafon — Terverifikasi Per Peran

Disimulasikan sebelum spec ini ditulis, bukan diperkirakan:

| Peran | Tingkat atas | Anak terbesar | Terbuka |
|---|---|---|---|
| `admin` | **8** / 9 | 7 | **15** / 16 |
| `manager` | 6 / 9 | 5 | 11 / 16 |
| `guest` | 5 / 9 | 7 | 12 / 16 |
| `staff_maintenance` | 3 / 9 | 5 | 8 / 16 |
| `staff_produksi` | 2 / 9 | 7 | 9 / 16 |
| `staff_qc` | 2 / 9 | 4 | 6 / 16 |

Admin menyisakan **satu baris napas di kedua sumbu**. Grup Sistem masih bisa menampung
Audit Trail plus sekitar tiga halaman lagi sebelum mentok kembali.

## 3. Konsekuensi yang Disengaja

**Backup keluar dari dropdown topbar.** Dua pintu masuk untuk satu halaman membingungkan.
Dropdown menyisakan Profil (masih dijaga `Route::has('profile.edit')`, karena route itu
memang belum pernah ada) dan Logout — yang tetap POST ber-CSRF.

**Anomali LHP kehilangan aksen kuningnya.** `partials/sidebar.blade.php` merender `accent`
hanya untuk tautan tingkat atas, tidak untuk anak grup, dan spec ini **tidak** memperluasnya.
Alasannya bukan hemat kerja: aksen yang hanya terlihat ketika grupnya kebetulan terbuka
praktis tidak berfungsi sebagai penanda "ada yang perlu diurus". Kalau sinyal itu memang
penting, tempatnya di halaman Overview atau lencana topbar — bukan warna menu yang tertutup
accordion. Ini kehilangan yang dipilih, bukan kelalaian.

Kunci `accent` ikut **dibuang** dari entri Anomali LHP di `config/sidebar.php`, berikut
barisnya di komentar kepala berkas itu. Menyimpan konfigurasi yang tidak pernah dibaca
membuat pembaca berikutnya percaya warnanya masih menyala.

**Key accordion `input` hilang, `sistem` muncul.** Key grup lain (`produksi`, `quality`,
`mtcmesin`, `mtcdies`, `master`) **tidak berubah**, jadi state `localStorage` pengguna yang
sudah ada tetap terpakai. Yang menyimpan `input` sebagai grup terbuka menjadi yatim: tidak
error, hanya tidak berefek.

**Muscle memory berubah sekali.** Paling terasa untuk `staff_produksi` dan `staff_qc` yang
setiap hari mengklik "Input Data". Ini ongkos yang diterima di muka, bukan efek samping yang
ditemukan belakangan.

## 4. Yang TIDAK Berubah

- **Nol route baru, nol URL berubah, nol migrasi.** Ini murni navigasi — `config/sidebar.php`
  hanya menyebut nama route yang semuanya sudah ada.
- Plafon `PLAFON_BARIS = 9` dan `PLAFON_TERBUKA = 16` **tidak dinaikkan**.
- Perilaku accordion, `SidebarMenu::forRole()`, `activeGroup()`, dan `isActive()` tidak
  diubah — hanya datanya.
- Aturan bahwa grup tidak punya `roles` sendiri (diturunkan dari anaknya) dipertahankan.

## 5. Dampak ke Test

| Test | Dampak |
|---|---|
| `SidebarMenuTest::test_urutan_dan_kunci_admin` | Daftar key admin berubah jadi `overview, chatbot, produksi, quality, mtcmesin, mtcdies, master, sistem` |
| `SidebarMenuTest` (tiga test grup `input`) | Ditulis ulang menargetkan grup baru. Yang dibuktikan tetap sama: **satu grup hanya merender anak yang boleh dilihat perannya** |
| `SidebarMenuTest` plafon | Tidak diubah — justru penjaga utama perubahan ini |
| `SidebarNavTest` | Memeriksa `navGroups('master')`; sesuaikan kalau grup rujukannya berpindah |
| `NgListSidebarTest` | Menjaga satu sub-halaman NG tidak menyalakan dua entri; harus tetap hijau |
| `BackupHalamanTest` | Asersi tautan Backup di topbar berpindah jadi asersi entri sidebar |

Penjaga baru yang perlu ada: **tidak ada dua entri menu yang menunjuk route yang sama**.
Backup sempat punya dua pintu masuk, dan tanpa penjaga hal itu bisa terulang saat halaman
berikutnya ditambahkan.

## 6. Di Luar Lingkup

- **Halaman Audit Trail.** Spec tersendiri. Saat halamannya ada, ia masuk grup Sistem —
  plafonnya sudah disiapkan.
- **Accordion dua tingkat.** Tidak dibutuhkan struktur ini, dan menambah kedalaman berarti
  menambah klik untuk semua orang.
- **Melebur MTC Mesin & MTC Dies.** Sudah dihitung: satu grup 10 anak membuat
  `tingkat_atas + anak_terbesar` = 17, melewati plafon.
- **Memindahkan sinyal "ada anomali"** ke Overview atau topbar. Layak dikerjakan, tapi itu
  keputusan tentang cara memberi peringatan — bukan tentang struktur menu.
