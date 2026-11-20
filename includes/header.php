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
