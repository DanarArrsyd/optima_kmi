# Loading State — VCB Mesin, VCB Produksi, VCB Quality

**Tanggal:** 2026-09-23

## Latar Belakang

Bagian kedua dari 3 fitur performa/UX yang diminta (ping indicator → **loading
state** → audit performa umum). Investigasi awal: dari 14 halaman yang
`fetch()` data, cuma 2 (`vcb/ng.blade.php`, `vcb/dandori.blade.php`) punya
feedback visual pas loading (pola `loading` flag Alpine + tombol disable +
teks "Memuat…"). Tiga halaman paling rame — **VCB Mesin, VCB Produksi (Mode
1 & 2), VCB Quality (Mode 1 & 2)** — nol feedback sama sekali: UI diem/data
basi selama fetch, dan `vcb/mesin.blade.php` malah nol `try/catch` — fetch
gagal bikin error JS mentah nggak ketangkep, chart nggak pernah update tanpa
penjelasan apa pun ke user.

**Scope:** 3 halaman ini doang. Modul maintenance (mtbf/mttr/availability/
control-stroke, 8 halaman) sengaja DILUAR scope — spec terpisah kalau nanti
diminta.

**Tidak ada migrasi** — murni perubahan Blade/CSS/JS.

## Keputusan Desain

- **Visual:** spinner overlay (Bootstrap `spinner-border`, sudah ke-vendor
  tapi belum pernah dipakai di codebase ini — di-restyle monokrom `#374151`,
  bukan warna default Bootstrap) menutupi panel/area konten yang lagi
  di-refresh, backdrop putih transparan (`rgba(255,255,255,.75)`) supaya
  data lama masih samar kebaca tapi jelas "lagi diproses".
- **Bukan Alpine** — 3 halaman ini pakai vanilla JS (`document.getElementById`
  + `fetch`), BUKAN komponen Alpine kayak `vcb/ng.blade.php`. Overlay dipasang
  lewat DOM manipulation langsung (`classList`/`style.display`), konsisten
  sama gaya file yang disentuh, bukan konversi ke Alpine.
- **Error banner:** reuse konvensi yang sudah ada (`.text-danger`, persis
  pola `vcb/ng.blade.php` baris 121-123) — teks "Gagal memuat data — coba
  lagi.", muncul di atas panel yang gagal di-refresh.
- **Tombol filter di-disable selama loading** — cegah user numpuk klik jadi
  beberapa request bersamaan (konsisten pola `vcb/ng.blade.php`).
- **Cakupan overlay per halaman:**
  - VCB Mesin: 1 instance, nutupin kedua panel (chart + tabel) sekaligus —
    dibungkus satu `<div style="position:relative">` baru.
  - VCB Produksi & VCB Quality: 2 instance independen, masing-masing
    nutupin **seluruh** wrapper `#modeAll`/`#modeOne` yang sudah ada di
    kedua file itu (bukan per-panel individual — struktur panel di kedua
    mode cukup kompleks/beda-beda, overlay satu lapis di level wrapper mode
    lebih simpel dan konsisten).

## Perubahan per Halaman

### `resources/views/layouts/app.blade.php`

CSS baru, di blok `<style>` (dekat definisi `.erp-panel`/style container lain):

```css
.erp-loading-overlay {
    position: absolute;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    background: rgba(255, 255, 255, .75);
    z-index: 5;
}
.erp-loading-overlay .spinner-border {
    color: #374151;
    width: 2.5rem;
    height: 2.5rem;
}
```

(`display:none` default, JS toggle ke `display:flex` saat loading — bukan
`x-show`, karena bukan Alpine scope.)

### `resources/views/vcb/mesin.blade.php`

- Bungkus dua `.erp-panel` (chart + tabel, baris 68-96 saat ini) dalam satu
  `<div style="position:relative">` baru, taruh overlay di dalamnya:
  ```blade
  <div style="position:relative">
      <div class="erp-loading-overlay" id="mesinLoadingOverlay">
          <div class="spinner-border" role="status"><span class="visually-hidden">Memuat...</span></div>
      </div>
      <div class="text-danger mb-3" id="mesinLoadError" style="display:none">
          Gagal memuat data — coba lagi.
      </div>

      <div class="erp-panel mb-3">
      {{-- ... isi existing tidak berubah ... --}}
      </div>

      <div class="erp-panel">
      {{-- ... isi existing tidak berubah ... --}}
      </div>
  </div>
  ```
