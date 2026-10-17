<?php
require __DIR__ . '/../includes/init.php';
$me = require_admin();

if (is_post()) {
    $act = p('action'); $id = (int)p('user_id');
    try {
        if ($act === 'create') {
            $f = ['username' => p('username'), 'email' => p('email'), 'full_name' => p('full_name')];
            $role = p('role'); $pw = p('password');
            $err = [];
            if (!preg_match('/^[A-Za-z0-9_.]{3,30}$/', $f['username'])) $err[] = 'Tên đăng nhập gồm 3-30 ký tự (chữ không dấu, số, . hoặc _).';
            if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) $err[] = 'Email không hợp lệ.';
            if (mb_strlen($f['full_name']) < 2) $err[] = 'Vui lòng nhập họ tên.';
            if (!in_array($role, ['READER', 'LIBRARIAN', 'ADMIN'], true)) $err[] = 'Vai trò không hợp lệ.';
            if ($m = validate_password($pw)) $err[] = $m;
            if (!$err && fetch_val("SELECT 1 FROM users WHERE username = ? OR email = ?", [$f['username'], $f['email']])) $err[] = 'Tên đăng nhập hoặc email đã tồn tại.';
            if ($err) throw new RuntimeException(implode(' ', $err));
            run("INSERT INTO users (role_id, username, email, password_hash, full_name) VALUES ((SELECT role_id FROM roles WHERE role_code = ?),?,?,?,?)",
                [$role, $f['username'], $f['email'], password_hash($pw, PASSWORD_DEFAULT), $f['full_name']]);
            audit('CREATE_USER', 'user', (int)db()->lastInsertId(), $role);
            flash('success', 'Đã tạo tài khoản ' . $f['username'] . '.');
        } elseif ($act === 'update') {
            $role = p('role'); $status = p('status'); $pw = p('password');
            if (!in_array($role, ['READER', 'LIBRARIAN', 'ADMIN'], true) || !in_array($status, ['ACTIVE', 'LOCKED', 'INACTIVE'], true)) throw new RuntimeException('Dữ liệu không hợp lệ.');
            if ($id === (int)$me['user_id'] && ($role !== 'ADMIN' || $status !== 'ACTIVE')) throw new RuntimeException('Bạn không thể tự hạ quyền hoặc khóa chính mình.');
            if ($pw !== '' && ($m = validate_password($pw))) throw new RuntimeException($m);
            tx(function () use ($id, $role, $status, $pw) {
                run("UPDATE users SET role_id = (SELECT role_id FROM roles WHERE role_code = ?), status = ?, failed_login_count = 0, locked_until = NULL WHERE user_id = ?", [$role, $status, $id]);
                if ($pw !== '') run("UPDATE users SET password_hash = ? WHERE user_id = ?", [password_hash($pw, PASSWORD_DEFAULT), $id]);
            });
            audit('UPDATE_USER', 'user', $id, "role=$role status=$status" . ($pw !== '' ? ' +password' : ''));
            flash('success', 'Đã cập nhật tài khoản.');
        }
    } catch (RuntimeException $ex) { flash('danger', $ex->getMessage()); }
    redirect('admin/users.php' . (g('role') ? '?role=' . urlencode(g('role')) : ''));
}
$roleF = g('role'); $kw = g('q'); $page = max(1, (int)g('page', '1')); $per = 15;
$where = '1=1'; $params = [];
if (in_array($roleF, ['READER', 'LIBRARIAN', 'ADMIN'], true)) { $where .= " AND r.role_code = ?"; $params[] = $roleF; }
if ($kw !== '') { $where .= " AND (u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)"; $l = '%' . $kw . '%'; array_push($params, $l, $l, $l); }
$total = (int)fetch_val("SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id = u.role_id WHERE $where", $params);
$off = ($page - 1) * $per;
$rows = fetch_all("SELECT u.*, r.role_code FROM users u JOIN roles r ON r.role_id = u.role_id WHERE $where ORDER BY FIELD(r.role_code,'ADMIN','LIBRARIAN','READER'), u.full_name LIMIT $per OFFSET $off", $params);

page_start('Tài khoản & phân quyền');
page_head('Tài khoản & phân quyền', 'Tạo tài khoản nhân sự, đổi vai trò, khóa / ngừng hoạt động, đặt lại mật khẩu. Không xóa tài khoản.',
  '<button class="btn btn-accent" type="button" data-bs-toggle="collapse" data-bs-target="#newUser">Thêm tài khoản</button>');
