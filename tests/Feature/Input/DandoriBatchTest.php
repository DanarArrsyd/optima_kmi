<?php

namespace Tests\Feature\Input;

use App\Models\MasterKaryawan;
use App\Models\MasterLosstime;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiDandoriRincian;
use App\Models\User;
use App\Services\DandoriRincianService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DandoriBatchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private array $ref;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Operator Batch', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $mesin = MasterMesin::create([
            'id_mesin' => 'DB-001', 'nama_mesin' => 'MESIN DB', 'line' => 'A',
            'tipe_mesin' => 'STAMPING', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'DB-P1', 'part_name' => 'Dandori Batch Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'STAMPING',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        $prosesProgressive = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 2, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        // std_menit_default dan std_menit_progressive SENGAJA beda supaya
        // test_proses_progressive_memakai_std_progressive dan
        // test_std_menit_diambil_dari_master_bukan_dari_payload tidak bisa
        // lolos hanya karena kedua angka itu kebetulan sama.
        $losstime = MasterLosstime::create([
            'kode' => 'LT1', 'jenis_losstime' => 'Ganti Dies',
            'std_menit_default' => 20, 'std_menit_progressive' => 35,
        ]);
        $losstimeKedua = MasterLosstime::create([
            'kode' => 'LT2', 'jenis_losstime' => 'Setting Ulang',
            'std_menit_default' => 10, 'std_menit_progressive' => 15,
        ]);

        $this->ref = [
            'shift' => $shift->id,
            'karyawan' => $karyawan->id,
            'mesin' => $mesin->id,
            'proses' => $proses->id,
            'prosesProgressive' => $prosesProgressive->id,
            'losstime' => $losstime->id,
            'losstimeKedua' => $losstimeKedua->id,
        ];
    }

    private function baris(array $ubah = []): array
    {
        return [
            'tanggal' => '2026-07-28',
            'id_shift' => $this->ref['shift'],
            'id_karyawan' => $this->ref['karyawan'],
            'id_mesin' => $this->ref['mesin'],
            'id_part_proses' => $this->ref['proses'],
            'rincian' => [
                ['id_losstime' => $this->ref['losstime'], 'actual_menit' => 25],
            ],
            ...$ubah,
        ];
    }

    private function kirim(array $rows)
    {
        return $this->actingAs($this->admin)->post(route('input.dandori.batch'), ['rows' => $rows]);
    }

    private function kirimJson(array $rows)
    {
        return $this->actingAs($this->admin)
            ->post(route('input.dandori.batch'), ['rows_json' => json_encode($rows)]);
    }

    public function test_satu_submit_menulis_semua_sesi(): void
    {
        $response = $this->kirim([
            $this->baris(),
            $this->baris(['id_karyawan' => $this->ref['karyawan']]),
        ]);

        $response->assertRedirect(route('input.dandori.index'));
        $this->assertSame(2, TransaksiDandoriRincian::count());
    }

    public function test_satu_sesi_menulis_satu_baris_per_rincian(): void
    {
        $this->kirim([
            $this->baris([
                'rincian' => [
                    ['id_losstime' => $this->ref['losstime'], 'actual_menit' => 25],
                    ['id_losstime' => $this->ref['losstimeKedua'], 'actual_menit' => 12],
                    ['id_losstime' => $this->ref['losstime'], 'actual_menit' => 8],
                ],
            ]),
        ]);

        $this->assertSame(3, TransaksiDandoriRincian::count());

        $baris = TransaksiDandoriRincian::all();
        foreach ($baris as $b) {
            $this->assertSame('2026-07-28', $b->tanggal->format('Y-m-d'));
            $this->assertSame($this->ref['shift'], $b->id_shift);
            $this->assertSame($this->ref['karyawan'], $b->id_karyawan);
            $this->assertSame($this->ref['mesin'], $b->id_mesin);
            $this->assertSame($this->ref['proses'], $b->id_part_proses);
        }
    }

    public function test_flash_menghitung_baris_tabel_bukan_baris_draft(): void
    {
        $this->kirim([
            $this->baris([
                'rincian' => [
                    ['id_losstime' => $this->ref['losstime'], 'actual_menit' => 25],
                    ['id_losstime' => $this->ref['losstimeKedua'], 'actual_menit' => 12],
                ],
            ]),
            $this->baris([
                'rincian' => [
                    ['id_losstime' => $this->ref['losstime'], 'actual_menit' => 25],
                    ['id_losstime' => $this->ref['losstimeKedua'], 'actual_menit' => 12],
                    ['id_losstime' => $this->ref['losstime'], 'actual_menit' => 5],
                ],
            ]),
        ])->assertSessionHas('batch_tersimpan', 5);
    }

    public function test_satu_baris_gagal_membatalkan_semuanya(): void
    {
        // Sesi kedua memakai id_shift yang tidak ada: request DITOLAK di
        // FormRequest, controller tidak pernah dimasuki, jadi tidak ada
        // satu pun baris yang sempat ditulis untuk dibatalkan.
        $response = $this->kirim([
            $this->baris(),
            $this->baris(['id_shift' => 999999]),
        ]);

        $response->assertSessionHasErrors('rows.1.id_shift');
        $this->assertSame(0, TransaksiDandoriRincian::count());
    }

    public function test_gagal_di_tengah_transaksi_tidak_menyisakan_baris(): void
    {
        // Validasi LOLOS, jadi controller sungguh masuk DB::transaction() dan
        // sesi pertama sungguh tertulis ke DB (double di bawah memanggil
        // parent::simpan() sungguhan untuk panggilan pertama). Baru pada
        // panggilan kedua DandoriRincianService dipaksa meledak — membuktikan
        // baris pertama ikut dibatalkan oleh transaksi.
        $this->app->instance(DandoriRincianService::class, new class extends DandoriRincianService
        {
            private int $panggilan = 0;

            public function simpan(array $sesi, array $rincian): int
            {
                $this->panggilan++;

                if ($this->panggilan >= 2) {
                    throw new \RuntimeException('sesi kedua sengaja gagal untuk menguji rollback');
                }

                return parent::simpan($sesi, $rincian);
            }
        });

        $this->withoutExceptionHandling();

        try {
            $this->kirim([
                $this->baris(),
                $this->baris(),
            ]);
        } catch (\RuntimeException $e) {
            $this->assertSame('sesi kedua sengaja gagal untuk menguji rollback', $e->getMessage());
        }

        $this->assertSame(0, TransaksiDandoriRincian::count());
    }

    public function test_rincian_menempel_pada_sesinya_masing_masing(): void
    {
        $karyawanLain = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Operator Lain', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);

        $this->kirim([
            $this->baris([
                'rincian' => [
                    ['id_losstime' => $this->ref['losstime'], 'actual_menit' => 25],
                    ['id_losstime' => $this->ref['losstimeKedua'], 'actual_menit' => 12],
                ],
            ]),
            $this->baris([
                'id_karyawan' => $karyawanLain->id,
                'rincian' => [
                    ['id_losstime' => $this->ref['losstime'], 'actual_menit' => 40],
                ],
            ]),
        ]);

        $sesiSatu = TransaksiDandoriRincian::where('id_karyawan', $this->ref['karyawan'])->get();
        $sesiDua = TransaksiDandoriRincian::where('id_karyawan', $karyawanLain->id)->get();

        $this->assertCount(2, $sesiSatu);
        $this->assertCount(1, $sesiDua);
        $this->assertSame(40.0, (float) $sesiDua->first()->actual_menit);
    }

    public function test_std_menit_diambil_dari_master_bukan_dari_payload(): void
    {
        $this->kirim([
            $this->baris([
                'rincian' => [
                    [
                        'id_losstime' => $this->ref['losstime'],
                        'actual_menit' => 25,
                        // Palsu — DandoriRincianService tidak pernah membaca
                        // std_menit/over_menit dari payload, jadi kalau kedua
                        // angka ini bocor ke DB, endpoint salah dipercaya
                        // pada input browser.
                        'std_menit' => 999,
                        'over_menit' => 999,
                    ],
                ],
            ]),
        ]);

        $baris = TransaksiDandoriRincian::first();
        // id_part_proses baris() adalah proses STAMPING (bukan PROGRESSIVE),
        // jadi std_menit_default (20) yang dipakai, bukan std_menit_progressive (35).
        $this->assertSame(20.0, (float) $baris->std_menit);
        $this->assertSame(5.0, (float) $baris->over_menit);
    }

    public function test_proses_progressive_memakai_std_progressive(): void
    {
        $this->kirim([
            $this->baris([
                'id_part_proses' => $this->ref['prosesProgressive'],
                'rincian' => [
                    ['id_losstime' => $this->ref['losstime'], 'actual_menit' => 40],
                ],
            ]),
        ]);

        $baris = TransaksiDandoriRincian::first();
        $this->assertSame(35.0, (float) $baris->std_menit);
        $this->assertSame(5.0, (float) $baris->over_menit);
    }

    public function test_over_menit_tidak_pernah_negatif(): void
    {
        $this->kirim([
            $this->baris([
                'rincian' => [
                    ['id_losstime' => $this->ref['losstime'], 'actual_menit' => 1],
                ],
            ]),
        ]);

        $baris = TransaksiDandoriRincian::first();
        $this->assertSame(0.0, (float) $baris->over_menit);
    }

    public function test_jenis_loss_time_kembar_diterima(): void
    {
        // SENGAJA tanpa `distinct` (PR #118): satu sesi bisa punya beberapa
        // pergantian dies yang memunculkan jenis loss time yang sama lagi.
        $response = $this->kirim([
            $this->baris([
                'rincian' => [
                    ['id_losstime' => $this->ref['losstime'], 'actual_menit' => 25],
                    ['id_losstime' => $this->ref['losstime'], 'actual_menit' => 8],
                ],
            ]),
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame(2, TransaksiDandoriRincian::count());
    }

    public function test_rincian_kosong_ditolak_per_baris(): void
    {
        $response = $this->kirim([
            $this->baris(),
            $this->baris(['rincian' => []]),
        ]);

        $response->assertSessionHasErrors('rows.1.rincian');
        $response->assertSessionDoesntHaveErrors('rows.0.rincian');
        $this->assertSame(0, TransaksiDandoriRincian::count());
    }

    public function test_rows_kosong_ditolak(): void
    {
        $this->kirim([])->assertSessionHasErrors('rows');
    }

    public function test_guest_tidak_bisa_menyimpan(): void
    {
        $guest = User::factory()->create(['role' => 'guest']);

        $this->actingAs($guest)->post(route('input.dandori.batch'), ['rows' => [$this->baris()]])
            ->assertForbidden();

        $this->assertSame(0, TransaksiDandoriRincian::count());
    }

    public function test_rows_json_menyimpan_baris(): void
    {
        $this->kirimJson([$this->baris()])->assertRedirect(route('input.dandori.index'));
        $this->assertSame(1, TransaksiDandoriRincian::count());
    }

    public function test_rows_json_rusak_ditolak_bukan_meledak(): void
    {
        $this->actingAs($this->admin)
            ->post(route('input.dandori.batch'), ['rows_json' => '{bukan json'])
            ->assertSessionHasErrors('rows');

        $this->assertSame(0, TransaksiDandoriRincian::count());
    }

    public function test_rows_json_menampung_50_baris_batas_server(): void
    {
        $rows = [];
        for ($i = 0; $i < 50; $i++) {
            $rows[] = $this->baris(['rincian' => [
                ['id_losstime' => $this->ref['losstime'], 'actual_menit' => 10 + $i],
            ]]);
        }

        $this->kirimJson($rows)->assertRedirect(route('input.dandori.index'));
        $this->assertSame(50, TransaksiDandoriRincian::count());
    }

    public function test_lebih_dari_50_baris_ditolak(): void
    {
        $rows = [];
        for ($i = 0; $i < 51; $i++) {
            $rows[] = $this->baris(['rincian' => [
                ['id_losstime' => $this->ref['losstime'], 'actual_menit' => 10 + $i],
            ]]);
        }

        $this->kirimJson($rows)->assertSessionHasErrors('rows');
        $this->assertSame(0, TransaksiDandoriRincian::count());
    }

    public function test_pesan_validasi_berbahasa_indonesia(): void
    {
        // tanggal.after_or_equal dipicu dengan tahun ketik salah (0026),
        // sesuai kejadian nyata di produksi yang melatarbelakangi pesan ini.
        $this->kirim([$this->baris(['tanggal' => '0026-07-28'])]);

        $pesan = session('errors')['default']['messages']['rows.0.tanggal'][0] ?? '';

        $this->assertSame(
            'Baris 1: Tanggal tidak masuk akal — periksa tahunnya (contoh salah ketik: 0026 untuk 2026).',
            $pesan
        );
    }
}
