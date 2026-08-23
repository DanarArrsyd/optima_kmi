@extends('layouts.auth')
@section('title', 'Login')
@section('auth_title', 'Welcome to OPTIMA')
@section('auth_subtitle', 'Gas Login buat akses sistem ini')
@section('content')

@if($errors->any())
<div style="background:#fef2f2;border:1px solid #fca5a5;border-left:3px solid #b91c1c;padding:9px 12px;border-radius:6px;font-size:12px;color:#b91c1c;margin-bottom:16px;display:flex;align-items:center;gap:8px">
    <i class="bi bi-exclamation-circle-fill"></i>
    <span>{{ $errors->first() }}</span>
</div>
@endif

<form method="POST" action="{{ route('login') }}" id="formLogin" style="margin:0">
    @csrf

    <div style="margin-bottom:18px">
        <label class="form-label" for="email">Email/Account</label>
        <div class="input-group-icon">
            <i class="bi bi-person-fill"></i>
            <input type="email" name="email" id="email"
                   class="form-control @error('email') is-invalid @enderror"
                   value="{{ old('email') }}"
                   placeholder="Masukkan email/account"
                   required autofocus autocomplete="username">
        </div>
        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div style="margin-bottom:4px">
        <label class="form-label" for="password">Password</label>
        <div class="input-group-icon">
            <i class="bi bi-lock-fill"></i>
            <input type="password" name="password" id="password"
                   class="form-control @error('password') is-invalid @enderror"
                   placeholder="Masukkan Password"
                   style="padding-right:52px"
                   required autocomplete="current-password">
            <button type="button" class="lihat-sandi" id="lihatSandi"
                    aria-label="Tampilkan password" aria-pressed="false">
                <i class="bi bi-eye-fill" aria-hidden="true"></i>
            </button>
        </div>
        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="form-check">
        <input type="checkbox" name="remember" class="form-check-input" id="remember"
               {{ old('remember') ? 'checked' : '' }}>
        <label class="form-check-label" for="remember">Ingat saya</label>
    </div>

    {{-- Tombolnya SELALU hidup dan selalu bisa ditekan. Awan di bawah ini cuma
         lapisan tampilan di atasnya (pointer-events:none), bukan pengunci. --}}
    <div class="gas-wrap">
        <button type="submit" class="btn-login">GAS LOGIN....</button>

        <svg class="awan" id="awan" viewBox="0 0 420 170" aria-hidden="true" focusable="false">
            <g fill="#ffffff">
                <ellipse cx="104" cy="104" rx="82" ry="50"/>
                <ellipse cx="176" cy="74"  rx="66" ry="50"/>
                <ellipse cx="252" cy="66"  rx="72" ry="54"/>
                <ellipse cx="326" cy="102" rx="76" ry="48"/>
                <rect x="26" y="100" width="372" height="64" rx="32"/>
            </g>
            <g fill="#f3f5f8">
                <ellipse cx="150" cy="140" rx="78" ry="22"/>
                <ellipse cx="280" cy="142" rx="70" ry="20"/>
            </g>
        </svg>
    </div>
</form>

@push('scripts')
<script>
(function () {
    var form = document.getElementById('formLogin');
    if (! form) return;

    var email = document.getElementById('email');
    var sandi = document.getElementById('password');
    var awan  = document.getElementById('awan');

    /* Tombol mata */
    var lihat = document.getElementById('lihatSandi');
    if (lihat && sandi) {
        lihat.addEventListener('click', function () {
            var tampil = sandi.type === 'password';
            sandi.type = tampil ? 'text' : 'password';
            lihat.setAttribute('aria-pressed', tampil ? 'true' : 'false');
            lihat.setAttribute('aria-label', tampil ? 'Sembunyikan password' : 'Tampilkan password');
            lihat.querySelector('i').className = tampil ? 'bi bi-eye-slash-fill' : 'bi bi-eye-fill';
        });
    }

    if (! email || ! sandi || ! awan) return;

    /* Awan baru DIPASANG setelah JS hidup. Kalau skrip ini tidak jalan sama
       sekali, awannya tetap tak terlihat dan tombol GAS LOGIN kelihatan
       seperti biasa — halaman login gagal ke arah yang aman. */
    awan.classList.add('awan-aktif');

    function selaras() {
        var lengkap = email.value.trim() !== '' && sandi.value !== '';
        awan.classList.toggle('awan-turun', lengkap);
    }

    ['input', 'change', 'keyup', 'paste'].forEach(function (ev) {
        email.addEventListener(ev, selaras);
        sandi.addEventListener(ev, selaras);
    });

    selaras();

    /* Isian otomatis peramban sering tidak memancarkan event input sama sekali,
       jadi keadaan awal diperiksa ulang sekali setelah halaman tenang. Tanpa
       ini, akun yang tersimpan di peramban membuat awan menutup tombol padahal
       kedua field sudah terisi. */
    setTimeout(selaras, 350);
})();
</script>
@endpush

@endsection
