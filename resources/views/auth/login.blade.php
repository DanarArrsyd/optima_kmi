@extends('layouts.auth')
@section('title', 'Login')
@section('auth_title', 'Welcome to OPTIMA')
@section('auth_subtitle', 'Gas Login buat akses sistem ini')
@section('content')

@if($errors->any())
<div style="background:#fef2f2;border:1px solid #fca5a5;border-left:3px solid #b91c1c;padding:0.6cqw 0.9cqw;border-radius:0.4cqw;font-size:0.9cqw;color:#b91c1c;margin-bottom:1cqw;display:flex;align-items:center;gap:0.5cqw">
    <i class="bi bi-exclamation-circle-fill"></i>
    <span>{{ $errors->first() }}</span>
</div>
@endif

<form method="POST" action="{{ route('login') }}" id="formLogin" style="margin:0">
    @csrf

    <div style="margin-bottom:1.3cqw">
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

    <div>
        <label class="form-label" for="password">Password</label>
        <div class="input-group-icon">
            <i class="bi bi-lock-fill"></i>
            <input type="password" name="password" id="password"
                   class="form-control @error('password') is-invalid @enderror"
                   placeholder="Masukkan Password"
                   style="padding-right:3.4cqw"
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

    <button type="submit" class="btn-login">GAS LOGIN....</button>
</form>

@push('scripts')
<script>
(function () {
    var sandi = document.getElementById('password');
    var lihat = document.getElementById('lihatSandi');
    if (! sandi || ! lihat) return;

    lihat.addEventListener('click', function () {
        var tampil = sandi.type === 'password';
        sandi.type = tampil ? 'text' : 'password';
        lihat.setAttribute('aria-pressed', tampil ? 'true' : 'false');
        lihat.setAttribute('aria-label', tampil ? 'Sembunyikan password' : 'Tampilkan password');
        lihat.querySelector('i').className = tampil ? 'bi bi-eye-slash-fill' : 'bi bi-eye-fill';
    });
})();
</script>
@endpush

@endsection
