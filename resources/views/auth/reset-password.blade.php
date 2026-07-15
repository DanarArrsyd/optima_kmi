@extends('layouts.auth')
@section('title', 'Atur Ulang Password')
@section('auth_title', 'Atur Ulang Password')
@section('auth_subtitle', 'Buat password baru OPTIMA MES')
@section('content')

<form method="POST" action="{{ route('password.store') }}" style="margin:0">
    @csrf
    <input type="hidden" name="token" value="{{ $request->route('token') }}">

    <div style="margin-bottom:16px">
        <label class="form-label">Email</label>
        <div class="input-group-icon">
            <i class="bi bi-envelope"></i>
            <input type="email" name="email"
                   class="form-control @error('email') is-invalid @enderror"
                   value="{{ old('email', $request->email) }}"
                   placeholder="nama@perusahaan.com"
                   required autofocus autocomplete="username">
        </div>
        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div style="margin-bottom:16px">
        <label class="form-label">Password Baru</label>
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
        <i class="bi bi-shield-lock" style="margin-right:6px"></i>
        Atur Ulang Password
    </button>
</form>

@endsection
