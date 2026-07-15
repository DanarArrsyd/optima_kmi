@extends('layouts.auth')
@section('title', 'Verifikasi Email')
@section('auth_title', 'Verifikasi Email')
@section('auth_subtitle', 'Konfirmasi alamat email Anda')
@section('content')

<p style="font-size:13px;color:#4b5563;margin:0 0 16px">
    Terima kasih sudah mendaftar. Sebelum mulai, verifikasi email Anda lewat tautan
    yang baru kami kirim. Belum menerima email? Kami kirim ulang dengan senang hati.
</p>

@if (session('status') == 'verification-link-sent')
<div style="background:#f0fdf4;border:1px solid #86efac;border-left:3px solid #15803d;padding:9px 12px;border-radius:2px;font-size:12px;color:#15803d;margin-bottom:16px;display:flex;align-items:center;gap:8px">
    <i class="bi bi-check-circle-fill"></i>
    <span>Tautan verifikasi baru sudah dikirim ke email Anda.</span>
</div>
@endif

<form method="POST" action="{{ route('verification.send') }}" style="margin:0">
    @csrf
    <button type="submit" class="btn-login">
        <i class="bi bi-envelope-arrow-up" style="margin-right:6px"></i>
        Kirim Ulang Email Verifikasi
    </button>
</form>

<form method="POST" action="{{ route('logout') }}" style="margin:16px 0 0;text-align:center">
    @csrf
    <button type="submit" style="background:none;border:none;color:#4b5563;font-size:13px;text-decoration:underline;cursor:pointer">
        Keluar
    </button>
</form>

@endsection
