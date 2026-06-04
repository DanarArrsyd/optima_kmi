<?php
namespace App\Http\Controllers\Master;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMasterPartRequest;
use App\Http\Requests\StoreMasterPartProsesRequest;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Imports\PartImport;
use App\Models\MasterPartProsesSphLog;
use App\Services\SphRecommendationService;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Http\Request;

class PartController extends Controller
{
    public function __construct(private SphRecommendationService $sphSvc) {}

    public function index()
    {
        $rows = MasterPartProses::with('part')
            ->join('master_part', 'master_part_proses.id_part', '=', 'master_part.id')
            ->whereNull('master_part.deleted_at')
            ->whereNull('master_part_proses.deleted_at')
            ->orderBy('master_part.part_name')
            ->orderBy('master_part_proses.id_proses')
            ->select('master_part_proses.*')
            ->paginate(30);

        $isAdmin = auth()->user()?->role === 'admin';

        $recommendations   = $isAdmin ? $this->sphSvc->getAll()    : collect();
        $recommendationsQc = $isAdmin ? $this->sphSvc->getAllQc()   : collect();

        return view('master.part.index', compact('rows', 'recommendations', 'recommendationsQc'));
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
        $part->load(['proses.sphProduksiLog.upgradedBy', 'proses.sphQcLog.upgradedBy']);

        $isAdmin = auth()->user()?->role === 'admin';

        $recommendations   = $isAdmin ? $this->sphSvc->forPart($part->id)->keyBy('id_part_proses')   : collect();
        $recommendationsQc = $isAdmin ? $this->sphSvc->forPartQc($part->id)->keyBy('id_part_proses') : collect();

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
        abort_if(auth()->user()?->role !== 'admin', 403);

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
        MasterPartProsesSphLog::create([
            'id_part_proses'   => $proses->id,
            'type'             => 'produksi',
            'sph_standar_lama' => $oldSph,
            'sph_standar_baru' => $newSph,
            'upgraded_by'      => auth()->id(),
            'catatan'          => $request->input('catatan'),
            'upgraded_at'      => $now,
        ]);

        return redirect()->route('master.part.edit', $part)
            ->with('success', "SPH Standard Produksi proses {$proses->nama_proses} diupgrade: {$oldSph} → {$newSph} pcs/jam. Data LHP sebelum {$now->format('d M Y')} tidak akan mempengaruhi rekomendasi berikutnya.");
    }

    /**
     * Terapkan rekomendasi upgrade SPH Standard QC (admin only).
     */
    public function upgradeQcSph(MasterPart $part, MasterPartProses $proses, Request $request)
    {
        abort_if(auth()->user()?->role !== 'admin', 403);

        $newSph = (int) $request->input('recommended_sph');
        $oldSph = (int) $proses->sph_standar_qc;

        if ($newSph <= 0 || $newSph <= $oldSph) {
            return redirect()->route('master.part.edit', $part)
                ->with('error', 'SPH QC rekomendasi tidak valid atau tidak lebih tinggi dari standard saat ini.');
        }

        $now = now();

        $proses->update([
            'sph_standar_qc'          => $newSph,
            'sph_qc_last_upgraded_at' => $now,
        ]);

        MasterPartProsesSphLog::create([
            'id_part_proses'   => $proses->id,
            'type'             => 'qc',
            'sph_standar_lama' => $oldSph,
            'sph_standar_baru' => $newSph,
            'upgraded_by'      => auth()->id(),
            'catatan'          => $request->input('catatan'),
            'upgraded_at'      => $now,
        ]);

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
        $export = new class implements FromArray, WithHeadings {
            public function headings(): array
            {
                return [
                    'Customer', 'Part No', 'Part Name',
                    'ID Proses', 'Nama Proses',
                    'SPH Produksi',
                    'SPH QC', 'Std Trial QC Menit',
                    'Std Setting Dies Menit',
                ];
            }
            public function array(): array
            {
                return [
                    ['Toyota', 'BT-091A-3', 'Bracket RH', 1, 'PROGRESSIVE', 800, 400, 5, 15],
                    ['Toyota', 'BT-091A-3', 'Bracket RH', 2, 'BENDING',     600, 300, 5, 15],
                    ['Honda',  'HC-202B',   'Cover LH',   1, 'PROGRESSIVE', 750, 375, 5, 20],
                ];
            }
        };
        return Excel::download($export, 'template_part.xlsx');
    }
}
