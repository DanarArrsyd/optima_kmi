@extends('layouts.auth')
@section('title', 'Daftar Akun')
@section('auth_title', 'Buat Akun')
@section('auth_subtitle', 'Daftar akun baru OPTIMA MES')
@section('content')

<form method="POST" action="{{ route('register') }}" style="margin:0">
    @csrf

    <div style="margin-bottom:16px">
        <label class="form-label">Nama</label>
        <div class="input-group-icon">
            <i class="bi bi-person"></i>
            <input type="text" name="name"
                   class="form-control @error('name') is-invalid @enderror"
                   value="{{ old('name') }}"
                   placeholder="Nama lengkap"
                   required autofocus autocomplete="name">
        </div>
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div style="margin-bottom:16px">
        <label class="form-label">Email</label>
        <div class="input-group-icon">
            <i class="bi bi-envelope"></i>
            <input type="email" name="email"
                   class="form-control @error('email') is-invalid @enderror"
                   value="{{ old('email') }}"
                   placeholder="nama@perusahaan.com"
                   required autocomplete="username">
        </div>
        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div style="margin-bottom:16px">
        <label class="form-label">Password</label>
        <div class="input-group-icon">
            <i class="bi bi-lock"></i>
            <input type="password" name="password"
                   class="form-control @error('password') is-invalid @enderror"
                   placeholder="••••••••"
                   required autocomplete="new-password">
        </div>
        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div style="margin-bottom:16px">
        <label class="form-label">Konfirmasi Password</label>
        <div class="input-group-icon">
            <i class="bi bi-lock-fill"></i>
            <input type="password" name="password_confirmation"
                   class="form-control @error('password_confirmation') is-invalid @enderror"
                   placeholder="••••••••"
                   required autocomplete="new-password">
        </div>
        @error('password_confirmation')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <button type="submit" class="btn-login">
        <i class="bi bi-person-plus" style="margin-right:6px"></i>
        Daftar
    </button>

    <div style="text-align:center;margin-top:16px;font-size:13px">
        <a href="{{ route('login') }}">Sudah punya akun? Masuk</a>
    </div>
</form>

@endsection
