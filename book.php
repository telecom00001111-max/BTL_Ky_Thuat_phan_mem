<?php
require __DIR__ . '/includes/init.php';
$u = user(); $staff = is_staff($u);
$id = (int)g('id', p('id'));
$b = fetch_one("SELECT t.*, c.name AS category_name, p.name AS publisher_name,
    (SELECT GROUP_CONCAT(a.name ORDER BY a.name SEPARATOR ', ') FROM title_authors ta JOIN authors a ON a.author_id = ta.author_id WHERE ta.title_id = t.title_id) AS authors
    FROM book_titles t LEFT JOIN categories c ON c.category_id = t.category_id LEFT JOIN publishers p ON p.publisher_id = t.publisher_id WHERE t.title_id = ?", [$id]);
if (!$b || (!$staff && $b['status'] !== 'ACTIVE')) {
    http_response_code(404);
    page_start('Không tìm thấy sách'); echo '<div class="alert alert-warning">Không tìm thấy đầu sách này. <a href="' . e(url('index.php')) . '">Về trang tra cứu</a></div>'; page_end(); exit;
}

if (is_post() && p('action') === 'reserve') {
    $me = require_login(['READER']);
    try {
        request_reservation((int)$me['user_id'], $id);
        flash('success', 'Đã gửi yêu cầu đặt giữ. Thủ thư sẽ duyệt và giữ sách cho bạn trong ' . policy()['hold_days'] . ' ngày.');
        redirect('reader/reservations.php');
    } catch (RuntimeException $ex) { flash('danger', $ex->getMessage()); redirect('book.php?id=' . $id); }
}

$copies = fetch_all("SELECT c.*, l.warehouse, l.shelf, l.slot FROM book_copies c LEFT JOIN storage_locations l ON l.location_id = c.location_id
                     WHERE c.title_id = ? AND (c.status <> 'INACTIVE' OR ?) ORDER BY c.barcode", [$id, $staff ? 1 : 0]);
$avail = 0; $total = 0;
foreach ($copies as $c) { if ($c['status'] !== 'INACTIVE') $total++; if ($c['status'] === 'AVAILABLE') $avail++; }
$free = title_free_copies($id);
$chk = ($u && $u['role_code'] === 'READER') ? reader_check((int)$u['user_id']) : null;
$mine = $u ? fetch_one("SELECT * FROM reservations WHERE reader_id = ? AND title_id = ? AND status IN ('PENDING','APPROVED')", [$u['user_id'], $id]) : null;

page_start($b['title']);
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb mb-0"><li class="breadcrumb-item"><a href="<?= e(url('index.php')) ?>">Tra cứu sách</a></li><li class="breadcrumb-item active" aria-current="page"><?= e($b['title']) ?></li></ol></nav>
<div class="row g-4">
  <div class="col-md-4 col-lg-3"><?= cover_html($b, 'cover-lg') ?></div>
  <div class="col-md-8 col-lg-9 min-w-0">
    <h1 class="mb-1"><?= e($b['title']) ?></h1>
    <p class="lead mb-3"><?= e($b['authors'] ?: 'Chưa rõ tác giả') ?></p>
    <dl class="row mb-3">
      <dt class="col-sm-3">ISBN</dt><dd class="col-sm-9"><?= e($b['isbn']) ?></dd>
      <dt class="col-sm-3">Thể loại</dt><dd class="col-sm-9"><?= e($b['category_name'] ?? '—') ?></dd>
      <dt class="col-sm-3">Nhà xuất bản</dt><dd class="col-sm-9"><?= e($b['publisher_name'] ?? '—') ?><?= $b['publish_year'] ? ', ' . (int)$b['publish_year'] : '' ?></dd>
      <dt class="col-sm-3">Tình trạng</dt><dd class="col-sm-9"><?= $b['status'] !== 'ACTIVE' ? badge('title', $b['status']) : ($avail > 0 ? '<span class="badge text-bg-success">Còn ' . $avail . '/' . $total . ' bản trên kệ</span>' : '<span class="badge text-bg-secondary">Hiện đã được mượn hết</span>') ?></dd>
    </dl>
    <?php if ($b['description']): ?><p style="max-width:70ch"><?= nl2br(e($b['description'])) ?></p><?php endif; ?>

    <?php if ($b['status'] === 'ACTIVE'): ?>
    <div class="card mt-3"><div class="card-body">
      <h2 class="h5 mb-2">Đặt giữ sách</h2>
      <?php if (!$u): ?>
        <p class="mb-2">Đăng nhập bằng tài khoản độc giả để đặt giữ sách.</p>
        <a class="btn btn-primary" href="<?= e(url('login.php?next=' . urlencode($_SERVER['REQUEST_URI']))) ?>">Đăng nhập</a>
      <?php elseif ($u['role_code'] !== 'READER'): ?>
        <p class="mb-0 text-muted">Đặt giữ dành cho tài khoản độc giả. Nhân viên lập phiếu mượn tại mục Mượn - Trả.</p>
      <?php elseif ($mine): ?>
        <p class="mb-0">Bạn đã có yêu cầu cho đầu sách này: <?= badge('reservation', $mine['status']) ?>
          <?php if ($mine['status'] === 'APPROVED'): ?> Hãy đến quầy nhận sách trước <strong><?= dmyhm($mine['hold_expire_at']) ?></strong>.<?php endif; ?></p>
      <?php elseif ($chk['problems']): ?>
        <div class="alert alert-warning mb-0"><strong>Bạn chưa thể đặt giữ:</strong><ul class="mb-0"><?php foreach ($chk['problems'] as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul></div>
      <?php elseif ($free <= 0): ?>
        <p class="mb-0 text-muted">Tất cả bản sách đang được mượn hoặc giữ. Bạn chỉ đặt giữ được khi còn sách trong kho, hãy quay lại sau.</p>
      <?php else: ?>
        <p class="mb-2">Sách đang còn trên kệ. Sau khi thủ thư duyệt, sách được giữ cho bạn <?= (int)policy()['hold_days'] ?> ngày.</p>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reserve"><input type="hidden" name="id" value="<?= $id ?>">
          <button class="btn btn-accent" type="submit">Đặt giữ sách này</button></form>
      <?php endif; ?>
    </div></div>
    <?php endif; ?>
  </div>
</div>

<h2 class="mt-5 mb-3">Các bản sách</h2>
<div class="table-wrap"><div class="table-responsive"><table class="table table-striped mb-0">
  <thead><tr><?php if ($staff): ?><th>Mã vạch</th><?php else: ?><th>Bản</th><?php endif; ?><th>Vị trí</th><th>Tình trạng</th><?php if ($staff): ?><th class="text-end">Giá</th><?php endif; ?></tr></thead>
  <tbody>
  <?php if (!$copies): ?><tr><td colspan="4" class="text-muted">Chưa có bản sách nào.</td></tr><?php endif; ?>
  <?php foreach ($copies as $i => $c): ?>
    <tr><td><?= $staff ? e($c['barcode']) : 'Bản ' . ($i + 1) ?></td>
      <td><?= $c['warehouse'] ? e($c['warehouse'] . ' · Kệ ' . $c['shelf'] . ($c['slot'] !== '' ? ' · Ô ' . $c['slot'] : '')) : '—' ?></td>
      <td><?= badge('copy', $c['status']) ?></td>
      <?php if ($staff): ?><td class="text-end"><?= money($c['price']) ?></td><?php endif; ?></tr>
  <?php endforeach; ?>
  </tbody></table></div></div>
<?php page_end();
