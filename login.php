<?php
require __DIR__ . '/includes/bootstrap.php';
start_secure_session();

if (is_logged_in()) {
    redirect('admin/dashboard.php');
}

$error = null;
$username = '';
if (is_post()) {
    $username = post_str('username', 50);
    $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
    if (!csrf_valid()) {
        $error = 'Your login form expired. Please try again.';
    } elseif ($username === '' || $password === '') {
        $error = 'Please enter your username and password.';
    } else {
        try {
            $error = attempt_login($username, $password);
        } catch (Throwable $ex) {
            $error = log_exception($ex);
        }
        if ($error === null) {
            redirect('admin/dashboard.php');
        }
    }
}
$flashes = take_flashes();
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login · <?= e(APP_NAME) ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🪔</text></svg>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body class="login-body">
<div class="login-wrap">
    <div class="login-card card shadow-lg">
        <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
                <div class="login-diya">🪔</div>
                <h1 class="h4 fw-bold mb-1">Diwali Lottery Draw</h1>
                <p class="text-muted mb-0">Administrator Login</p>
            </div>
            <?php foreach ($flashes as $f): ?>
                <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
            <?php endforeach; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= e($error) ?></div>
            <?php endif; ?>
            <form method="post" autocomplete="off" novalidate>
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label" for="username">Username</label>
                    <input class="form-control form-control-lg" id="username" name="username" value="<?= e($username) ?>" required autofocus>
                </div>
                <div class="mb-4">
                    <label class="form-label" for="password">Password</label>
                    <input class="form-control form-control-lg" id="password" name="password" type="password" required>
                </div>
                <button class="btn btn-festive btn-lg w-100" type="submit"><i class="bi bi-box-arrow-in-right"></i> Log in</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
