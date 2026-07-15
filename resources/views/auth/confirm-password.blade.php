@extends('layouts.auth')
@section('title', 'Konfirmasi Password')
@section('auth_title', 'Konfirmasi Password')
@section('auth_subtitle', 'Area aman OPTIMA MES')
@section('content')

<p style="font-size:13px;color:#4b5563;margin:0 0 16px">
    Ini area aman aplikasi. Konfirmasi password Anda sebelum melanjutkan.
</p>

<form method="POST" action="{{ route('password.confirm') }}" style="margin:0">
    @csrf

    <div style="margin-bottom:16px">
        <label class="form-label">Password</label>
        <div class="input-group-icon">
            <i class="bi bi-lock"></i>
            <input type="password" name="password"
                   class="form-control @error('password') is-invalid @enderror"
                   placeholder="••••••••"
                   required autocomplete="current-password" autofocus>
        </div>
        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <button type="submit" class="btn-login">
        <i class="bi bi-shield-check" style="margin-right:6px"></i>
        Konfirmasi
    </button>
</form>

@endsection
