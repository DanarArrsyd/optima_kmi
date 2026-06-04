<?php
namespace App\Http\Controllers\Master;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMasterUserRequest;
use App\Models\User;
use App\Models\MasterKaryawan;

class UserController extends Controller
{
    public function index()
    {
        $users    = User::with('karyawan')->orderBy('name')->paginate(20);
        $karyawan = MasterKaryawan::orderBy('nama_karyawan')->get();
        return view('master.users.index', compact('users', 'karyawan'));
    }

    public function create()
    {
        $karyawan = MasterKaryawan::orderBy('nama_karyawan')->get();
        return view('master.users.create', compact('karyawan'));
    }

    public function store(StoreMasterUserRequest $request)
    {
        User::create([
            'name'        => $request->name,
            'email'       => $request->email,
            'password'    => $request->password,  // model 'hashed' cast handles hashing
            'role'        => $request->role,
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
            'name'        => $request->name,
            'email'       => $request->email,
            'role'        => $request->role,
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
