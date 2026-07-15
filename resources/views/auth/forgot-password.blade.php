@extends('layouts.auth')
@section('title', 'Lupa Password')
@section('auth_title', 'Lupa Password')
@section('auth_subtitle', 'Reset password akun OPTIMA MES')
@section('content')

<p style="font-size:13px;color:#4b5563;margin:0 0 16px">
    Masukkan email terdaftar. Kami akan mengirim tautan untuk mengatur ulang password.
</p>

@if(session('status'))
<div style="background:#f0fdf4;border:1px solid #86efac;border-left:3px solid #15803d;padding:9px 12px;border-radius:2px;font-size:12px;color:#15803d;margin-bottom:16px;display:flex;align-items:center;gap:8px">
    <i class="bi bi-check-circle-fill"></i>
    <span>{{ session('status') }}</span>
</div>
@endif

<form method="POST" action="{{ route('password.email') }}" style="margin:0">
    @csrf

    <div style="margin-bottom:16px">
        <label class="form-label">Email</label>
        <div class="input-group-icon">
            <i class="bi bi-envelope"></i>
            <input type="email" name="email"
                   class="form-control @error('email') is-invalid @enderror"
                   value="{{ old('email') }}"
                   placeholder="nama@perusahaan.com"
                   required autofocus>
        </div>
        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <button type="submit" class="btn-login">
        <i class="bi bi-envelope-arrow-up" style="margin-right:6px"></i>
        Kirim Tautan Reset
    </button>

    <div style="text-align:center;margin-top:16px;font-size:13px">
        <a href="{{ route('login') }}">Kembali ke Masuk</a>
    </div>
</form>

@endsection
