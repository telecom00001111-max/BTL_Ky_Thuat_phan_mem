<?php
require __DIR__ . '/includes/init.php';
require_once ROOT . '/includes/mailer.php';
if (user()) redirect('profile.php');

$sent = false; $debugLink = '';
if (is_post()) {
    $email = p('email');
    $u = filter_var($email, FILTER_VALIDATE_EMAIL) ? fetch_one("SELECT * FROM users WHERE email = ? AND status <> 'INACTIVE'", [$email]) : null;
    // Luôn hiện cùng một thông báo để không lộ email nào đã đăng ký.
    $sent = true;
    if ($u && !fetch_val("SELECT 1 FROM password_resets WHERE user_id = ? AND used_at IS NULL AND expired_at > DATE_ADD(NOW(), INTERVAL 59 MINUTE)", [$u['user_id']])) {
        $token = bin2hex(random_bytes(32));
        run("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL", [$u['user_id']]);
        run("INSERT INTO password_resets (user_id, token_hash, expired_at) VALUES (?,?, DATE_ADD(NOW(), INTERVAL 60 MINUTE))", [$u['user_id'], hash('sha256', $token)]);
        $host = preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $_SERVER['HTTP_HOST'] ?? '') ? $_SERVER['HTTP_HOST'] : 'localhost';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $link = $scheme . '://' . $host . url('reset.php?token=' . $token);
        $html = '<p>Xin chào ' . e($u['full_name']) . ',</p><p>Bạn (hoặc ai đó) vừa yêu cầu đặt lại mật khẩu tại ' . e(cfg('app.name')) . '.</p>'
              . '<p><a href="' . e($link) . '">Bấm vào đây để đặt mật khẩu mới</a> (link có hiệu lực 60 phút).</p>'
              . '<p>Nếu không phải bạn yêu cầu, hãy bỏ qua email này.</p>';
        $ok = send_mail($u['email'], $u['full_name'], 'Đặt lại mật khẩu - ' . cfg('app.name'), $html);
        audit('PASSWORD_RESET_REQUEST', 'user', (int)$u['user_id'], $ok ? 'mail sent' : 'mail failed');
        if (!$ok && cfg('mail.show_link_on_fail')) $debugLink = $link;
    }
}
page_start('Quên mật khẩu');
?>
<div class="row justify-content-center"><div class="col-md-6 col-lg-5">
  <h1 class="mb-3">Quên mật khẩu</h1>
  <?php if ($sent): ?>
    <div class="alert alert-success">Nếu email này đã đăng ký, chúng tôi đã gửi hướng dẫn đặt lại mật khẩu. Hãy kiểm tra hộp thư (kể cả thư rác).</div>
    <?php if ($debugLink): ?><div class="alert alert-warning small"><strong>Chế độ thử nghiệm:</strong> gửi email thất bại (xem <code>storage/mail_error.log</code>). Link đặt lại: <a href="<?= e($debugLink) ?>">mở link</a></div><?php endif; ?>
    <a href="<?= e(url('login.php')) ?>">Quay lại đăng nhập</a>
  <?php else: ?>
  <form method="post" class="card card-body" novalidate><?= csrf_field() ?>
    <div class="mb-3"><label class="form-label" for="email">Email đã đăng ký</label><input class="form-control" type="email" id="email" name="email" required data-autofocus></div>
    <button class="btn btn-primary" type="submit">Gửi link đặt lại mật khẩu</button>
    <a class="small mt-3" href="<?= e(url('login.php')) ?>">Quay lại đăng nhập</a>
  </form>
  <?php endif; ?>
</div></div>
<?php page_end();
