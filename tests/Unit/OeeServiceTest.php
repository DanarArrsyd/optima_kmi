<?php

namespace Tests\Unit;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\User;
use App\Services\ChatbotTools;
use App\Services\OeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OeeServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{mesin: MasterMesin, proses: MasterPartProses} */
    private function scaffold(int $sph = 400, ?string $install = '2026-01-01'): array
    {
        $part = MasterPart::create(['part_no' => 'PT-OEE', 'part_name' => 'X', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BLANKING',
            'sph_standar_produksi' => $sph, 'sph_standar_qc' => 200,
        ]);
        $mesin = MasterMesin::create([
            'id_mesin' => 'M-OEE', 'nama_mesin' => 'Press', 'line' => '1',
            'tipe_mesin' => 'BLANKING', 'kapasitas_max_stroke' => 100, 'tanggal_instalasi' => $install,
        ]);

        return ['mesin' => $mesin, 'proses' => $proses];
    }

    private function lhp(int $mesinId, int $prosesId, int $qty, int $ng, string $tgl = '2026-07-15'): void
    {
        $shift = MasterShift::firstOrCreate(['nama_shift' => 'S1'], ['jam_start' => '08:00', 'jam_finish' => '16:00']);
        $kar = MasterKaryawan::firstOrCreate(['nik' => 'K-OEE'], ['nama_karyawan' => 'A', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $user = User::factory()->create();
        TransaksiProduksi::create([
            'tanggal_produksi' => $tgl, 'id_shift' => $shift->id, 'id_karyawan' => $kar->id,
            'id_mesin' => $mesinId, 'id_part_proses' => $prosesId,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => $qty, 'qty_ng' => $ng,
            'created_by' => $user->id,
        ]);
    }

    private function july(): array
    {
        $from = Carbon::create(2026, 7, 1)->startOfMonth();

        return [$from, $from->copy()->endOfMonth()];
    }

    public function test_performance_and_quality_are_exact(): void
    {
        ['mesin' => $mesin, 'proses' => $proses] = $this->scaffold(sph: 400);
        // 8 jam kerja × sph_std 400 = teoretis 3200. Diproduksi 3200 baik + 200 NG
        // = 3400 → performance 3400/3200 = 106,25% (di atas 100% = SPH standar terlalu rendah).
        // 3200 baik + 200 NG = 3400 diproduksi → quality 3200/3400 = 94,12%.
        $this->lhp($mesin->id, $proses->id, qty: 3200, ng: 200);

        [$from, $to] = $this->july();
        $r = (new OeeService)->computeMesin($mesin->id, $from, $to);

        $this->assertSame(106.25, $r['performance_pct']);
        $this->assertSame(94.12, $r['quality_pct']);
        $this->assertSame(3200, $r['total_qty']);
        $this->assertSame(200, $r['total_ng']);
        $this->assertSame(1, $r['jumlah_lhp']);
    }

    /**
     * qty_aktual berisi barang BAIK saja — NG dicatat terpisah. Rumus lama
     * (qty_aktual − qty_ng) ÷ qty_aktual mengurangi NG dari barang baik, jadi
     * NG dihukum dua kali dan Quality jatuh lebih dalam dari seharusnya.
     *
     * 100 baik + 50 NG: dari 150 pcs yang diproduksi, 100 di antaranya baik
     * → 66,67%. Rumus lama memberi (100−50)/100 = 50%.
     */
    public function test_quality_tidak_menghukum_ng_dua_kali(): void
    {
        ['mesin' => $mesin, 'proses' => $proses] = $this->scaffold(sph: 0);
        $this->lhp($mesin->id, $proses->id, qty: 100, ng: 50);

        [$from, $to] = $this->july();
        $r = (new OeeService)->computeMesin($mesin->id, $from, $to);

        $this->assertSame(66.67, $r['quality_pct']);
    }

    /**
     * Tidak ada validasi qty_ng <= qty_aktual di form (StoreProduksiRequest
     * hanya min:1), jadi qty_ng lebih besar itu mungkin. Rumus lama
     * menghasilkan Quality NEGATIF — mustahil secara fisik dan merusak
     * perkalian OEE. Rumus rasio tidak bisa negatif.
     */
    public function test_quality_tidak_pernah_negatif_walau_ng_lebih_besar(): void
    {
        ['mesin' => $mesin, 'proses' => $proses] = $this->scaffold(sph: 0);
        $this->lhp($mesin->id, $proses->id, qty: 10, ng: 20);

        [$from, $to] = $this->july();
        $r = (new OeeService)->computeMesin($mesin->id, $from, $to);

        $this->assertGreaterThan(0, $r['quality_pct'], 'Quality tidak boleh negatif.');
        $this->assertSame(33.33, $r['quality_pct']);
    }

    /**
     * Performance mengukur KECEPATAN MESIN. Mesin tetap menekan untuk barang
     * NG, jadi pembilangnya total yang diproduksi (baik + NG) — bukan barang
     * baik saja. Kalau pembilangnya barang baik, NG dihukum di Performance DAN
     * di Quality, padahal pemisahan tiga komponen OEE ada justru untuk mencegah
     * itu.
     *
     * teoretis 3200; diproduksi 3200 baik + 200 NG = 3400 → 106,25%.
     * Di atas 100% itu sah: artinya SPH standar diset terlalu rendah.
     */
    public function test_performance_menghitung_total_diproduksi_bukan_hanya_barang_baik(): void
    {
        ['mesin' => $mesin, 'proses' => $proses] = $this->scaffold(sph: 400);
        $this->lhp($mesin->id, $proses->id, qty: 3200, ng: 200);

        [$from, $to] = $this->july();
        $r = (new OeeService)->computeMesin($mesin->id, $from, $to);

        $this->assertSame(106.25, $r['performance_pct']);
    }

    /**
     * Sifat elok OEE standar: Availability × Performance × Quality menyusut
     * menjadi (barang baik ÷ output teoretis). Sifat itu hanya berlaku kalau
     * Performance memakai total diproduksi dan Quality memakai rasio baik dari
     * total — dengan rumus lama, keduanya tidak saling menghapus dan hasilnya
     * lebih kecil dari yang seharusnya.
     */
    public function test_performance_kali_quality_menyusut_jadi_baik_per_teoretis(): void
    {
        ['mesin' => $mesin, 'proses' => $proses] = $this->scaffold(sph: 400);
        $this->lhp($mesin->id, $proses->id, qty: 3000, ng: 200);

        [$from, $to] = $this->july();
        $r = (new OeeService)->computeMesin($mesin->id, $from, $to);

        // 3000 baik ÷ 3200 teoretis = 93,75%
        $gabungan = round($r['performance_pct'] / 100 * $r['quality_pct'] / 100 * 100, 2);
        $this->assertSame(93.75, $gabungan);
    }

    public function test_oee_is_product_of_three_components(): void
    {
        ['mesin' => $mesin, 'proses' => $proses] = $this->scaffold(sph: 400);
        $this->lhp($mesin->id, $proses->id, qty: 3200, ng: 200);

        [$from, $to] = $this->july();
        $r = (new OeeService)->computeMesin($mesin->id, $from, $to);

        $this->assertNotNull($r['availability_pct'], 'Instalasi diset → availability harus terhitung.');
        $expected = round($r['availability_pct'] / 100 * $r['performance_pct'] / 100 * $r['quality_pct'] / 100 * 100, 2);
        $this->assertSame($expected, $r['oee_pct']);
    }

    public function test_oee_null_when_install_date_missing(): void
    {
        ['mesin' => $mesin, 'proses' => $proses] = $this->scaffold(install: null);
        $this->lhp($mesin->id, $proses->id, qty: 1000, ng: 0);

        [$from, $to] = $this->july();
        $r = (new OeeService)->computeMesin($mesin->id, $from, $to);

        $this->assertNull($r['availability_pct']);
        $this->assertNull($r['oee_pct']);
        $this->assertNotNull($r['performance_pct']); // P & Q tetap bisa
        $this->assertTrue(collect($r['catatan'])->contains(fn ($c) => str_contains($c, 'instalasi')));
    }

    public function test_performance_null_when_std_sph_missing(): void
    {
        ['mesin' => $mesin, 'proses' => $proses] = $this->scaffold(sph: 0);
        $this->lhp($mesin->id, $proses->id, qty: 1000, ng: 50);

        [$from, $to] = $this->july();
        $r = (new OeeService)->computeMesin($mesin->id, $from, $to);

        $this->assertNull($r['performance_pct']);
        $this->assertSame(95.24, $r['quality_pct']); // 1000/1050 — quality tetap terhitung
        $this->assertNull($r['oee_pct']);
    }

    public function test_no_production_yields_null_perf_and_quality(): void
    {
        ['mesin' => $mesin] = $this->scaffold();

        [$from, $to] = $this->july();
        $r = (new OeeService)->computeMesin($mesin->id, $from, $to);

        $this->assertSame(0, $r['jumlah_lhp']);
        $this->assertNull($r['performance_pct']);
        $this->assertNull($r['quality_pct']);
    }

    public function test_chatbot_tools_resolves_kode_and_month(): void
    {
        ['mesin' => $mesin, 'proses' => $proses] = $this->scaffold();
        $this->lhp($mesin->id, $proses->id, qty: 3200, ng: 0);

        $r = (new ChatbotTools)->getOeeMesin('m-oee', '2026-07'); // case-insensitive kode

        $this->assertSame('Press', $r['mesin']);
        $this->assertSame('2026-07', $r['periode']);
        $this->assertSame(100.0, $r['performance_pct']);
    }

    public function test_chatbot_tools_errors_on_unknown_machine(): void
    {
        $r = (new ChatbotTools)->getOeeMesin('GHOST', '2026-07');
        $this->assertArrayHasKey('error', $r);
        $this->assertSame([], $r['kandidat'], 'Benar-benar tak ada → kandidat kosong.');
    }

    public function test_resolves_machine_by_nama_mesin(): void
    {
        // Kode A4, nama AMADA 80T — user sebut NAMA, harus tetap ketemu.
        $mesin = MasterMesin::create(['id_mesin' => 'A4', 'nama_mesin' => 'AMADA 80T', 'line' => 'A', 'tipe_mesin' => 'STAMPING', 'kapasitas_max_stroke' => 100, 'tanggal_instalasi' => '2026-01-01']);
        $part = MasterPart::create(['part_no' => 'PA', 'part_name' => 'X', 'customer' => 'Toyota']);
        $pr = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BLANK', 'sph_standar_produksi' => 400, 'sph_standar_qc' => 200]);
        $this->lhp($mesin->id, $pr->id, qty: 3200, ng: 0);

        $byName = (new ChatbotTools)->getOeeMesin('AMADA 80T', '2026-07');
        $byPartial = (new ChatbotTools)->getOeeMesin('amada', '2026-07');

        $this->assertSame('A4', $byName['kode_mesin']);
        $this->assertSame('A4', $byPartial['kode_mesin'], 'Partial unik juga resolve.');
    }

    public function test_ambiguous_machine_returns_candidates(): void
    {
        MasterMesin::create(['id_mesin' => 'A4', 'nama_mesin' => 'AMADA 80T', 'line' => 'A', 'tipe_mesin' => 'STAMPING', 'kapasitas_max_stroke' => 100]);
        MasterMesin::create(['id_mesin' => 'P3', 'nama_mesin' => 'AMADA 150T', 'line' => 'P', 'tipe_mesin' => 'STAMPING', 'kapasitas_max_stroke' => 100]);

        $r = (new ChatbotTools)->getOeeMesin('amada', '2026-07');

        $this->assertArrayHasKey('error', $r);
        $this->assertCount(2, $r['kandidat']);
        $this->assertEqualsCanonicalizing(['A4', 'P3'], array_column($r['kandidat'], 'kode'));
    }

    public function test_methodology_shows_actual_numbers(): void
    {
        ['mesin' => $mesin, 'proses' => $proses] = $this->scaffold(sph: 400);
        $this->lhp($mesin->id, $proses->id, qty: 3200, ng: 200); // teoretis 3200, perf 106.25, qual 94.12

        [$from, $to] = $this->july();
        $r = (new OeeService)->computeMesin($mesin->id, $from, $to);

        $this->assertSame(3200, $r['output_teoretis']);
        $this->assertStringContainsString('3400 ÷ 3200', $r['metodologi']['performance']);
        $this->assertStringContainsString('3200 ÷ 3400', $r['metodologi']['quality']);
    }
}
