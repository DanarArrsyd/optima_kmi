@extends('layouts.app')
@section('title', 'Generate QR Stasiun')

@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-qr-code"></i>Generate QR Stasiun</h1>
</div>

@foreach($stasiun as $s)
    <div class="erp-panel" style="max-width:320px;margin-bottom:16px">
        <div class="erp-panel-header">{{ $s['label'] }}</div>
        <div class="erp-panel-body" style="text-align:center">
            {!! $s['svg'] !!}
        </div>
    </div>
@endforeach
@endsection
