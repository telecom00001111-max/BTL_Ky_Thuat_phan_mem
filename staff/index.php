<?php
require __DIR__ . '/../includes/init.php';
$u = require_staff();

$s = [
  'borrowing' => (int)fetch_val("SELECT COUNT(*) FROM loan_items WHERE status = 'BORROWING'"),
  'overdue'   => (int)fetch_val("SELECT COUNT(*) FROM loan_items WHERE status = 'BORROWING' AND due_date < CURDATE()"),
  'pending'   => (int)fetch_val("SELECT COUNT(*) FROM reservations WHERE status = 'PENDING'"),
  'holding'   => (int)fetch_val("SELECT COUNT(*) FROM reservations WHERE status = 'APPROVED'"),
  'unpaid'    => (float)fetch_val("SELECT COALESCE(SUM(total_amount),0) FROM fines WHERE status = 'UNPAID'"),
  'readers'   => (int)fetch_val("SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.role_code = 'READER' AND u.status = 'ACTIVE'"),
  'titles'    => (int)fetch_val("SELECT COUNT(*) FROM book_titles WHERE status = 'ACTIVE'"),
  'copies'    => (int)fetch_val("SELECT COUNT(*) FROM book_copies WHERE status NOT IN ('INACTIVE','LOST')"),
];
$overdue = fetch_all("SELECT li.due_date, c.barcode, t.title, u.full_name, lc.card_no, DATEDIFF(CURDATE(), li.due_date) AS late
    FROM loan_items li JOIN loans l ON l.loan_id = li.loan_id JOIN users u ON u.user_id = l.reader_id JOIN library_cards lc ON lc.card_id = l.card_id
    JOIN book_copies c ON c.copy_id = li.copy_id JOIN book_titles t ON t.title_id = c.title_id
    WHERE li.status = 'BORROWING' AND li.due_date < CURDATE() ORDER BY li.due_date LIMIT 8");

page_start('Tổng quan');
page_head('Xin chào, ' . $u['full_name'], 'Tình hình thư viện hôm nay, ' . date('d/m/Y'));
?>
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="stat"><div class="n"><?= $s['borrowing'] ?></div><div class="l">Sách đang được mượn</div></div></div>
  <div class="col-6 col-lg-3"><div class="stat <?= $s['overdue'] ? 'bad' : '' ?>"><div class="n"><?= $s['overdue'] ?></div><div class="l">Sách quá hạn</div></div></div>
  <div class="col-6 col-lg-3"><div class="stat <?= $s['pending'] ? 'warn' : '' ?>"><div class="n"><?= $s['pending'] ?></div><div class="l">Đặt giữ chờ duyệt (<?= $s['holding'] ?> đang giữ)</div></div></div>
  <div class="col-6 col-lg-3"><div class="stat <?= $s['unpaid'] > 0 ? 'bad' : '' ?>"><div class="n" style="font-size:1.5rem"><?= money($s['unpaid']) ?></div><div class="l">Tiền phạt chưa thu</div></div></div>
  <div class="col-4"><div class="stat warn"><div class="n"><?= $s['readers'] ?></div><div class="l">Độc giả hoạt động</div></div></div>
  <div class="col-4"><div class="stat warn"><div class="n"><?= $s['titles'] ?></div><div class="l">Đầu sách</div></div></div>
  <div class="col-4"><div class="stat warn"><div class="n"><?= $s['copies'] ?></div><div class="l">Bản sách</div></div></div>
</div>
<div class="d-flex flex-wrap gap-2 mb-4">
  <a class="btn btn-primary" href="<?= e(url('staff/loan_new.php')) ?>">Lập phiếu mượn</a>
  <a class="btn btn-primary" href="<?= e(url('staff/return.php')) ?>">Lập phiếu trả</a>
  <a class="btn btn-outline-primary" href="<?= e(url('staff/reservations.php')) ?>">Duyệt đặt giữ</a>
  <a class="btn btn-outline-primary" href="<?= e(url('staff/fines.php')) ?>">Thu tiền phạt</a>
  <a class="btn btn-outline-primary" href="<?= e(url('staff/readers.php')) ?>">Quản lý độc giả</a>
</div>
<h2 class="mb-3">Sách quá hạn cần nhắc</h2>
<?php if (!$overdue): ?><div class="alert alert-light border">Không có sách nào quá hạn.</div>
<?php else: ?>
<div class="table-wrap"><div class="table-responsive"><table class="table table-striped mb-0">
  <thead><tr><th>Độc giả</th><th>Thẻ</th><th>Sách</th><th>Hạn trả</th><th>Quá hạn</th></tr></thead><tbody>
  <?php foreach ($overdue as $o): ?>
  <tr><td><?= e($o['full_name']) ?></td><td><?= e($o['card_no']) ?></td><td><?= e($o['title']) ?><div class="small text-muted"><?= e($o['barcode']) ?></div></td>
    <td><?= dmy($o['due_date']) ?></td><td><span class="badge text-bg-danger"><?= (int)$o['late'] ?> ngày</span></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<p class="mt-2"><a href="<?= e(url('staff/reports.php?r=violators')) ?>">Xem toàn bộ độc giả vi phạm</a></p>
<?php endif; ?>
<?php page_end();
