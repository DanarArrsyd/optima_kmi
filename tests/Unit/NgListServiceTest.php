<?php

namespace Tests\Unit;

use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\TransaksiProduksiNg;
use App\Models\TransaksiQc;
use App\Models\TransaksiQcNg;
use App\Models\User;
use App\Services\NgListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class NgListServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $user = User::factory()->create(['role' => 'admin']);
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $operator = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Operator A', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $inspector = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Inspector B', 'departemen' => 'QC', 'is_aktif' => true]);
        $mesin = MasterMesin::create(['id_mesin' => 'A4', 'nama_mesin' => 'Mesin A4', 'line' => '1', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        $proses = $this->partProses('PN-001', 'Bracket', 'Toyota');

        $dent = MasterJenisNg::create(['kode' => 'NG-01', 'nama_ng' => 'Dent']);
        $burr = MasterJenisNg::create(['kode' => 'NG-02', 'nama_ng' => 'Burr']);
        $crack = MasterJenisNg::create(['kode' => 'NG-03', 'nama_ng' => 'Crack']);

        return compact('user', 'shift', 'operator', 'inspector', 'mesin', 'proses', 'dent', 'burr', 'crack');
    }

    private function partProses(string $partNo, string $partName, string $customer): MasterPartProses
    {
        $part = MasterPart::create(['part_no' => $partNo, 'part_name' => $partName, 'customer' => $customer]);

        return MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
    }

    /** Satu sesi produksi + baris detail NG-nya. `$ng` = [jenisNgModel => qty]. */
    private function sesiProduksi(array $f, string $tanggal, array $ng, ?MasterPartProses $proses = null, ?MasterKaryawan $operator = null): TransaksiProduksi
    {
        $trx = TransaksiProduksi::create([
            'tanggal_produksi' => $tanggal, 'id_shift' => $f['shift']->id,
            'id_karyawan' => ($operator ?? $f['operator'])->id, 'id_mesin' => $f['mesin']->id,
            'id_part_proses' => ($proses ?? $f['proses'])->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00',
            'qty_aktual' => 100, 'qty_ng' => array_sum($ng), 'created_by' => $f['user']->id,
        ]);

        foreach ($ng as $jenisId => $qty) {
            TransaksiProduksiNg::create(['id_transaksi_produksi' => $trx->id, 'id_jenis_ng' => $jenisId, 'qty' => $qty]);
        }

        return $trx;
    }

    /** Satu sesi QC + baris detail NG-nya. */
    private function sesiQc(array $f, string $tanggal, array $ng, ?MasterPartProses $proses = null, ?MasterKaryawan $inspector = null): TransaksiQc
    {
        $trx = TransaksiQc::create([
            'tgl_pengecekan' => $tanggal, 'tgl_produksi_part' => $tanggal, 'id_shift' => $f['shift']->id,
            'id_karyawan_qc' => ($inspector ?? $f['inspector'])->id,
            'id_part_proses' => ($proses ?? $f['proses'])->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00',
            'qty_ok' => 100, 'qty_ng' => array_sum($ng), 'created_by' => $f['user']->id,
        ]);

        foreach ($ng as $jenisId => $qty) {
            TransaksiQcNg::create(['id_transaksi_qc' => $trx->id, 'id_jenis_ng' => $jenisId, 'qty' => $qty]);
        }

        return $trx;
    }

    /** Rentang yang memuat semua fixture — filter tanggal diuji terpisah. */
    private function semua(array $extra = []): array
    {
        return array_merge(['date_from' => '2020-01-01', 'date_to' => '2030-12-31'], $extra);
    }

    /**
     * Inti halaman ini: satu baris = satu jenis NG dalam satu sesi. Satu LHP yang
     * mencatat tiga jenis NG harus jadi tiga baris, bukan satu baris agregat —
     * granularitas itu yang membuat filter per jenis NG bekerja tepat.
     */
    public function test_one_session_with_three_ng_types_yields_three_rows(): void
    {
        $f = $this->fixtures();
        $this->sesiProduksi($f, '2026-03-01', [$f['dent']->id => 5, $f['burr']->id => 3, $f['crack']->id => 2]);

        $rows = (new NgListService)->rows('produksi', $this->semua());

        $this->assertCount(3, $rows);
        $this->assertEqualsCanonicalizing([5, 3, 2], $rows->pluck('qty')->all());
    }

    public function test_produksi_never_returns_qc_rows(): void
    {
        $f = $this->fixtures();
        $this->sesiProduksi($f, '2026-03-01', [$f['dent']->id => 5]);
        $this->sesiQc($f, '2026-03-01', [$f['burr']->id => 9]);

        $rows = (new NgListService)->rows('produksi', $this->semua());

        $this->assertCount(1, $rows);
        $this->assertSame(5, $rows->first()->qty);
    }

    public function test_qc_never_returns_produksi_rows(): void
    {
        $f = $this->fixtures();
        $this->sesiProduksi($f, '2026-03-01', [$f['dent']->id => 5]);
        $this->sesiQc($f, '2026-03-01', [$f['burr']->id => 9]);

        $rows = (new NgListService)->rows('qc', $this->semua());

        $this->assertCount(1, $rows);
        $this->assertSame(9, $rows->first()->qty);
    }

    /** Sumber tak dikenal harus MELEDAK, bukan diam-diam mengembalikan nol baris. */
    public function test_unknown_sumber_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new NgListService)->rows('gudang', $this->semua());
    }

    public function test_date_range_excludes_rows_outside_it(): void
    {
        $f = $this->fixtures();
        $this->sesiProduksi($f, '2026-01-15', [$f['dent']->id => 1]);
        $this->sesiProduksi($f, '2026-03-15', [$f['burr']->id => 2]);
        $this->sesiProduksi($f, '2026-06-15', [$f['crack']->id => 3]);

        $rows = (new NgListService)->rows('produksi', ['date_from' => '2026-02-01', 'date_to' => '2026-04-30']);

        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows->first()->qty);
    }

    /** Alasan halaman ini ada: menyaring per jenis NG sampai ke baris. */
    public function test_jenis_ng_filter_returns_only_that_type(): void
    {
        $f = $this->fixtures();
        $this->sesiProduksi($f, '2026-03-01', [$f['dent']->id => 5, $f['burr']->id => 3]);
        $this->sesiProduksi($f, '2026-04-01', [$f['burr']->id => 7]);

        $rows = (new NgListService)->rows('produksi', $this->semua(['jenis_ng_id' => $f['burr']->id]));

        $this->assertCount(2, $rows);
        $this->assertSame(['NG-02', 'NG-02'], $rows->pluck('kode_ng')->all());
    }

    public function test_text_filter_matches_part_no(): void
    {
        $f = $this->fixtures();
        $lain = $this->partProses('ZZ-999', 'Cover', 'Honda');
        $this->sesiProduksi($f, '2026-03-01', [$f['dent']->id => 5]);
        $this->sesiProduksi($f, '2026-03-02', [$f['burr']->id => 9], $lain);

        $rows = (new NgListService)->rows('produksi', $this->semua(['q' => 'PN-001']));

        $this->assertCount(1, $rows);
        $this->assertSame(5, $rows->first()->qty);
    }

    public function test_text_filter_matches_part_name(): void
    {
        $f = $this->fixtures();
        $lain = $this->partProses('ZZ-999', 'Cover', 'Honda');
        $this->sesiProduksi($f, '2026-03-01', [$f['dent']->id => 5]);
        $this->sesiProduksi($f, '2026-03-02', [$f['burr']->id => 9], $lain);

        $rows = (new NgListService)->rows('produksi', $this->semua(['q' => 'Bracket']));

        $this->assertCount(1, $rows);
        $this->assertSame(5, $rows->first()->qty);
    }

    public function test_text_filter_matches_customer(): void
    {
        $f = $this->fixtures();
        $lain = $this->partProses('ZZ-999', 'Cover', 'Honda');
        $this->sesiProduksi($f, '2026-03-01', [$f['dent']->id => 5]);
        $this->sesiProduksi($f, '2026-03-02', [$f['burr']->id => 9], $lain);

        $rows = (new NgListService)->rows('produksi', $this->semua(['q' => 'Honda']));

        $this->assertCount(1, $rows);
        $this->assertSame(9, $rows->first()->qty);
    }

    public function test_karyawan_filter_narrows_to_that_operator_on_produksi_side(): void
    {
        $f = $this->fixtures();
        $lain = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Operator C', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $this->sesiProduksi($f, '2026-03-01', [$f['dent']->id => 5]);
        $this->sesiProduksi($f, '2026-03-02', [$f['burr']->id => 9], null, $lain);

        $rows = (new NgListService)->rows('produksi', $this->semua(['karyawan_id' => $lain->id]));

        $this->assertCount(1, $rows);
        $this->assertSame('Operator C', $rows->first()->orang);
    }

    /** Sisi QC menyaring lewat id_karyawan_qc (`karyawanQc`), bukan id_karyawan. */
    public function test_karyawan_filter_narrows_to_that_inspector_on_qc_side(): void
    {
        $f = $this->fixtures();
        $lain = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Inspector D', 'departemen' => 'QC', 'is_aktif' => true]);
        $this->sesiQc($f, '2026-03-01', [$f['dent']->id => 5]);
        $this->sesiQc($f, '2026-03-02', [$f['burr']->id => 9], null, $lain);

        $rows = (new NgListService)->rows('qc', $this->semua(['karyawan_id' => $lain->id]));

        $this->assertCount(1, $rows);
        $this->assertSame('Inspector D', $rows->first()->orang);
    }

    /** Beberapa filter aktif digabung AND, bukan OR. */
    public function test_multiple_filters_combine_with_and(): void
    {
        $f = $this->fixtures();
        $honda = $this->partProses('ZZ-999', 'Cover', 'Honda');

        // Cocok jenis, salah customer.
        $this->sesiProduksi($f, '2026-03-01', [$f['burr']->id => 7], $honda);
        // Cocok customer, salah jenis.
        $this->sesiProduksi($f, '2026-03-02', [$f['dent']->id => 4]);
        // Cocok keduanya — hanya ini yang boleh lolos.
        $this->sesiProduksi($f, '2026-03-03', [$f['burr']->id => 11]);

        $rows = (new NgListService)->rows('produksi', $this->semua([
            'jenis_ng_id' => $f['burr']->id,
            'q' => 'Toyota',
        ]));

        $this->assertCount(1, $rows);
        $this->assertSame(11, $rows->first()->qty);
    }

    /** Filter kosong berarti tidak menyaring — bukan "tidak cocok apa pun". */
    public function test_empty_filters_do_not_narrow(): void
    {
        $f = $this->fixtures();
        $this->sesiProduksi($f, '2026-03-01', [$f['dent']->id => 5, $f['burr']->id => 3]);

        $rows = (new NgListService)->rows('produksi', $this->semua([
            'jenis_ng_id' => null, 'q' => null, 'karyawan_id' => null,
        ]));

        $this->assertCount(2, $rows);
    }

    public function test_rows_are_sorted_by_newest_date_then_largest_qty(): void
    {
        $f = $this->fixtures();
        $this->sesiProduksi($f, '2026-03-01', [$f['dent']->id => 99]);
        $this->sesiProduksi($f, '2026-05-01', [$f['dent']->id => 2, $f['burr']->id => 40, $f['crack']->id => 7]);

        $rows = (new NgListService)->rows('produksi', $this->semua());

        $this->assertSame([40, 7, 2, 99], $rows->pluck('qty')->all());
    }

    /**
     * Query berangkat dari model induk justru supaya global scope SoftDeletes
     * berlaku: sesi yang dihapus tidak boleh muncul, meski baris detailnya masih
     * ada (tabel detail sengaja tanpa SoftDeletes).
     */
    public function test_soft_deleted_session_disappears_from_the_list(): void
    {
        $f = $this->fixtures();
        $hidup = $this->sesiProduksi($f, '2026-03-01', [$f['dent']->id => 5]);
        $mati = $this->sesiProduksi($f, '2026-03-02', [$f['burr']->id => 9]);

        $mati->delete();

        $rows = (new NgListService)->rows('produksi', $this->semua());

        $this->assertCount(1, $rows);
        $this->assertSame($hidup->id, (int) $rows->first()->id_sesi);
    }

    public function test_soft_deleted_qc_session_disappears_from_the_list(): void
    {
        $f = $this->fixtures();
        $this->sesiQc($f, '2026-03-01', [$f['dent']->id => 5]);
        $this->sesiQc($f, '2026-03-02', [$f['burr']->id => 9])->delete();

        $this->assertCount(1, (new NgListService)->rows('qc', $this->semua()));
    }

    /**
     * Jendela antara rsync dan `artisan migrate` di prod: kode sudah live sebelum
     * tabelnya ada. Halaman harus kosong, bukan 500.
     */
    public function test_returns_empty_when_detail_tables_do_not_exist_yet(): void
    {
        $f = $this->fixtures();
        $this->sesiProduksi($f, '2026-03-01', [$f['dent']->id => 5]);

        \Schema::drop('transaksi_produksi_ng');

        $this->assertCount(0, (new NgListService)->rows('produksi', $this->semua()));
        $this->assertCount(0, (new NgListService)->paginate('produksi', $this->semua()));
    }

    public function test_paginate_defaults_to_fifty_rows_per_page(): void
    {
        $f = $this->fixtures();
        $this->sesiProduksi($f, '2026-03-01', [$f['dent']->id => 5]);

        $page = (new NgListService)->paginate('produksi', $this->semua());

        $this->assertInstanceOf(LengthAwarePaginator::class, $page);
        $this->assertSame(50, $page->perPage());
        $this->assertSame(1, $page->total());
    }

    public function test_paginate_splits_pages_and_keeps_the_ordering(): void
    {
        $f = $this->fixtures();
        $this->sesiProduksi($f, '2026-03-01', [$f['dent']->id => 30, $f['burr']->id => 20, $f['crack']->id => 10]);

        $svc = new NgListService;
        $page1 = $svc->paginate('produksi', $this->semua(), 2);

        $this->assertSame(3, $page1->total());
        $this->assertSame(2, $page1->lastPage());
        $this->assertSame([30, 20], $page1->pluck('qty')->all());
    }

    /** Export dan layar harus sepakat: rows() memakai filter yang sama persis. */
    public function test_rows_respects_the_same_filters_as_paginate(): void
    {
        $f = $this->fixtures();
        $this->sesiProduksi($f, '2026-03-01', [$f['dent']->id => 5, $f['burr']->id => 3]);

        $filter = $this->semua(['jenis_ng_id' => $f['dent']->id]);
        $svc = new NgListService;

        $this->assertCount(1, $svc->rows('produksi', $filter));
        $this->assertSame(1, $svc->paginate('produksi', $filter)->total());
    }

    /** Kolom mesin hanya ada di sisi Produksi — transaksi_qc tak punya id_mesin. */
    public function test_produksi_rows_expose_mesin_and_qc_rows_do_not(): void
    {
        $f = $this->fixtures();
        $this->sesiProduksi($f, '2026-03-01', [$f['dent']->id => 5]);
        $this->sesiQc($f, '2026-03-01', [$f['burr']->id => 9]);

        $svc = new NgListService;

        $this->assertSame('Mesin A4', $svc->rows('produksi', $this->semua())->first()->mesin);
        $this->assertObjectNotHasProperty('mesin', $svc->rows('qc', $this->semua())->first());
    }
}
