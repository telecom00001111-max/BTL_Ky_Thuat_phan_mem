<?php
require __DIR__ . '/../includes/init.php';
$u = require_staff();

$titleFilter = (int)g('title_id', p('title_id_back'));
if (is_post()) {
    $act = p('action');
    try {
        if ($act === 'add') {
            $tid = (int)p('title_id'); $qty = max(1, min(50, (int)p('qty', '1')));
            $price = (float)str_replace([',', '.'], '', p('price', '0')); $loc = (int)p('location_id') ?: null;
            $acq = p('acquired_date') ?: date('Y-m-d');
            if (!fetch_val("SELECT 1 FROM book_titles WHERE title_id = ?", [$tid])) throw new RuntimeException('Hãy chọn đầu sách.');
            if ($price <= 0) throw new RuntimeException('Hãy nhập giá sách (dùng để tính phạt hỏng / mất).');
            $custom = p('barcode');
            if ($custom !== '' && $qty > 1) throw new RuntimeException('Chỉ nhập mã vạch tùy chọn khi thêm 1 bản.');
            tx(function () use ($tid, $qty, $price, $loc, $acq, $custom) {
                for ($i = 0; $i < $qty; $i++) {
                    if ($custom !== '') $bc = $custom;
                    else { $n = (int)fetch_val("SELECT COUNT(*) FROM book_copies WHERE title_id = ?", [$tid]) + 1;
                           do { $bc = sprintf('BK%04d-%02d', $tid, $n++); } while (fetch_val("SELECT 1 FROM book_copies WHERE barcode = ?", [$bc])); }
                    if (fetch_val("SELECT 1 FROM book_copies WHERE barcode = ?", [$bc])) throw new RuntimeException("Mã vạch $bc đã tồn tại.");
                    run("INSERT INTO book_copies (title_id, location_id, barcode, price, acquired_date) VALUES (?,?,?,?,?)", [$tid, $loc, $bc, $price, $acq]);
                    audit('CREATE_COPY', 'copy', (int)db()->lastInsertId(), $bc);
                }
            });
            flash('success', "Đã thêm $qty bản sách.");
        } elseif ($act === 'edit') {
            $cid = (int)p('copy_id');
            $c = fetch_one("SELECT * FROM book_copies WHERE copy_id = ?", [$cid]);
            if (!$c) throw new RuntimeException('Không tìm thấy bản sách.');
            $price = (float)str_replace([',', '.'], '', p('price', '0')); if ($price <= 0) throw new RuntimeException('Giá sách phải lớn hơn 0.');
            $new = p('status');
            if (in_array($c['status'], ['BORROWED', 'RESERVED'], true) && $new !== $c['status'])
                throw new RuntimeException('Bản sách đang ' . ($c['status'] === 'BORROWED' ? 'được mượn' : 'được giữ') . ', chỉ đổi trạng thái sau khi trả / hết giữ.');
            if (!in_array($new, ['AVAILABLE', 'DAMAGED', 'LOST', 'INACTIVE', $c['status']], true)) throw new RuntimeException('Trạng thái không hợp lệ.');
            run("UPDATE book_copies SET location_id = ?, price = ?, status = ?, condition_status = ? WHERE copy_id = ?",
                [(int)p('location_id') ?: null, $price, $new, $new === 'DAMAGED' ? 'DAMAGED' : ($new === 'AVAILABLE' ? 'NORMAL' : $c['condition_status']), $cid]);
            audit('UPDATE_COPY', 'copy', $cid, "status=$new");
            flash('success', 'Đã cập nhật bản sách ' . $c['barcode'] . '.');
        }
    } catch (RuntimeException $ex) { flash('danger', $ex->getMessage()); }
    redirect('staff/copies.php' . ($titleFilter ? '?title_id=' . $titleFilter : ''));
}

