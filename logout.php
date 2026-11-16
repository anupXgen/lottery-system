<?php
require __DIR__ . '/includes/bootstrap.php';
start_secure_session();

// Logout is a POST with a CSRF token so it cannot be triggered by a foreign link.
if (is_post() && csrf_valid()) {
    logout_user();
    start_secure_session();
    flash('success', 'You have been logged out.');
    redirect('login.php');
}
redirect(is_logged_in() ? 'admin/dashboard.php' : 'login.php');
