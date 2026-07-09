<?php
// TEMPORARY - DELETE AFTER USE

$base = dirname(__DIR__);

// 1. Reset OPcache
$opcacheReset = function_exists('opcache_reset') ? opcache_reset() : null;

// 2. Clear bootstrap/cache
foreach (glob($base . '/bootstrap/cache/*.php') as $f) { @unlink($f); }

// 3. Check critical files exist
$criticalFiles = [
    'bootstrap/app.php',
    'routes/web.php',
    'app/Http/Middleware/CheckRole.php',
    'app/Http/Controllers/Vcb/VcbProduksiController.php',
    'app/Http/Controllers/Vcb/VcbMesinController.php',
    'app/Http/Controllers/Vcb/VcbQualityController.php',
    'app/Http/Controllers/Vcb/VcbDandoriController.php',
    'app/Services/DashboardService.php',
    'routes/auth.php',
];

// 4. Boot Laravel with Console Kernel & regenerate route cache
$vcbRoutes   = [];
$bootError   = null;
$cacheResult = null;
$cacheFiles  = [];
try {
    define('LARAVEL_START', microtime(true));
    require $base . '/vendor/autoload.php';
    $app = require $base . '/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    // Regenerate route cache with current routes
    ob_start();
    $exitCode = $kernel->call('route:cache');
    $cacheResult = ['code' => $exitCode, 'output' => trim(ob_get_clean())];

    // List bootstrap/cache files after caching
    foreach (glob($base . '/bootstrap/cache/*.php') as $f) {
        $cacheFiles[] = basename($f) . ' (' . number_format(filesize($f)) . ' bytes, modified ' . date('H:i:s', filemtime($f)) . ')';
    }

    $router = $app->make('router');
    foreach ($router->getRoutes() as $route) {
        if (str_contains($route->uri(), 'vcb')) {
            $vcbRoutes[] = [
                'method' => implode('|', $route->methods()),
                'uri'    => $route->uri(),
                'action' => $route->getActionName(),
            ];
        }
    }
} catch (\Throwable $e) {
    $bootError = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
}

// 5. Laravel log
$logPath  = $base . '/storage/logs/laravel.log';
$logLines = file_exists($logPath) ? array_slice(file($logPath), -50) : [];
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>OPTIMA Diagnostics v3</title>
    <style>
        body{font-family:monospace;background:#111;color:#eee;padding:24px;font-size:13px}
        h2{color:#60a5fa;margin-top:28px}
        .ok{color:#4ade80}.err{color:#f87171}
        table{border-collapse:collapse;width:100%;margin-top:8px}
        th,td{border:1px solid #333;padding:5px 10px;text-align:left}
        th{background:#1f2937;color:#93c5fd}
        pre{background:#1a1a1a;padding:12px;overflow-x:auto;white-space:pre-wrap;font-size:12px;color:#d1d5db}
        .warn{background:#7c2d12;color:#fed7aa;padding:12px 16px;border-radius:4px;margin-top:28px}
    </style>
</head>
<body>
<h1 style="color:#f9fafb">OPTIMA — Diagnostics v3</h1>

<h2>1. OPcache</h2>
<?php if ($opcacheReset === null): ?>
    <span class="err">✗ OPcache tidak aktif</span>
<?php elseif ($opcacheReset): ?>
    <span class="ok">✓ OPcache di-reset</span>
<?php else: ?>
    <span class="err">✗ OPcache reset gagal</span>
<?php endif; ?>

<h2>2. File Kritis di Server</h2>
<table>
    <tr><th>File</th><th>Status</th><th>Ukuran</th></tr>
    <?php foreach ($criticalFiles as $f): $full = $base.'/'.$f; ?>
    <tr>
        <td><?= $f ?></td>
        <td><?= file_exists($full) ? '<span class="ok">✓ ADA</span>' : '<span class="err">✗ TIDAK ADA</span>' ?></td>
        <td><?= file_exists($full) ? number_format(filesize($full)) . ' bytes' : '—' ?></td>
    </tr>
    <?php endforeach; ?>
</table>

<h2>3. Route Cache Regeneration</h2>
<?php if ($cacheResult !== null): ?>
    <?php if ($cacheResult['code'] === 0): ?>
        <span class="ok">✓ route:cache berhasil!</span>
    <?php else: ?>
        <span class="err">✗ route:cache gagal (exit: <?= $cacheResult['code'] ?>)</span>
    <?php endif; ?>
    <?php if ($cacheResult['output']): ?>
        <pre><?= htmlspecialchars($cacheResult['output']) ?></pre>
    <?php endif; ?>
    <?php if ($cacheFiles): ?>
        <p>File di bootstrap/cache sekarang:</p>
        <ul><?php foreach ($cacheFiles as $cf): ?><li class="ok"><?= htmlspecialchars($cf) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
<?php endif; ?>

<h2>4. VCB Routes Terdaftar</h2>
<?php if ($bootError): ?>
    <span class="err">✗ Boot error: <?= htmlspecialchars($bootError) ?></span>
<?php elseif (empty($vcbRoutes)): ?>
    <span class="err">✗ TIDAK ADA route VCB setelah boot penuh!</span>
<?php else: ?>
    <span class="ok">✓ <?= count($vcbRoutes) ?> route VCB terdaftar:</span>
    <table>
        <tr><th>Method</th><th>URI</th><th>Action</th></tr>
        <?php foreach ($vcbRoutes as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['method']) ?></td>
            <td><?= htmlspecialchars($r['uri']) ?></td>
            <td><?= htmlspecialchars($r['action']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<h2>4. Laravel Log (50 baris terakhir)</h2>
<?php if (empty($logLines)): ?>
    <p style="color:#6b7280">Log kosong.</p>
<?php else: ?>
    <pre><?= htmlspecialchars(implode('', $logLines)) ?></pre>
<?php endif; ?>

<div class="warn">⚠️ <strong>HAPUS file ini setelah selesai!</strong> — Delete <code>public/diag.php</code> dari server.</div>
</body>
</html>