$kw = g('q'); $st = g('st'); $page = max(1, (int)g('page', '1')); $per = 20;
$where = '1=1'; $params = [];
if ($titleFilter) { $where .= " AND c.title_id = ?"; $params[] = $titleFilter; }
if ($kw !== '') { $where .= " AND (c.barcode LIKE ? OR t.title LIKE ?)"; $l = '%' . $kw . '%'; array_push($params, $l, $l); }
if ($st !== '' && isset(STATUS_LABELS['copy'][$st])) { $where .= " AND c.status = ?"; $params[] = $st; }
$total = (int)fetch_val("SELECT COUNT(*) FROM book_copies c JOIN book_titles t ON t.title_id = c.title_id WHERE $where", $params);
$off = ($page - 1) * $per;
$rows = fetch_all("SELECT c.*, t.title, l.warehouse, l.shelf, l.slot FROM book_copies c JOIN book_titles t ON t.title_id = c.title_id
    LEFT JOIN storage_locations l ON l.location_id = c.location_id WHERE $where ORDER BY t.title, c.barcode LIMIT $per OFFSET $off", $params);
$titles = fetch_all("SELECT title_id, title FROM book_titles ORDER BY title");
$locs = fetch_all("SELECT * FROM storage_locations ORDER BY warehouse, shelf, slot");
$locLabel = fn($l) => $l['warehouse'] . ' · Kệ ' . $l['shelf'] . ($l['slot'] !== '' ? ' · Ô ' . $l['slot'] : '');
$filterTitle = $titleFilter ? fetch_val("SELECT title FROM book_titles WHERE title_id = ?", [$titleFilter]) : null;

page_start('Bản sách');
page_head('Bản sách vật lý', $filterTitle ? 'Đầu sách: ' . $filterTitle : 'Mỗi bản có mã vạch riêng, vị trí kho / kệ riêng',
  '<button class="btn btn-accent" type="button" data-bs-toggle="collapse" data-bs-target="#addCopy">Thêm bản sách</button>');
?>
<div class="collapse <?= $titleFilter && !$total ? 'show' : '' ?> mb-4" id="addCopy"><form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="title_id_back" value="<?= $titleFilter ?>">
  <div class="card-header">Thêm bản sách mới</div>
  <div class="card-body row g-3">
    <div class="col-md-6"><label class="form-label" for="a_title">Đầu sách</label><select class="form-select" id="a_title" name="title_id" required><option value="">— Chọn đầu sách —</option>
      <?php foreach ($titles as $t): ?><option value="<?= (int)$t['title_id'] ?>" <?= $titleFilter === (int)$t['title_id'] ? 'selected' : '' ?>><?= e($t['title']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label" for="a_qty">Số bản</label><input class="form-control" type="number" id="a_qty" name="qty" value="1" min="1" max="50"><div class="form-text">Mã vạch tự sinh dạng BK0001-02.</div></div>
    <div class="col-md-3"><label class="form-label" for="a_price">Giá sách (đ)</label><input class="form-control" id="a_price" name="price" inputmode="numeric" required></div>
    <div class="col-md-5"><label class="form-label" for="a_loc">Vị trí</label><select class="form-select" id="a_loc" name="location_id"><option value="">— Chưa xếp vị trí —</option>
      <?php foreach ($locs as $l): ?><option value="<?= (int)$l['location_id'] ?>"><?= e($locLabel($l)) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label" for="a_acq">Ngày nhập kho</label><input class="form-control" type="date" id="a_acq" name="acquired_date" value="<?= date('Y-m-d') ?>"></div>
    <div class="col-md-4"><label class="form-label" for="a_bc">Mã vạch tùy chọn</label><input class="form-control" id="a_bc" name="barcode" placeholder="Bỏ trống để tự sinh"></div>
    <div class="col-12"><button class="btn btn-primary" type="submit">Thêm bản sách</button></div>
  </div></form></div>

<form class="row g-2 mb-3" method="get" role="search">
  <?php if ($titleFilter): ?><input type="hidden" name="title_id" value="<?= $titleFilter ?>"><?php endif; ?>
  <div class="col-sm-6 col-md-5"><label class="visually-hidden" for="q">Tìm</label><input class="form-control" id="q" name="q" value="<?= e($kw) ?>" placeholder="Mã vạch hoặc tên sách"></div>
  <div class="col-sm-4 col-md-3"><label class="visually-hidden" for="st">Trạng thái</label><select class="form-select" id="st" name="st"><option value="">Mọi trạng thái</option>
    <?php foreach (STATUS_LABELS['copy'] as $k => $v): ?><option value="<?= $k ?>" <?= $st === $k ? 'selected' : '' ?>><?= e($v[0]) ?></option><?php endforeach; ?></select></div>
  <div class="col-auto"><button class="btn btn-primary" type="submit">Lọc</button> <a class="btn btn-outline-primary" href="<?= e(url('staff/copies.php')) ?>">Xóa lọc</a></div>
</form>
<div class="table-wrap"><div class="table-responsive"><table class="table table-striped mb-0">
  <thead><tr><th>Mã vạch</th><th>Sách</th><th>Vị trí</th><th class="text-end">Giá</th><th>Trạng thái</th><th class="text-end">Sửa</th></tr></thead><tbody>
  <?php if (!$rows): ?><tr><td colspan="6" class="text-muted">Chưa có bản sách nào.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): $locked = in_array($r['status'], ['BORROWED', 'RESERVED'], true); ?>
  <tr>
    <td class="fw-semibold"><?= e($r['barcode']) ?></td>
    <td><?= e($r['title']) ?></td>
    <td class="small"><?= $r['warehouse'] ? e($locLabel($r)) : '—' ?></td>
    <td class="text-end"><?= money($r['price']) ?></td>
    <td><?= badge('copy', $r['status']) ?></td>
    <td class="text-end"><button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#e<?= (int)$r['copy_id'] ?>">Sửa</button></td>
  </tr>
  <tr class="collapse" id="e<?= (int)$r['copy_id'] ?>"><td colspan="6" class="bg-light">
    <form method="post" class="row g-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="copy_id" value="<?= (int)$r['copy_id'] ?>"><input type="hidden" name="title_id_back" value="<?= $titleFilter ?>">
      <div class="col-md-4"><label class="form-label small">Vị trí</label><select class="form-select form-select-sm" name="location_id"><option value="">— Chưa xếp —</option>
        <?php foreach ($locs as $l): ?><option value="<?= (int)$l['location_id'] ?>" <?= (int)$r['location_id'] === (int)$l['location_id'] ? 'selected' : '' ?>><?= e($locLabel($l)) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-2"><label class="form-label small">Giá (đ)</label><input class="form-control form-control-sm" name="price" value="<?= (int)$r['price'] ?>" inputmode="numeric"></div>
      <div class="col-md-3"><label class="form-label small">Trạng thái</label><select class="form-select form-select-sm" name="status" <?= $locked ? 'disabled' : '' ?>>
        <?php foreach (($locked ? [$r['status']] : ['AVAILABLE', 'DAMAGED', 'LOST', 'INACTIVE']) as $k): ?><option value="<?= $k ?>" <?= $r['status'] === $k ? 'selected' : '' ?>><?= e(label('copy', $k)) ?></option><?php endforeach; ?></select>
        <?php if ($locked): ?><input type="hidden" name="status" value="<?= e($r['status']) ?>"><?php endif; ?></div>
      <div class="col-md-3"><button class="btn btn-sm btn-primary" type="submit">Lưu</button></div>
    </form></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<?= pager($total, $per, $page) ?>
<?php page_end();
