<?php
require __DIR__ . '/../includes/init.php';
$u = require_staff();

$r = g('r', 'top'); if (!in_array($r, ['top', 'stale', 'violators'], true)) $r = 'top';
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', g('from')) ? g('from') : date('Y-m-d', strtotime('-90 days'));
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', g('to')) ? g('to') : date('Y-m-d');
$limit = in_array((int)g('limit', '10'), [10, 20, 50], true) ? (int)g('limit', '10') : 10;
$days = max(1, (int)g('days', '90'));

/** Mỗi báo cáo trả về [tiêu đề, mô tả, cột[], dòng[][]] */
function report_data(string $r, string $from, string $to, int $limit, int $days): array {
    if ($r === 'top') {
        $rows = fetch_all("SELECT t.title, 
              (SELECT GROUP_CONCAT(a.name ORDER BY a.name SEPARATOR ', ') FROM title_authors ta JOIN authors a ON a.author_id = ta.author_id WHERE ta.title_id = t.title_id) AS authors,
              COUNT(*) AS cnt, COUNT(DISTINCT l.reader_id) AS readers
            FROM loan_items li JOIN loans l ON l.loan_id = li.loan_id JOIN book_copies c ON c.copy_id = li.copy_id JOIN book_titles t ON t.title_id = c.title_id
            WHERE DATE(l.loan_date) BETWEEN ? AND ? GROUP BY t.title_id, t.title ORDER BY cnt DESC, t.title LIMIT $limit", [$from, $to]);
        $out = []; foreach ($rows as $i => $x) $out[] = [$i + 1, $x['title'], $x['authors'] ?: '', (int)$x['cnt'], (int)$x['readers']];
        return ['Sách được mượn nhiều nhất', 'Từ ' . dmy($from) . ' đến ' . dmy($to) . ' · Top ' . $limit, ['#', 'Tên sách', 'Tác giả', 'Số lượt mượn', 'Số độc giả khác nhau'], $out];
    }
    if ($r === 'stale') {
        $rows = fetch_all("SELECT t.title, c.name AS category_name,
              (SELECT COUNT(*) FROM book_copies bc WHERE bc.title_id = t.title_id AND bc.status NOT IN ('INACTIVE','LOST')) AS copies,
              (SELECT MAX(l.loan_date) FROM loan_items li JOIN loans l ON l.loan_id = li.loan_id JOIN book_copies bc ON bc.copy_id = li.copy_id WHERE bc.title_id = t.title_id) AS last_loan,
              (SELECT MIN(bc.acquired_date) FROM book_copies bc WHERE bc.title_id = t.title_id) AS first_acq
            FROM book_titles t LEFT JOIN categories c ON c.category_id = t.category_id WHERE t.status = 'ACTIVE'
            HAVING copies > 0", []);
        $out = [];
        foreach ($rows as $x) {
            $ref = $x['last_loan'] ?: $x['first_acq'];
            $idle = $ref ? days_between($ref, date('Y-m-d')) : 0;
            if ($idle >= $days) $out[] = [$x['title'], $x['category_name'] ?? '', (int)$x['copies'], $x['last_loan'] ? dmy($x['last_loan']) : 'Chưa từng được mượn', $idle];
        }
        usort($out, fn($a, $b) => $b[4] <=> $a[4]);
        foreach ($out as $i => &$o) array_unshift($o, $i + 1); unset($o);
        return ['Sách tồn kho lâu', 'Đầu sách không được mượn từ ' . $days . ' ngày trở lên (tính từ lần mượn gần nhất hoặc ngày nhập kho)', ['#', 'Tên sách', 'Thể loại', 'Số bản', 'Lần mượn gần nhất', 'Số ngày tồn'], $out];
    }
    $rows = fetch_all("SELECT u.full_name, u.username, u.email, u.phone,
          (SELECT card_no FROM library_cards lc WHERE lc.reader_id = u.user_id ORDER BY expiry_date DESC LIMIT 1) AS card_no,
          (SELECT COUNT(*) FROM loan_items li JOIN loans l ON l.loan_id = li.loan_id WHERE l.reader_id = u.user_id AND li.status = 'BORROWING' AND li.due_date < CURDATE()) AS overdue_cnt,
          (SELECT COALESCE(MAX(DATEDIFF(CURDATE(), li.due_date)), 0) FROM loan_items li JOIN loans l ON l.loan_id = li.loan_id WHERE l.reader_id = u.user_id AND li.status = 'BORROWING' AND li.due_date < CURDATE()) AS max_days,
          (SELECT COUNT(*) FROM fines f WHERE f.reader_id = u.user_id AND f.status = 'UNPAID') AS fine_cnt,
          (SELECT COALESCE(SUM(f.total_amount), 0) FROM fines f WHERE f.reader_id = u.user_id AND f.status = 'UNPAID') AS unpaid
        FROM users u JOIN roles ro ON ro.role_id = u.role_id WHERE ro.role_code = 'READER'
        HAVING overdue_cnt > 0 OR unpaid > 0 ORDER BY unpaid DESC, overdue_cnt DESC", []);
    $out = []; foreach ($rows as $i => $x) $out[] = [$i + 1, $x['full_name'], $x['card_no'] ?? '', trim($x['email'] . ' ' . ($x['phone'] ?? '')), (int)$x['overdue_cnt'], (int)$x['max_days'], (int)$x['fine_cnt'], (float)$x['unpaid']];
    return ['Độc giả vi phạm và nợ phạt', 'Độc giả có sách quá hạn hoặc còn tiền phạt chưa đóng, tính đến ' . date('d/m/Y'), ['#', 'Độc giả', 'Số thẻ', 'Liên hệ', 'Sách quá hạn', 'Quá hạn lâu nhất (ngày)', 'Số khoản phạt', 'Nợ phạt (đ)'], $out];
}
[$title, $desc, $cols, $data] = report_data($r, $from, $to, $limit, $days);

// FR-14: xuất CSV (mở được bằng Excel)
if (g('export') === 'csv') {
    audit('EXPORT_REPORT', 'report', null, $r);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bao-cao-' . $r . '-' . date('Ymd') . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");               // BOM để Excel đọc đúng tiếng Việt
    fputcsv($o, [$title]); fputcsv($o, [$desc]); fputcsv($o, []);
    fputcsv($o, $cols);
    foreach ($data as $row) fputcsv($o, $row);
    fclose($o); exit;
}

$qs = $_GET; unset($qs['export']);
page_start($title);
page_head($title, $desc, '<a class="btn btn-outline-primary" href="?' . e(http_build_query($qs + ['export' => 'csv'])) . '">Xuất Excel (CSV)</a> <button class="btn btn-primary" type="button" onclick="window.print()">In / Lưu PDF</button>');
?>
<ul class="nav nav-pills mb-3 no-print">
  <?php foreach (['top' => 'Sách mượn nhiều nhất', 'stale' => 'Sách tồn kho lâu', 'violators' => 'Độc giả vi phạm / nợ phạt'] as $k => $v): ?>
  <li class="nav-item"><a class="nav-link <?= $r === $k ? 'active bg-primary' : '' ?>" href="?r=<?= $k ?>"><?= e($v) ?></a></li>
  <?php endforeach; ?>
</ul>
<?php if ($r === 'top'): ?>
<form class="row g-2 align-items-end mb-3 no-print" method="get"><input type="hidden" name="r" value="top">
  <div class="col-auto"><label class="form-label" for="from">Từ ngày</label><input class="form-control" type="date" id="from" name="from" value="<?= e($from) ?>"></div>
  <div class="col-auto"><label class="form-label" for="to">Đến ngày</label><input class="form-control" type="date" id="to" name="to" value="<?= e($to) ?>"></div>
  <div class="col-auto"><label class="form-label" for="limit">Hiển thị</label><select class="form-select" id="limit" name="limit"><?php foreach ([10, 20, 50] as $n): ?><option value="<?= $n ?>" <?= $limit === $n ? 'selected' : '' ?>>Top <?= $n ?></option><?php endforeach; ?></select></div>
  <div class="col-auto"><button class="btn btn-primary" type="submit">Xem báo cáo</button></div></form>
<?php elseif ($r === 'stale'): ?>
<form class="row g-2 align-items-end mb-3 no-print" method="get"><input type="hidden" name="r" value="stale">
  <div class="col-auto"><label class="form-label" for="days">Không được mượn từ (ngày)</label><input class="form-control" type="number" min="1" id="days" name="days" value="<?= $days ?>"></div>
  <div class="col-auto"><button class="btn btn-primary" type="submit">Xem báo cáo</button></div></form>
<?php endif; ?>

<div class="table-wrap"><div class="table-responsive"><table class="table table-striped mb-0">
  <thead><tr><?php foreach ($cols as $i => $c): ?><th class="<?= in_array($c, ['Số lượt mượn', 'Số ngày tồn', 'Số bản', 'Sách quá hạn', 'Nợ phạt (đ)', 'Số khoản phạt', 'Quá hạn lâu nhất (ngày)', 'Số độc giả khác nhau']) ? 'text-end' : '' ?>"><?= e($c) ?></th><?php endforeach; ?></tr></thead>
  <tbody>
  <?php if (!$data): ?><tr><td colspan="<?= count($cols) ?>" class="text-muted">Không có dữ liệu cho điều kiện này.</td></tr><?php endif; ?>
  <?php foreach ($data as $row): ?><tr>
    <?php foreach ($row as $i => $cell): $isNum = is_int($cell) || is_float($cell); ?>
      <td class="<?= $isNum && $i > 0 ? 'text-end' : '' ?>"><?= $cols[$i] === 'Nợ phạt (đ)' ? ($cell > 0 ? '<span class="text-danger fw-semibold">' . money($cell) . '</span>' : '—') : e($cell) ?></td>
    <?php endforeach; ?></tr><?php endforeach; ?>
  </tbody></table></div></div>
<p class="small text-muted mt-2">Báo cáo lập lúc <?= date('H:i d/m/Y') ?> bởi <?= e($u['full_name']) ?>. Để lưu PDF: bấm “In / Lưu PDF” rồi chọn “Lưu dưới dạng PDF” trong hộp thoại in của trình duyệt.</p>
<?php page_end();
