<?php
require __DIR__ . '/../includes/init.php';
$u = require_staff();
$id = (int)g('id');
$loan = fetch_one("SELECT l.*, u.full_name, u.username, lc.card_no, s.full_name AS staff_name FROM loans l JOIN users u ON u.user_id = l.reader_id
    JOIN library_cards lc ON lc.card_id = l.card_id JOIN users s ON s.user_id = l.librarian_id WHERE l.loan_id = ?", [$id]);
if (!$loan) { http_response_code(404); page_start('Không tìm thấy'); echo '<div class="alert alert-warning">Không tìm thấy phiếu mượn.</div>'; page_end(); exit; }
$items = fetch_all("SELECT li.*, c.barcode, t.title FROM loan_items li JOIN book_copies c ON c.copy_id = li.copy_id JOIN book_titles t ON t.title_id = c.title_id WHERE li.loan_id = ? ORDER BY li.loan_item_id", [$id]);
page_start('Phiếu mượn #' . $id);
?>
<div class="mx-auto" style="max-width:720px">
  <div class="text-center mb-3"><h1 class="mb-0"><?= e(cfg('app.name')) ?></h1><div class="text-muted">PHIẾU MƯỢN SÁCH #<?= $id ?></div></div>
  <dl class="row">
    <dt class="col-4">Độc giả</dt><dd class="col-8"><?= e($loan['full_name']) ?> (<?= e($loan['username']) ?>)</dd>
    <dt class="col-4">Số thẻ</dt><dd class="col-8"><?= e($loan['card_no']) ?></dd>
    <dt class="col-4">Ngày mượn</dt><dd class="col-8"><?= dmyhm($loan['loan_date']) ?></dd>
    <dt class="col-4">Thủ thư</dt><dd class="col-8"><?= e($loan['staff_name']) ?></dd>
  </dl>
  <table class="table table-bordered"><thead><tr><th>STT</th><th>Mã vạch</th><th>Tên sách</th><th>Hạn trả</th></tr></thead><tbody>
    <?php foreach ($items as $i => $it): ?><tr><td><?= $i + 1 ?></td><td><?= e($it['barcode']) ?></td><td><?= e($it['title']) ?></td><td><strong><?= dmy($it['due_date']) ?></strong></td></tr><?php endforeach; ?>
  </tbody></table>
  <p class="small">Quá hạn trả sẽ bị phạt <?= money(policy(date('Y-m-d', strtotime($loan['loan_date'])))['fine_per_day']) ?>/ngày/cuốn. Làm hỏng hoặc mất sách phải đền theo quy định thư viện.</p>
  <div class="row text-center mt-4"><div class="col-6">Độc giả<br><br><br>(ký, ghi rõ họ tên)</div><div class="col-6">Thủ thư<br><br><br><?= e($loan['staff_name']) ?></div></div>
  <div class="text-center mt-4 no-print"><button class="btn btn-primary" onclick="window.print()">In phiếu</button></div>
</div>
<?php page_end();
