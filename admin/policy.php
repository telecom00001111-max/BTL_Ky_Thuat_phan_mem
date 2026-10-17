<?php
require __DIR__ . '/../includes/init.php';
$me = require_admin();

$fields = [
  'loan_days' => ['Số ngày mượn mỗi lần', 1, 365, 'ngày'], 'max_books_per_loan' => ['Số sách tối đa được mượn cùng lúc', 1, 50, 'cuốn'],
  'max_renewals' => ['Số lần gia hạn tối đa', 0, 20, 'lần'], 'renew_days' => ['Số ngày mỗi lần gia hạn', 1, 365, 'ngày'],
  'grace_days' => ['Số ngày ân hạn (không tính phạt)', 0, 30, 'ngày'], 'fine_per_day' => ['Mức phạt quá hạn / cuốn / ngày', 0, 10000000, 'đồng'],
  'lost_fine_rate' => ['Hệ số phạt mất sách (1 = 100% giá sách)', 0, 10, 'lần giá sách'], 'hold_days' => ['Số ngày giữ sách sau khi duyệt đặt giữ', 1, 30, 'ngày'],
  'max_failed_login' => ['Số lần nhập sai mật khẩu trước khi khóa', 1, 20, 'lần'], 'lock_minutes' => ['Thời gian khóa tạm thời', 1, 1440, 'phút'],
  'card_valid_years' => ['Hiệu lực thẻ thư viện', 1, 10, 'năm'],
];
$cur = policy();
if (is_post()) {
    $v = []; $err = [];
    foreach ($fields as $k => [$lbl, $min, $max]) {
        $raw = str_replace(',', '.', p($k));
        if ($raw === '' || !is_numeric($raw) || $raw < $min || $raw > $max) $err[] = "$lbl phải từ $min đến $max.";
        $v[$k] = $raw;
    }
    $eff = p('effective_from');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eff) || $eff < date('Y-m-d')) $err[] = 'Ngày áp dụng không được ở quá khứ.';
    if ($err) { flash('danger', implode(' ', $err)); }
    else {
        run("INSERT INTO library_policy (loan_days, max_books_per_loan, max_renewals, renew_days, grace_days, fine_per_day, lost_fine_rate, hold_days, max_failed_login, lock_minutes, card_valid_years, effective_from, updated_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)", [$v['loan_days'], $v['max_books_per_loan'], $v['max_renewals'], $v['renew_days'], $v['grace_days'], $v['fine_per_day'], $v['lost_fine_rate'], $v['hold_days'], $v['max_failed_login'], $v['lock_minutes'], $v['card_valid_years'], $eff, $me['user_id']]);
        audit('UPDATE_POLICY', 'policy', (int)db()->lastInsertId());
        flash('success', 'Đã lưu quy định mới, áp dụng từ ' . dmy($eff) . '. Phiếu mượn cũ vẫn tính phạt theo quy định lúc mượn.');
    }
    redirect('admin/policy.php');
}
$hist = fetch_all("SELECT p.*, u.full_name FROM library_policy p LEFT JOIN users u ON u.user_id = p.updated_by ORDER BY p.effective_from DESC, p.policy_id DESC LIMIT 10");
page_start('Quy định thư viện');
page_head('Quy định thư viện', 'Mỗi lần lưu tạo một phiên bản mới. Phiếu mượn đã lập vẫn tính phạt theo quy định có hiệu lực lúc mượn.');
?>
<form method="post" class="card mb-4"><?= csrf_field() ?>
  <div class="card-header">Phiên bản mới (đang áp dụng: #<?= (int)$cur['policy_id'] ?>, từ <?= dmy($cur['effective_from']) ?>)</div>
  <div class="card-body row g-3">
    <?php foreach ($fields as $k => [$lbl, $min, $max, $unit]): ?>
    <div class="col-md-6 col-lg-4"><label class="form-label" for="<?= $k ?>"><?= e($lbl) ?></label>
      <div class="input-group"><input class="form-control" id="<?= $k ?>" name="<?= $k ?>" inputmode="decimal" value="<?= e(rtrim(rtrim((string)$cur[$k], '0'), '.') ?: '0') ?>" required><span class="input-group-text"><?= e($unit) ?></span></div></div>
    <?php endforeach; ?>
    <div class="col-md-6 col-lg-4"><label class="form-label" for="effective_from">Áp dụng từ ngày</label><input class="form-control" type="date" id="effective_from" name="effective_from" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required></div>
    <div class="col-12"><button class="btn btn-primary" type="submit">Lưu quy định mới</button></div>
  </div>
</form>
<h2 class="h5">Lịch sử quy định</h2>
<div class="table-wrap"><div class="table-responsive"><table class="table table-striped mb-0">
  <thead><tr><th>#</th><th>Áp dụng từ</th><th>Ngày mượn</th><th>Tối đa</th><th>Gia hạn</th><th>Phạt/ngày</th><th>Phạt mất</th><th>Giữ sách</th><th>Cập nhật bởi</th></tr></thead><tbody>
  <?php foreach ($hist as $h): ?><tr><td><?= (int)$h['policy_id'] ?></td><td><?= dmy($h['effective_from']) ?></td><td><?= (int)$h['loan_days'] ?> ngày</td><td><?= (int)$h['max_books_per_loan'] ?> cuốn</td>
    <td><?= (int)$h['max_renewals'] ?> × <?= (int)$h['renew_days'] ?> ngày</td><td><?= money($h['fine_per_day']) ?></td><td><?= (float)$h['lost_fine_rate'] * 100 ?>%</td><td><?= (int)$h['hold_days'] ?> ngày</td><td><?= e($h['full_name'] ?? '—') ?></td></tr><?php endforeach; ?>
  </tbody></table></div></div>
<?php page_end();
