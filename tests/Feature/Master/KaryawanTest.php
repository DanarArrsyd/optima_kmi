<?php
namespace Tests\Feature\Master;
use Tests\TestCase;
use App\Models\User;
use App\Models\MasterKaryawan;
use Illuminate\Foundation\Testing\RefreshDatabase;

class KaryawanTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_admin_can_view_karyawan_list(): void
    {
        MasterKaryawan::create(['nik' => 'K001', 'nama_karyawan' => 'Budi', 'departemen' => 'Produksi']);
        $this->actingAs($this->admin)
             ->get('/master/karyawan')
             ->assertStatus(200)
             ->assertSee('Budi');
    }

    public function test_admin_can_create_karyawan(): void
    {
        $this->actingAs($this->admin)
             ->post('/master/karyawan', [
                 'nik'           => 'K002',
                 'nama_karyawan' => 'Siti',
                 'departemen'    => 'QC',
             ])
             ->assertRedirect('/master/karyawan');
        $this->assertDatabaseHas('master_karyawan', ['nik' => 'K002']);
    }

    public function test_admin_can_soft_delete_karyawan(): void
    {
        $k = MasterKaryawan::create(['nik' => 'K003', 'nama_karyawan' => 'Andi', 'departemen' => 'Produksi']);
        $this->actingAs($this->admin)
             ->delete("/master/karyawan/{$k->id}")
             ->assertRedirect('/master/karyawan');
        $this->assertSoftDeleted('master_karyawan', ['id' => $k->id]);
    }

    public function test_admin_can_update_karyawan(): void
    {
        $k = MasterKaryawan::create(['nik' => 'K010', 'nama_karyawan' => 'Dewi', 'departemen' => 'Produksi']);
        $this->actingAs($this->admin)
             ->put("/master/karyawan/{$k->id}", [
                 'nik'           => 'K010',        // same NIK — must not fail unique check
                 'nama_karyawan' => 'Dewi Edited',
                 'departemen'    => 'QC',
             ])
             ->assertRedirect('/master/karyawan');
        $this->assertDatabaseHas('master_karyawan', ['id' => $k->id, 'nama_karyawan' => 'Dewi Edited']);
    }

    public function test_staff_produksi_cannot_access_karyawan(): void
    {
        $staff = User::factory()->create(['role' => 'staff_produksi']);
        $this->actingAs($staff)->get('/master/karyawan')->assertStatus(403);
    }
}
