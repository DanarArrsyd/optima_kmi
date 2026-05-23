<?php
namespace Tests\Feature\Master;
use Tests\TestCase;
use App\Models\User;
use App\Models\MasterShift;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ShiftTest extends TestCase
{
    use RefreshDatabase;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_admin_can_view_shift_list(): void
    {
        MasterShift::create(['nama_shift' => 'Shift Pagi', 'jam_start' => '06:00', 'jam_finish' => '14:00']);
        $this->actingAs($this->admin)->get('/master/shift')->assertStatus(200)->assertSee('Shift Pagi');
    }

    public function test_admin_can_create_shift(): void
    {
        $this->actingAs($this->admin)->post('/master/shift', [
            'nama_shift' => 'Shift Malam', 'jam_start' => '22:00', 'jam_finish' => '06:00',
        ])->assertRedirect('/master/shift');
        $this->assertDatabaseHas('master_shift', ['nama_shift' => 'Shift Malam']);
    }

    public function test_admin_can_update_shift(): void
    {
        $s = MasterShift::create(['nama_shift' => 'Shift X', 'jam_start' => '06:00', 'jam_finish' => '14:00']);
        $this->actingAs($this->admin)->put("/master/shift/{$s->id}", [
            'nama_shift' => 'Shift X Updated', 'jam_start' => '07:00', 'jam_finish' => '15:00',
        ])->assertRedirect('/master/shift');
        $this->assertDatabaseHas('master_shift', ['id' => $s->id, 'nama_shift' => 'Shift X Updated']);
    }

    public function test_admin_can_delete_shift(): void
    {
        $s = MasterShift::create(['nama_shift' => 'Shift Del', 'jam_start' => '06:00', 'jam_finish' => '14:00']);
        $this->actingAs($this->admin)->delete("/master/shift/{$s->id}")->assertRedirect('/master/shift');
        $this->assertDatabaseMissing('master_shift', ['id' => $s->id]);
    }
}
