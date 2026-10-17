<?php
require __DIR__ . '/includes/init.php';
$u = user();
$staff = is_staff($u);

$kw = g('q'); $isbn = g('isbn'); $cat = (int)g('cat'); $st = g('st');
$page = max(1, (int)g('page', '1')); $per = (int)cfg('per_page', 12);

$inner = "SELECT t.*, c.name AS category_name,
  (SELECT GROUP_CONCAT(a.name ORDER BY a.name SEPARATOR ', ') FROM title_authors ta JOIN authors a ON a.author_id = ta.author_id WHERE ta.title_id = t.title_id) AS authors,
  (SELECT COUNT(*) FROM book_copies bc WHERE bc.title_id = t.title_id AND bc.status <> 'INACTIVE') AS total_copies,
  (SELECT COUNT(*) FROM book_copies bc WHERE bc.title_id = t.title_id AND bc.status = 'AVAILABLE') AS avail_copies
  FROM book_titles t LEFT JOIN categories c ON c.category_id = t.category_id";
$where = []; $params = [];
if (!$staff) $where[] = "x.status = 'ACTIVE'";
if ($kw !== '') { $where[] = "(x.title LIKE ? OR x.authors LIKE ? OR x.isbn LIKE ?)"; $l = '%' . $kw . '%'; array_push($params, $l, $l, $l); }
if ($isbn !== '') { $where[] = "REPLACE(x.isbn,'-','') LIKE ?"; $params[] = '%' . str_replace('-', '', $isbn) . '%'; }
if ($cat > 0) { $where[] = "x.category_id = ?"; $params[] = $cat; }
if ($st === 'available') $where[] = "x.status = 'ACTIVE' AND x.avail_copies > 0";
elseif ($st === 'out') $where[] = "x.status = 'ACTIVE' AND x.avail_copies = 0";
elseif ($st === 'discontinued' && $staff) $where[] = "x.status = 'DISCONTINUED'";
$w = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$total = (int)fetch_val("SELECT COUNT(*) FROM ($inner) x $w", $params);
$off = ($page - 1) * $per;
$books = fetch_all("SELECT * FROM ($inner) x $w ORDER BY x.title LIMIT $per OFFSET $off", $params);
$cats = fetch_all("SELECT category_id, name FROM categories WHERE status = 'ACTIVE' ORDER BY name");

page_start('Tra cứu sách');
?>
<div class="row align-items-end g-4">
  <div class="col-lg-5">
    <h1 class="mb-2">Tìm sách trong kho của thư viện</h1>
    <p class="hero-copy mb-0">Tra theo tên sách, tác giả, ISBN hoặc thể loại. Sách còn trên kệ có thể đặt giữ trực tuyến, thủ thư sẽ giữ sẵn cho bạn.</p>
  </div>
  <div class="col-lg-7">
    <form method="get" class="catalog-card" role="search">
      <span class="catalog-tab">Tra cứu</span>
      <div class="row g-2">
        <div class="col-12"><label class="visually-hidden" for="q">Từ khóa</label>
          <input class="form-control form-control-lg" id="q" name="q" value="<?= e($kw) ?>" placeholder="Tên sách hoặc tác giả"></div>
        <div class="col-sm-6"><label class="form-label small mb-0" for="isbn">ISBN</label><input class="form-control" id="isbn" name="isbn" value="<?= e($isbn) ?>"></div>
        <div class="col-sm-6"><label class="form-label small mb-0" for="cat">Thể loại</label>
          <select class="form-select" id="cat" name="cat"><option value="0">Tất cả</option>
          <?php foreach ($cats as $c): ?><option value="<?= (int)$c['category_id'] ?>" <?= $cat === (int)$c['category_id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-sm-6"><label class="form-label small mb-0" for="st">Trạng thái</label>
          <select class="form-select" id="st" name="st">
            <option value="">Tất cả</option>
            <option value="available" <?= $st === 'available' ? 'selected' : '' ?>>Còn sách</option>
            <option value="out" <?= $st === 'out' ? 'selected' : '' ?>>Hết sách</option>
            <?php if ($staff): ?><option value="discontinued" <?= $st === 'discontinued' ? 'selected' : '' ?>>Ngừng lưu hành</option><?php endif; ?>
          </select></div>
        <div class="col-sm-6 d-flex align-items-end gap-2">
          <button class="btn btn-primary flex-fill" type="submit">Tìm sách</button>
          <a class="btn btn-outline-primary" href="<?= e(url('index.php')) ?>">Xóa lọc</a></div>
      </div>
    </form>
  </div>
</div>

<h2 class="mt-5 mb-3"><?= $total ?> đầu sách<?= ($kw !== '' || $isbn !== '' || $cat || $st) ? ' phù hợp' : '' ?></h2>
<?php if (!$books): ?>
  <div class="alert alert-light border">Không tìm thấy sách nào. Thử bỏ bớt bộ lọc hoặc đổi từ khóa.</div>
<?php else: ?>
<div class="row g-3">
<?php foreach ($books as $b): $avail = (int)$b['avail_copies']; ?>
  <div class="col-6 col-md-4 col-lg-3">
    <a class="book-card" href="<?= e(url('book.php?id=' . $b['title_id'])) ?>">
      <?= cover_html($b) ?>
      <div class="t"><?= e($b['title']) ?></div>
      <div class="a"><?= e($b['authors'] ?: 'Chưa rõ tác giả') ?></div>
      <div class="meta">
        <?php if ($b['status'] !== 'ACTIVE'): ?><?= badge('title', $b['status']) ?>
        <?php elseif ($avail > 0): ?><span class="badge text-bg-success">Còn <?= $avail ?>/<?= (int)$b['total_copies'] ?> bản</span>
        <?php else: ?><span class="badge text-bg-secondary">Hết sách</span><?php endif; ?>
        <span class="text-muted ms-1"><?= e($b['category_name'] ?? '') ?></span>
      </div>
    </a>
  </div>
<?php endforeach; ?>
</div>
<?= pager($total, $per, $page) ?>
<?php endif; ?>
<?php page_end();
