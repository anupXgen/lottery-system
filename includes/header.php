<?php
/**
 * Admin layout header.
 * Expects: $pageTitle (string), $activeNav (string)
 */
$pageTitle = $pageTitle ?? 'Admin';
$activeNav = $activeNav ?? '';
$navItems = [
    'dashboard' => ['admin/dashboard.php', 'bi-speedometer2', 'Dashboard'],
    'tickets'   => ['admin/tickets.php', 'bi-ticket-perforated', 'Tickets'],
    'upload'    => ['admin/upload-tickets.php', 'bi-cloud-arrow-up', 'Upload'],
    'prizes'    => ['admin/prizes.php', 'bi-gift', 'Prizes'],
    'live'      => ['admin/live-draw.php', 'bi-broadcast', 'Live Draw'],
    'reports'   => ['admin/reports.php', 'bi-file-earmark-pdf', 'Reports'],
    'settings'  => ['admin/settings.php', 'bi-gear', 'Settings'],
];
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
    <link rel="icon" href="<?= e(url('assets/images/logo.jpg')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
    <?php foreach (($pageStyles ?? []) as $pageStyle): ?>
    <link rel="stylesheet" href="<?= e(url($pageStyle)) ?>">
    <?php endforeach; ?>
</head>
<body class="admin-body">
<nav class="navbar navbar-expand-lg navbar-dark admin-nav sticky-top">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="<?= e(url('admin/dashboard.php')) ?>">
            <img class="brand-logo" src="<?= e(url('assets/images/logo.jpg')) ?>" alt="" width="34" height="34"> Diwali Lottery
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto">
                <?php foreach ($navItems as $key => $item): ?>
                    <li class="nav-item">
                        <a class="nav-link<?= $activeNav === $key ? ' active' : '' ?>" href="<?= e(url($item[0])) ?>">
                            <i class="bi <?= e($item[1]) ?>"></i> <?= e($item[2]) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
                <?php // Housie / Tambola module (independent of the lottery) ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle<?= strpos($activeNav, 'housie') === 0 ? ' active' : '' ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-grid-3x3-gap"></i> Housie / Tambola
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark">
                        <li><a class="dropdown-item" href="<?= e(url('housie/index.php')) ?>"><i class="bi bi-speedometer2"></i> Housie Dashboard</a></li>
                        <li><a class="dropdown-item" href="<?= e(url('housie/tickets.php')) ?>"><i class="bi bi-ticket-detailed"></i> Ticket Management</a></li>
                        <li><a class="dropdown-item" href="<?= e(url('housie/prizes.php')) ?>"><i class="bi bi-trophy"></i> Prize Configuration</a></li>
                        <li><a class="dropdown-item" href="<?= e(url('housie/live-draw.php')) ?>"><i class="bi bi-broadcast"></i> Live Number Draw</a></li>
                        <li><a class="dropdown-item" href="<?= e(url('housie/claims.php')) ?>"><i class="bi bi-patch-check"></i> Claim Verification</a></li>
                        <li><a class="dropdown-item" href="<?= e(url('housie/reports.php')) ?>"><i class="bi bi-file-earmark-text"></i> Housie Winners Report</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= e(url('housie/live-display.php')) ?>" target="_blank" rel="noopener"><i class="bi bi-display"></i> Housie LED Display</a></li>
                    </ul>
                </li>
            </ul>
            <div class="d-flex align-items-center gap-2">
                <a class="btn btn-sm btn-warning fw-semibold" href="<?= e(url('live-display.php')) ?>" target="_blank" rel="noopener">
                    <i class="bi bi-display"></i> LED Display
                </a>
                <span class="text-white-50 small d-none d-lg-inline"><i class="bi bi-person-circle"></i> <?= e(current_username()) ?></span>
                <form method="post" action="<?= e(url('logout.php')) ?>" class="m-0">
                    <?= csrf_field() ?>
                    <button class="btn btn-sm btn-outline-light" type="submit"><i class="bi bi-box-arrow-right"></i> Logout</button>
                </form>
            </div>
        </div>
    </div>
</nav>
<main class="container-xxl py-4">
    <div id="flash-area">
        <?php foreach (take_flashes() as $f): ?>
            <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show" role="alert">
                <?= e($f['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endforeach; ?>
    </div>
