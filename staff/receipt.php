<?php
require __DIR__ . '/../includes/init.php';
$u = require_staff();
$id = (int)g('id');
$p = fetch_one("SELECT p.*, f.reason, f.overdue_days, f.overdue_amount, f.damage_amount, f.total_amount, u.full_name, u.username, t.title, c.barcode, s.full_name AS staff_name
    FROM payments p JOIN fines f ON f.fine_id = p.fine_id JOIN users u ON u.user_id = f.reader_id JOIN users s ON s.user_id = p.received_by
    JOIN loan_items li ON li.loan_item_id = f.loan_item_id JOIN book_copies c ON c.copy_id = li.copy_id JOIN book_titles t ON t.title_id = c.title_id WHERE p.payment_id = ?", [$id]);
if (!$p) { http_response_code(404); page_start('Không tìm thấy'); echo '<div class="alert alert-warning">Không tìm thấy biên lai.</div>'; page_end(); exit; }
page_start('Biên lai #' . $id);
?>
<div class="mx-auto" style="max-width:560px">
  <div class="text-center mb-3"><h1 class="mb-0"><?= e(cfg('app.name')) ?></h1><div class="text-muted">BIÊN LAI THU TIỀN PHẠT #<?= $id ?></div></div>
  <dl class="row">
    <dt class="col-5">Độc giả</dt><dd class="col-7"><?= e($p['full_name']) ?> (<?= e($p['username']) ?>)</dd>
    <dt class="col-5">Sách</dt><dd class="col-7"><?= e($p['title']) ?> <span class="text-muted">(<?= e($p['barcode']) ?>)</span></dd>
    <dt class="col-5">Lý do</dt><dd class="col-7"><?= e($p['reason']) ?></dd>
    <dt class="col-5">Phạt quá hạn</dt><dd class="col-7"><?= money($p['overdue_amount']) ?> (<?= (int)$p['overdue_days'] ?> ngày)</dd>
    <dt class="col-5">Phạt hỏng / mất</dt><dd class="col-7"><?= money($p['damage_amount']) ?></dd>
    <dt class="col-5">Đã thu</dt><dd class="col-7 fs-5 fw-bold"><?= money($p['amount']) ?></dd>
    <dt class="col-5">Hình thức</dt><dd class="col-7"><?= $p['method'] === 'CASH' ? 'Tiền mặt' : 'Chuyển khoản' ?></dd>
    <dt class="col-5">Thời gian</dt><dd class="col-7"><?= dmyhm($p['paid_at']) ?></dd>
    <dt class="col-5">Thủ thư thu</dt><dd class="col-7"><?= e($p['staff_name']) ?></dd>
  </dl>
  <div class="text-center mt-4 no-print"><button class="btn btn-primary" onclick="window.print()">In biên lai</button> <a class="btn btn-outline-primary" href="<?= e(url('staff/fines.php')) ?>">Về danh sách phạt</a></div>
</div>
<?php page_end();
