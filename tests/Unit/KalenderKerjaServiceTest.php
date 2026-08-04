<?php

namespace Tests\Unit;

use App\Models\MasterShift;
use App\Models\MasterShiftIstirahat;
use App\Services\KalenderKerjaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Jam kerja terjadwal — PENYEBUT Availability.
 *
 * Kelas ini menggantikan jam kalender 24/7. Salah di sini berarti Availability
 * dan OEE salah di SEMUA halaman sekaligus, dan salahnya tidak kelihatan:
 * angkanya tetap masuk akal, cuma bohong. Karena itu tiap aturan diuji sendiri
 * dengan tanggal yang nama harinya ditulis eksplisit.
 */
class KalenderKerjaServiceTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): KalenderKerjaService
    {
        return new KalenderKerjaService;
    }

    private function tgl(string $s): Carbon
    {
        return Carbon::parse($s)->startOfDay();
    }

    /** @param  array<string, mixed>  $extra */
    private function shift(string $nama, string $start, string $finish, array $extra = []): MasterShift
    {
        return MasterShift::create(array_merge([
            'nama_shift' => $nama,
            'jam_start' => $start,
            'jam_finish' => $finish,
        ], $extra));
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['hari_libur' => []]);
    }

    /* ═══ Hari kerja ══════════════════════════════════════════════ */

    public function test_minggu_nol_jam_tanpa_perlu_didaftarkan(): void
    {
        $this->shift('Shift 1', '06:00', '14:00');

        // 2026-08-02 Minggu, 2026-08-03 Senin.
        $this->assertSame(0.0, $this->svc()->jamHari($this->tgl('2026-08-02')));
        $this->assertSame(8.0, $this->svc()->jamHari($this->tgl('2026-08-03')));
    }

    public function test_hari_libur_dari_config_nol_jam(): void
    {
        config(['hari_libur' => ['2026-08-17']]);
        $this->shift('Shift 1', '06:00', '14:00');

        // 2026-08-17 Senin — hari kerja kalau bukan tanggal merah.
        $this->assertSame(0.0, $this->svc()->jamHari($this->tgl('2026-08-17')));
        $this->assertSame(8.0, $this->svc()->jamHari($this->tgl('2026-08-18')));
    }

    /* ═══ Jam per kelompok hari ═══════════════════════════════════ */

    public function test_dua_shift_dijumlah(): void
    {
        $this->shift('Shift 1', '06:00', '14:00');
        $this->shift('Shift 2', '14:00', '22:00');

        // Rabu: 8 + 8 = 16 jam. Inilah jam kerja pabrik yang sebenarnya —
        // penyebut lama 24 jam memuat 8 jam yang tak pernah dijadwalkan.
        $this->assertSame(16.0, $this->svc()->jamHari($this->tgl('2026-08-05')));
    }

    public function test_shift_lewat_tengah_malam_delapan_jam_bukan_negatif(): void
    {
        // 22:00 → 06:00 melewati tengah malam. Tanpa penanganan, selisihnya
        // −16 jam dan menyeret total jadi negatif.
        $this->shift('Shift 3', '22:00', '06:00');

        $this->assertSame(8.0, $this->svc()->jamHari($this->tgl('2026-08-05')));
    }

    public function test_kolom_jumat_dan_sabtu_dipakai_kalau_ada(): void
    {
        $this->shift('Shift 1', '06:00', '14:00', [
            'jam_start_jumat' => '06:00', 'jam_finish_jumat' => '11:30',
            'jam_start_sabtu' => '07:00', 'jam_finish_sabtu' => '12:00',
        ]);

        $this->assertSame(8.0, $this->svc()->jamHari($this->tgl('2026-08-05')));    // Rabu
        $this->assertSame(5.5, $this->svc()->jamHari($this->tgl('2026-08-07')));    // Jumat
        $this->assertSame(5.0, $this->svc()->jamHari($this->tgl('2026-08-08')));    // Sabtu
    }

    public function test_jumat_sabtu_null_jatuh_ke_jam_senin_kamis(): void
    {
        /*
         * Null diartikan "sama seperti Senin–Kamis", BUKAN "tidak dijadwalkan".
         * Migrasi yang menambahkan kolom itu menulis bahwa jam_start/jam_finish
         * asli dipakai Senin–Kamis, jadi baris lama yang belum pernah disunting
         * punya Jumat/Sabtu null padahal pabriknya jelas bekerja hari itu.
         * Mengartikannya nol memotong dua hari kerja per minggu dari penyebut
         * dan melambungkan Availability.
         */
        $this->shift('Shift 1', '06:00', '14:00');

        $this->assertSame(8.0, $this->svc()->jamHari($this->tgl('2026-08-07')));    // Jumat
        $this->assertSame(8.0, $this->svc()->jamHari($this->tgl('2026-08-08')));    // Sabtu
    }

    /* ═══ Istirahat ═══════════════════════════════════════════════ */

    public function test_istirahat_dikurangi_dari_jam_terjadwal(): void
    {
        $s = $this->shift('Shift 1', '06:00', '14:00');
        MasterShiftIstirahat::create([
            'id_shift' => $s->id, 'kelompok_hari' => 'senin_kamis',
            'jam_start' => '09:00', 'jam_finish' => '09:30',
        ]);

        // 8 jam − 30 menit = 7,5 jam.
        $this->assertSame(7.5, $this->svc()->jamHari($this->tgl('2026-08-05')));
    }

    public function test_istirahat_hanya_berlaku_di_kelompok_harinya(): void
    {
        $s = $this->shift('Shift 1', '06:00', '14:00');
        MasterShiftIstirahat::create([
            'id_shift' => $s->id, 'kelompok_hari' => 'jumat',
            'jam_start' => '11:30', 'jam_finish' => '13:00',
        ]);

        $this->assertSame(8.0, $this->svc()->jamHari($this->tgl('2026-08-05')));    // Rabu, utuh
        $this->assertSame(6.5, $this->svc()->jamHari($this->tgl('2026-08-07')));    // Jumat, −1,5j
    }

    public function test_tabel_istirahat_kosong_ditoleransi(): void
    {
        // Prod belum tentu mengisi istirahat. Yang benar: jam terjadwal penuh,
        // bukan error atau nol.
        $this->shift('Shift 1', '06:00', '14:00');

        $this->assertSame(8.0, $this->svc()->jamHari($this->tgl('2026-08-05')));
    }

    /* ═══ Penanda dihitung_availability ═══════════════════════════ */

    public function test_shift_yang_dimatikan_tidak_masuk_penyebut(): void
    {
        // Inti permintaan user: pabrik hanya menjalankan Shift 1 & 2, tapi
        // Shift 3 terdaftar di master. Tanpa penanda ini penyebutnya salah
        // 8 jam per hari.
        $this->shift('Shift 1', '06:00', '14:00');
        $this->shift('Shift 2', '14:00', '22:00');
        $this->shift('Shift 3', '22:00', '06:00', ['dihitung_availability' => false]);

        $this->assertSame(16.0, $this->svc()->jamHari($this->tgl('2026-08-05')));
    }

    public function test_default_penanda_true_supaya_data_lama_tak_berubah_arti(): void
    {
        // Kolom baru tidak boleh mengubah arti baris yang sudah ada tanpa ada
        // yang memutuskan. Shift yang dibuat tanpa menyebut penandanya ikut
        // dihitung.
        $s = $this->shift('Shift 1', '06:00', '14:00');

        $this->assertTrue($s->fresh()->dihitung_availability);
        $this->assertSame(8.0, $this->svc()->jamHari($this->tgl('2026-08-05')));
    }

    /* ═══ Rentang ═════════════════════════════════════════════════ */

    public function test_rentang_menjumlah_hanya_hari_kerja(): void
    {
        $this->shift('Shift 1', '06:00', '14:00');

        /*
         * 2026-08-03 (Sen) s/d 2026-08-09 (Min) = 7 hari kalender.
         * Yang hari kerja: Sen, Sel, Rab, Kam, Jum, Sab = 6 hari × 8 jam = 48.
         * Minggu 09-Aug nol. Penyebut kalender lama akan memberi 168 jam.
         */
        $this->assertSame(48.0, $this->svc()->jamTerjadwal(
            $this->tgl('2026-08-03'),
            $this->tgl('2026-08-09')
        ));
    }

    public function test_rentang_terbalik_nol_bukan_negatif(): void
    {
        $this->shift('Shift 1', '06:00', '14:00');

        $this->assertSame(0.0, $this->svc()->jamTerjadwal(
            $this->tgl('2026-08-09'),
            $this->tgl('2026-08-03')
        ));
    }

    public function test_nol_shift_di_master_berarti_nol_jam(): void
    {
        // Bukan kasus teoretis: DB test tanpa master_shift menghasilkan ini, dan
        // hasilnya Availability null. Itu benar — "tidak ada jam kerja" bukan
        // berarti "sempurna".
        $this->assertSame(0.0, $this->svc()->jamHari($this->tgl('2026-08-05')));
        $this->assertSame(0.0, $this->svc()->jamTerjadwal(
            $this->tgl('2026-08-03'),
            $this->tgl('2026-08-09')
        ));
    }
}
