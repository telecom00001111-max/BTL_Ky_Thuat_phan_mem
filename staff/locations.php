<?php
require __DIR__ . '/../includes/init.php';
$u = require_staff();

if (is_post()) {
    $act = p('action'); $id = (int)p('location_id');
    try {
        if ($act === 'save') {
            $wh = p('warehouse'); $shelf = p('shelf'); $slot = p('slot');
            if ($wh === '' || $shelf === '') throw new RuntimeException('Kho và kệ không được để trống.');
            if (fetch_val("SELECT 1 FROM storage_locations WHERE warehouse = ? AND shelf = ? AND slot = ? AND location_id <> ?", [$wh, $shelf, $slot, $id]))
                throw new RuntimeException('Vị trí này đã tồn tại.');
            if ($id) { run("UPDATE storage_locations SET warehouse=?, shelf=?, slot=? WHERE location_id=?", [$wh, $shelf, $slot, $id]); flash('success', 'Đã cập nhật vị trí.'); }
            else { run("INSERT INTO storage_locations (warehouse, shelf, slot) VALUES (?,?,?)", [$wh, $shelf, $slot]); flash('success', 'Đã thêm vị trí mới.'); }
            audit($id ? 'UPDATE_LOCATION' : 'CREATE_LOCATION', 'location', $id ?: (int)db()->lastInsertId());
        } elseif ($act === 'delete') {
            if (fetch_val("SELECT 1 FROM book_copies WHERE location_id = ? LIMIT 1", [$id]))
                throw new RuntimeException('Vị trí này đang chứa bản sách. Hãy chuyển sách sang vị trí khác trước khi xóa.');
            run("DELETE FROM storage_locations WHERE location_id = ?", [$id]);
            audit('DELETE_LOCATION', 'location', $id);
            flash('success', 'Đã xóa vị trí trống.');
        }
    } catch (RuntimeException $ex) { flash('danger', $ex->getMessage()); }
    redirect('staff/locations.php');
}
$edit = (int)g('edit');
$cur = $edit ? fetch_one("SELECT * FROM storage_locations WHERE location_id = ?", [$edit]) : null;
$rows = fetch_all("SELECT l.*, (SELECT COUNT(*) FROM book_copies c WHERE c.location_id = l.location_id) AS copies FROM storage_locations l ORDER BY l.warehouse, l.shelf, l.slot");
$whs = array_unique(array_column($rows, 'warehouse'));

page_start('Kho / kệ / vị trí');
page_head('Kho, kệ và vị trí lưu trữ', 'Phân loại nơi để từng bản sách (FR-06)');
?>
<div class="row g-4">
  <div class="col-lg-4">
    <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="location_id" value="<?= (int)($cur['location_id'] ?? 0) ?>">
      <div class="card-header"><?= $cur ? 'Sửa vị trí' : 'Thêm vị trí' ?></div>
      <div class="card-body">
        <div class="mb-3"><label class="form-label" for="warehouse">Kho</label><input class="form-control" id="warehouse" name="warehouse" list="whs" value="<?= e($cur['warehouse'] ?? '') ?>" required><datalist id="whs"><?php foreach ($whs as $w): ?><option value="<?= e($w) ?>"><?php endforeach; ?></datalist></div>
        <div class="mb-3"><label class="form-label" for="shelf">Kệ</label><input class="form-control" id="shelf" name="shelf" value="<?= e($cur['shelf'] ?? '') ?>" required></div>
        <div class="mb-3"><label class="form-label" for="slot">Ô / tầng</label><input class="form-control" id="slot" name="slot" value="<?= e($cur['slot'] ?? '') ?>"></div>
        <button class="btn btn-primary" type="submit">Lưu</button>
        <?php if ($cur): ?><a class="btn btn-outline-primary" href="<?= e(url('staff/locations.php')) ?>">Hủy</a><?php endif; ?>
      </div></form>
  </div>
  <div class="col-lg-8">
    <div class="table-wrap"><div class="table-responsive"><table class="table table-striped mb-0">
      <thead><tr><th>Kho</th><th>Kệ</th><th>Ô</th><th class="text-center">Số bản</th><th class="text-end">Thao tác</th></tr></thead><tbody>
      <?php foreach ($rows as $r): ?>
      <tr><td><?= e($r['warehouse']) ?></td><td><?= e($r['shelf']) ?></td><td><?= e($r['slot'] ?: '—') ?></td>
        <td class="text-center"><?= (int)$r['copies'] ?></td>
        <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('staff/locations.php?edit=' . $r['location_id'])) ?>">Sửa</a>
          <?php if ((int)$r['copies'] === 0): ?><form method="post" class="d-inline" data-confirm="Xóa vị trí trống này?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="location_id" value="<?= (int)$r['location_id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit">Xóa</button></form><?php endif; ?></td></tr>
      <?php endforeach; ?></tbody></table></div></div>
    <p class="small text-muted mt-2">Chỉ xóa được vị trí chưa chứa bản sách nào.</p>
  </div>
</div>
<?php page_end();
