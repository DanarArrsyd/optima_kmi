@extends('layouts.app')
@section('title', 'Edit Jadwal Preventive Mesin')

@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-calendar-week"></i>Edit Jadwal Preventive Mesin</h1>
    <div class="d-flex gap-2">
        <a href="{{ route('maintenance.control-stroke-mesin.schedule-pdf') }}" target="_blank" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-file-earmark-pdf me-1"></i>Schedule PDF
        </a>
        <a href="{{ route('maintenance.control-stroke-mesin') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Kembali
        </a>
    </div>
</div>

@include('maintenance.partials.preventive-schedule-grid', ['jenis' => 'mesin', 'schedule' => $schedule, 'bulan' => $bulan, 'bolehEdit' => $bolehEdit])
@endsection
