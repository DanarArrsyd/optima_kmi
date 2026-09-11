@extends('layouts.auth')
@section('title', 'Akses Ditolak')
@section('auth_title', 'Akses Ditolak')
@section('auth_subtitle', 'Akun ini tidak punya izin untuk membuka halaman tersebut.')

@section('content')
<a href="{{ route('dashboard') }}" class="btn-login" style="display:block;text-align:center;text-decoration:none">
    Kembali ke Dashboard
</a>
@endsection
