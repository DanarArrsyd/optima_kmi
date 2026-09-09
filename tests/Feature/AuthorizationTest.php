<?php

namespace Tests\Feature;

use App\Models\DiesPreventiveLog;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Role-based access (CheckRole middleware) + redirect dashboard per role.
 * Boundary otorisasi tak boleh diam-diam melebar.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->get(route('master.mesin.index'))->assertRedirect(route('login'));
    }

    public function test_master_data_is_admin_only(): void
    {
        $this->actingAs($this->user('admin'))->get(route('master.mesin.index'))->assertOk();
        $this->actingAs($this->user('staff_produksi'))->get(route('master.mesin.index'))->assertForbidden();
        $this->actingAs($this->user('staff_qc'))->get(route('master.mesin.index'))->assertForbidden();
        $this->actingAs($this->user('manager'))->get(route('master.mesin.index'))->assertForbidden();
    }

    public function test_input_produksi_limited_to_admin_and_staff_produksi(): void
    {
        $this->actingAs($this->user('staff_produksi'))->get(route('input.produksi.index'))->assertOk();
        $this->actingAs($this->user('admin'))->get(route('input.produksi.index'))->assertOk();
        $this->actingAs($this->user('staff_qc'))->get(route('input.produksi.index'))->assertForbidden();
    }

    public function test_input_qc_limited_to_admin_and_staff_qc(): void
    {
        $this->actingAs($this->user('staff_qc'))->get(route('input.qc.index'))->assertOk();
        $this->actingAs($this->user('staff_produksi'))->get(route('input.qc.index'))->assertForbidden();
    }

    public function test_input_repair_hanya_admin_dan_staff_repair(): void
    {
        $this->actingAs($this->user('admin'))->get(route('input.repair.index'))->assertOk();
        $this->actingAs($this->user('staff_repair'))->get(route('input.repair.index'))->assertOk();
        $this->actingAs($this->user('staff_produksi'))->get(route('input.repair.index'))->assertForbidden();
        $this->actingAs($this->user('staff_qc'))->get(route('input.repair.index'))->assertForbidden();
        $this->actingAs($this->user('staff_maintenance'))->get(route('input.repair.index'))->assertForbidden();
        $this->actingAs($this->user('manager'))->get(route('input.repair.index'))->assertForbidden();
    }

    public function test_chatbot_limited_to_admin_and_manager(): void
    {
        $this->actingAs($this->user('manager'))->get(route('chatbot.index'))->assertOk();
        $this->actingAs($this->user('admin'))->get(route('chatbot.index'))->assertOk();
        $this->actingAs($this->user('staff_produksi'))->get(route('chatbot.index'))->assertForbidden();
        $this->actingAs($this->user('staff_qc'))->get(route('chatbot.index'))->assertForbidden();
    }

    public function test_maintenance_blocks_non_maintenance_roles(): void
    {
        $this->actingAs($this->user('staff_maintenance'))->get(route('maintenance.mttr'))->assertOk();
        $this->actingAs($this->user('staff_qc'))->get(route('maintenance.mttr'))->assertForbidden();
    }

    public function test_dashboard_redirects_by_role(): void
    {
        $this->actingAs($this->user('staff_maintenance'))->get('/dashboard')->assertRedirect('/maintenance/mttr');
        $this->actingAs($this->user('staff_qc'))->get('/dashboard')->assertRedirect('/vcb/quality');
        $this->actingAs($this->user('staff_produksi'))->get('/dashboard')->assertRedirect('/vcb/produksi');
        $this->actingAs($this->user('manager'))->get('/dashboard')->assertRedirect('/vcb/produksi');
    }

    /**
     * Route yang boleh dibuka guest.
     *
     * NB: 'maintenance.control-stroke-mesin.history' & '...control-stroke-dies.history'
     * TIDAK ada di sini karena keduanya butuh route-model-binding (perlu record nyata
     * di DB), sedangkan data provider ini statis dan jalan sebelum DB tersedia. Keduanya
     * dites terpisah di test_guest_can_view_control_stroke_history_routes() dengan
     * fixture dari seedMasterData(). Tapi keduanya TETAP masuk hitungan whitelist 39
     * route di test_guest_route_whitelist_matches_role_middleware_exactly() lewat nama
     * route (guestAllowedRouteNames()), jadi assertion default-deny tidak bolong.
     */
    public static function guestAllowedRoutes(): array
    {
        return [
            ['vcb.produksi'], ['vcb.produksi.data'],
            // operator-data adalah endpoint Mode 2 (drill-down per operator) — butuh
            // karyawan_id, kalau kosong controller sengaja balas 422 "Pilih operator
            // terlebih dahulu." Bukan bug; kirim query param supaya konsisten 200.
            ['vcb.produksi.operator-data', ['karyawan_id' => 1]],
            ['vcb.mesin'], ['vcb.mesin.data'],
            ['vcb.dandori'], ['vcb.dandori.data'],
            ['vcb.quality'], ['vcb.quality.data'],
            ['vcb.quality.operator-data', ['karyawan_id' => 1]],
            ['vcb.ng-proses'],
            ['vcb.ng-proses.data', ['date_from' => '2026-01-01', 'date_to' => '2026-12-31']],
            ['vcb.ng-proses.detail', ['bulan' => '2026-03']],
            // Daftar NG sengaja TANPA query param di sini: filternya semuanya
            // opsional, dan halaman harus 200 saat dibuka lewat menu.
            ['vcb.ng-proses.daftar'],
            ['vcb.ng-total'],
            ['vcb.ng-total.data', ['date_from' => '2026-01-01', 'date_to' => '2026-12-31']],
            ['vcb.ng-total.detail', ['bulan' => '2026-03']],
            ['vcb.ng-total.daftar'],
            ['maintenance.mttr'], ['maintenance.mttr.data'],
            ['maintenance.mtbf'], ['maintenance.mtbf.data'],
            ['maintenance.availability'], ['maintenance.availability.data'],
            ['maintenance.mttr-dies'], ['maintenance.mttr-dies.data'],
            ['maintenance.mtbf-dies'], ['maintenance.mtbf-dies.data'],
            ['maintenance.availability-dies'], ['maintenance.availability-dies.data'],
            ['maintenance.control-stroke-mesin'], ['maintenance.control-stroke-mesin.data'],
            ['maintenance.control-stroke-dies'], ['maintenance.control-stroke-dies.data'],
            // schedule-pdf & schedule-edit SENGAJA nol di sini — dicabut dari guest (dan
            // staff_maintenance/manager) 2026-09-03, sekarang admin-only route group
            // terpisah. Lihat test_staff_maintenance_dan_guest_tidak_boleh_akses_schedule_admin_only().
            ['input.produksi.index'], ['input.qc.index'], ['input.dandori.index'],
            // Overview: grafik global pabrik, read-only, tanpa export.
            ['overview'],
        ];
    }

    /**
     * Nama 39 route whitelist guest yang dimaksud (dataset lengkap termasuk 2 route
     * history yang tak bisa masuk guestAllowedRoutes() karena butuh model binding).
     * Dipakai sebagai satu-satunya sumber kebenaran untuk assertion default-deny di
     * test_guest_route_whitelist_matches_role_middleware_exactly().
     */
    public static function guestAllowedRouteNames(): array
    {
        $names = array_map(static fn (array $row) => $row[0], self::guestAllowedRoutes());
        $names[] = 'maintenance.control-stroke-mesin.history';
        $names[] = 'maintenance.control-stroke-dies.history';

        return $names;
    }

    /** Route yang HARUS ditolak untuk guest. */
    public static function guestBlockedRoutes(): array
    {
        return [
            ['master.karyawan.index'], ['master.mesin.index'], ['master.part.index'],
            ['master.losstime.index'], ['master.shift.index'], ['master.users.index'],
            ['master.jenis-ng.index'], ['master.users.create'],
            ['master.part.export'],
            ['input.produksi.export'], ['input.qc.export'], ['input.dandori.export'],
            ['vcb.dandori.export'],
            ['vcb.ng-proses.daftar.export'], ['vcb.ng-total.daftar.export'],
            ['input.produksi.create'], ['input.qc.create'], ['input.dandori.create'],
            ['input.produksi.import-template'], ['input.qc.import-template'], ['input.dandori.import-template'],
            ['maintenance.breakdown.index'], ['maintenance.breakdown-dies.index'],
            ['maintenance.breakdown.export'], ['maintenance.breakdown-dies.export'],
            ['maintenance.breakdown.create'], ['maintenance.breakdown-dies.create'],
            ['chatbot.index'], ['admin.anomali'],
            ['maintenance.control-stroke-mesin.schedule-pdf'],
            ['maintenance.control-stroke-dies.schedule-pdf'],
            ['maintenance.control-stroke-mesin.schedule-edit'],
            ['maintenance.control-stroke-dies.schedule-edit'],
        ];
    }

    #[DataProvider('guestAllowedRoutes')]
    public function test_guest_can_view_allowed_routes(string $routeName, array $query = []): void
    {
        $this->actingAs($this->user('guest'))->get(route($routeName, $query))->assertOk();
    }

    #[DataProvider('guestBlockedRoutes')]
    public function test_guest_cannot_view_blocked_routes(string $routeName): void
    {
        $this->actingAs($this->user('guest'))->get(route($routeName))->assertForbidden();
    }

    /**
     * Fixture minimal untuk route ber-binding model. Perlu record NYATA (bukan ID
     * mentah 1) karena implicit route-model-binding (SubstituteBindings) di app ini
     * jalan SEBELUM middleware `role` — kalau ID tidak ada di DB, request 404 duluan
     * lewat ModelNotFoundException sebelum middleware role sempat menolak, sehingga
     * assertForbidden() (403) tidak pernah teruji. Dibuktikan lewat probe manual:
     * bahkan user admin dapat 404 untuk ID yang tidak ada — perilaku binding standar
     * Laravel, bukan soal role sama sekali.
     */
    private function seedMasterData(): array
    {
        $mesin = MasterMesin::create([
            'id_mesin' => 'M-TEST-1', 'nama_mesin' => 'Mesin Test', 'line' => 'A',
            'tipe_mesin' => 'STAMPING',
        ]);
        $part = MasterPart::create([
            'part_no' => 'PART-TEST-1', 'part_name' => 'Part Test',
        ]);
        $partProses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 'P1', 'nama_proses' => 'Proses Test',
        ]);
        $karyawan = MasterKaryawan::create([
            'nik' => 'NIK-TEST-1', 'nama_karyawan' => 'Karyawan Test', 'departemen' => 'Produksi',
        ]);
        $shift = MasterShift::create([
            'nama_shift' => 'Shift Test', 'jam_start' => '07:00', 'jam_finish' => '15:00',
        ]);

        return compact('mesin', 'part', 'partProses', 'karyawan', 'shift');
    }

    public function test_guest_cannot_open_control_stroke_write_routes(): void
    {
        $guest = $this->user('guest');
        $admin = $this->user('admin');
        $data = $this->seedMasterData();

        $diesLog = DiesPreventiveLog::create([
            'id_part_proses' => $data['partProses']->id,
            'tanggal_preventive' => now()->toDateString(),
            'id_karyawan' => $data['karyawan']->id,
            'stroke_at_preventive' => 100,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($guest)
            ->get(route('maintenance.control-stroke-mesin.preventive.create', ['mesin' => $data['mesin']->id]))
            ->assertForbidden();

        $this->actingAs($guest)
            ->get(route('maintenance.control-stroke-dies.preventive.create', ['partProses' => $data['partProses']->id]))
            ->assertForbidden();

        $this->actingAs($guest)
            ->get(route('maintenance.control-stroke-dies.preventive.checksheet', ['diesPreventiveLog' => $diesLog->id]))
            ->assertForbidden();

        // Cek di atas HTTP-level saja TIDAK cukup: ketiga controller (ControlStrokeMesinController.php:42,
        // ControlStrokeDiesController.php:27,106) punya guard `abort_if(...403)` sendiri di dalam method-nya.
        // Kalau 'guest' ditambahkan ke role: grup route ini (role:admin,staff_maintenance,manager), request
        // TETAP lolos middleware role lalu baru ditolak controller — assertForbidden() di atas tetap hijau
        // dan diam-diam menyembunyikan lubang di lapisan route. Assertion di bawah ini menguji lapisan route
        // itu sendiri, lepas dari guard controller.
        foreach ([
            'maintenance.control-stroke-mesin.preventive.create',
            'maintenance.control-stroke-dies.preventive.create',
            'maintenance.control-stroke-dies.preventive.checksheet',
        ] as $routeName) {
            $route = app('router')->getRoutes()->getByName($routeName);
            $this->assertNotNull($route, "Route {$routeName} tidak terdaftar.");

            $roleMiddleware = collect($route->gatherMiddleware())
                ->first(fn (string $m) => str_starts_with($m, 'role:'));

            $this->assertNotNull($roleMiddleware, "Route {$routeName} harus digerbangi middleware role:.");

            $roles = explode(',', substr($roleMiddleware, strlen('role:')));
            $this->assertNotContains('guest', $roles, "Route {$routeName} tidak boleh mengizinkan guest.");
        }
    }

    /**
     * Dua route history butuh model binding nyata (MasterMesin / MasterPartProses),
     * makanya tidak ikut guestAllowedRoutes() (data provider statis, jalan sebelum DB
     * tersedia). Dites terpisah di sini pakai seedMasterData(), tapi keduanya tetap
     * masuk hitungan 37 whitelist lewat guestAllowedRouteNames() supaya assertion
     * default-deny di test_guest_route_whitelist_matches_role_middleware_exactly()
     * tidak bolong.
     */
    public function test_guest_can_view_control_stroke_history_routes(): void
    {
        $guest = $this->user('guest');
        $data = $this->seedMasterData();

        $this->actingAs($guest)
            ->get(route('maintenance.control-stroke-mesin.history', ['mesin' => $data['mesin']->id]))
            ->assertOk();

        $this->actingAs($guest)
            ->get(route('maintenance.control-stroke-dies.history', ['partProses' => $data['partProses']->id]))
            ->assertOk();
    }

    /**
     * Properti default-deny: himpunan route yang BENAR-BENAR bisa diakses guest (lewat
     * middleware role: yang memuat 'guest') harus SAMA PERSIS dengan whitelist 39 nama
     * di guestAllowedRouteNames(). Ini satu-satunya assertion yang menutup celah "route
     * GET baru ditambahkan ke grup guest yang sudah ada, tapi lupa didaftarkan di test" —
     * guestBlockedRoutes() cuma daftar manual yang tidak pernah tahu soal route baru,
     * dan DenyGuestWrite hanya menolak verb non-GET, bukan menolak GET yang tidak
     * seharusnya guest akses.
     *
     * Cakupan yang SENGAJA dikecualikan: route di dalam grup 'auth' yang TIDAK punya
     * middleware 'role' sama sekali (mis. GET /notifications/poll) otomatis bisa diakses
     * SEMUA user yang login, termasuk guest, terlepas dari whitelist berbasis role: ini.
     * Itu kategori terpisah (login-gate biasa, bukan role-gate) dan di luar cakupan
     * pertanyaan "role apa saja yang eksplisit mengizinkan guest" — dikecualikan secara
     * sadar, bukan karena terlewat.
     */
    public function test_guest_route_whitelist_matches_role_middleware_exactly(): void
    {
        $guestReachable = [];

        foreach (app('router')->getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            // Route di luar grup 'auth' (mis. GET / redirect, login/register) tidak
            // relevan — guest yang dimaksud di sini adalah role 'guest' yang sudah login.
            if (! in_array('auth', $middleware, true)) {
                continue;
            }

            $roleMiddleware = collect($middleware)
                ->first(fn (string $m) => str_starts_with($m, 'role:'));

            // Lihat blok komentar method ini: route auth TANPA middleware role sengaja
            // tidak dihitung di sini — kategori berbeda, dikecualikan secara sadar.
            if ($roleMiddleware === null) {
                continue;
            }

            $roles = explode(',', substr($roleMiddleware, strlen('role:')));

            if (in_array('guest', $roles, true)) {
                $guestReachable[] = $name;
            }
        }

        sort($guestReachable);
        $expected = self::guestAllowedRouteNames();
        sort($expected);

        $this->assertSame($expected, $guestReachable);
    }

    public function test_guest_write_verbs_are_rejected_on_reachable_pages(): void
    {
        $guest = $this->user('guest');
        $admin = $this->user('admin');
        $data = $this->seedMasterData();

        $produksi = TransaksiProduksi::create([
            'tanggal_produksi' => now()->toDateString(),
            'id_shift' => $data['shift']->id,
            'id_karyawan' => $data['karyawan']->id,
            'id_mesin' => $data['mesin']->id,
            'id_part_proses' => $data['partProses']->id,
            'jam_start' => '07:00',
            'jam_finish' => '08:00',
            'qty_aktual' => 10,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($guest)->post(route('input.produksi.note'), [
            'id' => 1, 'note' => 'coba tulis',
        ])->assertForbidden();

        $this->actingAs($guest)->delete(route('input.produksi.destroy', ['produksi' => $produksi->id]))->assertForbidden();
    }

    public function test_dashboard_redirects_guest_to_vcb_produksi(): void
    {
        $this->actingAs($this->user('guest'))->get('/dashboard')->assertRedirect('/vcb/produksi');
    }
}
