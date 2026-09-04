<?php

namespace App\Http\Controllers\Master;

use App\Exports\PartExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMasterPartProsesRequest;
use App\Http\Requests\StoreMasterPartRequest;
use App\Imports\PartImport;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterPartProsesSphLog;
use App\Services\SphRecommendationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;

class PartController extends Controller
{
    public function __construct(private SphRecommendationService $sphSvc) {}

    public function index(Request $request)
    {
        $search = trim((string) $request->input('q', ''));

        $q = MasterPartProses::with('part')
            ->join('master_part', 'master_part_proses.id_part', '=', 'master_part.id')
            ->whereNull('master_part.deleted_at')
            ->whereNull('master_part_proses.deleted_at')
            ->orderBy('master_part.part_name')
            ->orderBy('master_part_proses.id_proses')
            ->select('master_part_proses.*');

        if ($search !== '') {
            $like = '%'.$search.'%';
            $q->where(function ($sub) use ($like) {
                $sub->where('master_part.part_no', 'like', $like)
                    ->orWhere('master_part.part_name', 'like', $like)
                    ->orWhere('master_part.customer', 'like', $like)
                    ->orWhere('master_part_proses.nama_proses', 'like', $like);
            });
        }

        /* Filter per kolom di dock, di atas pencarian bebas yang sudah ada. */
        $filterCustomer = trim((string) $request->input('filter_customer', ''));
        $filterPartId = (int) $request->input('filter_part_id', 0);
        $filterProsesId = (int) $request->input('filter_proses_id', 0);

        if ($filterCustomer !== '') {
            $q->where('master_part.customer', $filterCustomer);
        }
        if ($filterPartId > 0) {
            $q->where('master_part_proses.id_part', $filterPartId);
        }
        if ($filterProsesId > 0) {
            $q->where('master_part_proses.id', $filterProsesId);
        }

        $rows = $q->paginate(30)->withQueryString();

        $isAdmin = auth()->user()?->hasRole('admin') ?? false;

        $dismissedProd = session('sph_dismissed_produksi', []);
        $dismissedQc = session('sph_dismissed_qc', []);

        $recommendations = $isAdmin
            ? $this->sphSvc->getAll()->filter(fn ($r) => ! in_array($r->id_part_proses, $dismissedProd))->values()
            : collect();
        $recommendationsQc = $isAdmin
            ? $this->sphSvc->getAllQc()->filter(fn ($r) => ! in_array($r->id_part_proses, $dismissedQc))->values()
            : collect();

        $dismissedProdCount = count($dismissedProd);
        $dismissedQcCount = count($dismissedQc);

        $customerList = MasterPart::whereNotNull('customer')->where('customer', '!=', '')
            ->distinct()->orderBy('customer')->pluck('customer');
        $partsForFilter = MasterPart::with('proses')->orderBy('part_name')->get();

        return view('master.part.index', compact(
            'rows', 'recommendations', 'recommendationsQc', 'search',
            'dismissedProdCount', 'dismissedQcCount', 'customerList',
            'filterCustomer', 'filterPartId', 'filterProsesId', 'partsForFilter'
        ));
    }

    public function export(Request $request)
    {
        return Excel::download(
            new PartExport($request->input('customer') ?: null),
            'master_part.xlsx'
        );
    }

    public function dismissSphRecommendation(Request $request): RedirectResponse
    {
        $id = (int) $request->input('id_part_proses');
        $type = $request->input('type', 'produksi');
        $key = "sph_dismissed_{$type}";

        $dismissed = session($key, []);
        if ($id && ! in_array($id, $dismissed)) {
            $dismissed[] = $id;
            session([$key => $dismissed]);
        }

        return back();
    }

    public function resetSphDismissed(Request $request): RedirectResponse
    {
        $type = $request->input('type', 'produksi');
        session()->forget("sph_dismissed_{$type}");

        return back();
    }

    public function create()
    {
        return view('master.part.create');
    }

    public function store(StoreMasterPartRequest $request)
    {
        MasterPart::create($request->validated());

        return redirect()->route('master.part.index')->with('success', 'Part berhasil ditambahkan. Tambahkan proses di halaman edit.');
    }

    public function edit(MasterPart $part)
    {
        // View edit memakai relasi $p->sphLog & $p->sphQcLog — keduanya HARUS
        // ter-load di sini. Jika tidak, Blade akan lazy-load saat render dan
        // (bila tabel sph_log belum ada di DB) melempar QueryException di tengah
        // section sehingga konten halaman kosong.
        try {
            $part->load(['proses.sphLog.upgradedBy', 'proses.sphQcLog.upgradedBy']);
        } catch (QueryException) {
            $part->load(['proses']);
            $part->proses->each(function ($p) {
                $p->setRelation('sphLog', collect());
                $p->setRelation('sphQcLog', collect());
            });
        }

        $isAdmin = auth()->user()?->hasRole('admin') ?? false;

        try {
            $recommendations = $isAdmin ? $this->sphSvc->forPart($part->id)->keyBy('id_part_proses') : collect();
            $recommendationsQc = $isAdmin ? $this->sphSvc->forPartQc($part->id)->keyBy('id_part_proses') : collect();
        } catch (QueryException) {
            $recommendations = collect();
            $recommendationsQc = collect();
        }

        return view('master.part.edit', compact('part', 'recommendations', 'recommendationsQc'));
    }

