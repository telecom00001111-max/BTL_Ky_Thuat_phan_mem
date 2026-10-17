<?php
require __DIR__ . '/../includes/init.php';
$u = require_staff();
$levels = cfg('damage_levels');

if (is_post()) {
    $act = p('action');
    try {
        if ($act === 'find') {
            $it = find_open_item(p('barcode'));
            if (!$it) throw new RuntimeException('Không có phiếu mượn đang mở cho mã vạch "' . p('barcode') . '". Bản sách này không ở trạng thái đang mượn.');
            redirect('staff/return.php?item=' . $it['loan_item_id']);
        } elseif ($act === 'confirm') {
            $level = p('level') !== '' ? (int)p('level') : null;
            $res = process_return((int)p('item'), p('cond'), $level, (int)$u['user_id']);
            flash('success', 'Đã ghi nhận trả sách.');
            redirect('staff/return.php?done=' . $res['return_id'] . ($res['fine_id'] ? '&fine=' . $res['fine_id'] : ''));
        } elseif ($act === 'pay') {
            $pid = record_payment((int)p('fine_id'), p('method'), (int)$u['user_id']);
            flash('success', 'Đã thu tiền phạt.');
            redirect('staff/receipt.php?id=' . $pid);
        }
    } catch (RuntimeException $ex) {
        flash('danger', $ex->getMessage());
        redirect('staff/return.php' . (p('item') ? '?item=' . (int)p('item') : ''));
    }
}

$itemId = (int)g('item'); $done = (int)g('done'); $fineId = (int)g('fine');
$item = $itemId ? get_item($itemId) : null;
if ($item && $item['status'] !== 'BORROWING') { flash('warning', 'Sách này đã được trả trước đó.'); redirect('staff/return.php'); }
$cond = g('cond'); $level = g('level') !== '' ? (int)g('level') : null; $preview = null; $previewErr = '';
if ($item && in_array($cond, ['NORMAL', 'DAMAGED', 'LOST'], true)) {
    try { $preview = compute_fine($item, $cond, $level); } catch (RuntimeException $ex) { $previewErr = $ex->getMessage(); }
}

page_start('Lập phiếu trả');
page_head('Lập phiếu trả sách', 'Quét sách → chọn tình trạng → xem tiền phạt → xác nhận → thu tiền');

if ($done):
    $fine = $fineId ? fetch_one("SELECT f.*, t.title FROM fines f JOIN loan_items li ON li.loan_item_id = f.loan_item_id JOIN book_copies c ON c.copy_id = li.copy_id JOIN book_titles t ON t.title_id = c.title_id WHERE f.fine_id = ?", [$fineId]) : null; ?>
  <div class="alert alert-success">Phiếu trả #<?= $done ?> đã được ghi nhận.</div>
  <?php if ($fine): ?>
  <div class="card mb-3" style="max-width:560px"><div class="card-header">Tiền phạt cần thu</div><div class="card-body">
    <p class="mb-1"><?= e($fine['title']) ?> · <?= e($fine['reason']) ?></p>
    <p class="display-6 mb-3"><?= money($fine['total_amount']) ?></p>
    <?php if ($fine['status'] === 'UNPAID'): ?>
    <form method="post" class="row g-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="action" value="pay"><input type="hidden" name="fine_id" value="<?= (int)$fine['fine_id'] ?>">
      <div class="col-auto"><label class="form-label" for="method">Hình thức thu</label><select class="form-select" id="method" name="method"><option value="CASH">Tiền mặt</option><option value="TRANSFER">Chuyển khoản</option></select></div>
      <div class="col-auto"><button class="btn btn-accent" type="submit">Xác nhận đã thu tiền</button></div>
    </form>
    <p class="small text-muted mt-2 mb-0">Nếu độc giả chưa đóng, bạn có thể thu sau ở mục Tiền phạt. Độc giả sẽ bị chặn mượn mới cho đến khi đóng phạt.</p>
    <?php else: ?><?= badge('fine', 'PAID') ?><?php endif; ?>
  </div></div>
  <?php endif; ?>
  <a class="btn btn-primary" href="<?= e(url('staff/return.php')) ?>">Trả sách tiếp theo</a>

<?php elseif (!$item): ?>
  <form method="post" class="card" style="max-width:520px"><?= csrf_field() ?><input type="hidden" name="action" value="find">
    <div class="card-body"><label class="form-label" for="barcode">Mã vạch bản sách</label>
      <div class="input-group"><input class="form-control scan-input" id="barcode" name="barcode" placeholder="VD: BK0009-01" required data-autofocus autocomplete="off">
        <button class="btn btn-primary" type="submit">Tìm phiếu mượn</button></div></div></form>

