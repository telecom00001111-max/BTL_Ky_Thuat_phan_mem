<?php
require __DIR__ . '/../includes/init.php';
$u = require_login(['READER']);

if (is_post() && p('action') === 'cancel') {
    try { cancel_reservation((int)p('id'), (int)$u['user_id']); flash('success', 'Đã hủy yêu cầu đặt giữ.'); }
    catch (RuntimeException $ex) { flash('danger', $ex->getMessage()); }
    redirect('reader/reservations.php');
}
$rows = fetch_all("SELECT r.*, t.title, t.title_id, t.cover_image, c.barcode,
                          (SELECT GROUP_CONCAT(a.name SEPARATOR ', ') FROM title_authors ta JOIN authors a ON a.author_id = ta.author_id WHERE ta.title_id = t.title_id) AS authors
                   FROM reservations r JOIN book_titles t ON t.title_id = r.title_id LEFT JOIN book_copies c ON c.copy_id = r.copy_id
                   WHERE r.reader_id = ? ORDER BY r.created_at DESC", [$u['user_id']]);
page_start('Đặt giữ của tôi');
page_head('Đặt giữ của tôi', 'Sách được giữ ' . (int)policy()['hold_days'] . ' ngày kể từ khi thủ thư duyệt. Quá hạn mà chưa đến lấy, yêu cầu sẽ tự hủy.', '<a class="btn btn-accent" href="' . e(url('index.php')) . '">Tìm sách để đặt</a>');
?>
<?php if (!$rows): ?>
  <div class="alert alert-light border">Bạn chưa đặt giữ cuốn nào. Vào <a href="<?= e(url('index.php')) ?>">Tra cứu sách</a>, chọn sách còn trên kệ rồi bấm “Đặt giữ sách này”.</div>
<?php else: ?>
<div class="table-wrap"><div class="table-responsive"><table class="table table-striped mb-0">
  <thead><tr><th>Sách</th><th>Ngày đặt</th><th>Trạng thái</th><th>Ghi chú</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><a class="fw-semibold" href="<?= e(url('book.php?id=' . $r['title_id'])) ?>"><?= e($r['title']) ?></a><div class="small text-muted"><?= e($r['authors']) ?></div></td>
    <td><?= dmyhm($r['created_at']) ?></td>
    <td><?= badge('reservation', $r['status']) ?></td>
    <td class="small">
      <?php if ($r['status'] === 'APPROVED'): ?>Đến quầy nhận sách trước <strong><?= dmyhm($r['hold_expire_at']) ?></strong>
      <?php elseif ($r['status'] === 'REJECTED' && $r['reject_reason']): ?>Lý do: <?= e($r['reject_reason']) ?>
      <?php elseif ($r['status'] === 'EXPIRED'): ?>Quá hạn đến lấy sách, hệ thống đã hủy giữ.
      <?php elseif ($r['status'] === 'PENDING'): ?>Đang chờ thủ thư duyệt.<?php endif; ?>
    </td>
    <td class="text-end">
      <?php if (in_array($r['status'], ['PENDING', 'APPROVED'], true)): ?>
      <form method="post" data-confirm="Hủy yêu cầu đặt giữ này?"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$r['reservation_id'] ?>">
        <button class="btn btn-sm btn-outline-danger" type="submit">Hủy</button></form>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
<?php page_end();
