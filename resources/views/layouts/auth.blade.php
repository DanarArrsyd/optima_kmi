<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OPTIMA - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #1a1a2e; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .login-card { width: 100%; max-width: 400px; }
        .brand { color: #fff; font-size: 2rem; font-weight: 800; text-align: center; margin-bottom: 8px; }
        .brand-sub { color: #888; font-size: 0.75rem; text-align: center; margin-bottom: 24px; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="brand">OPTIMA</div>
        <div class="brand-sub">OPERATOR & PRODUCTION TRACKING INFORMATION SYSTEM</div>
        <div class="card shadow">
            <div class="card-body p-4">
                @yield('content')
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
