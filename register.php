<?php
require __DIR__ . '/includes/init.php';
if (user()) redirect('index.php');

$err = []; $v = ['username' => '', 'email' => '', 'full_name' => '', 'phone' => ''];
if (is_post()) {
    foreach ($v as $k => $_) $v[$k] = p($k);
    $pw = p('password'); $pw2 = p('password2');
    if (!preg_match('/^[A-Za-z0-9_.]{3,30}$/', $v['username'])) $err[] = 'Tên đăng nhập gồm 3-30 ký tự: chữ không dấu, số, dấu chấm hoặc gạch dưới.';
    if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $err[] = 'Email không hợp lệ.';
    if (mb_strlen($v['full_name']) < 2) $err[] = 'Vui lòng nhập họ tên.';
    if ($v['phone'] !== '' && !preg_match('/^[0-9+\s.-]{8,20}$/', $v['phone'])) $err[] = 'Số điện thoại không hợp lệ.';
    if ($m = validate_password($pw)) $err[] = $m;
    if ($pw !== $pw2) $err[] = 'Mật khẩu nhập lại không khớp.';
    if (!$err && fetch_val("SELECT 1 FROM users WHERE username = ?", [$v['username']])) $err[] = 'Tên đăng nhập đã được dùng.';
    if (!$err && fetch_val("SELECT 1 FROM users WHERE email = ?", [$v['email']])) $err[] = 'Email đã được dùng.';
    if (!$err) {
        run("INSERT INTO users (role_id, username, email, password_hash, full_name, phone, status) VALUES ((SELECT role_id FROM roles WHERE role_code='READER'),?,?,?,?,?, 'ACTIVE')",
            [$v['username'], $v['email'], password_hash($pw, PASSWORD_DEFAULT), $v['full_name'], $v['phone'] ?: null]);
        audit('REGISTER', 'user', (int)db()->lastInsertId());
        flash('success', 'Đăng ký thành công. Hãy mang giấy tờ tùy thân đến quầy thư viện để được cấp thẻ, sau đó bạn có thể đặt giữ sách.');
        redirect('login.php');
    }
}
page_start('Đăng ký tài khoản');
?>
<div class="row justify-content-center"><div class="col-md-8 col-lg-6">
  <h1 class="mb-1">Đăng ký tài khoản độc giả</h1>
  <p class="text-muted">Sau khi đăng ký, bạn cần đến quầy để thủ thư cấp thẻ thư viện.</p>
  <?php if ($err): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($err as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <form method="post" class="card card-body" novalidate><?= csrf_field() ?>
    <div class="row g-3">
      <div class="col-12"><label class="form-label" for="full_name">Họ và tên</label><input class="form-control" id="full_name" name="full_name" value="<?= e($v['full_name']) ?>" required></div>
      <div class="col-md-6"><label class="form-label" for="username">Tên đăng nhập</label><input class="form-control" id="username" name="username" value="<?= e($v['username']) ?>" autocomplete="username" required></div>
      <div class="col-md-6"><label class="form-label" for="phone">Số điện thoại</label><input class="form-control" id="phone" name="phone" value="<?= e($v['phone']) ?>"></div>
      <div class="col-12"><label class="form-label" for="email">Email</label><input class="form-control" type="email" id="email" name="email" value="<?= e($v['email']) ?>" required><div class="form-text">Dùng để nhận link đặt lại mật khẩu.</div></div>
      <div class="col-md-6"><label class="form-label" for="password">Mật khẩu</label><input class="form-control" type="password" id="password" name="password" autocomplete="new-password" required><div class="form-text">Ít nhất 8 ký tự, gồm chữ và số.</div></div>
      <div class="col-md-6"><label class="form-label" for="password2">Nhập lại mật khẩu</label><input class="form-control" type="password" id="password2" name="password2" autocomplete="new-password" required></div>
    </div>
    <button class="btn btn-primary mt-3" type="submit">Đăng ký</button>
  </form>
</div></div>
<?php page_end();
