@extends('layouts.auth')
@section('title', 'Verifikasi 2FA')
@section('auth_title', 'Verifikasi Dua Faktor')
@section('auth_subtitle', 'Masukkan kode dari aplikasi authenticator lo')
@section('content')

@if($errors->any())
<div style="background:#fef2f2;border:1px solid #fca5a5;border-left:3px solid #b91c1c;padding:0.6cqw 0.9cqw;border-radius:0.4cqw;font-size:0.9cqw;color:#b91c1c;margin-bottom:1cqw;display:flex;align-items:center;gap:0.5cqw">
    <i class="bi bi-exclamation-circle-fill"></i>
    <span>{{ $errors->first() }}</span>
</div>
@endif

<form method="POST" action="{{ route('two-factor.challenge') }}" style="margin:0">
    @csrf

    <div style="margin-bottom:1.3cqw">
        <label class="form-label" for="code">Kode Authenticator / Recovery Code</label>
        <div class="input-group-icon">
            <i class="bi bi-shield-lock-fill"></i>
            <input type="text" name="code" id="code"
                   class="form-control @error('code') is-invalid @enderror"
                   placeholder="Contoh: 123456"
                   inputmode="numeric" autocomplete="one-time-code"
                   required autofocus>
        </div>
        @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <div class="form-text" style="font-size:0.8cqw;color:#6b7280;margin-top:0.4cqw">
            Kehilangan HP? Masukkan salah satu recovery code yang disimpan saat setup.
        </div>
    </div>

    <button type="submit" class="btn-login">VERIFIKASI</button>
</form>

@endsection
