<?php

namespace App\View\Components\Filter;

use App\Models\MasterMesin;
use Illuminate\Support\Collection;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Pilih mesin jamak ("Semua Mesin", dikelompokkan per line) — satu bentuk di
 * semua dock. Membuka scope Alpine mesinFilterComp-nya SENDIRI dan memasang
 * window._mesinForJs sendiri, jadi halaman tidak bisa lupa menyediakannya
 * (PR #178: lupa emit = filter mati senyap, seluruh test PHPUnit tetap hijau).
 */
class Mesin extends Component
{
    /** @var Collection<int, MasterMesin> */
    public Collection $mesin;

    /** @var list<int> */
    public array $terpilih;

    public function __construct(array $selected = [], public string $name = 'mesin_ids[]')
    {
        $this->mesin = MasterMesin::orderBy('line')->orderBy('id_mesin')->get();
        $this->terpilih = array_values(array_filter(array_map('intval', $selected)));
    }

    public function render(): View
    {
        return view('components.filter.mesin');
    }
}
