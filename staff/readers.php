<?php
require __DIR__ . '/../includes/init.php';
$u = require_staff();

if (is_post()) {
    $act = p('action'); $rid = (int)p('reader_id');
    try {
        if ($act === 'create') {
            $f = ['username' => p('username'), 'email' => p('email'), 'full_name' => p('full_name'), 'phone' => p('phone'), 'address' => p('address')];
            $pw = p('password');
            $err = [];
            if (!preg_match('/^[A-Za-z0-9_.]{3,30}$/', $f['username'])) $err[] = 'Tên đăng nhập gồm 3-30 ký tự (chữ không dấu, số, . hoặc _).';
            if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) $err[] = 'Email không hợp lệ.';
            if (mb_strlen($f['full_name']) < 2) $err[] = 'Vui lòng nhập họ tên.';
            if ($m = validate_password($pw)) $err[] = $m;
            if (!$err && fetch_val("SELECT 1 FROM users WHERE username = ? OR email = ?", [$f['username'], $f['email']])) $err[] = 'Tên đăng nhập hoặc email đã tồn tại.';
            if ($err) throw new RuntimeException(implode(' ', $err));
            $newId = tx(function () use ($f, $pw) {
                run("INSERT INTO users (role_id, username, email, password_hash, full_name, phone, address) VALUES ((SELECT role_id FROM roles WHERE role_code='READER'),?,?,?,?,?,?)",
                    [$f['username'], $f['email'], password_hash($pw, PASSWORD_DEFAULT), $f['full_name'], $f['phone'] ?: null, $f['address'] ?: null]);
                $id = (int)db()->lastInsertId();
                if (p('issue_card') === '1') issue_card($id);
                return $id;
            });
            audit('CREATE_READER', 'user', $newId);
            flash('success', 'Đã tạo tài khoản độc giả ' . $f['username'] . (p('issue_card') === '1' ? ' kèm thẻ thư viện.' : '.'));
        } elseif ($act === 'toggle_user') {
            $cur = fetch_val("SELECT status FROM users WHERE user_id = ?", [$rid]);
            $new = $cur === 'ACTIVE' ? 'LOCKED' : 'ACTIVE';
            run("UPDATE users SET status = ?, failed_login_count = 0, locked_until = NULL WHERE user_id = ? AND role_id = (SELECT role_id FROM roles WHERE role_code='READER')", [$new, $rid]);
            audit($new === 'LOCKED' ? 'LOCK_READER' : 'UNLOCK_READER', 'user', $rid);
            flash('success', $new === 'LOCKED' ? 'Đã khóa tài khoản độc giả.' : 'Đã mở khóa tài khoản độc giả.');
        } elseif ($act === 'issue_card') {
            if (active_card($rid)) throw new RuntimeException('Độc giả này đang có thẻ còn hiệu lực. Hãy dùng chức năng gia hạn thẻ.');
            $cid = issue_card($rid); audit('ISSUE_CARD', 'card', $cid);
            flash('success', 'Đã cấp thẻ mới cho độc giả.');
        } elseif ($act === 'renew_card') {
            $new = renew_card((int)p('card_id')); audit('RENEW_CARD', 'card', (int)p('card_id'));
            flash('success', 'Đã gia hạn thẻ đến ' . dmy($new) . '.');
        } elseif ($act === 'toggle_card') {
            $cid = (int)p('card_id');
            $cur = fetch_val("SELECT status FROM library_cards WHERE card_id = ?", [$cid]);
            $new = $cur === 'LOCKED' ? 'ACTIVE' : 'LOCKED';
            run("UPDATE library_cards SET status = ? WHERE card_id = ?", [$new, $cid]);
            audit($new === 'LOCKED' ? 'LOCK_CARD' : 'UNLOCK_CARD', 'card', $cid);
            flash('success', $new === 'LOCKED' ? 'Đã khóa thẻ.' : 'Đã mở khóa thẻ.');
        }
    } catch (RuntimeException $ex) { flash('danger', $ex->getMessage()); }
    redirect('staff/readers.php' . (p('back') !== '' ? '?q=' . urlencode(p('back')) : ''));
}

