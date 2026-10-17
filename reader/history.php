<?php
require __DIR__ . '/../includes/init.php';
$u = require_login(['READER']);
$rid = (int)$u['user_id'];

if (is_post() && p('action') === 'renew') {
    [$ok, $msg] = renew_item((int)p('item'), $rid);
    flash($ok ? 'success' : 'warning', $msg);
    redirect('reader/history.php');
}
$pol = policy();
$card = latest_card($rid);
$borrowing = fetch_all("SELECT li.*, l.loan_date, c.barcode, t.title, t.title_id
    FROM loan_items li JOIN loans l ON l.loan_id = li.loan_id JOIN book_copies c ON c.copy_id = li.copy_id JOIN book_titles t ON t.title_id = c.title_id
    WHERE l.reader_id = ? AND li.status = 'BORROWING' ORDER BY li.due_date", [$rid]);
$history = fetch_all("SELECT li.*, l.loan_date, c.barcode, t.title, t.title_id
    FROM loan_items li JOIN loans l ON l.loan_id = li.loan_id JOIN book_copies c ON c.copy_id = li.copy_id JOIN book_titles t ON t.title_id = c.title_id
    WHERE l.reader_id = ? AND li.status <> 'BORROWING' ORDER BY li.return_date DESC", [$rid]);
$fines = fetch_all("SELECT f.*, t.title, p.paid_at FROM fines f JOIN loan_items li ON li.loan_item_id = f.loan_item_id
    JOIN book_copies c ON c.copy_id = li.copy_id JOIN book_titles t ON t.title_id = c.title_id
    LEFT JOIN payments p ON p.fine_id = f.fine_id WHERE f.reader_id = ? ORDER BY f.created_at DESC", [$rid]);
$unpaid = unpaid_total($rid);

page_start('Mượn - trả - phạt');
page_head('Mượn - trả - phạt', 'Lịch sử giao dịch của ' . $u['full_name']);
?>
<div class="row g-3 mb-4">
  <div class="col-sm-4"><div class="stat"><div class="n"><?= count($borrowing) ?>/<?= (int)$pol['max_books_per_loan'] ?></div><div class="l">Sách đang mượn / tối đa</div></div></div>
  <div class="col-sm-4"><div class="stat <?= $unpaid > 0 ? 'bad' : '' ?>"><div class="n"><?= money($unpaid) ?></div><div class="l">Tiền phạt chưa đóng</div></div></div>
  <div class="col-sm-4"><div class="stat warn"><div class="n" style="font-size:1.2rem"><?= $card ? e($card['card_no']) : 'Chưa có thẻ' ?></div>
    <div class="l"><?= $card ? badge('card', card_state($card)) . ' · hạn ' . dmy($card['expiry_date']) : 'Đến quầy để được cấp thẻ' ?></div></div></div>
</div>

<h2 class="mb-3">Sách đang mượn</h2>
<?php if (!$borrowing): ?><div class="alert alert-light border">Bạn không có sách nào đang mượn.</div>
<?php else: ?>
<div class="table-wrap mb-4"><div class="table-responsive"><table class="table table-striped mb-0">
  <thead><tr><th>Sách</th><th>Ngày mượn</th><th>Hạn trả</th><th>Gia hạn</th><th></th></tr></thead><tbody>
  <?php foreach ($borrowing as $b): $late = days_between($b['due_date'], date('Y-m-d')); $left = (int)$pol['max_renewals'] - (int)$b['renewal_count']; ?>
  <tr>
    <td><a class="fw-semibold" href="<?= e(url('book.php?id=' . $b['title_id'])) ?>"><?= e($b['title']) ?></a><div class="small text-muted"><?= e($b['barcode']) ?></div></td>
    <td><?= dmy($b['loan_date']) ?></td>
    <td><?= dmy($b['due_date']) ?>
      <?php if ($late > 0): ?><span class="badge text-bg-danger">Quá hạn <?= $late ?> ngày</span>
      <?php elseif ($late === 0): ?><span class="badge text-bg-warning">Hạn trả hôm nay</span>
      <?php elseif ($late >= -2): ?><span class="badge text-bg-warning">Sắp đến hạn</span><?php endif; ?></td>
    <td>Đã <?= (int)$b['renewal_count'] ?>/<?= (int)$pol['max_renewals'] ?> lần</td>
    <td class="text-end">
      <?php if ($left > 0 && $late <= 0): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="renew"><input type="hidden" name="item" value="<?= (int)$b['loan_item_id'] ?>">
        <button class="btn btn-sm btn-primary" type="submit">Gia hạn thêm <?= (int)$pol['renew_days'] ?> ngày</button></form>
      <?php elseif ($late > 0): ?><span class="small text-muted">Hãy trả sách tại quầy</span>
      <?php else: ?><span class="small text-muted">Hết lượt gia hạn</span><?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?></tbody></table></div></div>
<?php endif; ?>

<h2 class="mb-3">Tiền phạt</h2>
<?php if (!$fines): ?><div class="alert alert-light border">Bạn không có khoản phạt nào.</div>
<?php else: ?>
<div class="table-wrap mb-4"><div class="table-responsive"><table class="table table-striped mb-0">
  <thead><tr><th>Sách</th><th>Lý do</th><th class="text-end">Số tiền</th><th>Ngày lập</th><th>Trạng thái</th></tr></thead><tbody>
  <?php foreach ($fines as $f): ?>
  <tr><td><?= e($f['title']) ?></td>
    <td><?= e($f['reason']) ?><?= $f['overdue_days'] > 0 ? '<div class="small text-muted">Quá hạn ' . (int)$f['overdue_days'] . ' ngày</div>' : '' ?></td>
    <td class="text-end fw-semibold"><?= money($f['total_amount']) ?></td><td><?= dmy($f['created_at']) ?></td>
    <td><?= badge('fine', $f['status']) ?><?= $f['paid_at'] ? '<div class="small text-muted">' . dmy($f['paid_at']) . '</div>' : '' ?></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<?php if ($unpaid > 0): ?><p class="text-danger">Bạn cần đóng phạt tại quầy trước khi mượn hoặc đặt giữ sách mới.</p><?php endif; ?>
<?php endif; ?>

<h2 class="mb-3">Lịch sử đã trả</h2>
<?php if (!$history): ?><div class="alert alert-light border">Chưa có sách nào đã trả.</div>
<?php else: ?>
<div class="table-wrap"><div class="table-responsive"><table class="table table-striped mb-0">
  <thead><tr><th>Sách</th><th>Ngày mượn</th><th>Ngày trả</th><th>Tình trạng khi trả</th></tr></thead><tbody>
  <?php foreach ($history as $h): ?>
  <tr><td><a href="<?= e(url('book.php?id=' . $h['title_id'])) ?>"><?= e($h['title']) ?></a></td><td><?= dmy($h['loan_date']) ?></td><td><?= dmy($h['return_date']) ?></td>
    <td><?= $h['status'] === 'LOST' ? badge('item', 'LOST') : ($h['condition_on_return'] === 'DAMAGED' ? '<span class="badge text-bg-warning">Hỏng</span>' : '<span class="badge text-bg-success">Bình thường</span>') ?>
      <?php if ($h['renewal_count'] > 0): ?><span class="small text-muted ms-1">(gia hạn <?= (int)$h['renewal_count'] ?> lần)</span><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<?php endif; ?>
<?php page_end();
