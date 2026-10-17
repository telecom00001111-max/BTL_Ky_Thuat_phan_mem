<?php
require __DIR__ . '/includes/init.php';
$u = require_login();
$err = [];
if (is_post()) {
    if (p('action') === 'profile') {
        $name = p('full_name'); $email = p('email'); $phone = p('phone'); $addr = p('address');
        if (mb_strlen($name) < 2) $err[] = 'Vui lòng nhập họ tên.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err[] = 'Email không hợp lệ.';
        if ($phone !== '' && !preg_match('/^[0-9+\s.-]{8,20}$/', $phone)) $err[] = 'Số điện thoại không hợp lệ.';
        if (!$err && fetch_val("SELECT 1 FROM users WHERE email = ? AND user_id <> ?", [$email, $u['user_id']])) $err[] = 'Email đã được tài khoản khác dùng.';
        if (!$err) {
            run("UPDATE users SET full_name=?, email=?, phone=?, address=? WHERE user_id=?", [$name, $email, $phone ?: null, $addr ?: null, $u['user_id']]);
            audit('PROFILE_UPDATE', 'user', (int)$u['user_id']);
            flash('success', 'Đã cập nhật hồ sơ.'); redirect('profile.php');
        }
    } elseif (p('action') === 'password') {
        if (!password_verify(p('current'), $u['password_hash'])) $err[] = 'Mật khẩu hiện tại không đúng.';
        elseif ($m = validate_password(p('new'))) $err[] = $m;
        elseif (p('new') !== p('new2')) $err[] = 'Mật khẩu mới nhập lại không khớp.';
        else {
            run("UPDATE users SET password_hash = ? WHERE user_id = ?", [password_hash(p('new'), PASSWORD_DEFAULT), $u['user_id']]);
            audit('PASSWORD_CHANGE', 'user', (int)$u['user_id']);
            flash('success', 'Đã đổi mật khẩu.'); redirect('profile.php');
        }
    }
}
$card = $u['role_code'] === 'READER' ? latest_card((int)$u['user_id']) : null;
page_start('Hồ sơ cá nhân');
page_head('Hồ sơ cá nhân', $u['role_name'] . ' · ' . $u['username']);
if ($err): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($err as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="row g-4">
  <div class="col-lg-7">
    <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="profile">
      <div class="card-header">Thông tin cá nhân</div>
      <div class="card-body row g-3">
        <div class="col-12"><label class="form-label" for="full_name">Họ và tên</label><input class="form-control" id="full_name" name="full_name" value="<?= e($u['full_name']) ?>" required></div>
        <div class="col-md-6"><label class="form-label" for="email">Email</label><input class="form-control" type="email" id="email" name="email" value="<?= e($u['email']) ?>" required></div>
        <div class="col-md-6"><label class="form-label" for="phone">Số điện thoại</label><input class="form-control" id="phone" name="phone" value="<?= e($u['phone']) ?>"></div>
        <div class="col-12"><label class="form-label" for="address">Địa chỉ</label><input class="form-control" id="address" name="address" value="<?= e($u['address']) ?>"></div>
        <div class="col-12"><button class="btn btn-primary" type="submit">Lưu thay đổi</button></div>
      </div>
    </form>
  </div>
  <div class="col-lg-5">
    <?php if ($u['role_code'] === 'READER'): ?>
    <div class="card mb-4"><div class="card-header">Thẻ thư viện</div><div class="card-body">
      <?php if ($card): $st = card_state($card); ?>
        <p class="mb-1"><strong><?= e($card['card_no']) ?></strong> <?= badge('card', $st) ?></p>
        <p class="mb-0 text-muted small">Cấp ngày <?= dmy($card['issue_date']) ?> · Hết hạn <?= dmy($card['expiry_date']) ?></p>
      <?php else: ?><p class="mb-0 text-muted">Bạn chưa có thẻ. Hãy đến quầy thư viện để được cấp thẻ.</p><?php endif; ?>
    </div></div>
    <?php endif; ?>
    <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="password">
      <div class="card-header">Đổi mật khẩu</div>
      <div class="card-body">
        <div class="mb-3"><label class="form-label" for="current">Mật khẩu hiện tại</label><input class="form-control" type="password" id="current" name="current" autocomplete="current-password" required></div>
        <div class="mb-3"><label class="form-label" for="new">Mật khẩu mới</label><input class="form-control" type="password" id="new" name="new" autocomplete="new-password" required></div>
        <div class="mb-3"><label class="form-label" for="new2">Nhập lại mật khẩu mới</label><input class="form-control" type="password" id="new2" name="new2" autocomplete="new-password" required></div>
        <button class="btn btn-primary" type="submit">Đổi mật khẩu</button>
      </div>
    </form>
  </div>
</div>
<?php page_end();
