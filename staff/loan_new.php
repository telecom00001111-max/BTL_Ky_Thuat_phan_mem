<?php
require __DIR__ . '/../includes/init.php';
$u = require_staff();
$cart = &$_SESSION['loan_cart'];   // ['reader_id'=>int, 'barcodes'=>[barcode=>title]]

if (is_post()) {
    $act = p('action');
    try {
        if ($act === 'find') {
            $no = p('card_no');
            $card = fetch_one("SELECT * FROM library_cards WHERE card_no = ?", [$no]);
            if (!$card) throw new RuntimeException("Không tìm thấy thẻ có số $no.");
            $cart = ['reader_id' => (int)$card['reader_id'], 'barcodes' => []];
        } elseif ($act === 'reset') {
            $cart = null;
        } elseif ($act === 'add' && $cart) {
            $bc = p('barcode');
            if ($bc === '') throw new RuntimeException('Hãy quét hoặc nhập mã vạch bản sách.');
            if (isset($cart['barcodes'][$bc])) throw new RuntimeException('Bản sách này đã có trong phiếu.');
            $pol = policy();
            if (borrowing_count($cart['reader_id']) + count($cart['barcodes']) >= (int)$pol['max_books_per_loan'])
                throw new RuntimeException('Độc giả đã đạt số sách tối đa được mượn (' . $pol['max_books_per_loan'] . ' cuốn).');
            [$copy, $res] = validate_copy_for_loan($bc, $cart['reader_id']);
            $cart['barcodes'][$bc] = $copy['title_name'] . ($res ? ' (đã đặt giữ)' : '');
        } elseif ($act === 'remove' && $cart) {
            unset($cart['barcodes'][p('barcode')]);
        } elseif ($act === 'confirm' && $cart) {
            $loanId = create_loan($cart['reader_id'], (int)$u['user_id'], array_keys($cart['barcodes']), p('note'));
            $cart = null;
            flash('success', 'Đã lập phiếu mượn #' . $loanId . '.');
            redirect('staff/loan_new.php?done=' . $loanId);
        }
    } catch (RuntimeException $ex) { flash('danger', $ex->getMessage()); }
    redirect('staff/loan_new.php');
}

$done = (int)g('done');
$chk = $cart ? reader_check((int)$cart['reader_id']) : null;
$pol = policy();
page_start('Lập phiếu mượn');
page_head('Lập phiếu mượn tại quầy', 'Bước 1: quét thẻ · Bước 2: quét từng cuốn sách · Bước 3: xác nhận');

if ($done): $loan = fetch_one("SELECT l.*, u.full_name FROM loans l JOIN users u ON u.user_id = l.reader_id WHERE l.loan_id = ?", [$done]); ?>
  <div class="alert alert-success">Phiếu mượn #<?= $done ?> của <strong><?= e($loan['full_name'] ?? '') ?></strong> đã được ghi nhận.</div>
  <a class="btn btn-primary" href="<?= e(url('staff/loan_print.php?id=' . $done)) ?>" target="_blank">In phiếu mượn</a>
  <a class="btn btn-outline-primary" href="<?= e(url('staff/loan_new.php')) ?>">Lập phiếu khác</a>
<?php elseif (!$cart): ?>
  <form method="post" class="card" style="max-width:520px"><?= csrf_field() ?><input type="hidden" name="action" value="find">
    <div class="card-body"><label class="form-label" for="card_no">Số thẻ thư viện</label>
      <div class="input-group"><input class="form-control scan-input" id="card_no" name="card_no" placeholder="VD: TV2026-0001" required data-autofocus autocomplete="off">
        <button class="btn btn-primary" type="submit">Kiểm tra thẻ</button></div></div></form>
<?php else:
    $r = $chk['user']; $card = $chk['card']; $borrowed = borrowing_count((int)$cart['reader_id']); ?>
  <div class="card mb-3"><div class="card-body d-flex flex-wrap justify-content-between gap-2">
    <div><div class="h5 mb-0"><?= e($r['full_name'] ?? '?') ?></div>
      <div class="text-muted small"><?= e($r['username'] ?? '') ?> · Đang mượn <?= $borrowed ?>/<?= (int)$pol['max_books_per_loan'] ?> cuốn<?= $card ? ' · Thẻ ' . e($card['card_no']) . ' hạn ' . dmy($card['expiry_date']) : '' ?></div></div>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reset"><button class="btn btn-sm btn-outline-primary" type="submit">Chọn độc giả khác</button></form>
  </div></div>
  <?php if ($chk['problems']): ?>
    <div class="alert alert-danger"><strong>Không thể lập phiếu mượn:</strong><ul class="mb-0"><?php foreach ($chk['problems'] as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul></div>
    <p class="text-muted">Hãy xử lý vi phạm (thu phạt, nhận sách quá hạn, gia hạn thẻ...) rồi kiểm tra lại.</p>
  <?php else: ?>
    <form method="post" class="row g-2 mb-3"><?= csrf_field() ?><input type="hidden" name="action" value="add">
      <div class="col-md-6"><label class="form-label" for="barcode">Mã vạch bản sách</label>
        <div class="input-group"><input class="form-control scan-input" id="barcode" name="barcode" placeholder="VD: BK0009-01" autocomplete="off" data-autofocus>
          <button class="btn btn-primary" type="submit">Thêm vào phiếu</button></div></div>
    </form>
    <h2 class="h5">Sách trong phiếu (<?= count($cart['barcodes']) ?>)</h2>
    <?php if (!$cart['barcodes']): ?><div class="alert alert-light border">Chưa có cuốn nào. Quét mã vạch trên sách để thêm.</div>
    <?php else: ?>
    <div class="table-wrap mb-3"><div class="table-responsive"><table class="table mb-0">
      <thead><tr><th>Mã vạch</th><th>Sách</th><th>Hạn trả dự kiến</th><th></th></tr></thead><tbody>
      <?php foreach ($cart['barcodes'] as $bc => $name): ?>
      <tr><td class="fw-semibold"><?= e($bc) ?></td><td><?= e($name) ?></td><td><?= date('d/m/Y', strtotime('+' . (int)$pol['loan_days'] . ' days')) ?></td>
        <td class="text-end"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="barcode" value="<?= e($bc) ?>"><button class="btn btn-sm btn-outline-danger" type="submit">Bỏ</button></form></td></tr>
      <?php endforeach; ?></tbody></table></div></div>
    <form method="post" class="row g-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="action" value="confirm">
      <div class="col-md-6"><label class="form-label" for="note">Ghi chú (không bắt buộc)</label><input class="form-control" id="note" name="note" maxlength="255"></div>
      <div class="col-auto"><button class="btn btn-accent btn-lg" type="submit">Xác nhận lập phiếu mượn</button></div>
    </form>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>
<?php page_end();
