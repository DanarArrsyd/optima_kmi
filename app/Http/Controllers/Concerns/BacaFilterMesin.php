<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Filter mesin jamak dari dock (x-filter.mesin → mesin_ids[]).
 *
 * Halaman yang dulu memakai mesin TUNGGAL (filter_mesin_id, mesin_id) tetap
 * menerima param lamanya: tautan, bookmark, dan back_query yang tersimpan
 * sebelum dock diseragamkan tidak boleh diam-diam berhenti menyaring.
 */
trait BacaFilterMesin
{
    /** @return list<int> */
    protected function mesinIds(Request $request, string ...$kunciLama): array
    {
        $ids = (array) $request->input('mesin_ids', []);
        foreach ($kunciLama as $kunci) {
            $ids[] = $request->input($kunci);
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }
}
