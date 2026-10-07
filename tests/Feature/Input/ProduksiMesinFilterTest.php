<?php

namespace Tests\Feature\Input;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Filter mesin multi-select ("Semua Mesin" grouped by line) di LHP Produksi.
 * mesinFilterComp() dipromote jadi shared component (dipakai juga di VCB) —
 * test ini bukti nol regresi perilaku lama LHP Produksi setelah refactor.
 */
class ProduksiMesinFilterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Rentang tanggal eksplisit. Tanpa ini halaman memakai default bulan
     * berjalan, dan fixture 2026-09-01 jatuh di luar rentang begitu bulan
     * berganti — test ini merah sendiri sejak 1 Okt 2026.
     */
    private const RENTANG = ['date_from' => '2026-09-01', 'date_to' => '2026-09-30'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->admin);
    }

    private function duaMesin(): array
    {
        $shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $part = MasterPart::create(['part_no' => 'P-'.uniqid(), 'part_name' => 'Part']);
        $proses = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE', 'sph_standar_produksi' => 500]);
        $mesinA = MasterMesin::create(['id_mesin' => 'A-'.uniqid(), 'nama_mesin' => 'MESIN A', 'line' => 'A', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        $mesinB = MasterMesin::create(['id_mesin' => 'B-'.uniqid(), 'nama_mesin' => 'MESIN B', 'line' => 'B', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'OPERATOR', 'departemen' => 'Produksi', 'is_aktif' => true]);

        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-09-01', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesinA->id, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 100, 'created_by' => $this->admin->id,
        ]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-09-01', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesinB->id, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 200, 'created_by' => $this->admin->id,
        ]);

        return compact('mesinA', 'mesinB');
    }

    /**
     * Dock filter (dropdown "Semua Mesin") sengaja tetap merender SEMUA
     * mesin sebagai opsi pilihan — itu bukan bagian yang diuji di sini.
     * Yang dibuktikan filter beneran menyaring adalah isi TABEL (<tbody>),
     * jadi assersi discope ke potongan HTML setelah <tbody> supaya nggak
     * kena false-negative dari kode mesin yang muncul di daftar dropdown.
     */
    private function tbodyHtml(string $html): string
    {
        $start = strpos($html, '<tbody>');
        $end = strpos($html, '</tbody>');
        $this->assertNotFalse($start, 'Marker <tbody> tidak ditemukan di halaman LHP Produksi.');
        $this->assertNotFalse($end, 'Marker </tbody> tidak ditemukan di halaman LHP Produksi.');

        return substr($html, $start, $end - $start);
    }

    public function test_filter_mesin_ids_menyaring_tabel_lhp_produksi(): void
    {
        $m = $this->duaMesin();

        $html = $this->get(route('input.produksi.index', [...self::RENTANG, 'mesin_ids' => [$m['mesinA']->id]]))
            ->assertOk()->getContent();
        $tbody = $this->tbodyHtml($html);

        $this->assertStringContainsString($m['mesinA']->id_mesin, $tbody);
        $this->assertStringNotContainsString($m['mesinB']->id_mesin, $tbody);
    }

    public function test_tanpa_filter_mesin_ids_menampilkan_semua(): void
    {
        $m = $this->duaMesin();

        $html = $this->get(route('input.produksi.index', self::RENTANG))->assertOk()->getContent();
        $tbody = $this->tbodyHtml($html);

        $this->assertStringContainsString($m['mesinA']->id_mesin, $tbody);
        $this->assertStringContainsString($m['mesinB']->id_mesin, $tbody);
    }

    public function test_halaman_memanggil_komponen_shared_dengan_data_awal(): void
    {
        $m = $this->duaMesin();

        $html = $this->get(route('input.produksi.index', [...self::RENTANG, 'mesin_ids' => [$m['mesinA']->id]]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('mesinFilterComp(_mesinForJs, _mesinSelected)', $html);
    }
}
