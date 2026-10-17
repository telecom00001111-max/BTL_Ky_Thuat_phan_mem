<?php
require __DIR__ . '/includes/init.php';
if (user()) redirect(is_staff() ? 'staff/index.php' : 'index.php');

$error = '';
$next = g('next', p('next'));
if (is_post()) {
    $login = p('username'); $pw = p('password');
    $u = fetch_one("SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1", [$login, $login]);
    $pol = policy();
    if (!$u) {
        password_verify($pw, '$2y$10$usesomesillystringforsaltuQ1xT1iYwZ4mR8x3pYjP0m0r0k1t6Bq2K');
        $error = 'Sai tên đăng nhập hoặc mật khẩu.';
    } elseif ($u['status'] !== 'ACTIVE') {
        $error = 'Tài khoản đã bị khóa hoặc ngừng hoạt động. Vui lòng liên hệ thủ thư.';
    } elseif ($u['locked_until'] && strtotime($u['locked_until']) > time()) {
        $error = 'Tài khoản tạm khóa do nhập sai mật khẩu nhiều lần. Thử lại sau ' . date('H:i', strtotime($u['locked_until'])) . '.';
    } elseif (!password_verify($pw, $u['password_hash'])) {
        $n = (int)$u['failed_login_count'] + 1;
        if ($n >= (int)$pol['max_failed_login']) {
            run("UPDATE users SET failed_login_count = 0, locked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE user_id = ?", [(int)$pol['lock_minutes'], $u['user_id']]);
            audit('LOGIN_LOCKED', 'user', (int)$u['user_id']);
            $error = 'Bạn đã nhập sai ' . $pol['max_failed_login'] . ' lần. Tài khoản tạm khóa ' . $pol['lock_minutes'] . ' phút.';
        } else {
            run("UPDATE users SET failed_login_count = ? WHERE user_id = ?", [$n, $u['user_id']]);
            $error = 'Sai tên đăng nhập hoặc mật khẩu. Còn ' . ((int)$pol['max_failed_login'] - $n) . ' lần thử trước khi bị khóa tạm thời.';
        }
    } else {
        run("UPDATE users SET failed_login_count = 0, locked_until = NULL WHERE user_id = ?", [$u['user_id']]);
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['user_id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        audit('LOGIN', 'user', (int)$u['user_id']);
        if ($next !== '' && strpos($next, BASE_URL . '/') === 0 && strpos($next, '//') !== 0) redirect($next);
        $role = fetch_val("SELECT role_code FROM roles WHERE role_id = ?", [$u['role_id']]);
        redirect(in_array($role, ['LIBRARIAN', 'ADMIN'], true) ? 'staff/index.php' : 'index.php');
    }
}
page_start('Đăng nhập');
?>
<div class="row justify-content-center"><div class="col-md-6 col-lg-5">
  <h1 class="mb-3">Đăng nhập</h1>
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="card card-body" novalidate>
    <?= csrf_field() ?><input type="hidden" name="next" value="<?= e($next) ?>">
    <div class="mb-3"><label class="form-label" for="username">Tên đăng nhập hoặc email</label>
      <input class="form-control" id="username" name="username" value="<?= e(p('username')) ?>" autocomplete="username" required data-autofocus></div>
    <div class="mb-3"><label class="form-label" for="password">Mật khẩu</label>
      <input class="form-control" type="password" id="password" name="password" autocomplete="current-password" required></div>
    <button class="btn btn-primary w-100" type="submit">Đăng nhập</button>
    <div class="d-flex justify-content-between mt-3 small">
      <a href="<?= e(url('forgot.php')) ?>">Quên mật khẩu?</a>
      <a href="<?= e(url('register.php')) ?>">Chưa có tài khoản? Đăng ký</a>
    </div>
  </form>
</div></div>
<?php page_end();
