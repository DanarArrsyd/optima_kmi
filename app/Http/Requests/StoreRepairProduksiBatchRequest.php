<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRepairProduksiBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rows' => 'required|array|min:1|max:50',
            'rows.*.tanggal_repair_produksi' => 'required|date|after_or_equal:2020-01-01|before_or_equal:tomorrow',
            'rows.*.id_shift' => 'required|exists:master_shift,id',
            'rows.*.id_karyawan' => 'required|exists:master_karyawan,id',
            'rows.*.id_part_proses_repair_produksi' => 'required|exists:master_part_proses_repair_produksi,id',
            'rows.*.jam_start' => 'required|date_format:H:i',
            'rows.*.jam_finish' => 'required|date_format:H:i',
            'rows.*.qty_aktual' => 'required|integer|min:1',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('rows_json') && ! $this->has('rows')) {
            $decoded = json_decode((string) $this->input('rows_json'), true);
            $this->merge(['rows' => is_array($decoded) ? $this->kosongJadiNull($decoded) : []]);
        }
    }

    private function kosongJadiNull(array $data): array
    {
        foreach ($data as $kunci => $nilai) {
            if (is_array($nilai)) {
                $data[$kunci] = $this->kosongJadiNull($nilai);
            } elseif ($nilai === '') {
                $data[$kunci] = null;
            }
        }

        return $data;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $rows = $this->input('rows');
            if (! is_array($rows)) {
                return;
            }

            foreach ($rows as $i => $row) {
                if (! is_array($row) || empty($row['jam_start']) || empty($row['jam_finish'])) {
                    continue;
                }
                if ($row['jam_start'] === $row['jam_finish']) {
                    $validator->errors()->add(
                        "rows.{$i}.jam_finish",
                        'Baris '.($i + 1).': jam mulai dan jam selesai tidak boleh sama.'
                    );
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'rows.required' => 'Minimal harus ada 1 baris untuk disimpan.',
            'rows.min' => 'Minimal harus ada 1 baris untuk disimpan.',
            'rows.max' => 'Maksimal 50 baris per submit.',
            'rows.*.tanggal_repair_produksi.required' => 'Baris :position: Tanggal Repair wajib diisi.',
            'rows.*.id_shift.required' => 'Baris :position: Shift wajib dipilih.',
            'rows.*.id_karyawan.required' => 'Baris :position: Operator wajib dipilih.',
            'rows.*.id_part_proses_repair_produksi.required' => 'Baris :position: Part dan Proses Repair Produksi wajib dipilih.',
            'rows.*.id_part_proses_repair_produksi.exists' => 'Baris :position: Part/Proses Repair Produksi yang dipilih tidak valid.',
            'rows.*.jam_start.required' => 'Baris :position: Jam Start wajib diisi.',
            'rows.*.jam_finish.required' => 'Baris :position: Jam Finish wajib diisi.',
            'rows.*.qty_aktual.required' => 'Baris :position: Qty Act Repair wajib diisi.',
            'rows.*.qty_aktual.min' => 'Baris :position: Qty Act Repair minimal 1.',
        ];
    }

    public function attributes(): array
    {
        return [
            'rows.*.tanggal_repair_produksi' => 'Tanggal Repair',
            'rows.*.id_shift' => 'Shift',
            'rows.*.id_karyawan' => 'Operator',
            'rows.*.id_part_proses_repair_produksi' => 'Proses Repair Produksi',
            'rows.*.jam_start' => 'Jam Start',
            'rows.*.jam_finish' => 'Jam Finish',
            'rows.*.qty_aktual' => 'Qty Act Repair',
        ];
    }
}
