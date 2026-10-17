<?php
require __DIR__ . '/includes/init.php';
$token = g('token', p('token'));
$row = $token !== '' ? fetch_one("SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expired_at > NOW()", [hash('sha256', $token)]) : null;

$err = '';
if ($row && is_post()) {
    $pw = p('password');
    if ($m = validate_password($pw)) $err = $m;
    elseif ($pw !== p('password2')) $err = 'Mật khẩu nhập lại không khớp.';
    else {
        tx(function () use ($row, $pw) {
            run("UPDATE users SET password_hash = ?, failed_login_count = 0, locked_until = NULL WHERE user_id = ?", [password_hash($pw, PASSWORD_DEFAULT), $row['user_id']]);
            run("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL", [$row['user_id']]);
        });
        flash('success', 'Đã đặt lại mật khẩu. Hãy đăng nhập bằng mật khẩu mới.');
        redirect('login.php');
    }
}
page_start('Đặt lại mật khẩu');
?>
<div class="row justify-content-center"><div class="col-md-6 col-lg-5">
  <h1 class="mb-3">Đặt lại mật khẩu</h1>
  <?php if (!$row): ?>
    <div class="alert alert-danger">Link không hợp lệ hoặc đã hết hạn. <a href="<?= e(url('forgot.php')) ?>">Yêu cầu link mới</a>.</div>
  <?php else: ?>
    <?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>
    <form method="post" class="card card-body" novalidate><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
      <div class="mb-3"><label class="form-label" for="password">Mật khẩu mới</label><input class="form-control" type="password" id="password" name="password" autocomplete="new-password" required data-autofocus><div class="form-text">Ít nhất 8 ký tự, gồm chữ và số.</div></div>
      <div class="mb-3"><label class="form-label" for="password2">Nhập lại mật khẩu mới</label><input class="form-control" type="password" id="password2" name="password2" autocomplete="new-password" required></div>
      <button class="btn btn-primary" type="submit">Lưu mật khẩu mới</button>
    </form>
  <?php endif; ?>
</div></div>
<?php page_end();
