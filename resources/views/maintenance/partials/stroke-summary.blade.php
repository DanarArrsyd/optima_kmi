{{-- Ringkasan stroke berjalan satu unit (dies atau mesin). Butuh: $currentStroke, $threshold --}}
@php
    $sPct    = $threshold > 0 ? round($currentStroke / $threshold * 100, 1) : 0;
    $sSisa   = $threshold - $currentStroke;
    $sBarPct = min($sPct, 100);
    $sStatus = $sPct >= 100 ? 'overdue' : ($sPct >= 80 ? 'warning' : 'ok');
    $sBarCls = ['ok' => 'bg-success', 'warning' => 'bg-warning', 'overdue' => 'bg-danger'][$sStatus];
    $sTxtCol = ['ok' => '#15803d', 'warning' => '#b45309', 'overdue' => '#b91c1c'][$sStatus];
@endphp

<div style="border:1px solid var(--border);background:var(--surface-2);padding:12px 14px">
    <div class="d-flex justify-content-between align-items-baseline mb-1">
        <span class="text-muted small">Stroke berjalan sejak preventive terakhir</span>
        <strong style="color:{{ $sTxtCol }}">{{ $sPct }}%</strong>
    </div>
    <div class="d-flex justify-content-between align-items-baseline mb-1">
        <span><strong>{{ number_format($currentStroke, 0, ',', '.') }}</strong>
              <span class="text-muted">/ {{ number_format($threshold, 0, ',', '.') }}</span></span>
        <span style="font-size:12px">
            @if($sSisa >= 0)
                <span class="text-muted">sisa {{ number_format($sSisa, 0, ',', '.') }} stroke</span>
            @else
                <span style="color:var(--c-danger);font-weight:600">lewat {{ number_format(-$sSisa, 0, ',', '.') }} stroke</span>
            @endif
        </span>
    </div>
    <div class="progress" style="height:9px;background:var(--border-2);border-radius:4px">
        <div class="progress-bar {{ $sBarCls }}" style="width:{{ $sBarPct }}%"></div>
    </div>
</div>