?>
<div class="collapse mb-4" id="newUser"><form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="create">
  <div class="card-header">Tạo tài khoản mới</div><div class="card-body row g-3">
    <div class="col-md-4"><label class="form-label" for="c_name">Họ và tên</label><input class="form-control" id="c_name" name="full_name" required></div>
    <div class="col-md-3"><label class="form-label" for="c_user">Tên đăng nhập</label><input class="form-control" id="c_user" name="username" required></div>
    <div class="col-md-5"><label class="form-label" for="c_mail">Email</label><input class="form-control" type="email" id="c_mail" name="email" required></div>
    <div class="col-md-4"><label class="form-label" for="c_role">Vai trò</label><select class="form-select" id="c_role" name="role"><option value="LIBRARIAN">Thủ thư</option><option value="ADMIN">Quản trị viên</option><option value="READER">Độc giả</option></select></div>
    <div class="col-md-4"><label class="form-label" for="c_pw">Mật khẩu ban đầu</label><input class="form-control" id="c_pw" name="password" autocomplete="off" required><div class="form-text">≥ 8 ký tự, có chữ và số.</div></div>
    <div class="col-12"><button class="btn btn-primary" type="submit">Tạo tài khoản</button></div>
  </div></form></div>

<form class="row g-2 mb-3" method="get" role="search">
  <div class="col-sm-5 col-md-4"><label class="visually-hidden" for="q">Tìm</label><input class="form-control" id="q" name="q" value="<?= e($kw) ?>" placeholder="Tên, tên đăng nhập hoặc email"></div>
  <div class="col-sm-4 col-md-3"><label class="visually-hidden" for="role">Vai trò</label><select class="form-select" id="role" name="role"><option value="">Mọi vai trò</option>
    <?php foreach (['ADMIN' => 'Quản trị viên', 'LIBRARIAN' => 'Thủ thư', 'READER' => 'Độc giả'] as $k => $v): ?><option value="<?= $k ?>" <?= $roleF === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
  <div class="col-auto"><button class="btn btn-primary" type="submit">Lọc</button></div>
</form>
<div class="table-wrap"><div class="table-responsive"><table class="table table-striped mb-0">
  <thead><tr><th>Tài khoản</th><th>Vai trò</th><th>Trạng thái</th><th>Mật khẩu mới</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): $self = (int)$r['user_id'] === (int)$me['user_id']; $fid = 'fu' . (int)$r['user_id']; ?>
  <tr>
    <td><form method="post" id="<?= $fid ?>"><?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="user_id" value="<?= (int)$r['user_id'] ?>"></form>
      <div class="fw-semibold"><?= e($r['full_name']) ?><?= $self ? ' <span class="badge text-bg-warning">Bạn</span>' : '' ?></div><div class="small text-muted"><?= e($r['username']) ?> · <?= e($r['email']) ?></div></td>
    <td><select class="form-select form-select-sm" name="role" form="<?= $fid ?>" aria-label="Vai trò" <?= $self ? 'disabled' : '' ?>>
      <?php foreach (['ADMIN' => 'Quản trị viên', 'LIBRARIAN' => 'Thủ thư', 'READER' => 'Độc giả'] as $k => $v): ?><option value="<?= $k ?>" <?= $r['role_code'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
      <?php if ($self): ?><input type="hidden" name="role" value="ADMIN" form="<?= $fid ?>"><?php endif; ?></td>
    <td><select class="form-select form-select-sm" name="status" form="<?= $fid ?>" aria-label="Trạng thái" <?= $self ? 'disabled' : '' ?>>
      <?php foreach (['ACTIVE' => 'Hoạt động', 'LOCKED' => 'Bị khóa', 'INACTIVE' => 'Ngừng hoạt động'] as $k => $v): ?><option value="<?= $k ?>" <?= $r['status'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
      <?php if ($self): ?><input type="hidden" name="status" value="ACTIVE" form="<?= $fid ?>"><?php endif; ?></td>
    <td><input class="form-control form-control-sm" name="password" form="<?= $fid ?>" placeholder="Để trống = giữ nguyên" autocomplete="off" aria-label="Mật khẩu mới"></td>
    <td class="text-end"><button class="btn btn-sm btn-primary" type="submit" form="<?= $fid ?>">Lưu</button></td>
  </tr>
  <?php endforeach; ?></tbody></table></div></div>
<?= pager($total, $per, $page) ?>
<?php page_end();
