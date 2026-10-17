<?php
require __DIR__ . '/../includes/init.php';
$u = require_staff();

if (is_post() && p('action') === 'pay') {
    try { $pid = record_payment((int)p('fine_id'), p('method'), (int)$u['user_id']); flash('success', 'Đã thu tiền phạt.'); redirect('staff/receipt.php?id=' . $pid); }
    catch (RuntimeException $ex) { flash('danger', $ex->getMessage()); redirect('staff/fines.php'); }
}
$st = g('st', 'UNPAID'); if (!in_array($st, ['UNPAID', 'PAID', 'ALL'], true)) $st = 'UNPAID';
$kw = g('q'); $page = max(1, (int)g('page', '1')); $per = 20;
$where = "(? = 'ALL' OR f.status = ?)"; $params = [$st, $st];
if ($kw !== '') { $where .= " AND (u.full_name LIKE ? OR u.username LIKE ? OR t.title LIKE ?)"; $l = '%' . $kw . '%'; array_push($params, $l, $l, $l); }
$base = "FROM fines f JOIN users u ON u.user_id = f.reader_id JOIN loan_items li ON li.loan_item_id = f.loan_item_id
         JOIN book_copies c ON c.copy_id = li.copy_id JOIN book_titles t ON t.title_id = c.title_id LEFT JOIN payments p ON p.fine_id = f.fine_id WHERE $where";
$total = (int)fetch_val("SELECT COUNT(*) $base", $params);
$sum = (float)fetch_val("SELECT COALESCE(SUM(f.total_amount),0) $base", $params);
$off = ($page - 1) * $per;
$rows = fetch_all("SELECT f.*, u.full_name, u.username, t.title, c.barcode, p.payment_id, p.paid_at, p.method $base ORDER BY f.created_at DESC LIMIT $per OFFSET $off", $params);

page_start('Tiền phạt');
page_head('Tiền phạt', 'Phạt = số ngày quá hạn tính phí × mức phạt + phạt hỏng / mất. Độc giả còn nợ phạt bị chặn mượn mới.');
?>
<form class="row g-2 mb-3" method="get" role="search">
  <div class="col-sm-4 col-md-3"><label class="visually-hidden" for="st">Trạng thái</label><select class="form-select" id="st" name="st">
    <option value="UNPAID" <?= $st === 'UNPAID' ? 'selected' : '' ?>>Chưa đóng</option><option value="PAID" <?= $st === 'PAID' ? 'selected' : '' ?>>Đã đóng</option><option value="ALL" <?= $st === 'ALL' ? 'selected' : '' ?>>Tất cả</option></select></div>
  <div class="col-sm-6 col-md-5"><label class="visually-hidden" for="q">Tìm</label><input class="form-control" id="q" name="q" value="<?= e($kw) ?>" placeholder="Tên độc giả hoặc tên sách"></div>
  <div class="col-auto"><button class="btn btn-primary" type="submit">Lọc</button></div>
</form>
<p><strong><?= $total ?></strong> khoản phạt · tổng <strong><?= money($sum) ?></strong></p>
<?php if (!$rows): ?><div class="alert alert-light border">Không có khoản phạt nào.</div>
<?php else: ?>
<div class="table-wrap"><div class="table-responsive"><table class="table table-striped mb-0">
  <thead><tr><th>Độc giả</th><th>Sách</th><th>Lý do</th><th class="text-end">Số tiền</th><th>Trạng thái</th><th class="text-end">Thu tiền</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
  <tr><td><div class="fw-semibold"><?= e($r['full_name']) ?></div><div class="small text-muted"><?= e($r['username']) ?></div></td>
    <td><?= e($r['title']) ?><div class="small text-muted"><?= e($r['barcode']) ?> · lập <?= dmy($r['created_at']) ?></div></td>
    <td><?= e($r['reason']) ?><?= $r['overdue_days'] > 0 ? '<div class="small text-muted">' . (int)$r['overdue_days'] . ' ngày × ' . money($r['overdue_amount'] / max(1, $r['overdue_days'])) . '</div>' : '' ?></td>
    <td class="text-end fw-semibold"><?= money($r['total_amount']) ?></td>
    <td><?= badge('fine', $r['status']) ?><?= $r['paid_at'] ? '<div class="small text-muted">' . dmy($r['paid_at']) . '</div>' : '' ?></td>
    <td class="text-end">
      <?php if ($r['status'] === 'UNPAID'): ?>
      <form method="post" class="d-inline-flex gap-1" data-confirm="Xác nhận đã thu <?= e(money($r['total_amount'])) ?> của <?= e($r['full_name']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="pay"><input type="hidden" name="fine_id" value="<?= (int)$r['fine_id'] ?>">
        <select class="form-select form-select-sm w-auto" name="method" aria-label="Hình thức thu"><option value="CASH">Tiền mặt</option><option value="TRANSFER">Chuyển khoản</option></select>
        <button class="btn btn-sm btn-primary" type="submit">Thu</button></form>
      <?php elseif ($r['payment_id']): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url('staff/receipt.php?id=' . $r['payment_id'])) ?>">Biên lai</a><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<?= pager($total, $per, $page) ?>
<?php endif; ?>
<?php page_end();
