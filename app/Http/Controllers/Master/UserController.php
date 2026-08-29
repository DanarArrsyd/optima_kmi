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
            ->when($filterRole !== '', fn ($q) => $q->where('role', $filterRole))
            ->orderBy('name')
            ->paginate(20)->withQueryString();

        $karyawan = MasterKaryawan::orderBy('nama_karyawan')->get();
        $roleList = User::distinct()->orderBy('role')->pluck('role')->filter()->values();

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
            'role' => $request->role,
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
            'role' => $request->role,
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
                "User \"{$user->name}\" tidak bisa dihapus karena masih tercatat sebagai pembuat data (LHP, QC, Dandori, atau Breakdown) — menghapusnya akan menyisakan data yatim. Ganti passwordnya kalau akses akun ingin dicabut."
            );
        }

        return redirect()->route('master.users.index')->with('success', 'User berhasil dihapus.');
    }
}
