<?php

namespace Tests\Feature\Sistem;

use App\Contracts\PembuatDump;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BackupHalamanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->app->bind(PembuatDump::class, fn () => new class implements PembuatDump
        {
            public function keBerkas(string $tujuan): void
            {
                // Diulang supaya melewati ambang UKURAN_DUMP_MINIMAL (1024 byte)
                // di BackupService — bukan aspek yang diuji test-test di berkas ini.
                file_put_contents($tujuan, str_repeat("-- dump uji\n", 100));
            }
        });
    }

    public static function peranBukanAdmin(): array
    {
        return [
            'manager' => ['manager'],
            'staff_produksi' => ['staff_produksi'],
            'staff_qc' => ['staff_qc'],
            'staff_maintenance' => ['staff_maintenance'],
            'guest' => ['guest'],
        ];
    }

    #[DataProvider('peranBukanAdmin')]
    public function test_hanya_admin_yang_boleh_membuka(string $peran): void
    {
        // Satu arsip memuat seluruh DB termasuk tabel users.
        $u = User::factory()->create(['role' => $peran]);

        // Nama arsip WAJIB berpola valid (POLA_NAMA) di dua URL di bawah —
        // kalau tidak, 403 yang diharapkan bisa jadi kebetulan datang dari
        // validator nama berkas (404) yang jalan sebelum middleware peran
        // sempat dicek, dan test ini lolos tanpa membuktikan apa pun soal
        // otorisasi.
        $arsip = 'optima-2026-01-01_010000.zip';

        $this->actingAs($u)->get(route('sistem.backup.index'))->assertForbidden();
        $this->actingAs($u)->post(route('sistem.backup.jalankan'))->assertForbidden();
        $this->actingAs($u)->get(route('sistem.backup.unduh', $arsip))->assertForbidden();
        $this->actingAs($u)->delete(route('sistem.backup.hapus', $arsip))->assertForbidden();
    }

    public function test_admin_bisa_membuka(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('sistem.backup.index'))->assertOk();
    }

    public function test_tombol_membuat_arsip(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('sistem.backup.jalankan'))->assertRedirect();

        $this->assertCount(1, Storage::disk('local')->files(BackupService::DIREKTORI));
    }

    public function test_tombol_ditolak_saat_backup_lain_sedang_berjalan(): void
    {
        // CACHE_STORE=array di phpunit.xml, dan ArrayStore mengimplementasikan
        // LockProvider in-process — jadi lock yang diambil di sini benar-benar
        // dilihat kode produksi lewat Cache::lock(), bukan dua kunci yang beda.
        // Ini persis skenario jadwal terjadwal (backup:jalankan lewat SSH) yang
        // bertabrakan dengan klik manual: kalau lock cuma dipasang di satu sisi,
        // request ini akan LOLOS dan mysqldump kedua ikut jalan bersamaan.
        $kunci = Cache::lock('backup-berjalan', 600);
        $this->assertTrue($kunci->get());

        try {
            $admin = User::factory()->create(['role' => 'admin']);

            $this->actingAs($admin)
                ->post(route('sistem.backup.jalankan'))
                ->assertRedirect();

            $this->assertSame(
                'Backup sedang berjalan. Tunggu sampai selesai.',
                session('error')
            );
            $this->assertSame([], Storage::disk('local')->files(BackupService::DIREKTORI));
        } finally {
            $kunci->release();
        }
    }

    public function test_nama_berkas_di_luar_pola_ditolak(): void
    {
        // Direktori di atas backups/ memuat .env server.
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get('/sistem/backup/'.urlencode('../../.env').'/unduh')
            ->assertNotFound();
    }

    public function test_unduh_mengirim_arsip(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $nama = app(BackupService::class)->jalankan('manual', $admin->id);

        $this->actingAs($admin)
            ->get(route('sistem.backup.unduh', $nama))
            ->assertOk()
            ->assertDownload($nama);
    }

    public function test_hapus_membuang_arsip(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $nama = app(BackupService::class)->jalankan('manual', $admin->id);

        $this->actingAs($admin)->delete(route('sistem.backup.hapus', $nama))->assertRedirect();

        $this->assertSame([], Storage::disk('local')->files(BackupService::DIREKTORI));
    }

    public function test_kolom_dipicu_oleh_menampilkan_nama_dan_em_dash(): void
    {
        // Nama khas supaya tidak kebetulan cocok dengan teks lain di halaman.
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Budi Kencana Pemicu']);

        // Nama arsip cuma presisi ke detik (POLA_NAMA) — dua backup di detik
        // yang sama akan tabrakan nama dan File::move() salah satu menimpa
        // yang lain diam-diam. Pin waktunya beda detik supaya test ini
        // deterministik, bukan tergantung seberapa cepat mesin CI berjalan.
        Carbon::setTestNow(Carbon::parse('2026-01-01 10:00:00', 'Asia/Jakarta'));
        app(BackupService::class)->jalankan('manual', $admin->id);
        Carbon::setTestNow(Carbon::parse('2026-01-01 10:00:05', 'Asia/Jakarta'));
        app(BackupService::class)->jalankan('terjadwal', null);
        Carbon::setTestNow();

        $html = $this->actingAs($admin)->get(route('sistem.backup.index'))->getContent();

        // Anchor ke <tbody> tabel arsip, BUKAN seluruh halaman: nama admin yang
        // login juga selalu muncul di status bar ("Login as {{ ...->name }}"),
        // jadi assertSee() ke seluruh HTML tidak pernah bisa gagal kalau kolom
        // dipicu_oleh_nama dihapus atau kuncinya diganti nama.
        $ditemukan = preg_match('/<tbody>.*?<\/tbody>/s', $html, $cocok);
        $this->assertSame(1, $ditemukan, 'Tabel arsip (tbody) tidak ditemukan di halaman.');
        $grid = $cocok[0];

        $this->assertStringContainsString('Budi Kencana Pemicu', $grid);
        $this->assertStringContainsString('—', $grid);
    }

    public function test_kartu_umur_memberi_peringatan_saat_belum_pernah_backup(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('sistem.backup.index'))
            ->assertSee('Belum pernah', false);
    }

    public function test_div_seimbang(): void
    {
        // Halaman ini sengaja di luar ErpWorkspaceMarkupTest, jadi penjaga
        // keseimbangan <div>-nya harus ada di sini — <div> nyasar tetap
        // membalas 200 dan rusaknya cuma terlihat mata.
        $admin = User::factory()->create(['role' => 'admin']);

        $html = $this->actingAs($admin)
            ->get(route('sistem.backup.index'))->assertOk()->getContent();

        $this->assertSame(
            preg_match_all('/<\/div>/i', $html),
            preg_match_all('/<div\b/i', $html),
            'Jumlah <div> dan </div> tidak seimbang di halaman Backup.'
        );
    }

    public function test_pesan_gagal_tidak_membocorkan_path_server(): void
    {
        // Pesan asli BackupService memuat path absolut server.
        $this->app->bind(PembuatDump::class, fn () => new class implements PembuatDump
        {
            public function keBerkas(string $tujuan): void
            {
                throw new \RuntimeException('Gagal memindahkan arsip ke /home/rahasia/public_html/storage/app/private/backups/x.zip.');
            }
        });

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('sistem.backup.jalankan'))
            ->assertRedirect();

        $this->assertStringNotContainsString('/home/rahasia', session('error'));
    }

    public function test_kartu_umur_merah_setelah_48_jam(): void
    {
        // Kartu ini yang memberi tahu kalau jadwalnya berhenti — kalau ambangnya
        // salah, satu-satunya alarm fitur ini ikut mati.
        $admin = User::factory()->create(['role' => 'admin']);
        $nama = app(BackupService::class)->jalankan('terjadwal', null);

        // Mundurkan waktu ubah berkasnya ke 50 jam lalu.
        touch(
            Storage::disk('local')->path(BackupService::DIREKTORI.'/'.$nama),
            time() - (50 * 3600)
        );

        $this->actingAs($admin)
            ->get(route('sistem.backup.index'))
            ->assertSee('#b91c1c', false);
    }

    // Catatan: route('dashboard') di aplikasi ini SELALU redirect (302) ke
    // halaman lain sesuai peran — body-nya cuma halaman "Redirecting to ..."
    // bawaan Symfony, bukan topbar. Dites lewat route('vcb.produksi') —
    // halaman nyata yang memuat layouts.app, sama seperti konvensi di
    // tests/Feature/Layout/GuestUiTest.php.

    public function test_admin_melihat_tautan_backup_di_topbar(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('vcb.produksi'))
            ->assertSee(route('sistem.backup.index'), false);
    }

    public function test_bukan_admin_tidak_melihat_tautan_backup(): void
    {
        // Kenyamanan, bukan pengaman — pengamannya role:admin di route.
        $manager = User::factory()->create(['role' => 'manager']);

        $this->actingAs($manager)
            ->get(route('vcb.produksi'))
            ->assertDontSee(route('sistem.backup.index'), false);
    }

    public function test_guest_tetap_melihat_penanda_mode_tamu(): void
    {
        $guest = User::factory()->create(['role' => 'guest']);

        $this->actingAs($guest)
            ->get(route('vcb.produksi'))
            ->assertSee('MODE TAMU', false);
    }

    public function test_logout_tetap_form_post(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $html = $this->actingAs($admin)->get(route('vcb.produksi'))->getContent();

        // Dijangkarkan ke <form> yang action-nya persis route('logout'), bukan
        // seluruh halaman — supaya token CSRF yang diperiksa di bawah pasti
        // milik form logout ini, bukan form lain di halaman yang kebetulan
        // juga punya token.
        $ditemukan = preg_match(
            '#<form\b[^>]*action="'.preg_quote(route('logout'), '#').'"[^>]*>(.*?)</form>#s',
            $html,
            $cocok
        );
        $this->assertSame(1, $ditemukan, 'Form logout (action ke route logout) tidak ditemukan di halaman.');
        $form = $cocok[0];

        // method dan action diperiksa TERPISAH (bukan satu literal
        // "method=... action=...") supaya reorder atribut kosmetik pada
        // <form> tidak membuat test ini merah tanpa alasan perilaku.
        $this->assertStringContainsString('method="POST"', $form);

        // Tanpa token ini, VerifyCsrfToken menolak submit logout dengan 419 di
        // produksi dan tidak seorang pun bisa logout — kejadian yang lolos
        // deteksi murni dari method+action, karena keduanya tidak menyentuh
        // keberadaan @csrf sama sekali.
        $this->assertMatchesRegularExpression(
            '/<input[^>]+type="hidden"[^>]+name="_token"[^>]*>/',
            $form,
            'Form logout kehilangan token CSRF (@csrf).'
        );
    }
}
