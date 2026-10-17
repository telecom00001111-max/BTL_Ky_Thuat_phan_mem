<?php
/**
 * Lớp nghiệp vụ: điều kiện độc giả, đặt giữ, mượn, trả, phạt, gia hạn.
 * Các hàm ném RuntimeException với thông điệp tiếng Việt khi vi phạm quy tắc nghiệp vụ.
 */

/** Hủy giữ sách quá hạn (chạy tự động mỗi request vì XAMPP không có cron). */
function expire_holds(): void {
    run("UPDATE book_copies c JOIN reservations r ON r.copy_id = c.copy_id
         SET c.status = 'AVAILABLE'
         WHERE r.status = 'APPROVED' AND r.hold_expire_at < NOW() AND c.status = 'RESERVED'");
    run("UPDATE reservations SET status = 'EXPIRED' WHERE status = 'APPROVED' AND hold_expire_at < NOW()");
}

function active_card(int $readerId): ?array {
    return fetch_one("SELECT * FROM library_cards WHERE reader_id = ? AND status = 'ACTIVE' AND expiry_date >= CURDATE()
                      ORDER BY expiry_date DESC LIMIT 1", [$readerId]);
}
function latest_card(int $readerId): ?array {
    return fetch_one("SELECT * FROM library_cards WHERE reader_id = ? ORDER BY expiry_date DESC, card_id DESC LIMIT 1", [$readerId]);
}
function card_state(?array $c): string {
    if (!$c) return 'NONE';
    if ($c['status'] === 'ACTIVE' && $c['expiry_date'] < date('Y-m-d')) return 'EXPIRED';
    return $c['status'];
}
function borrowing_count(int $readerId): int {
    return (int)fetch_val("SELECT COUNT(*) FROM loan_items li JOIN loans l ON l.loan_id = li.loan_id
                           WHERE l.reader_id = ? AND li.status = 'BORROWING'", [$readerId]);
}
function active_hold_count(int $readerId): int {
    return (int)fetch_val("SELECT COUNT(*) FROM reservations WHERE reader_id = ? AND status IN ('PENDING','APPROVED')", [$readerId]);
}
function unpaid_total(int $readerId): float {
    return (float)fetch_val("SELECT COALESCE(SUM(total_amount),0) FROM fines WHERE reader_id = ? AND status = 'UNPAID'", [$readerId]);
}
/** Số bản còn có thể đặt giữ = bản sẵn sàng - yêu cầu đang chờ duyệt. */
function title_free_copies(int $titleId): int {
    $avail = (int)fetch_val("SELECT COUNT(*) FROM book_copies WHERE title_id = ? AND status = 'AVAILABLE'", [$titleId]);
    $pend  = (int)fetch_val("SELECT COUNT(*) FROM reservations WHERE title_id = ? AND status = 'PENDING'", [$titleId]);
    return $avail - $pend;
}

/** UC23 - Kiểm tra điều kiện độc giả. Trả về ['problems' => [...], 'card' => ?array, 'user' => ?array] */
function reader_check(int $readerId): array {
    $problems = [];
    $u = fetch_one("SELECT u.*, r.role_code FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = ?", [$readerId]);
    if (!$u || $u['role_code'] !== 'READER') return ['problems' => ['Không tìm thấy độc giả.'], 'card' => null, 'user' => null];
    if ($u['status'] !== 'ACTIVE') $problems[] = 'Tài khoản độc giả đang bị khóa / ngừng hoạt động.';
    if ($u['locked_until'] && strtotime($u['locked_until']) > time()) $problems[] = 'Tài khoản đang bị khóa tạm thời do nhập sai mật khẩu nhiều lần.';
    $card = active_card($readerId);
    if (!$card) $problems[] = 'Thẻ thư viện không có hiệu lực (chưa cấp, hết hạn hoặc đang bị khóa).';
    $over = (int)fetch_val("SELECT COUNT(*) FROM loan_items li JOIN loans l ON l.loan_id = li.loan_id
                            WHERE l.reader_id = ? AND li.status = 'BORROWING' AND li.due_date < CURDATE()", [$readerId]);
    if ($over > 0) $problems[] = "Đang có $over sách quá hạn chưa trả.";
    $unpaid = unpaid_total($readerId);
    if ($unpaid > 0) $problems[] = 'Còn nợ tiền phạt ' . money($unpaid) . '.';
    return ['problems' => $problems, 'card' => $card, 'user' => $u];
}

// ---------------------------------------------------------------- ĐẶT GIỮ (FR-07)
function request_reservation(int $readerId, int $titleId): int {
    return tx(function () use ($readerId, $titleId) {
        $t = fetch_one("SELECT * FROM book_titles WHERE title_id = ? FOR UPDATE", [$titleId]);
        if (!$t || $t['status'] !== 'ACTIVE') throw new RuntimeException('Đầu sách này đã ngừng lưu hành.');
        $chk = reader_check($readerId);
        if ($chk['problems']) throw new RuntimeException('Bạn chưa đủ điều kiện đặt giữ: ' . implode(' ', $chk['problems']));
        if (fetch_val("SELECT 1 FROM reservations WHERE reader_id = ? AND title_id = ? AND status IN ('PENDING','APPROVED')", [$readerId, $titleId]))
            throw new RuntimeException('Bạn đã có yêu cầu đặt giữ cho đầu sách này.');
        if (fetch_val("SELECT 1 FROM loan_items li JOIN loans l ON l.loan_id = li.loan_id JOIN book_copies c ON c.copy_id = li.copy_id
                       WHERE l.reader_id = ? AND c.title_id = ? AND li.status = 'BORROWING'", [$readerId, $titleId]))
            throw new RuntimeException('Bạn đang mượn đầu sách này rồi.');
        $pol = policy();
        if (borrowing_count($readerId) + active_hold_count($readerId) >= (int)$pol['max_books_per_loan'])
            throw new RuntimeException('Tổng số sách đang mượn và đang đặt giữ đã đạt tối đa ' . $pol['max_books_per_loan'] . ' cuốn.');
        if (title_free_copies($titleId) <= 0)
            throw new RuntimeException('Hiện không còn bản sách nào để đặt giữ.');
        run("INSERT INTO reservations (reader_id, title_id, status) VALUES (?,?, 'PENDING')", [$readerId, $titleId]);
        $id = (int)db()->lastInsertId();
        audit('RESERVE_REQUEST', 'reservation', $id, "title=$titleId");
        return $id;
    });
}

function approve_reservation(int $resId, int $staffId): void {
    tx(function () use ($resId, $staffId) {
        $r = fetch_one("SELECT * FROM reservations WHERE reservation_id = ? FOR UPDATE", [$resId]);
        if (!$r || $r['status'] !== 'PENDING') throw new RuntimeException('Yêu cầu không còn ở trạng thái chờ duyệt.');
        $chk = reader_check((int)$r['reader_id']);
        if ($chk['problems']) throw new RuntimeException('Độc giả không đủ điều kiện: ' . implode(' ', $chk['problems']) . ' Hãy từ chối yêu cầu nếu cần.');
        $copy = fetch_one("SELECT copy_id FROM book_copies WHERE title_id = ? AND status = 'AVAILABLE' ORDER BY copy_id LIMIT 1 FOR UPDATE", [$r['title_id']]);
        if (!$copy) throw new RuntimeException('Không còn bản sách sẵn sàng để giữ cho yêu cầu này.');
        $pol = policy();
        run("UPDATE book_copies SET status = 'RESERVED' WHERE copy_id = ?", [$copy['copy_id']]);
        run("UPDATE reservations SET status = 'APPROVED', copy_id = ?, processed_by = ?, processed_at = NOW(),
             hold_expire_at = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE reservation_id = ?", [$copy['copy_id'], $staffId, (int)$pol['hold_days'], $resId]);
        audit('RESERVE_APPROVE', 'reservation', $resId, 'copy=' . $copy['copy_id']);
    });
}
function reject_reservation(int $resId, int $staffId, string $reason): void {
    tx(function () use ($resId, $staffId, $reason) {
        $r = fetch_one("SELECT * FROM reservations WHERE reservation_id = ? FOR UPDATE", [$resId]);
        if (!$r || $r['status'] !== 'PENDING') throw new RuntimeException('Yêu cầu không còn ở trạng thái chờ duyệt.');
        run("UPDATE reservations SET status = 'REJECTED', processed_by = ?, processed_at = NOW(), reject_reason = ? WHERE reservation_id = ?",
            [$staffId, $reason !== '' ? $reason : null, $resId]);
        audit('RESERVE_REJECT', 'reservation', $resId, $reason);
    });
}
function cancel_reservation(int $resId, int $readerId): void {
    tx(function () use ($resId, $readerId) {
        $r = fetch_one("SELECT * FROM reservations WHERE reservation_id = ? AND reader_id = ? FOR UPDATE", [$resId, $readerId]);
        if (!$r || !in_array($r['status'], ['PENDING', 'APPROVED'], true)) throw new RuntimeException('Không thể hủy yêu cầu này.');
        if ($r['status'] === 'APPROVED' && $r['copy_id'])
            run("UPDATE book_copies SET status = 'AVAILABLE' WHERE copy_id = ? AND status = 'RESERVED'", [$r['copy_id']]);
        run("UPDATE reservations SET status = 'CANCELLED', processed_at = NOW() WHERE reservation_id = ?", [$resId]);
        audit('RESERVE_CANCEL', 'reservation', $resId);
    });
}

// ---------------------------------------------------------------- LẬP PHIẾU MƯỢN (FR-08)
/** Kiểm tra một bản sách có thể cho độc giả mượn không. Trả về [copy, reservation|null]. */
function validate_copy_for_loan(string $barcode, int $readerId): array {
    $c = fetch_one("SELECT c.*, t.title AS title_name, t.status AS title_status FROM book_copies c JOIN book_titles t ON t.title_id = c.title_id WHERE c.barcode = ?", [$barcode]);
    if (!$c) throw new RuntimeException("Không tìm thấy bản sách có mã vạch $barcode.");
    $res = null;
    switch ($c['status']) {
        case 'AVAILABLE': break;
        case 'RESERVED':
            $res = fetch_one("SELECT * FROM reservations WHERE copy_id = ? AND status = 'APPROVED' AND hold_expire_at >= NOW()", [$c['copy_id']]);
            if (!$res || (int)$res['reader_id'] !== $readerId) throw new RuntimeException('Bản sách đang được giữ cho độc giả khác.');
            break;
        case 'BORROWED': throw new RuntimeException('Bản sách đang được mượn.');
        case 'LOST': throw new RuntimeException('Bản sách đã bị mất.');
        case 'DAMAGED': throw new RuntimeException('Bản sách đang hỏng, chưa thể cho mượn.');
        default: throw new RuntimeException('Bản sách đã ngừng lưu hành.');
    }
    return [$c, $res];
}

function create_loan(int $readerId, int $staffId, array $barcodes, string $note = ''): int {
    return tx(function () use ($readerId, $staffId, $barcodes, $note) {
        if (!$barcodes) throw new RuntimeException('Chưa có sách nào trong phiếu mượn.');
        $chk = reader_check($readerId);   // kiểm tra lại ngay trong transaction
        if ($chk['problems']) throw new RuntimeException(implode(' ', $chk['problems']));
        $pol = policy();
        if (borrowing_count($readerId) + count($barcodes) > (int)$pol['max_books_per_loan'])
            throw new RuntimeException('Vượt số sách được mượn tối đa (' . $pol['max_books_per_loan'] . ' cuốn).');
        // khóa các bản sách để chống hai quầy cùng mượn một bản
        $ph = implode(',', array_fill(0, count($barcodes), '?'));
        run("SELECT copy_id FROM book_copies WHERE barcode IN ($ph) FOR UPDATE", array_values($barcodes));
        $checked = [];
        foreach ($barcodes as $bc) $checked[] = validate_copy_for_loan($bc, $readerId);

        run("INSERT INTO loans (reader_id, card_id, librarian_id, loan_date, note) VALUES (?,?,?,NOW(),?)",
            [$readerId, $chk['card']['card_id'], $staffId, $note !== '' ? $note : null]);
        $loanId = (int)db()->lastInsertId();
        foreach ($checked as [$c, $res]) {
            run("INSERT INTO loan_items (loan_id, copy_id, reservation_id, due_date, condition_on_loan, status)
                 VALUES (?,?,?, DATE_ADD(CURDATE(), INTERVAL ? DAY), ?, 'BORROWING')",
                [$loanId, $c['copy_id'], $res['reservation_id'] ?? null, (int)$pol['loan_days'], $c['condition_status']]);
            run("UPDATE book_copies SET status = 'BORROWED' WHERE copy_id = ?", [$c['copy_id']]);
            if ($res) run("UPDATE reservations SET status = 'FULFILLED' WHERE reservation_id = ?", [$res['reservation_id']]);
            else run("UPDATE reservations SET status = 'FULFILLED' WHERE reader_id = ? AND title_id = ? AND status = 'PENDING'", [$readerId, $c['title_id']]);
        }
        audit('CREATE_LOAN', 'loan', $loanId, count($checked) . ' sách');
        return $loanId;
    });
}

// ---------------------------------------------------------------- TRẢ SÁCH & TÍNH PHẠT (FR-09, FR-11)
function find_open_item(string $barcode): ?array {
    return fetch_one("SELECT li.*, l.loan_date, l.reader_id, c.barcode, c.price, c.title_id, t.title AS title_name,
                             u.full_name, u.username, lc.card_no
                      FROM loan_items li
                      JOIN loans l ON l.loan_id = li.loan_id
                      JOIN book_copies c ON c.copy_id = li.copy_id
                      JOIN book_titles t ON t.title_id = c.title_id
                      JOIN users u ON u.user_id = l.reader_id
                      JOIN library_cards lc ON lc.card_id = l.card_id
                      WHERE c.barcode = ? AND li.status = 'BORROWING' LIMIT 1", [$barcode]);
}
function get_item(int $id): ?array {
    return fetch_one("SELECT li.*, l.loan_date, l.reader_id, c.barcode, c.price, c.title_id, t.title AS title_name,
                             u.full_name, u.username, lc.card_no
                      FROM loan_items li
                      JOIN loans l ON l.loan_id = li.loan_id
                      JOIN book_copies c ON c.copy_id = li.copy_id
                      JOIN book_titles t ON t.title_id = c.title_id
                      JOIN users u ON u.user_id = l.reader_id
                      JOIN library_cards lc ON lc.card_id = l.card_id
                      WHERE li.loan_item_id = ?", [$id]);
}

/**
 * Phạt = (số ngày quá hạn tính phí × mức phạt) + phạt hỏng/mất.
 * Dùng quy định có hiệu lực tại ngày lập phiếu mượn.
 */
function compute_fine(array $item, string $cond, ?int $level = null): array {
    $pol = policy(date('Y-m-d', strtotime($item['loan_date'])));
    $late = max(0, days_between($item['due_date'], date('Y-m-d')));
    $days = max(0, $late - (int)$pol['grace_days']);
    $overdue = $days * (float)$pol['fine_per_day'];
    $dmg = 0.0; $lvl = null; $reason = [];
    if ($days > 0) $reason[] = 'Quá hạn';
    if ($cond === 'DAMAGED') {
        if (!in_array($level, cfg('damage_levels'), true)) throw new RuntimeException('Hãy chọn mức phạt hỏng sách.');
        $lvl = $level; $dmg = (float)$item['price'] * $level / 100; $reason[] = "Hỏng $level%";
    } elseif ($cond === 'LOST') {
        $dmg = (float)$item['price'] * (float)$pol['lost_fine_rate']; $reason[] = 'Mất sách';
    } elseif ($cond !== 'NORMAL') throw new RuntimeException('Tình trạng sách không hợp lệ.');
    return ['policy_id' => (int)$pol['policy_id'], 'policy' => $pol, 'late_days' => $late, 'overdue_days' => $days,
            'overdue_amount' => $overdue, 'damage_level' => $lvl, 'damage_amount' => $dmg,
            'total' => $overdue + $dmg, 'reason' => implode(' + ', $reason)];
}

function process_return(int $itemId, string $cond, ?int $level, int $staffId): array {
    return tx(function () use ($itemId, $cond, $level, $staffId) {
        run("SELECT loan_item_id FROM loan_items WHERE loan_item_id = ? FOR UPDATE", [$itemId]);
        $item = get_item($itemId);
        if (!$item || $item['status'] !== 'BORROWING') throw new RuntimeException('Sách này không còn ở trạng thái đang mượn.');
        $f = compute_fine($item, $cond, $level);
        run("INSERT INTO return_receipts (librarian_id, return_date) VALUES (?, NOW())", [$staffId]);
        $retId = (int)db()->lastInsertId();
        run("UPDATE loan_items SET return_id = ?, return_date = NOW(), condition_on_return = ?, status = ? WHERE loan_item_id = ?",
            [$retId, $cond, $cond === 'LOST' ? 'LOST' : 'RETURNED', $itemId]);
        if ($cond === 'NORMAL') run("UPDATE book_copies SET status = 'AVAILABLE', condition_status = 'NORMAL' WHERE copy_id = ?", [$item['copy_id']]);
        elseif ($cond === 'DAMAGED') run("UPDATE book_copies SET status = 'DAMAGED', condition_status = 'DAMAGED' WHERE copy_id = ?", [$item['copy_id']]);
        else run("UPDATE book_copies SET status = 'LOST' WHERE copy_id = ?", [$item['copy_id']]);
        $fineId = null;
        if ($f['total'] > 0) {
            run("INSERT INTO fines (loan_item_id, reader_id, policy_id, overdue_days, overdue_amount, damage_level, damage_amount, total_amount, reason, status)
                 VALUES (?,?,?,?,?,?,?,?,?, 'UNPAID')",
                [$itemId, $item['reader_id'], $f['policy_id'], $f['overdue_days'], $f['overdue_amount'], $f['damage_level'], $f['damage_amount'], $f['total'], $f['reason']]);
            $fineId = (int)db()->lastInsertId();
        }
        audit('RETURN_BOOK', 'loan_item', $itemId, "cond=$cond fine=" . $f['total']);
        return ['return_id' => $retId, 'fine_id' => $fineId, 'fine' => $f];
    });
}

function record_payment(int $fineId, string $method, int $staffId): int {
    return tx(function () use ($fineId, $method, $staffId) {
        $f = fetch_one("SELECT * FROM fines WHERE fine_id = ? FOR UPDATE", [$fineId]);
        if (!$f) throw new RuntimeException('Không tìm thấy khoản phạt.');
        if ($f['status'] !== 'UNPAID') throw new RuntimeException('Khoản phạt này đã được đóng.');
        if (!in_array($method, ['CASH', 'TRANSFER'], true)) $method = 'CASH';
        run("INSERT INTO payments (fine_id, received_by, amount, method) VALUES (?,?,?,?)", [$fineId, $staffId, $f['total_amount'], $method]);
        $pid = (int)db()->lastInsertId();
        run("UPDATE fines SET status = 'PAID' WHERE fine_id = ?", [$fineId]);
        audit('PAY_FINE', 'fine', $fineId, 'amount=' . $f['total_amount']);
        return $pid;
    });
}

// ---------------------------------------------------------------- GIA HẠN (FR-10) - tự động duyệt
/** Trả về [bool đã gia hạn, thông điệp]. */
function renew_item(int $itemId, int $readerId): array {
    try {
        return tx(function () use ($itemId, $readerId) {
            run("SELECT loan_item_id FROM loan_items WHERE loan_item_id = ? FOR UPDATE", [$itemId]);
            $it = fetch_one("SELECT li.*, l.reader_id, c.title_id FROM loan_items li JOIN loans l ON l.loan_id = li.loan_id
                             JOIN book_copies c ON c.copy_id = li.copy_id WHERE li.loan_item_id = ?", [$itemId]);
            if (!$it || (int)$it['reader_id'] !== $readerId || $it['status'] !== 'BORROWING')
                throw new RuntimeException('Không tìm thấy sách đang mượn để gia hạn.');
            $pol = policy();
            $reject = null;
            if ((int)$it['renewal_count'] >= (int)$pol['max_renewals']) $reject = 'Đã gia hạn tối đa ' . $pol['max_renewals'] . ' lần.';
            elseif ($it['due_date'] < date('Y-m-d')) $reject = 'Sách đã quá hạn, không thể gia hạn. Hãy trả sách tại quầy.';
            elseif (fetch_val("SELECT 1 FROM reservations WHERE title_id = ? AND reader_id <> ? AND status IN ('PENDING','APPROVED') LIMIT 1", [$it['title_id'], $readerId]))
                $reject = 'Có độc giả khác đang đặt giữ đầu sách này nên không thể gia hạn.';
            else {
                $chk = reader_check($readerId);
                if ($chk['problems']) $reject = implode(' ', $chk['problems']);
            }
            if ($reject !== null) {
                run("INSERT INTO renewals (loan_item_id, old_due_date, new_due_date, status, reject_reason) VALUES (?,?,NULL,'REJECTED',?)", [$itemId, $it['due_date'], $reject]);
                return [false, $reject];
            }
            $new = date('Y-m-d', strtotime($it['due_date'] . ' +' . (int)$pol['renew_days'] . ' days'));
            run("UPDATE loan_items SET due_date = ?, renewal_count = renewal_count + 1 WHERE loan_item_id = ?", [$new, $itemId]);
            run("INSERT INTO renewals (loan_item_id, old_due_date, new_due_date, status) VALUES (?,?,?,'APPROVED')", [$itemId, $it['due_date'], $new]);
            audit('RENEW', 'loan_item', $itemId, "new_due=$new");
            return [true, 'Gia hạn thành công. Hạn trả mới: ' . dmy($new) . '.'];
        });
    } catch (RuntimeException $ex) { return [false, $ex->getMessage()]; }
}

// ---------------------------------------------------------------- THẺ THƯ VIỆN (FR-03)
function next_card_no(): string {
    $n = (int)fetch_val("SELECT COALESCE(MAX(card_id),0) + 1 FROM library_cards");
    do { $no = 'TV' . date('Y') . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT); $n++; }
    while (fetch_val("SELECT 1 FROM library_cards WHERE card_no = ?", [$no]));
    return $no;
}
function issue_card(int $readerId): int {
    $years = (int)policy()['card_valid_years'];
    run("INSERT INTO library_cards (reader_id, card_no, issue_date, expiry_date, status) VALUES (?,?,CURDATE(), DATE_ADD(CURDATE(), INTERVAL ? YEAR), 'ACTIVE')",
        [$readerId, next_card_no(), $years]);
    return (int)db()->lastInsertId();
}
function renew_card(int $cardId): string {
    $years = (int)policy()['card_valid_years'];
    $c = fetch_one("SELECT * FROM library_cards WHERE card_id = ?", [$cardId]);
    if (!$c) throw new RuntimeException('Không tìm thấy thẻ.');
    $from = max(date('Y-m-d'), $c['expiry_date']);
    $new = date('Y-m-d', strtotime("$from +$years years"));
    run("UPDATE library_cards SET expiry_date = ?, status = 'ACTIVE' WHERE card_id = ?", [$new, $cardId]);
    return $new;
}

function validate_password(string $pw): ?string {
    if (strlen($pw) < 8) return 'Mật khẩu phải có ít nhất 8 ký tự.';
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) return 'Mật khẩu phải gồm cả chữ và số.';
    return null;
}