$kw = g('q'); $page = max(1, (int)g('page', '1')); $per = 15;
$where = "r.role_code = 'READER'"; $params = [];
if ($kw !== '') {
    $where .= " AND (u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR EXISTS (SELECT 1 FROM library_cards c WHERE c.reader_id = u.user_id AND c.card_no LIKE ?))";
    $l = '%' . $kw . '%'; array_push($params, $l, $l, $l, $l, $l);
}
$total = (int)fetch_val("SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id = u.role_id WHERE $where", $params);
$off = ($page - 1) * $per;
$rows = fetch_all("SELECT u.*, 
   (SELECT COUNT(*) FROM loan_items li JOIN loans l ON l.loan_id = li.loan_id WHERE l.reader_id = u.user_id AND li.status = 'BORROWING') AS borrowing,
   (SELECT COALESCE(SUM(total_amount),0) FROM fines f WHERE f.reader_id = u.user_id AND f.status = 'UNPAID') AS unpaid
   FROM users u JOIN roles r ON r.role_id = u.role_id WHERE $where ORDER BY u.created_at DESC, u.user_id DESC LIMIT $per OFFSET $off", $params);

page_start('Độc giả & thẻ thư viện');
page_head('Độc giả & thẻ thư viện', 'Tạo tài khoản, cấp thẻ, khóa / mở khóa, gia hạn thẻ (hiệu lực ' . (int)policy()['card_valid_years'] . ' năm)',
  '<button class="btn btn-accent" type="button" data-bs-toggle="collapse" data-bs-target="#newReader">Thêm độc giả</button>');
?>
<div class="collapse mb-4" id="newReader"><form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="create">
  <div class="card-header">Tạo tài khoản độc giả tại quầy</div>
  <div class="card-body row g-3">
    <div class="col-md-6"><label class="form-label" for="n_name">Họ và tên</label><input class="form-control" id="n_name" name="full_name" required></div>
    <div class="col-md-3"><label class="form-label" for="n_user">Tên đăng nhập</label><input class="form-control" id="n_user" name="username" required></div>
    <div class="col-md-3"><label class="form-label" for="n_phone">Điện thoại</label><input class="form-control" id="n_phone" name="phone"></div>
    <div class="col-md-5"><label class="form-label" for="n_mail">Email</label><input class="form-control" type="email" id="n_mail" name="email" required></div>
    <div class="col-md-4"><label class="form-label" for="n_pw">Mật khẩu ban đầu</label><input class="form-control" type="text" id="n_pw" name="password" required autocomplete="off"><div class="form-text">≥ 8 ký tự, có chữ và số. Độc giả nên đổi sau.</div></div>
    <div class="col-md-3"><label class="form-label" for="n_addr">Địa chỉ</label><input class="form-control" id="n_addr" name="address"></div>
    <div class="col-12 form-check ms-2"><input class="form-check-input" type="checkbox" id="n_card" name="issue_card" value="1" checked><label class="form-check-label" for="n_card">Cấp thẻ thư viện ngay</label></div>
    <div class="col-12"><button class="btn btn-primary" type="submit">Tạo độc giả</button></div>
  </div></form></div>

<form class="row g-2 mb-3" method="get" role="search">
  <div class="col-sm-8 col-md-6"><label class="visually-hidden" for="q">Tìm độc giả</label><input class="form-control" id="q" name="q" value="<?= e($kw) ?>" placeholder="Tên, tên đăng nhập, email, SĐT hoặc số thẻ"></div>
  <div class="col-auto"><button class="btn btn-primary" type="submit">Tìm</button> <a class="btn btn-outline-primary" href="<?= e(url('staff/readers.php')) ?>">Xóa lọc</a></div>
</form>

<div class="table-wrap"><div class="table-responsive"><table class="table table-striped mb-0">
  <thead><tr><th>Độc giả</th><th>Thẻ thư viện</th><th>Tài khoản</th><th class="text-center">Đang mượn</th><th class="text-end">Nợ phạt</th><th class="text-end">Thao tác</th></tr></thead><tbody>
  <?php if (!$rows): ?><tr><td colspan="6" class="text-muted">Không có độc giả nào.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): $card = latest_card((int)$r['user_id']); $cs = card_state($card); ?>
  <tr>
    <td><div class="fw-semibold"><?= e($r['full_name']) ?></div><div class="small text-muted"><?= e($r['username']) ?> · <?= e($r['email']) ?><?= $r['phone'] ? ' · ' . e($r['phone']) : '' ?></div></td>
    <td><?php if ($card): ?><?= e($card['card_no']) ?> <?= badge('card', $cs) ?><div class="small text-muted">Hạn <?= dmy($card['expiry_date']) ?></div>
        <?php else: ?><span class="text-muted">Chưa có thẻ</span><?php endif; ?></td>
    <td><?= badge('user', $r['status']) ?><?= ($r['locked_until'] && strtotime($r['locked_until']) > time()) ? '<div class="small text-danger">Khóa tạm đến ' . date('H:i', strtotime($r['locked_until'])) . '</div>' : '' ?></td>
    <td class="text-center"><?= (int)$r['borrowing'] ?></td>
    <td class="text-end"><?= $r['unpaid'] > 0 ? '<span class="text-danger fw-semibold">' . money($r['unpaid']) . '</span>' : '—' ?></td>
    <td class="text-end"><div class="dropdown">
      <button class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">Thao tác</button>
      <ul class="dropdown-menu dropdown-menu-end">
        <?php
        $btn = function (string $action, string $label, array $extra = [], string $confirm = '') use ($r, $kw) {
            $h = '<li><form method="post"' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field() . '<input type="hidden" name="action" value="' . e($action) . '"><input type="hidden" name="reader_id" value="' . (int)$r['user_id'] . '"><input type="hidden" name="back" value="' . e($kw) . '">';
            foreach ($extra as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
            return $h . '<button class="dropdown-item" type="submit">' . e($label) . '</button></form></li>';
        };
        if (!$card || $cs === 'INACTIVE') echo $btn('issue_card', 'Cấp thẻ mới');
        elseif ($cs === 'EXPIRED') { echo $btn('renew_card', 'Gia hạn thẻ', ['card_id' => $card['card_id']]); }
        else { echo $btn('renew_card', 'Gia hạn thẻ', ['card_id' => $card['card_id']]);
               echo $btn('toggle_card', $cs === 'LOCKED' ? 'Mở khóa thẻ' : 'Khóa thẻ', ['card_id' => $card['card_id']], $cs === 'LOCKED' ? '' : 'Khóa thẻ này? Độc giả sẽ không mượn được sách.'); }
        echo '<li><hr class="dropdown-divider"></li>';
        echo $btn('toggle_user', $r['status'] === 'ACTIVE' ? 'Khóa tài khoản' : 'Mở khóa tài khoản', [], $r['status'] === 'ACTIVE' ? 'Khóa tài khoản độc giả này?' : '');
        ?>
      </ul></div></td>
  </tr>
  <?php endforeach; ?></tbody></table></div></div>
<?= pager($total, $per, $page) ?>
<?php page_end();
