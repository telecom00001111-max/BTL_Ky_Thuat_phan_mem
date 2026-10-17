<?php
require __DIR__ . '/../includes/init.php';
$u = require_staff();

function find_or_create(string $table, string $idCol, string $name): int {
    $id = fetch_val("SELECT $idCol FROM $table WHERE name = ?", [$name]);
    if ($id) return (int)$id;
    run("INSERT INTO $table (name) VALUES (?)", [$name]);
    return (int)db()->lastInsertId();
}

if (is_post()) {
    $act = p('action'); $id = (int)p('title_id');
    try {
        if ($act === 'save') {
            $isbn = preg_replace('/[^0-9Xx-]/', '', p('isbn')); $title = p('title');
            $year = p('publish_year') !== '' ? (int)p('publish_year') : null;
            $err = [];
            if ($isbn === '') $err[] = 'Vui lòng nhập ISBN.';
            if ($title === '') $err[] = 'Vui lòng nhập tên sách.';
            if ($year !== null && ($year < 1000 || $year > (int)date('Y') + 1)) $err[] = 'Năm xuất bản không hợp lệ.';
            if (fetch_val("SELECT 1 FROM book_titles WHERE isbn = ? AND title_id <> ?", [$isbn, $id])) $err[] = 'ISBN đã tồn tại.';
            if ($err) throw new RuntimeException(implode(' ', $err));
            $cover = save_cover($_FILES['cover'] ?? []);
            $newId = tx(function () use ($id, $isbn, $title, $year, $cover) {
                $cat = (int)p('category_id') ?: null;
                if (p('new_category') !== '') $cat = find_or_create('categories', 'category_id', p('new_category'));
                $pub = p('publisher') !== '' ? find_or_create('publishers', 'publisher_id', p('publisher')) : null;
                if ($id) {
                    run("UPDATE book_titles SET isbn=?, title=?, category_id=?, publisher_id=?, publish_year=?, description=? WHERE title_id=?",
                        [$isbn, $title, $cat, $pub, $year, p('description') ?: null, $id]);
                    if ($cover) run("UPDATE book_titles SET cover_image = ? WHERE title_id = ?", [$cover, $id]);
                    $tid = $id;
                } else {
                    run("INSERT INTO book_titles (isbn, title, category_id, publisher_id, publish_year, description, cover_image) VALUES (?,?,?,?,?,?,?)",
                        [$isbn, $title, $cat, $pub, $year, p('description') ?: null, $cover]);
                    $tid = (int)db()->lastInsertId();
                }
                run("DELETE FROM title_authors WHERE title_id = ?", [$tid]);
                foreach (array_unique(array_filter(array_map('trim', explode(',', p('authors'))))) as $a)
                    run("INSERT IGNORE INTO title_authors (title_id, author_id) VALUES (?,?)", [$tid, find_or_create('authors', 'author_id', $a)]);
                return $tid;
            });
            audit($id ? 'UPDATE_TITLE' : 'CREATE_TITLE', 'title', $newId, $title);
            flash('success', $id ? 'Đã cập nhật đầu sách.' : 'Đã thêm đầu sách. Hãy thêm bản sách vật lý cho đầu sách này.');
            redirect($id ? 'staff/titles.php' : 'staff/copies.php?title_id=' . $newId);
        } elseif ($act === 'status') {
            $new = p('status') === 'DISCONTINUED' ? 'DISCONTINUED' : 'ACTIVE';
            run("UPDATE book_titles SET status = ? WHERE title_id = ?", [$new, $id]);
            audit($new === 'DISCONTINUED' ? 'DISCONTINUE_TITLE' : 'RESTORE_TITLE', 'title', $id);
            flash('success', $new === 'DISCONTINUED' ? 'Đã ngừng lưu hành đầu sách (dữ liệu giao dịch vẫn được giữ).' : 'Đã phục hồi đầu sách.');
        }
    } catch (RuntimeException $ex) { flash('danger', $ex->getMessage()); redirect('staff/titles.php' . ($id ? '?edit=' . $id : '?new=1')); }
    redirect('staff/titles.php');
}

