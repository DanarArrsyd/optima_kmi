<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMasterUserRequest;
use App\Models\MasterKaryawan;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->input('q', ''));
        $filterRole = trim((string) $request->input('filter_role', ''));

        $users = User::with('karyawan')
            ->when($search !== '', fn ($q) => $q->where(fn ($s) => $s
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('email', 'like', '%'.$search.'%')))
            /* whereJsonContains, bukan where('roles', ...) — roles sekarang
             * array, dan filter mencari SALAH SATU pemilik role itu. */
            ->when($filterRole !== '', fn ($q) => $q->whereJsonContains('roles', $filterRole))
            ->orderBy('name')
            ->paginate(20)->withQueryString();

        $karyawan = MasterKaryawan::orderBy('nama_karyawan')->get();

        /* Daftar role TETAP dari User::ROLES, bukan diturunkan dari data
         * (distinct()->pluck('roles') tidak berarti apa-apa untuk kolom
         * JSON) — enam role-nya sudah tetap sejak dulu, hardcode di sini
         * sama benarnya dan jauh lebih murah daripada memecah array JSON
         * lewat SQL. */
        $roleList = collect(User::ROLES);

        return view('master.users.index', compact('users', 'karyawan', 'search', 'filterRole', 'roleList'));
    }

    public function create()
    {
        $karyawan = MasterKaryawan::orderBy('nama_karyawan')->get();

        return view('master.users.create', compact('karyawan'));
    }

    public function store(StoreMasterUserRequest $request)
    {
        User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,  // model 'hashed' cast handles hashing
            'roles' => $request->input('roles', []),
            'id_karyawan' => $request->id_karyawan,
        ]);

        return redirect()->route('master.users.index')->with('success', 'User berhasil dibuat.');
    }

    public function edit(User $user)
    {
        $karyawan = MasterKaryawan::orderBy('nama_karyawan')->get();

        return view('master.users.edit', compact('user', 'karyawan'));
    }

    public function update(StoreMasterUserRequest $request, User $user)
    {
        $data = [
            'name' => $request->name,
            'email' => $request->email,
            'roles' => $request->input('roles', []),
            'id_karyawan' => $request->id_karyawan,
        ];
        if ($request->filled('password')) {
            $data['password'] = $request->password;  // model 'hashed' cast handles hashing
        }
        $user->update($data);

        return redirect()->route('master.users.index')->with('success', 'User berhasil diupdate.');
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return redirect()->route('master.users.index')->with('error', 'Tidak bisa menghapus akun sendiri.');
        }

        /* User TANPA SoftDeletes (beda dari model lain di app ini) dan
         * created_by/updated_by di transaksi_produksi/qc/dandori/breakdown
         * serta app_notifications semuanya foreignId('users') tanpa
         * onDelete cascade — RESTRICT bawaan MySQL. Menghapus akun yang
         * pernah menulis satu baris pun melempar QueryException 23000 yang
         * sebelumnya tidak ditangkap sama sekali, jadi operator melihat
         * halaman 500 polos tanpa penjelasan. Constraint-nya SENGAJA
         * dibiarkan RESTRICT — akun yang sudah menulis data produksi tidak
         * boleh dihapus diam-diam (baris transaksi jadi yatim / created_by
         * hilang), jadi yang diperbaiki di sini cuma pesannya. */
        try {
            $user->delete();
        } catch (QueryException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            return redirect()->route('master.users.index')->with(
                'error',
                "User \"{$user->name}\" tidak bisa dihapus karena masih tercatat sebagai pembuat data (LHP, QC, Dandori, atau Breakdown) — menghapusnya akan menyisakan data yatim. Nonaktifkan akunnya kalau akses ingin dicabut."
            );
        }

        return redirect()->route('master.users.index')->with('success', 'User berhasil dihapus.');
    }

    /**
     * Nonaktifkan/aktifkan akun. Satu-satunya cara mencabut akses mantan
     * pegawai yang datanya tidak bisa dihapus (lihat destroy()) — nol
     * pengaruh ke riwayat, hanya memblokir login lewat DenyNonaktifSession
     * (sesi yang sedang terbuka) dan LoginRequest (login baru).
     *
     * update()/store() di atas TIDAK menyertakan is_aktif di array yang
     * dikirim ke create()/update() walau kolomnya fillable — jadi form
     * Tambah/Edit User tidak bisa mengubahnya diam-diam; cuma toggleAktif()
     * yang menulisnya.
     */
    public function toggleAktif(User $user)
    {
        if ($user->id === auth()->id()) {
            return redirect()->route('master.users.index')->with('error', 'Tidak bisa menonaktifkan akun sendiri.');
        }

        $user->is_aktif = ! $user->is_aktif;
        $user->save();

        $pesan = $user->is_aktif
            ? "User \"{$user->name}\" diaktifkan kembali."
            : "User \"{$user->name}\" dinonaktifkan — tidak bisa login lagi, sesi yang sedang terbuka ikut terputus.";

        return redirect()->route('master.users.index')->with('success', $pesan);
    }
}