<?php else:
    $late = max(0, days_between($item['due_date'], date('Y-m-d'))); ?>
  <div class="row g-4">
    <div class="col-lg-5">
      <div class="card"><div class="card-header">Thông tin mượn</div><div class="card-body">
        <h2 class="h5"><?= e($item['title_name']) ?></h2>
        <dl class="row mb-0 small">
          <dt class="col-5">Mã vạch</dt><dd class="col-7"><?= e($item['barcode']) ?></dd>
          <dt class="col-5">Độc giả</dt><dd class="col-7"><?= e($item['full_name']) ?> (<?= e($item['card_no']) ?>)</dd>
          <dt class="col-5">Ngày mượn</dt><dd class="col-7"><?= dmy($item['loan_date']) ?></dd>
          <dt class="col-5">Hạn trả</dt><dd class="col-7"><?= dmy($item['due_date']) ?> <?= $late > 0 ? '<span class="badge text-bg-danger">Quá ' . $late . ' ngày</span>' : '' ?></dd>
          <dt class="col-5">Gia hạn</dt><dd class="col-7"><?= (int)$item['renewal_count'] ?> lần</dd>
          <dt class="col-5">Giá sách</dt><dd class="col-7"><?= money($item['price']) ?></dd>
        </dl></div></div>
    </div>
    <div class="col-lg-7">
      <form method="get" class="card mb-3"><input type="hidden" name="item" value="<?= (int)$item['loan_item_id'] ?>">
        <div class="card-header">Tình trạng sách khi trả</div>
        <div class="card-body">
          <div class="form-check"><input class="form-check-input" type="radio" name="cond" id="c1" value="NORMAL" <?= ($cond ?: 'NORMAL') === 'NORMAL' ? 'checked' : '' ?>><label class="form-check-label" for="c1">Bình thường</label></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="cond" id="c2" value="DAMAGED" <?= $cond === 'DAMAGED' ? 'checked' : '' ?>><label class="form-check-label" for="c2">Hỏng</label>
            <select class="form-select form-select-sm d-inline-block w-auto ms-2" name="level" aria-label="Mức phạt hỏng"><option value="">Chọn mức phạt…</option>
              <?php foreach ($levels as $lv): ?><option value="<?= $lv ?>" <?= $level === $lv ? 'selected' : '' ?>>Phạt <?= $lv ?>% giá sách (<?= money($item['price'] * $lv / 100) ?>)</option><?php endforeach; ?></select></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="cond" id="c3" value="LOST" <?= $cond === 'LOST' ? 'checked' : '' ?>><label class="form-check-label" for="c3">Mất sách (phạt <?= (float)policy(date('Y-m-d', strtotime($item['loan_date'])))['lost_fine_rate'] * 100 ?>% giá sách)</label></div>
          <button class="btn btn-primary mt-3" type="submit">Tính tiền phạt</button>
        </div></form>
      <?php if ($previewErr): ?><div class="alert alert-warning"><?= e($previewErr) ?></div><?php endif; ?>
      <?php if ($preview): ?>
      <div class="card border-dark"><div class="card-header">Bảng tính tiền phạt</div><div class="card-body">
        <table class="table table-sm mb-3">
          <tr><td>Số ngày quá hạn tính phí</td><td class="text-end"><?= $preview['overdue_days'] ?> ngày × <?= money($preview['policy']['fine_per_day']) ?></td></tr>
          <tr><td>Phạt quá hạn</td><td class="text-end"><?= money($preview['overdue_amount']) ?></td></tr>
          <tr><td>Phạt <?= $cond === 'LOST' ? 'mất sách' : ($cond === 'DAMAGED' ? 'hỏng sách (' . (int)$preview['damage_level'] . '%)' : 'hỏng / mất') ?></td><td class="text-end"><?= money($preview['damage_amount']) ?></td></tr>
          <tr class="fw-bold"><td>Tổng tiền phạt</td><td class="text-end fs-5"><?= money($preview['total']) ?></td></tr>
        </table>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="confirm"><input type="hidden" name="item" value="<?= (int)$item['loan_item_id'] ?>">
          <input type="hidden" name="cond" value="<?= e($cond) ?>"><input type="hidden" name="level" value="<?= e((string)($cond === 'DAMAGED' ? $level : '')) ?>">
          <button class="btn btn-accent btn-lg" type="submit">Xác nhận lập phiếu trả</button>
          <a class="btn btn-outline-primary btn-lg" href="<?= e(url('staff/return.php')) ?>">Hủy</a></form>
      </div></div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
<?php page_end();