$edit = (int)g('edit'); $isNew = g('new') === '1';
$cur = $edit ? fetch_one("SELECT t.*, p.name AS publisher_name, (SELECT GROUP_CONCAT(a.name ORDER BY a.name SEPARATOR ', ') FROM title_authors ta JOIN authors a ON a.author_id = ta.author_id WHERE ta.title_id = t.title_id) AS authors
                          FROM book_titles t LEFT JOIN publishers p ON p.publisher_id = t.publisher_id WHERE t.title_id = ?", [$edit]) : null;
$cats = fetch_all("SELECT * FROM categories WHERE status = 'ACTIVE' ORDER BY name");
$pubs = fetch_all("SELECT name FROM publishers ORDER BY name");

$kw = g('q'); $page = max(1, (int)g('page', '1')); $per = 15;
$where = '1=1'; $params = [];
if ($kw !== '') { $where = "(t.title LIKE ? OR t.isbn LIKE ? OR EXISTS (SELECT 1 FROM title_authors ta JOIN authors a ON a.author_id = ta.author_id WHERE ta.title_id = t.title_id AND a.name LIKE ?))"; $l = '%' . $kw . '%'; $params = [$l, $l, $l]; }
$total = (int)fetch_val("SELECT COUNT(*) FROM book_titles t WHERE $where", $params);
$off = ($page - 1) * $per;
$rows = fetch_all("SELECT t.*, c.name AS category_name,
    (SELECT GROUP_CONCAT(a.name ORDER BY a.name SEPARATOR ', ') FROM title_authors ta JOIN authors a ON a.author_id = ta.author_id WHERE ta.title_id = t.title_id) AS authors,
    (SELECT COUNT(*) FROM book_copies bc WHERE bc.title_id = t.title_id AND bc.status <> 'INACTIVE') AS copies
    FROM book_titles t LEFT JOIN categories c ON c.category_id = t.category_id WHERE $where ORDER BY t.title LIMIT $per OFFSET $off", $params);

page_start('Đầu sách');
page_head('Đầu sách', 'Thông tin chung của sách (mỗi đầu sách có nhiều bản vật lý). Không xóa, chỉ ngừng lưu hành.',
  '<a class="btn btn-accent" href="' . e(url('staff/titles.php?new=1')) . '">Thêm đầu sách</a>');
if ($isNew || $cur): $f = $cur ?: ['title_id' => 0, 'isbn' => '', 'title' => '', 'category_id' => null, 'publisher_name' => '', 'publish_year' => '', 'description' => '', 'authors' => '', 'cover_image' => null]; ?>
<form method="post" enctype="multipart/form-data" class="card mb-4"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="title_id" value="<?= (int)$f['title_id'] ?>">
  <div class="card-header"><?= $cur ? 'Sửa đầu sách' : 'Thêm đầu sách mới' ?></div>
  <div class="card-body row g-3">
    <div class="col-md-8"><label class="form-label" for="title">Tên sách</label><input class="form-control" id="title" name="title" value="<?= e($f['title']) ?>" required></div>
    <div class="col-md-4"><label class="form-label" for="isbn">ISBN</label><input class="form-control" id="isbn" name="isbn" value="<?= e($f['isbn']) ?>" required></div>
    <div class="col-md-8"><label class="form-label" for="authors">Tác giả</label><input class="form-control" id="authors" name="authors" value="<?= e($f['authors']) ?>"><div class="form-text">Nhiều tác giả: ngăn cách bằng dấu phẩy.</div></div>
    <div class="col-md-4"><label class="form-label" for="publish_year">Năm xuất bản</label><input class="form-control" type="number" id="publish_year" name="publish_year" value="<?= e($f['publish_year']) ?>"></div>
    <div class="col-md-4"><label class="form-label" for="category_id">Thể loại</label><select class="form-select" id="category_id" name="category_id"><option value="">— Chưa phân loại —</option>
      <?php foreach ($cats as $c): ?><option value="<?= (int)$c['category_id'] ?>" <?= (int)$f['category_id'] === (int)$c['category_id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label" for="new_category">Hoặc thể loại mới</label><input class="form-control" id="new_category" name="new_category" placeholder="Nhập để tạo thể loại mới"></div>
    <div class="col-md-4"><label class="form-label" for="publisher">Nhà xuất bản</label><input class="form-control" id="publisher" name="publisher" list="pubs" value="<?= e($f['publisher_name']) ?>"><datalist id="pubs"><?php foreach ($pubs as $pb): ?><option value="<?= e($pb['name']) ?>"><?php endforeach; ?></datalist></div>
    <div class="col-12"><label class="form-label" for="description">Mô tả</label><textarea class="form-control" id="description" name="description" rows="3"><?= e($f['description']) ?></textarea></div>
    <div class="col-md-6"><label class="form-label" for="cover">Ảnh bìa (JPG / PNG / WebP, tối đa 2MB)</label><input class="form-control" type="file" id="cover" name="cover" accept="image/jpeg,image/png,image/webp"><?php if ($f['cover_image']): ?><div class="form-text">Đã có ảnh bìa. Chọn file mới để thay.</div><?php endif; ?></div>
    <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit">Lưu đầu sách</button><a class="btn btn-outline-primary" href="<?= e(url('staff/titles.php')) ?>">Hủy</a></div>
  </div></form>
<?php endif; ?>

<form class="row g-2 mb-3" method="get" role="search">
  <div class="col-sm-8 col-md-6"><label class="visually-hidden" for="q">Tìm đầu sách</label><input class="form-control" id="q" name="q" value="<?= e($kw) ?>" placeholder="Tên sách, tác giả hoặc ISBN"></div>
  <div class="col-auto"><button class="btn btn-primary" type="submit">Tìm</button> <a class="btn btn-outline-primary" href="<?= e(url('staff/titles.php')) ?>">Xóa lọc</a></div>
</form>
<div class="table-wrap"><div class="table-responsive"><table class="table table-striped mb-0">
  <thead><tr><th style="width:56px"></th><th>Tên sách</th><th>ISBN</th><th>Thể loại</th><th class="text-center">Bản</th><th>Trạng thái</th><th class="text-end">Thao tác</th></tr></thead><tbody>
  <?php if (!$rows): ?><tr><td colspan="7" class="text-muted">Không có đầu sách nào.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><?= cover_html($r, 'cover-sm') ?></td>
    <td><a class="fw-semibold" href="<?= e(url('book.php?id=' . $r['title_id'])) ?>"><?= e($r['title']) ?></a><div class="small text-muted"><?= e($r['authors']) ?></div></td>
    <td class="small"><?= e($r['isbn']) ?></td><td><?= e($r['category_name'] ?? '—') ?></td>
    <td class="text-center"><a href="<?= e(url('staff/copies.php?title_id=' . $r['title_id'])) ?>"><?= (int)$r['copies'] ?></a></td>
    <td><?= badge('title', $r['status']) ?></td>
    <td class="text-end text-nowrap">
      <a class="btn btn-sm btn-outline-primary" href="<?= e(url('staff/titles.php?edit=' . $r['title_id'])) ?>">Sửa</a>
      <form method="post" class="d-inline" data-confirm="<?= $r['status'] === 'ACTIVE' ? 'Ngừng lưu hành đầu sách này? Độc giả sẽ không thấy sách nữa.' : 'Phục hồi đầu sách này?' ?>"><?= csrf_field() ?>
        <input type="hidden" name="action" value="status"><input type="hidden" name="title_id" value="<?= (int)$r['title_id'] ?>"><input type="hidden" name="status" value="<?= $r['status'] === 'ACTIVE' ? 'DISCONTINUED' : 'ACTIVE' ?>">
        <button class="btn btn-sm btn-outline-<?= $r['status'] === 'ACTIVE' ? 'danger' : 'success' ?>" type="submit"><?= $r['status'] === 'ACTIVE' ? 'Ngừng lưu hành' : 'Phục hồi' ?></button></form>
    </td>
  </tr>
  <?php endforeach; ?></tbody></table></div></div>
<?= pager($total, $per, $page) ?>
<?php page_end();
