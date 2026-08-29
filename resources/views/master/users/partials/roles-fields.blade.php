{{--
    Checkbox multi-role, dipakai halaman index (laci Tambah) dan edit.blade.php.
    Satu definisi supaya perilaku guest-eksklusif tidak menyimpang antara
    kedua form — riwayat taField (18 salinan, 5 varian) adalah alasan pola
    ini diekstrak sejak baris pertama, bukan menunggu salinan ketiga.

    Parameter:
      $selectedRoles : array role yang sudah tercentang (old() atau data user)
--}}
<div x-data="rolesCheckboxComp(@js($selectedRoles ?? []))">
    <label class="form-label" style="font-size:0.8rem">Role <span class="text-danger">*</span></label>
    <div class="d-flex flex-column gap-1">
        @foreach(\App\Models\User::ROLES as $kode => $label)
            <label class="form-check" style="font-size:0.85rem">
                <input type="checkbox" class="form-check-input" name="roles[]" value="{{ $kode }}"
                       x-model="dipilih" @change="jagaGuestEksklusif('{{ $kode }}')">
                {{ $label }}
            </label>
        @endforeach
    </div>
    <div class="small text-danger mt-1" x-show="pesanGuest" x-cloak x-text="pesanGuest"></div>
    @error('roles')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    @error('roles.0')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
</div>

@once
@push('scripts')
<script>
/*
 * Guest itu jaminan read-only (DenyGuestWrite). Server sudah menolak
 * kombinasi guest+role-lain (StoreMasterUserRequest); ini penjaga sisi
 * browser supaya operator tidak perlu submit dulu baru tahu kombinasinya
 * ditolak — mencentang guest langsung melepas yang lain, dan sebaliknya.
 */
function rolesCheckboxComp(initial) {
    return {
        dipilih: initial,
        pesanGuest: '',

        jagaGuestEksklusif(kodeBaruDiklik) {
            if (kodeBaruDiklik === 'guest' && this.dipilih.includes('guest')) {
                this.dipilih = ['guest'];
                this.pesanGuest = '';
            } else if (kodeBaruDiklik !== 'guest' && this.dipilih.includes('guest')) {
                this.dipilih = this.dipilih.filter(r => r !== 'guest');
                this.pesanGuest = 'Guest dilepas karena role kerja dipilih — guest tidak bisa digabung.';
                setTimeout(() => { this.pesanGuest = ''; }, 3000);
            }
        },
    };
}
</script>
@endpush
@endonce
