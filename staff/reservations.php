<?php
require __DIR__ . '/../includes/init.php';
$u = require_staff();

if (is_post()) {
    $id = (int)p('id');
    try {
        if (p('action') === 'approve') { approve_reservation($id, (int)$u['user_id']); flash('success', 'Đã duyệt và giữ sách cho độc giả.'); }
        elseif (p('action') === 'reject') { reject_reservation($id, (int)$u['user_id'], p('reason')); flash('success', 'Đã từ chối yêu cầu.'); }
    } catch (RuntimeException $ex) { flash('danger', $ex->getMessage()); }
    redirect('staff/reservations.php' . (g('st') ? '?st=' . urlencode(g('st')) : ''));
}
$st = g('st', 'PENDING');
if ($st !== 'ALL' && !isset(STATUS_LABELS['reservation'][$st])) $st = 'PENDING';
$rows = fetch_all("SELECT r.*, u.full_name, u.username, t.title, c.barcode, s.full_name AS staff_name,
    (SELECT card_no FROM library_cards lc WHERE lc.reader_id = r.reader_id ORDER BY expiry_date DESC LIMIT 1) AS card_no
    FROM reservations r JOIN users u ON u.user_id = r.reader_id JOIN book_titles t ON t.title_id = r.title_id
    LEFT JOIN book_copies c ON c.copy_id = r.copy_id LEFT JOIN users s ON s.user_id = r.processed_by
    WHERE (? = 'ALL' OR r.status = ?) ORDER BY r.created_at DESC LIMIT 100", [$st, $st]);

page_start('Duyệt đặt giữ');
page_head('Duyệt yêu cầu đặt giữ', 'Duyệt xong, hệ thống giữ một bản sách cho độc giả trong ' . (int)policy()['hold_days'] . ' ngày.');
?>
<ul class="nav nav-pills mb-3 no-print">
  <?php foreach (['PENDING' => 'Chờ duyệt', 'APPROVED' => 'Đang giữ', 'FULFILLED' => 'Đã nhận sách', 'REJECTED' => 'Từ chối', 'EXPIRED' => 'Hết hạn giữ', 'CANCELLED' => 'Đã hủy', 'ALL' => 'Tất cả'] as $k => $v): ?>
  <li class="nav-item"><a class="nav-link <?= $st === $k ? 'active bg-primary' : '' ?>" href="?st=<?= $k ?>"><?= e($v) ?></a></li>
  <?php endforeach; ?>
</ul>
<?php if (!$rows): ?><div class="alert alert-light border">Không có yêu cầu nào ở trạng thái này.</div>
<?php else: ?>
<div class="table-wrap"><div class="table-responsive"><table class="table table-striped mb-0">
  <thead><tr><th>Độc giả</th><th>Sách</th><th>Ngày đặt</th><th>Trạng thái</th><th class="text-end">Thao tác</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><div class="fw-semibold"><?= e($r['full_name']) ?></div><div class="small text-muted"><?= e($r['card_no'] ?? 'Chưa có thẻ') ?></div></td>
    <td><?= e($r['title']) ?><?= $r['barcode'] ? '<div class="small text-muted">Bản giữ: ' . e($r['barcode']) . '</div>' : '' ?></td>
    <td><?= dmyhm($r['created_at']) ?></td>
    <td><?= badge('reservation', $r['status']) ?>
      <?php if ($r['status'] === 'APPROVED'): ?><div class="small text-muted">Giữ đến <?= dmyhm($r['hold_expire_at']) ?></div><?php endif; ?>
      <?php if ($r['reject_reason']): ?><div class="small text-muted"><?= e($r['reject_reason']) ?></div><?php endif; ?>
      <?php if ($r['staff_name'] && $r['status'] !== 'PENDING'): ?><div class="small text-muted">Xử lý: <?= e($r['staff_name']) ?></div><?php endif; ?></td>
    <td class="text-end">
      <?php if ($r['status'] === 'PENDING'): $chk = reader_check((int)$r['reader_id']); ?>
        <?php if ($chk['problems']): ?><div class="small text-danger mb-1 text-start" style="max-width:260px"><?= e(implode(' ', $chk['problems'])) ?></div><?php endif; ?>
        <div class="d-inline-flex gap-1 align-items-start">
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= (int)$r['reservation_id'] ?>">
            <button class="btn btn-sm btn-primary" type="submit" <?= $chk['problems'] ? 'disabled' : '' ?>>Duyệt</button></form>
          <form method="post" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?= (int)$r['reservation_id'] ?>">
            <input class="form-control form-control-sm" style="width:150px" name="reason" placeholder="Lý do từ chối" aria-label="Lý do từ chối">
            <button class="btn btn-sm btn-outline-danger" type="submit">Từ chối</button></form>
        </div>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?></tbody></table></div></div>
<?php endif; ?>
<?php page_end();
