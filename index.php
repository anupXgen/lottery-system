<?php
require __DIR__ . '/includes/bootstrap.php';
start_secure_session();
redirect(is_logged_in() ? 'admin/dashboard.php' : 'login.php');
