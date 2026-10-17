<?php
require __DIR__ . '/includes/init.php';
if (is_post() && user()) audit('LOGOUT', 'user', (int)user()['user_id']);
if (is_post()) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) { $c = session_get_cookie_params(); setcookie(session_name(), '', time() - 3600, $c['path'], $c['domain'], $c['secure'], $c['httponly']); }
    session_destroy();
    session_start();
    flash('success', 'Bạn đã đăng xuất.');
    redirect('login.php');
}
redirect('index.php');
