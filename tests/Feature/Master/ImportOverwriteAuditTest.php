<?php
namespace Tests\Feature\Master;

use App\Imports\MesinImport;
use App\Imports\PartImport;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * MesinImport & PartImport memuat record lama lalu menugaskan apa pun yang terbaca dari
 * Excel. Kolom yang tidak ada / sel kosong jatuh ke nilai perusak: '' untuk string,
 * 0 untuk SPH, null untuk kapasitas — menimpa data yang sudah benar, tanpa error.
 *
 * Yang paling menyakitkan bukan yang paling kelihatan:
 *  - sph_standar_* → 0  : part hilang dari statistik achievement (DashboardService memfilter
 *                          `sph_standar_produksi > 0`), dan target_qty jadi 0.
 *  - tipe_mesin → ''    : mesin hilang dari dashboard maintenance yang difilter per tipe.
 *  - customer → ''      : kosmetik.
 *
 * Template resmi memang membawa semua kolom, jadi "kolom hilang" perlu file bikinan tangan —
 * tapi "sel dikosongin" itu realistis: download template, isi, satu sel SPH kelewat.
 *
 * Keputusan: kolom hilang ATAU sel kosong = jangan sentuh nilai lama. Record BARU tetap
 * diisi seperti sebelumnya — master_mesin.line & tipe_mesin NOT NULL, jadi tak boleh dilewat.
 */
class ImportOverwriteAuditTest extends TestCase
{
    use RefreshDatabase;

    /* ── Part: customer ───────────────────────────────────────────────── */

    public function test_part_reimport_without_customer_column_keeps_existing(): void
    {
        MasterPart::create(['part_no' => 'A-1', 'part_name' => 'Panel A', 'customer' => 'Toyota']);

        (new PartImport())->collection(new Collection([
            collect(['part_no', 'part_name']),
            collect(['A-1', 'Panel A']),
        ]));

        $this->assertSame('Toyota', MasterPart::where('part_no', 'A-1')->sole()->customer);
    }

    public function test_part_reimport_with_a_real_customer_still_updates(): void
    {
        MasterPart::create(['part_no' => 'A-1', 'part_name' => 'Panel A', 'customer' => 'Toyota']);

        (new PartImport())->collection(new Collection([
            collect(['part_no', 'part_name', 'customer']),
            collect(['A-1', 'Panel A', 'Honda']),
        ]));

        $this->assertSame('Honda', MasterPart::where('part_no', 'A-1')->sole()->customer);
    }

    /* ── Part proses: SPH ─────────────────────────────────────────────── */

    private function seedPartProses(): MasterPart
    {
        $part = MasterPart::create(['part_no' => 'A-2', 'part_name' => 'Panel B', 'customer' => 'Toyota']);
        MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'DRAW',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        return $part;
    }

    public function test_part_proses_reimport_without_sph_columns_keeps_existing(): void
    {
        $part = $this->seedPartProses();

        (new PartImport())->collection(new Collection([
            collect(['part_no', 'part_name', 'id_proses', 'nama_proses']),
            collect(['A-2', 'Panel B', '1', 'DRAW']),
        ]));

        $p = MasterPartProses::where('id_part', $part->id)->sole();
        // Banding numerik: MySQL balikin "500.00", SQLite (DB test) balikin "500".
        $this->assertEqualsWithDelta(500, (float) $p->sph_standar_produksi, 0.01, 'SPH produksi kehapus jadi 0.');
        $this->assertEqualsWithDelta(250, (float) $p->sph_standar_qc, 0.01, 'SPH QC kehapus jadi 0.');
    }

    public function test_part_proses_reimport_with_real_sph_still_updates(): void
    {
        $part = $this->seedPartProses();

        (new PartImport())->collection(new Collection([
            collect(['part_no', 'part_name', 'id_proses', 'nama_proses', 'sph_produksi', 'sph_qc']),
            collect(['A-2', 'Panel B', '1', 'DRAW', '800', '400']),
        ]));

        $p = MasterPartProses::where('id_part', $part->id)->sole();
        $this->assertEqualsWithDelta(800, (float) $p->sph_standar_produksi, 0.01);
        $this->assertEqualsWithDelta(400, (float) $p->sph_standar_qc, 0.01);
    }

    /* ── Mesin ────────────────────────────────────────────────────────── */

    private function seedMesin(): void
    {
        MasterMesin::create([
            'id_mesin' => 'M-1', 'nama_mesin' => 'Press M', 'line' => 'A',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 150,
        ]);
    }

    public function test_mesin_reimport_without_line_and_tipe_columns_keeps_existing(): void
    {
        $this->seedMesin();

        (new MesinImport())->collection(new Collection([[
            'id_mesin' => 'M-1', 'nama_mesin' => 'Press M',
        ]]));

        $m = MasterMesin::where('id_mesin', 'M-1')->sole();
        $this->assertSame('A', $m->line, 'line kehapus.');
        $this->assertSame('PROGRESSIVE', $m->tipe_mesin, 'tipe_mesin kehapus.');
        $this->assertSame(150, $m->kapasitas_max_stroke, 'kapasitas kehapus.');
    }

    public function test_mesin_reimport_with_real_values_still_updates(): void
    {
        $this->seedMesin();

        (new MesinImport())->collection(new Collection([[
            'id_mesin' => 'M-1', 'nama_mesin' => 'Press M Baru', 'line' => 'B',
            'tipe_mesin' => 'STAMPING', 'kapasitas_max_stroke' => 300,
        ]]));

        $m = MasterMesin::where('id_mesin', 'M-1')->sole();
        $this->assertSame('Press M Baru', $m->nama_mesin);
        $this->assertSame('B', $m->line);
        $this->assertSame('STAMPING', $m->tipe_mesin);
        $this->assertSame(300, $m->kapasitas_max_stroke);
    }

    /** Record BARU harus tetap tersimpan — line & tipe_mesin NOT NULL di master_mesin. */
    public function test_new_mesin_without_line_and_tipe_still_saves(): void
    {
        (new MesinImport())->collection(new Collection([[
            'id_mesin' => 'M-BARU', 'nama_mesin' => 'Press Baru',
        ]]));

        $m = MasterMesin::where('id_mesin', 'M-BARU')->sole();
        $this->assertSame('', $m->line);
        $this->assertSame('', $m->tipe_mesin);
        $this->assertNull($m->kapasitas_max_stroke);
    }

    public function test_new_part_proses_without_sph_defaults_to_zero(): void
    {
        (new PartImport())->collection(new Collection([
            collect(['part_no', 'part_name', 'id_proses', 'nama_proses']),
            collect(['B-1', 'Panel Baru', '1', 'DRAW']),
        ]));

        $p = MasterPartProses::sole();
        $this->assertEqualsWithDelta(0, (float) $p->sph_standar_produksi, 0.01);
    }
}
