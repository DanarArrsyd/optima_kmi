<?php

/*
|--------------------------------------------------------------------------
| Definisi menu sidebar
|--------------------------------------------------------------------------
|
| Array polos — TANPA closure, TANPA route(), TANPA auth(). Kalau kelak
| `config:cache` dipakai, closure gagal di-serialize dan route() belum
| tersedia saat config dimuat.
|
| type   : 'link' (tautan tunggal) atau 'group' (accordion)
| key    : identitas grup untuk accordion & localStorage. Nilai grup TIDAK
|          boleh diganti — dia identitas accordion (dipakai localStorage,
|          isOpen()/toggle() di JS, dan diasersikan langsung oleh test).
|          Nama key storage-nya sendiri pernah diganti sekali
|          (optima_vcb_group → optima_nav_group, PR #56), jadi nilai lama
|          memang sudah yatim sejak itu. Penataan domain-first berikutnya
|          membuang key grup 'input' dan menambah 'sistem'; key yang
|          bertahan sengaja TIDAK diganti supaya state pengguna tetap
|          terpakai.
| route  : nama route tujuan
| match  : pola nama route yang membuat entri menyala (dipakai routeIs()).
|          Wildcard hanya boleh kalau tidak ada menu lain yang berbagi
|          prefiks nama. `vcb.ng-proses` WAJIB persis: pola `vcb.ng-proses*`
|          akan ikut menelan `vcb.ng-proses.daftar` dan menyalakan dua entri.
| roles  : role yang boleh melihat. Grup TIDAK punya 'roles' — diturunkan
|          dari gabungan role anaknya, supaya cuma ada satu tempat yang
|          bisa salah.
|
| Route *.data, *.detail, *.import, *.export adalah endpoint JSON/unduhan
| yang tidak pernah merender layout, jadi tidak perlu masuk 'match'.
*/

