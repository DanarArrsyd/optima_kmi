<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMasterUserRequest;
use App\Models\MasterKaryawan;
use App\Models\User;
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
        $user->delete();

        return redirect()->route('master.users.index')->with('success', 'User berhasil dihapus.');
    }
}