    public function update(StoreMasterPartRequest $request, MasterPart $part)
    {
        $part->update($request->validated());

        return redirect()->route('master.part.edit', $part)->with('success', 'Part berhasil diupdate.');
    }

    public function destroy(MasterPart $part)
    {
        $part->delete();

        return redirect()->route('master.part.index')->with('success', 'Part berhasil dihapus.');
    }

    public function createProses(MasterPart $part)
    {
        return view('master.part.proses-create', compact('part'));
    }

    public function storeProses(StoreMasterPartProsesRequest $request, MasterPart $part)
    {
        $part->proses()->create($request->validated());

        return redirect()->route('master.part.edit', $part)->with('success', 'Proses berhasil ditambahkan.');
    }

    public function destroyProses(MasterPart $part, MasterPartProses $proses)
    {
        $proses->delete();

        return redirect()->route('master.part.edit', $part)->with('success', 'Proses berhasil dihapus.');
    }

    /**
     * Terapkan rekomendasi upgrade SPH Standard (admin only).
     * Mencatat upgrade_at + menyimpan log agar data LHP lama tidak
     * ikut dianalisis terhadap standar baru.
     */
    public function upgradeSph(MasterPart $part, MasterPartProses $proses, Request $request)
    {
        abort_if(! (auth()->user()?->hasRole('admin') ?? false), 403);

        $newSph = (int) $request->input('recommended_sph');
        $oldSph = (int) $proses->sph_standar_produksi;

        if ($newSph <= 0 || $newSph <= $oldSph) {
            return redirect()->route('master.part.edit', $part)
                ->with('error', 'SPH rekomendasi tidak valid atau tidak lebih tinggi dari standard saat ini.');
        }

        $now = now();

        // 1. Update master data + catat kapan di-upgrade
        $proses->update([
            'sph_standar_produksi' => $newSph,
            'sph_last_upgraded_at' => $now,
        ]);

        // 2. Simpan ke log audit
        try {
            MasterPartProsesSphLog::create([
                'id_part_proses' => $proses->id,
                'type' => 'produksi',
                'sph_standar_lama' => $oldSph,
                'sph_standar_baru' => $newSph,
                'upgraded_by' => auth()->id(),
                'catatan' => $request->input('catatan'),
                'upgraded_at' => $now,
            ]);
        } catch (QueryException) {
            // tabel log belum ada — upgrade tetap tersimpan, log dilewati
        }

        return redirect()->route('master.part.edit', $part)
            ->with('success', "SPH Standard Produksi proses {$proses->nama_proses} diupgrade: {$oldSph} → {$newSph} pcs/jam. Data LHP sebelum {$now->format('d M Y')} tidak akan mempengaruhi rekomendasi berikutnya.");
    }

    /**
     * Terapkan rekomendasi upgrade SPH Standard QC (admin only).
     */
    public function upgradeQcSph(MasterPart $part, MasterPartProses $proses, Request $request)
    {
        abort_if(! (auth()->user()?->hasRole('admin') ?? false), 403);

        $newSph = (int) $request->input('recommended_sph');
        $oldSph = (int) $proses->sph_standar_qc;

        if ($newSph <= 0 || $newSph <= $oldSph) {
            return redirect()->route('master.part.edit', $part)
                ->with('error', 'SPH QC rekomendasi tidak valid atau tidak lebih tinggi dari standard saat ini.');
        }

        $now = now();

        $proses->update([
            'sph_standar_qc' => $newSph,
            'sph_qc_last_upgraded_at' => $now,
        ]);

        try {
            MasterPartProsesSphLog::create([
                'id_part_proses' => $proses->id,
                'type' => 'qc',
                'sph_standar_lama' => $oldSph,
                'sph_standar_baru' => $newSph,
                'upgraded_by' => auth()->id(),
                'catatan' => $request->input('catatan'),
                'upgraded_at' => $now,
            ]);
        } catch (QueryException) {
            // tabel log belum ada — upgrade tetap tersimpan, log dilewati
        }

        return redirect()->route('master.part.edit', $part)
            ->with('success', "SPH Standard QC proses {$proses->nama_proses} diupgrade: {$oldSph} → {$newSph} pcs/jam. Data LHP Quality sebelum {$now->format('d M Y')} tidak akan mempengaruhi rekomendasi berikutnya.");
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv|max:5120']);
        $import = new PartImport;
        Excel::import($import, $request->file('file'));

        return redirect()->route('master.part.index')
            ->with('success', "Import berhasil: {$import->imported} baris diproses, {$import->skipped} baris dilewati.");
    }

    public function importTemplate()
    {
        $export = new class implements FromArray, WithHeadings
        {
            public function headings(): array
            {
                return [
                    'Customer', 'Part No', 'Part Name', 'Tanggal Instalasi',
                    'ID Proses', 'Nama Proses',
                    'SPH Produksi',
                    'SPH QC', 'Std Trial QC Menit',
                    'Std Setting Dies Menit',
                ];
            }

            public function array(): array
            {
                return [
                    ['Toyota', 'BT-091A-3', 'Bracket RH', '2024-03-10', 1, 'PROGRESSIVE', 800, 400, 5, 15],
                    ['Toyota', 'BT-091A-3', 'Bracket RH', '2024-03-10', 2, 'BENDING',     600, 300, 5, 15],
                    ['Honda',  'HC-202B',   'Cover LH',   '2023-11-20', 1, 'PROGRESSIVE', 750, 375, 5, 20],
                ];
            }
        };

        return Excel::download($export, 'template_part.xlsx');
    }
}