return [
    [
        'type' => 'link',
        'key' => 'overview',
        'label' => 'Overview',
        'icon' => 'bi-graph-up-arrow',
        'route' => 'overview',
        'roles' => ['admin', 'manager', 'staff_produksi', 'staff_qc', 'staff_maintenance', 'guest'],
        'match' => ['overview'],
    ],

    [
        'type' => 'link',
        'key' => 'chatbot',
        'label' => 'AI Assistant',
        'icon' => 'bi-robot',
        'route' => 'chatbot.index',
        'roles' => ['admin', 'manager'],
        'match' => ['chatbot.*'],
    ],

    // Satu sumbu: domain. Halaman input tinggal serumah dengan dashboard yang
    // membacanya — yang menginput LHP Produksi adalah yang membaca VCB Produksi.
    [
        'type' => 'group',
        'key' => 'produksi',
        'label' => 'Produksi',
        'icon' => 'bi-box-seam',
        'items' => [
            ['label' => 'LHP Produksi', 'icon' => 'bi-clipboard-data', 'route' => 'input.produksi.index', 'roles' => ['admin', 'staff_produksi', 'guest'], 'match' => ['input.produksi.*']],
            ['label' => 'Dandori', 'icon' => 'bi-wrench-adjustable', 'route' => 'input.dandori.index', 'roles' => ['admin', 'staff_produksi', 'guest'], 'match' => ['input.dandori.*']],
            ['label' => 'VCB Produksi', 'icon' => 'bi-bar-chart', 'route' => 'vcb.produksi', 'roles' => ['admin', 'staff_produksi', 'manager', 'guest'], 'match' => ['vcb.produksi']],
            ['label' => 'VCB Mesin', 'icon' => 'bi-gear', 'route' => 'vcb.mesin', 'roles' => ['admin', 'staff_produksi', 'manager', 'guest'], 'match' => ['vcb.mesin']],
            ['label' => 'VCB Dandori', 'icon' => 'bi-clock-history', 'route' => 'vcb.dandori', 'roles' => ['admin', 'staff_produksi', 'manager', 'guest'], 'match' => ['vcb.dandori']],
            // TANPA wildcard: 'vcb.ng-proses*' akan ikut menelan
            // 'vcb.ng-proses.daftar' dan menyalakan dua entri sekaligus.
            ['label' => 'VCB NG Proses', 'icon' => 'bi-exclamation-triangle', 'route' => 'vcb.ng-proses', 'roles' => ['admin', 'staff_produksi', 'manager', 'guest'], 'match' => ['vcb.ng-proses']],
            ['label' => 'Daftar NG Proses', 'icon' => 'bi-list-ul', 'route' => 'vcb.ng-proses.daftar', 'roles' => ['admin', 'staff_produksi', 'manager', 'guest'], 'match' => ['vcb.ng-proses.daftar']],
        ],
    ],

    [
        'type' => 'group',
        'key' => 'quality',
        'label' => 'Quality',
        'icon' => 'bi-shield-check',
        'items' => [
            ['label' => 'LHP Quality', 'icon' => 'bi-clipboard-check', 'route' => 'input.qc.index', 'roles' => ['admin', 'staff_qc', 'guest'], 'match' => ['input.qc.*']],
            ['label' => 'VCB Quality', 'icon' => 'bi-shield-check', 'route' => 'vcb.quality', 'roles' => ['admin', 'staff_qc', 'manager', 'guest'], 'match' => ['vcb.quality']],
            ['label' => 'VCB NG Total', 'icon' => 'bi-exclamation-triangle', 'route' => 'vcb.ng-total', 'roles' => ['admin', 'staff_qc', 'manager', 'guest'], 'match' => ['vcb.ng-total']],
            ['label' => 'Daftar NG Total', 'icon' => 'bi-list-ul', 'route' => 'vcb.ng-total.daftar', 'roles' => ['admin', 'staff_qc', 'manager', 'guest'], 'match' => ['vcb.ng-total.daftar']],
        ],
    ],

    [
        'type' => 'group',
        'key' => 'mtcmesin',
        'label' => 'MTC Mesin',
        'icon' => 'bi-tools',
        'items' => [
            ['label' => 'Breakdown Mesin', 'icon' => 'bi-tools', 'route' => 'maintenance.breakdown.index', 'roles' => ['admin', 'staff_maintenance', 'manager'], 'match' => ['maintenance.breakdown.*']],
            ['label' => 'MTTR', 'icon' => 'bi-clock-history', 'route' => 'maintenance.mttr', 'roles' => ['admin', 'staff_maintenance', 'manager', 'guest'], 'match' => ['maintenance.mttr']],
            ['label' => 'MTBF', 'icon' => 'bi-arrow-repeat', 'route' => 'maintenance.mtbf', 'roles' => ['admin', 'staff_maintenance', 'manager', 'guest'], 'match' => ['maintenance.mtbf']],
            ['label' => 'Availability', 'icon' => 'bi-graph-up', 'route' => 'maintenance.availability', 'roles' => ['admin', 'staff_maintenance', 'manager', 'guest'], 'match' => ['maintenance.availability']],
            ['label' => 'Control Stroke', 'icon' => 'bi-speedometer2', 'route' => 'maintenance.control-stroke-mesin', 'roles' => ['admin', 'staff_maintenance', 'manager', 'guest'], 'match' => ['maintenance.control-stroke-mesin*']],
        ],
    ],

    [
        'type' => 'group',
        'key' => 'mtcdies',
        'label' => 'MTC Dies',
        'icon' => 'bi-hammer',
        'items' => [
            ['label' => 'Breakdown Dies', 'icon' => 'bi-hammer', 'route' => 'maintenance.breakdown-dies.index', 'roles' => ['admin', 'staff_maintenance', 'manager'], 'match' => ['maintenance.breakdown-dies.*']],
            ['label' => 'MTTR', 'icon' => 'bi-clock-history', 'route' => 'maintenance.mttr-dies', 'roles' => ['admin', 'staff_maintenance', 'manager', 'guest'], 'match' => ['maintenance.mttr-dies']],
            ['label' => 'MTBF', 'icon' => 'bi-arrow-repeat', 'route' => 'maintenance.mtbf-dies', 'roles' => ['admin', 'staff_maintenance', 'manager', 'guest'], 'match' => ['maintenance.mtbf-dies']],
            ['label' => 'Availability', 'icon' => 'bi-graph-up', 'route' => 'maintenance.availability-dies', 'roles' => ['admin', 'staff_maintenance', 'manager', 'guest'], 'match' => ['maintenance.availability-dies']],
            ['label' => 'Control Stroke', 'icon' => 'bi-speedometer2', 'route' => 'maintenance.control-stroke-dies', 'roles' => ['admin', 'staff_maintenance', 'manager', 'guest'], 'match' => ['maintenance.control-stroke-dies*']],
        ],
    ],

    // Master data pabrik. Users PINDAH ke grup Sistem: akun dan peran itu
    // administrasi sistem, bukan data pabrik.
    [
        'type' => 'group',
        'key' => 'master',
        'label' => 'Master Data',
        'icon' => 'bi-database',
        'items' => [
            ['label' => 'Karyawan', 'icon' => 'bi-people', 'route' => 'master.karyawan.index', 'roles' => ['admin'], 'match' => ['master.karyawan.*']],
            ['label' => 'Mesin', 'icon' => 'bi-cpu', 'route' => 'master.mesin.index', 'roles' => ['admin'], 'match' => ['master.mesin.*']],
            ['label' => 'Part / Dies', 'icon' => 'bi-box-seam', 'route' => 'master.part.index', 'roles' => ['admin'], 'match' => ['master.part.*']],
            ['label' => 'Loss Time', 'icon' => 'bi-stopwatch', 'route' => 'master.losstime.index', 'roles' => ['admin'], 'match' => ['master.losstime.*']],
            ['label' => 'Shift', 'icon' => 'bi-calendar-week', 'route' => 'master.shift.index', 'roles' => ['admin'], 'match' => ['master.shift.*']],
            ['label' => 'Jenis NG', 'icon' => 'bi-exclamation-octagon', 'route' => 'master.jenis-ng.index', 'roles' => ['admin'], 'match' => ['master.jenis-ng.*']],
        ],
    ],

    // Administrasi sistem. Audit Trail masuk ke sini saat halamannya ada —
    // plafonnya sudah disiapkan, dan menu yang menunjuk halaman yang belum
    // ada lebih buruk daripada menu yang belum ada.
    [
        'type' => 'group',
        'key' => 'sistem',
        'label' => 'Sistem',
        'icon' => 'bi-sliders',
        'items' => [
            ['label' => 'Anomali LHP', 'icon' => 'bi-exclamation-triangle-fill', 'route' => 'admin.anomali', 'roles' => ['admin'], 'match' => ['admin.anomali', 'admin.anomali.approve']],
            ['label' => 'Users', 'icon' => 'bi-person-gear', 'route' => 'master.users.index', 'roles' => ['admin'], 'match' => ['master.users.*']],
            ['label' => 'Backup', 'icon' => 'bi-download', 'route' => 'sistem.backup.index', 'roles' => ['admin'], 'match' => ['sistem.backup.*']],
            ['label' => 'Log Aktivitas', 'icon' => 'bi-journal-text', 'route' => 'sistem.log-aktivitas.index', 'roles' => ['admin'], 'match' => ['sistem.log-aktivitas.*']],
        ],
    ],
];