- `loadData()` (baris 122-223) dibungkus `try/catch/finally` (saat ini NOL
  `try/catch` sama sekali):
  ```js
  async function loadData() {
      const overlay = document.getElementById('mesinLoadingOverlay');
      const errBox  = document.getElementById('mesinLoadError');
      const submitBtn = document.querySelector('#filterForm button[type="submit"]');
      overlay.style.display = 'flex';
      errBox.style.display = 'none';
      submitBtn.disabled = true;
      try {
          // ... isi function yang sudah ada, TIDAK berubah ...
      } catch (e) {
          console.error('loadData error:', e);
          errBox.style.display = '';
      } finally {
          overlay.style.display = 'none';
          submitBtn.disabled = false;
      }
  }
  ```
  (Isi asli fungsi — fetch, render chart, render tabel — dipindah ke dalam
  blok `try`, logikanya sendiri TIDAK diubah.)

### `resources/views/vcb/produksi.blade.php`

- `#modeAll` (baris 132) dan `#modeOne` (baris 196) masing-masing dapat
  `style="position:relative"` ditambahkan ke tag pembuka yang sudah ada, dan
  overlay + error banner disisipkan sebagai child pertama masing-masing:
  ```blade
  <div id="modeAll" style="position:relative">
      <div class="erp-loading-overlay" id="allLoadingOverlay">
          <div class="spinner-border" role="status"><span class="visually-hidden">Memuat...</span></div>
      </div>
      <div class="text-danger mb-3" id="allLoadError" style="display:none">
          Gagal memuat data — coba lagi.
      </div>
      {{-- ... isi existing modeAll tidak berubah ... --}}
  ```
  Sama polanya buat `#modeOne` (`oneLoadingOverlay`/`oneLoadError`).
- `loadAllOperators()` (baris ~518-551, sudah punya `try/catch` tapi cuma
  `console.error` di catch) — tambah overlay/disable-button di awal `try`,
  tambah error banner di `catch`, tambah `finally` buat matiin
  overlay+enable button:
  ```js
  async function loadAllOperators() {
      const overlay = document.getElementById('allLoadingOverlay');
      const errBox  = document.getElementById('allLoadError');
      const submitBtn = document.querySelector('#formAll button[type="submit"]');
      overlay.style.display = 'flex';
      errBox.style.display = 'none';
      submitBtn.disabled = true;
      // ... isi try yang sudah ada, TIDAK berubah ...
      // di dalam catch(err) yang sudah ada, TAMBAH baris:
      //     errBox.style.display = '';
      // TAMBAH blok finally baru:
      // finally {
      //     overlay.style.display = 'none';
      //     submitBtn.disabled = false;
      // }
  }
  ```
- `loadOneOperator()` (baris ~724-756) — pola sama persis pakai
  `oneLoadingOverlay`/`oneLoadError`/`#formOne button[type="submit"]`.
  Catatan: fungsi ini punya early `return` di tengah (kalau `karyawanId`
  kosong, sebelum fetch dipanggil) — early return itu TIDAK perlu
  overlay/disable (belum ada fetch yang jalan), jadi taruh
  `overlay.style.display='flex'`/`submitBtn.disabled=true` SETELAH validasi
  early-return itu, bukan di baris paling atas function.

### `resources/views/vcb/quality.blade.php`

Pola identik `produksi.blade.php`: `#modeAll`/`#modeOne` dapat overlay +
error banner + `position:relative`, `loadAllInspectors()`/`loadOneInspector()`
dibungkus try/catch/finally dengan target `allLoadingOverlay`/`allLoadError`/
`#formAll button[type="submit"]` dan `oneLoadingOverlay`/`oneLoadError`/
`#formOne button[type="submit"]` masing-masing.

## Testing

Untuk tiap 3 halaman, tambah test baru yang assert:
- Overlay markup (`id="...LoadingOverlay"`, class `erp-loading-overlay`)
  muncul di HTML halaman.
- Error banner markup (`id="...LoadError"`, class `text-danger`) muncul di
  HTML halaman.
- JS `loadData()`/`loadAllOperators()`/dst memanggil
  `overlay.style.display` dan `submitBtn.disabled` (source-text assertion
  terhadap rendered JS — pola sama `ChatbotCanvasTest`, dibuktikan berguna
  di fitur ping indicator sebelumnya buat nangkep regresi threshold).

Test manual browser (bukan otomatis, PHPUnit nggak eksekusi JS):
lambatin network di DevTools (Slow 3G), klik "Tampilkan" di ketiga halaman,
pastikan spinner muncul, tombol ke-disable, lalu ilang pas data dateng.
Matiin koneksi, klik lagi, pastikan banner error muncul dan tombol balik
aktif (bisa klik ulang).
