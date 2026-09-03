@extends('layouts.app')
@section('title', 'Edit Jadwal Preventive Dies')

@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-calendar-week"></i>Edit Jadwal Preventive Dies</h1>
    <div class="d-flex gap-2">
        <a href="{{ route('maintenance.control-stroke-dies.schedule-pdf') }}" target="_blank" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-file-earmark-pdf me-1"></i>Schedule PDF
        </a>
        <a href="{{ route('maintenance.control-stroke-dies') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Kembali
        </a>
    </div>
</div>

@include('maintenance.partials.preventive-schedule-grid', ['jenis' => 'dies', 'schedule' => $schedule, 'bulan' => $bulan, 'bolehEdit' => $bolehEdit])
@endsection
