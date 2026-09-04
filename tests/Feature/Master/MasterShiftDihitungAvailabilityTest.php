<?php

namespace Tests\Feature\Master;

use App\Models\MasterShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresi: ShiftController::store()/update() lupa masukkan
 * 'dihitung_availability' ke whitelist $request->only([...]). Checkbox-nya
 * ada di form (create/edit.blade.php) dan StoreMasterShiftRequest sudah
 * menormalkannya jadi boolean lewat prepareForValidation(), tapi nol pernah
 * ikut ter-tulis — kolom selalu jatuh ke default DB (true) berapa pun
 * checkbox-nya dicentang. Baru ketahuan pas drawer (yang malah nol punya
 * field ini sama sekali) dicabut dan create.blade.php jadi satu-satunya
 * jalur — nilainya tetap nol pernah tersimpan.
 */
class MasterShiftDihitungAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'nama_shift' => 'Shift Malam',
            'jam_start' => '22:00',
            'jam_finish' => '06:00',
        ], $overrides);
    }

    public function test_uncheck_dihitung_availability_tersimpan_false_saat_create(): void
    {
        // Checkbox tidak dicentang = key absen dari request sama sekali.
        $this->actingAs($this->admin)->post(route('master.shift.store'), $this->payload());

        $shift = MasterShift::where('nama_shift', 'Shift Malam')->firstOrFail();
        $this->assertFalse((bool) $shift->dihitung_availability);
    }

    public function test_check_dihitung_availability_tersimpan_true_saat_create(): void
    {
        $this->actingAs($this->admin)->post(route('master.shift.store'), $this->payload([
            'dihitung_availability' => '1',
        ]));

        $shift = MasterShift::where('nama_shift', 'Shift Malam')->firstOrFail();
        $this->assertTrue((bool) $shift->dihitung_availability);
    }

    public function test_uncheck_dihitung_availability_tersimpan_false_saat_update(): void
    {
        $shift = MasterShift::create([
            'nama_shift' => 'Shift 3',
            'jam_start' => '22:00',
            'jam_finish' => '06:00',
            'dihitung_availability' => true,
        ]);

        $this->actingAs($this->admin)->put(route('master.shift.update', $shift), $this->payload([
            'nama_shift' => 'Shift 3',
        ]));

        $this->assertFalse((bool) $shift->fresh()->dihitung_availability);
    }
}
