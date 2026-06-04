@extends('layouts.auth')
@section('title', 'Login')
@section('content')

@if($errors->any())
<div style="background:#fef2f2;border:1px solid #fca5a5;border-left:3px solid #b91c1c;padding:9px 12px;border-radius:2px;font-size:12px;color:#b91c1c;margin-bottom:16px;display:flex;align-items:center;gap:8px">
    <i class="bi bi-exclamation-circle-fill"></i>
    <span>{{ $errors->first() }}</span>
</div>
@endif

<form method="POST" action="{{ route('login') }}" style="margin:0">
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

    <div style="margin-bottom:16px">
        <label class="form-label">Password</label>
        <div class="input-group-icon">
            <i class="bi bi-lock"></i>
            <input type="password" name="password"
                   class="form-control @error('password') is-invalid @enderror"
                   placeholder="••••••••"
                   required>
        </div>
        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="form-check">
        <input type="checkbox" name="remember" class="form-check-input" id="remember"
               {{ old('remember') ? 'checked' : '' }}>
        <label class="form-check-label" for="remember">Ingat saya</label>
    </div>

    <button type="submit" class="btn-login">
        <i class="bi bi-box-arrow-in-right" style="margin-right:6px"></i>
        Masuk
    </button>
</form>

@endsection
