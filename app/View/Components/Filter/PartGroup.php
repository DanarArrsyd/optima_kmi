<?php

namespace App\View\Components\Filter;

use App\Models\MasterPart;
use Illuminate\Support\Collection;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Kelompok "Part & Customer" baku di dock filter: Customer, Nama Part, Proses,
 * dan Nomor Part readonly yang diturunkan dari Nama Part (partGroupComp).
 *
 * Komponen ini memuat datanya SENDIRI dan memasang _customersTA/_partsTA/
 * _prosesTA/_partsFullData ke halaman. Dulu tiap halaman wajib menyediakannya;
 * lupa satu = ReferenceError senyap di browser, test server tetap hijau
 * (kejadian PR #178 untuk _mesinForJs).
 */
class PartGroup extends Component
{
    /** @var Collection<int, MasterPart> */
    public Collection $parts;

    public string $partLabel = '';

    public string $prosesLabel = '';

    /**
     * Proses produksi, proses repair, dan proses repair produksi itu tiga
     * tabel berbeda — LHR Repair menyaring id_part_proses_repair, bukan
     * id_part_proses. Salah relasi = daftar Proses berisi id yang tak cocok.
     */
    private const RELASI = ['proses', 'prosesRepair', 'prosesRepairProduksi'];

    public function __construct(
        public string $customerName = 'filter_customer',
        public string $partName = 'filter_part_id',
        public string $prosesName = 'filter_proses_id',
        public ?string $customer = null,
        public int|string|null $partId = null,
        public int|string|null $prosesId = null,
        public bool $denganCustomer = true,
        public bool $denganProses = true,
        public ?string $idCustomer = null,
        public ?string $idPart = null,
        public ?string $idProses = null,
        public string $relasiProses = 'proses',
        public string $labelProses = 'Proses',
    ) {
        abort_unless(in_array($relasiProses, self::RELASI, true), 500, "Relasi proses tak dikenal: {$relasiProses}");
        $this->parts = MasterPart::with($relasiProses)->orderBy('part_name')->get();
        $this->partId = (int) $partId ?: null;
        $this->prosesId = (int) $prosesId ?: null;

        if ($this->partId) {
            $this->partLabel = (string) ($this->parts->firstWhere('id', $this->partId)?->part_name ?? '');
        }
        if ($this->prosesId) {
            $pr = $this->parts->flatMap(fn ($p) => $p->{$this->relasiProses})->firstWhere('id', $this->prosesId);
            $this->prosesLabel = $pr ? $pr->id_proses.' – '.$pr->nama_proses : '';
        }
    }

    /** @return array<string, mixed> data JS yang dibaca partGroupComp & kotak Customer */
    public function dataJs(): array
    {
        $penuh = $this->parts->map(fn ($p) => [
            'id' => $p->id,
            'part_no' => $p->part_no,
            'part_name' => $p->part_name,
            'customer' => $p->customer,
            'proses' => $p->{$this->relasiProses}->map(fn ($pr) => ['id' => $pr->id, 'label' => $pr->id_proses.' – '.$pr->nama_proses])->values(),
        ])->values();

        return [
            '_customersTA' => $this->parts->pluck('customer')->filter()->unique()->sort()->values()
                ->map(fn ($c) => ['v' => $c, 't' => $c]),
            '_partsTA' => $penuh->map(fn ($p) => ['v' => $p['id'], 't' => $p['part_name'], 'sub' => $p['part_no'], 'pno' => $p['part_no']]),
            '_prosesTA' => $penuh->flatMap(fn ($p) => $p['proses'])->unique('id')->values()
                ->map(fn ($pr) => ['v' => $pr['id'], 't' => $pr['label']]),
            '_partsFullData' => $penuh,
        ];
    }

    public function render(): View
    {
        return view('components.filter.part-group');
    }
}
