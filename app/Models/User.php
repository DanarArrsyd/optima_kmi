<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, LogsActivity, Notifiable;

    /** Password sengaja TIDAK ikut ter-log — hash saja, nol nilai audit, potensi bocor kalau ke-dump. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'roles', 'id_karyawan', 'is_aktif'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('users');
    }

    /**
     * Satu-satunya daftar role yang sah, dan satu-satunya tempat labelnya
     * ditulis. StoreMasterUserRequest, form Tambah/Edit User, dan filter
     * di halaman daftar semuanya membaca dari sini — role baru cukup
     * ditambah SEKALI, bukan di empat tempat sekaligus.
     */
    public const ROLES = [
        'admin' => 'Admin',
        'staff_produksi' => 'Staff Produksi',
        'staff_qc' => 'Staff QC',
        'staff_maintenance' => 'Staff Maintenance',
        'manager' => 'Manager',
        'guest' => 'Guest (Read-Only)',
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'roles',
        'id_karyawan',
        'is_aktif',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_aktif' => 'boolean',
            'roles' => 'array',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** two_factor_* SENGAJA tidak di $fillable — cuma ditulis lewat TwoFactorAuthService::confirm()/disable(). */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(MasterKaryawan::class, 'id_karyawan');
    }

    /**
     * Cocok kalau SALAH SATU role user ada di daftar yang diperiksa —
     * sama seperti middleware `role:a,b,c`. Tanda tangannya sengaja tidak
     * berubah dari versi single-role: setiap pemanggil (CheckRole, 18
     * gerbang route, request FormRequest) tetap bekerja tanpa disentuh.
     */
    public function hasRole(string|array $roles): bool
    {
        return array_intersect((array) $roles, $this->roles ?? []) !== [];
    }

    /**
     * Akun tamu read-only. Dipakai DenyGuestWrite dan Blade untuk menyembunyikan aksi tulis.
     * Jangan pakai direktif @guest bawaan Laravel — itu artinya "belum login".
     *
     * Guest SENGAJA dibuat eksklusif di lapisan validasi (StoreMasterUserRequest)
     * — kalau roles memuat 'guest', itu satu-satunya role yang boleh ada.
     * hasRole() di atas tidak tahu soal eksklusivitas ini; kalau constraint-nya
     * pernah dilanggar lewat jalur lain (seed manual, dsb), guest yang
     * digabung role lain di sini tetap terbaca sebagai guest — DenyGuestWrite
     * gagal AMAN (mengunci tulis), bukan gagal terbuka.
     */
    public function isGuest(): bool
    {
        return in_array('guest', $this->roles ?? [], true);
    }
}
