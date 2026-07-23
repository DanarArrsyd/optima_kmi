<?php

namespace Tests\Unit;

use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\TransaksiBreakdownDies;
use App\Models\TransaksiBreakdownMesin;
use App\Services\ChatbotTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RootCauseTest extends TestCase
{
    use RefreshDatabase;

    private function mesin(string $kode = 'A4', string $nama = 'AMADA 80T'): MasterMesin
    {
        return MasterMesin::create(['id_mesin' => $kode, 'nama_mesin' => $nama, 'line' => 'A', 'tipe_mesin' => 'STAMPING', 'kapasitas_max_stroke' => 100]);
    }

    private function bdMesin(int $mesinId, string $problem, string $penyebab, string $tindakan, string $tgl = '2026-07-10'): void
    {
        TransaksiBreakdownMesin::create([
            'no_lkm' => 'LKM-'.uniqid(), 'id_mesin' => $mesinId,
            'tanggal_breakdown' => $tgl, 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => $tgl, 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => $tgl, 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => $problem, 'penyebab_breakdown' => $penyebab, 'tindakan_perbaikan' => $tindakan,
            'status_breakdown' => 'Ringan', 'pic_maintenance' => 'Budi',
            'repair_time' => 1, 'total_downtime' => 1,
        ]);
    }

    public function test_focuses_top_problem_and_lists_recorded_causes(): void
    {
        $m = $this->mesin();
        // Problem "OVERHEAT" 3×, "BUNYI" 1× → fokus OVERHEAT.
        $this->bdMesin($m->id, 'OVERHEAT', 'Oli kurang', 'Tambah oli');
        $this->bdMesin($m->id, 'OVERHEAT', 'Oli kurang', 'Tambah oli');
        $this->bdMesin($m->id, 'OVERHEAT', 'Fan mati', 'Ganti fan');
        $this->bdMesin($m->id, 'BUNYI', 'Baut kendor', 'Kencangkan');

        $r = (new ChatbotTools)->getRootCause('mesin', 'A4');

        $this->assertSame('OVERHEAT', $r['problem_fokus']);
        $this->assertSame(3, $r['total_kejadian_problem']);
        $this->assertSame(4, $r['total_kejadian_semua']);
        $this->assertSame(75.0, $r['pct_dari_semua']);
        // Penyebab tersering "Oli kurang" (2×) di atas "Fan mati" (1×).
        $this->assertSame('Oli kurang', $r['penyebab_tercatat'][0]['teks']);
        $this->assertSame(2, $r['penyebab_tercatat'][0]['jumlah']);
        $this->assertNotEmpty($r['contoh_kejadian']);
        $this->assertArrayHasKey('catatan', $r);
    }

    public function test_resolves_machine_by_name(): void
    {
        $m = $this->mesin('A4', 'AMADA 80T');
        $this->bdMesin($m->id, 'OVERHEAT', 'Oli kurang', 'Tambah oli');

        $r = (new ChatbotTools)->getRootCause('mesin', 'AMADA 80T'); // pakai nama
        $this->assertSame('AMADA 80T', $r['fokus']);
        $this->assertSame('OVERHEAT', $r['problem_fokus']);
    }

    public function test_filter_specific_problem(): void
    {
        $m = $this->mesin();
        $this->bdMesin($m->id, 'OVERHEAT', 'Oli kurang', 'Tambah oli');
        $this->bdMesin($m->id, 'OVERHEAT', 'Oli kurang', 'Tambah oli');
        $this->bdMesin($m->id, 'BUNYI KASAR', 'Bearing aus', 'Ganti bearing');

        $r = (new ChatbotTools)->getRootCause('mesin', 'A4', 'bunyi');
        $this->assertSame('BUNYI KASAR', $r['problem_fokus']);
        $this->assertSame(1, $r['total_kejadian_problem']);
    }

    public function test_dies_target(): void
    {
        $part = MasterPart::create(['part_no' => 'PT-D1', 'part_name' => 'Bracket', 'customer' => 'Toyota']);
        $pr = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BLANK', 'sph_standar_produksi' => 400, 'sph_standar_qc' => 200]);
        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD-'.uniqid(), 'id_part_proses' => $pr->id,
            'tanggal_breakdown' => '2026-07-10', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-10', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-10', 'jam_selesai_perbaikan' => '11:00',
            'problem_dies' => 'CRACK', 'penyebab_breakdown' => 'Fatigue', 'tindakan_perbaikan' => 'Welding',
            'status_breakdown' => 'Sedang', 'pic_maintenance' => 'Budi',
            'repair_time' => 1, 'total_downtime' => 1,
        ]);

        $r = (new ChatbotTools)->getRootCause('dies', 'PT-D1');
        $this->assertSame('CRACK', $r['problem_fokus']);
        $this->assertSame('Fatigue', $r['penyebab_tercatat'][0]['teks']);
    }

    public function test_invalid_target_errors(): void
    {
        $r = (new ChatbotTools)->getRootCause('operator');
        $this->assertArrayHasKey('error', $r);
    }

    public function test_no_data_errors(): void
    {
        $this->mesin();
        $r = (new ChatbotTools)->getRootCause('mesin', 'A4');
        $this->assertArrayHasKey('error', $r);
    }
}
