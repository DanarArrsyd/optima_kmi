<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>OPTIMA - @yield('title', 'Dashboard')</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .sidebar { width: 240px; min-height: 100vh; background: #1a1a2e; position: fixed; top: 0; left: 0; z-index: 100; padding-top: 60px; }
        .sidebar .nav-link { color: #ccc; padding: 8px 20px; font-size: 0.875rem; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: #fff; background: rgba(255,255,255,0.1); border-radius: 4px; }
        .sidebar .nav-header { color: #888; font-size: 0.7rem; text-transform: uppercase; padding: 12px 20px 4px; letter-spacing: 1px; }
        .main-content { margin-left: 240px; padding-top: 60px; }
        .topbar { position: fixed; top: 0; left: 0; right: 0; height: 60px; background: #fff; border-bottom: 1px solid #dee2e6; z-index: 200; display: flex; align-items: center; padding: 0 20px; gap: 12px; }
        .topbar .logo { font-weight: 800; font-size: 1.1rem; color: #1a1a2e; margin-left: 240px; }
    </style>
    @stack('styles')
</head>
<body>
    {{-- TOPBAR --}}
    <div class="topbar">
        <span class="logo">OPTIMA</span>
        <div class="ms-auto d-flex align-items-center gap-3">
            <span class="badge bg-secondary">{{ strtoupper(str_replace('_', ' ', auth()->user()->role)) }}</span>
            <span class="text-muted small">{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-secondary">Logout</button>
            </form>
        </div>
    </div>

    {{-- SIDEBAR --}}
    <div class="sidebar">
        @php $role = auth()->user()->role; @endphp

        @if(in_array($role, ['admin', 'staff_produksi', 'staff_qc']))
        <div class="nav-header">Input Data</div>
        @if(in_array($role, ['admin', 'staff_produksi']))
        <a href="{{ route('input.produksi.index') }}" class="nav-link {{ request()->is('input/produksi*') ? 'active' : '' }}">
            <i class="bi bi-clipboard-plus me-2"></i>LHP Produksi
        </a>
        <a href="{{ route('input.dandori.index') }}" class="nav-link {{ request()->is('input/dandori*') ? 'active' : '' }}">
            <i class="bi bi-tools me-2"></i>Dandori
        </a>
        @endif
        @if(in_array($role, ['admin', 'staff_qc']))
        <a href="{{ route('input.qc.index') }}" class="nav-link {{ request()->is('input/qc*') ? 'active' : '' }}">
            <i class="bi bi-check2-square me-2"></i>LHP Quality
        </a>
        @endif
        @endif

        <div class="nav-header">VCB Dashboard</div>
        @if(in_array($role, ['admin', 'staff_produksi', 'manager']))
        <a href="{{ route('vcb.produksi') }}" class="nav-link {{ request()->is('vcb/produksi*') ? 'active' : '' }}">
            <i class="bi bi-bar-chart me-2"></i>VCB Produksi
        </a>
        <a href="{{ route('vcb.mesin') }}" class="nav-link {{ request()->is('vcb/mesin*') ? 'active' : '' }}">
            <i class="bi bi-gear me-2"></i>VCB Mesin
        </a>
        <a href="{{ route('vcb.dandori') }}" class="nav-link {{ request()->is('vcb/dandori*') ? 'active' : '' }}">
            <i class="bi bi-clock-history me-2"></i>VCB Dandori
        </a>
        @endif
        @if(in_array($role, ['admin', 'staff_qc', 'manager']))
        <a href="{{ route('vcb.quality') }}" class="nav-link {{ request()->is('vcb/quality*') ? 'active' : '' }}">
            <i class="bi bi-shield-check me-2"></i>VCB Quality
        </a>
        @endif

        @if($role === 'admin')
        <div class="nav-header">Master Data</div>
        <a href="{{ route('master.karyawan.index') }}" class="nav-link {{ request()->is('master/karyawan*') ? 'active' : '' }}">
            <i class="bi bi-people me-2"></i>Karyawan
        </a>
        <a href="{{ route('master.mesin.index') }}" class="nav-link {{ request()->is('master/mesin*') ? 'active' : '' }}">
            <i class="bi bi-cpu me-2"></i>Mesin
        </a>
        <a href="{{ route('master.part.index') }}" class="nav-link {{ request()->is('master/part*') ? 'active' : '' }}">
            <i class="bi bi-box-seam me-2"></i>Part / Dies
        </a>
        <a href="{{ route('master.losstime.index') }}" class="nav-link {{ request()->is('master/losstime*') ? 'active' : '' }}">
            <i class="bi bi-exclamation-triangle me-2"></i>Loss Time
        </a>
        <a href="{{ route('master.shift.index') }}" class="nav-link {{ request()->is('master/shift*') ? 'active' : '' }}">
            <i class="bi bi-calendar-week me-2"></i>Shift
        </a>
        <a href="{{ route('master.users.index') }}" class="nav-link {{ request()->is('master/users*') ? 'active' : '' }}">
            <i class="bi bi-person-gear me-2"></i>Users
        </a>
        @endif
    </div>

    {{-- MAIN CONTENT --}}
    <div class="main-content p-4">
        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show">
                {{ session('success') }} <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif
        @if(session('error'))
            <div class="alert alert-danger alert-dismissible fade show">
                {{ session('error') }} <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif
        @yield('content')
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js" defer></script>
    @stack('scripts')
</body>
</html>
