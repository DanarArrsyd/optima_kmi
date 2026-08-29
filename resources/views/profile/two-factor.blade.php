@extends('layouts.app')
@section('title', 'Autentikasi Dua Faktor')

{{--
    Genre sama seperti sistem/backup/index.blade.php: setelan admin yang
    jarang dibuka, bukan ruang kerja padat — SENGAJA BUKAN .erp-ws.
--}}

@section('content')

<div style="max-width:560px;margin:0 auto;padding:24px 16px">

<h1 style="font-size:22px;font-weight:700;color:var(--text);margin:0 0 4px">Autentikasi Dua Faktor (2FA)</h1>
<div style="font-size:12.5px;color:var(--text-3);margin-bottom:20px">
    Lapisan tambahan buat akun admin — password tetap wajib, plus kode dari aplikasi authenticator tiap login.
</div>

@if(session('status') === 'two-factor-disabled')
<div style="background:#f0fdf4;border:1px solid #86efac;border-left:3px solid #15803d;padding:10px 14px;border-radius:4px;font-size:13px;color:#15803d;margin-bottom:16px">
    2FA dinonaktifkan.
</div>
@endif

@if(session('error'))
<div style="background:#fef2f2;border:1px solid #fca5a5;border-left:3px solid #b91c1c;padding:10px 14px;border-radius:4px;font-size:13px;color:#b91c1c;margin-bottom:16px">
    {{ session('error') }}
</div>
@endif

@if(session('recovery_codes'))
<div style="background:#fffbeb;border:1px solid #fcd34d;border-left:3px solid #b45309;padding:14px 16px;border-radius:4px;margin-bottom:20px">
    <div style="font-weight:700;color:#92400e;margin-bottom:6px">
        <i class="bi bi-exclamation-triangle-fill me-1"></i>Recovery Code — SIMPAN SEKARANG
    </div>
    <div style="font-size:12.5px;color:#92400e;margin-bottom:10px">
        Cuma tampil SEKALI. Kalau HP hilang/authenticator ke-reset, pakai salah satu kode ini buat login. Tiap kode sekali pakai.
    </div>
    <div style="font-family:'Courier New',monospace;font-size:13px;background:#fff;border:1px solid #fde68a;border-radius:4px;padding:10px;display:grid;grid-template-columns:1fr 1fr;gap:6px">
        @foreach(session('recovery_codes') as $code)
        <div>{{ $code }}</div>
        @endforeach
    </div>
</div>
@endif

@if($enabled)
    <div class="erp-panel">
        <div class="erp-panel-body" style="padding:16px">
            <div style="display:flex;align-items:center;gap:8px;color:#15803d;font-weight:700;margin-bottom:12px">
                <i class="bi bi-shield-check"></i>2FA AKTIF
            </div>
            <div style="font-size:12.5px;color:var(--text-3);margin-bottom:16px">
                Tiap login butuh kode dari aplikasi authenticator lo, selain password.
            </div>

            <form method="POST" action="{{ route('profile.two-factor.destroy') }}"
                  onsubmit="return confirm('Nonaktifkan 2FA? Login berikutnya cuma butuh password.')">
                @csrf
                @method('DELETE')
                <label class="form-label" for="password" style="font-size:12.5px">Konfirmasi password buat nonaktifkan</label>
                <input type="password" name="password" id="password" class="form-control @error('password', 'twoFactorDisable') is-invalid @enderror" required>
                @error('password', 'twoFactorDisable')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <button type="submit" class="btn btn-danger btn-sm" style="margin-top:10px">Nonaktifkan 2FA</button>
            </form>
        </div>
    </div>
@else
    <div class="erp-panel">
        <div class="erp-panel-body" style="padding:16px">
            <div style="font-weight:700;margin-bottom:10px">1. Scan QR ini pakai Google Authenticator / Microsoft Authenticator</div>
            {{-- Raw output aman: $qrSvg dibangun BaconQrCode dari secret sendiri,
                 nol input user masuk ke string ini. --}}
            <div style="background:#fff;border:1px solid var(--border);border-radius:4px;padding:16px;display:flex;justify-content:center;margin-bottom:12px">
                {!! $qrSvg !!}
            </div>
            <div style="font-size:12px;color:var(--text-3);margin-bottom:16px">
                Nggak bisa scan? Ketik manual kode ini di aplikasi authenticator:
                <code style="user-select:all">{{ $secret }}</code>
            </div>

            <div style="font-weight:700;margin-bottom:8px">2. Masukkan kode 6 digit yang muncul</div>
            <form method="POST" action="{{ route('profile.two-factor.confirm') }}">
                @csrf
                <input type="text" name="code" class="form-control @error('code') is-invalid @enderror"
                       inputmode="numeric" placeholder="123456" required autofocus style="max-width:200px">
                @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <button type="submit" class="btn btn-primary btn-sm" style="margin-top:10px">Aktifkan 2FA</button>
            </form>
        </div>
    </div>
@endif

</div>

@endsection
