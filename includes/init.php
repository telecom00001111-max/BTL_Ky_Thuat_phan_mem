<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
$GLOBALS['CFG'] = require ROOT . '/config/config.php';

function cfg(string $key, $default = null) {
    $v = $GLOBALS['CFG'];
    foreach (explode('.', $key) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) return $default;
        $v = $v[$k];
    }
    return $v;
}

if (cfg('app.debug')) { ini_set('display_errors', '1'); error_reporting(E_ALL); }
else { ini_set('display_errors', '0'); error_reporting(E_ALL & ~E_DEPRECATED); }

date_default_timezone_set(cfg('app.timezone', 'Asia/Ho_Chi_Minh'));
mb_internal_encoding('UTF-8');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

// ---- Đường dẫn gốc (tự nhận khi chạy trong htdocs/library) ----
$__doc  = rtrim(str_replace('\\', '/', (string)realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
$__root = str_replace('\\', '/', ROOT);
$__auto = ($__doc !== '' && strpos($__root, $__doc) === 0) ? substr($__root, strlen($__doc)) : '';
define('BASE_URL', rtrim((string)(cfg('app.base_url') ?: $__auto), '/'));

function url(string $path = ''): string { return BASE_URL . '/' . ltrim($path, '/'); }
function redirect(string $path): void { header('Location: ' . (preg_match('#^(https?:)?//#', $path) ? $path : url($path))); exit; }
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ---- Cơ sở dữ liệu ----
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = cfg('db');
    try {
        $pdo = new PDO("mysql:host={$c['host']};dbname={$c['name']};charset={$c['charset']}", $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $ex) {
        http_response_code(500);
        exit('<meta charset="utf-8"><h3>Không kết nối được cơ sở dữ liệu</h3><p>Hãy bật MySQL trong XAMPP, import <code>database/library_db.sql</code> bằng phpMyAdmin và kiểm tra <code>config/config.php</code>.</p>');
    }
    $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
    return $pdo;
}
function run(string $sql, array $p = []): PDOStatement { $st = db()->prepare($sql); $st->execute($p); return $st; }
function fetch_one(string $sql, array $p = []): ?array { $r = run($sql, $p)->fetch(); return $r === false ? null : $r; }
function fetch_all(string $sql, array $p = []): array { return run($sql, $p)->fetchAll(); }
function fetch_val(string $sql, array $p = []) { $r = run($sql, $p)->fetchColumn(); return $r === false ? null : $r; }

/** Chạy trong transaction. Ném RuntimeException để báo lỗi nghiệp vụ (đã tự rollback). */
function tx(callable $fn) {
    $pdo = db();
    $pdo->beginTransaction();
    try { $r = $fn(); $pdo->commit(); return $r; }
    catch (Throwable $t) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $t; }
}

// ---- Request helpers ----
function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; }
function g(string $k, string $d = ''): string { return isset($_GET[$k]) && is_string($_GET[$k]) ? trim($_GET[$k]) : $d; }
function p(string $k, string $d = ''): string { return isset($_POST[$k]) && is_string($_POST[$k]) ? trim($_POST[$k]) : $d; }

// ---- CSRF (tự kiểm tra mọi request POST) ----
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'; }
if (is_post()) {
    $t = $_POST['_csrf'] ?? '';
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(419);
        exit('<meta charset="utf-8"><h3>Phiên làm việc đã hết hạn</h3><p>Hãy quay lại, tải lại trang và thử lại.</p>');
    }
}

// ---- Flash messages ----
function flash(string $type, string $msg): void { $_SESSION['flash'][] = [$type, $msg]; }
function flashes(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

// ---- Định dạng ----
function money($n): string { return number_format((float)$n, 0, ',', '.') . 'đ'; }
function dmy(?string $d): string { return $d ? date('d/m/Y', strtotime($d)) : '—'; }
function dmyhm(?string $d): string { return $d ? date('d/m/Y H:i', strtotime($d)) : '—'; }
function days_between(string $from, string $to): int { return (int)round((strtotime(date('Y-m-d', strtotime($to))) - strtotime(date('Y-m-d', strtotime($from)))) / 86400); }

const STATUS_LABELS = [
    'copy' => ['AVAILABLE' => ['Sẵn sàng', 'success'], 'RESERVED' => ['Đang giữ', 'warning'], 'BORROWED' => ['Đang mượn', 'primary'],
               'LOST' => ['Mất', 'danger'], 'DAMAGED' => ['Hỏng', 'danger'], 'INACTIVE' => ['Ngừng lưu hành', 'secondary']],
    'title' => ['ACTIVE' => ['Đang lưu hành', 'success'], 'DISCONTINUED' => ['Ngừng lưu hành', 'secondary']],
    'reservation' => ['PENDING' => ['Chờ duyệt', 'warning'], 'APPROVED' => ['Đã duyệt - đang giữ', 'success'], 'REJECTED' => ['Bị từ chối', 'danger'],
                      'CANCELLED' => ['Đã hủy', 'secondary'], 'FULFILLED' => ['Đã nhận sách', 'primary'], 'EXPIRED' => ['Hết hạn giữ', 'secondary']],
    'user' => ['ACTIVE' => ['Hoạt động', 'success'], 'LOCKED' => ['Bị khóa', 'danger'], 'INACTIVE' => ['Ngừng hoạt động', 'secondary']],
    'card' => ['ACTIVE' => ['Còn hiệu lực', 'success'], 'LOCKED' => ['Bị khóa', 'danger'], 'INACTIVE' => ['Ngừng', 'secondary'], 'EXPIRED' => ['Hết hạn', 'warning']],
    'fine' => ['UNPAID' => ['Chưa đóng', 'danger'], 'PAID' => ['Đã đóng', 'success']],
    'item' => ['BORROWING' => ['Đang mượn', 'primary'], 'RETURNED' => ['Đã trả', 'success'], 'LOST' => ['Mất sách', 'danger']],
    'role' => ['READER' => ['Độc giả', 'info'], 'LIBRARIAN' => ['Thủ thư', 'primary'], 'ADMIN' => ['Quản trị viên', 'dark']],
];
function label(string $type, string $v): string { return STATUS_LABELS[$type][$v][0] ?? $v; }
function badge(string $type, string $v): string {
    [$t, $c] = STATUS_LABELS[$type][$v] ?? [$v, 'secondary'];
    return '<span class="badge text-bg-' . $c . '">' . e($t) . '</span>';
}

// ---- Xác thực & phân quyền ----
function user(): ?array {
    static $u = false;
    if ($u !== false) return $u;
    $u = null;
    if (!empty($_SESSION['uid'])) {
        $r = fetch_one("SELECT u.*, r.role_code, r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = ?", [$_SESSION['uid']]);
        if ($r && $r['status'] === 'ACTIVE') $u = $r; else unset($_SESSION['uid']);
    }
    return $u;
}
function is_staff(?array $u = null): bool { $u = $u ?? user(); return $u && in_array($u['role_code'], ['LIBRARIAN', 'ADMIN'], true); }
function is_admin(?array $u = null): bool { $u = $u ?? user(); return $u && $u['role_code'] === 'ADMIN'; }

function require_login(array $roles = []): array {
    $u = user();
    if (!$u) {
        $next = $_SERVER['REQUEST_URI'] ?? '';
        redirect('login.php' . ($next ? '?next=' . urlencode($next) : ''));
    }
    if ($roles && !in_array($u['role_code'], $roles, true)) {
        http_response_code(403);
        require_once ROOT . '/includes/layout.php';
        page_start('Không có quyền truy cập');
        echo '<div class="alert alert-danger mt-4">Tài khoản của bạn không có quyền vào trang này.</div>';
        page_end();
        exit;
    }
    return $u;
}
function require_staff(): array { return require_login(['LIBRARIAN', 'ADMIN']); }
function require_admin(): array { return require_login(['ADMIN']); }

function audit(string $action, ?string $entity = null, ?int $id = null, string $detail = ''): void {
    $u = user();
    run("INSERT INTO audit_logs (user_id, action, entity_name, entity_id, detail) VALUES (?,?,?,?,?)",
        [$u['user_id'] ?? null, $action, $entity, $id, $detail]);
}

// ---- Quy định thư viện (phiên bản mới nhất có hiệu lực tại ngày $date) ----
function policy(?string $date = null): array {
    $date = $date ?: date('Y-m-d');
    $r = fetch_one("SELECT * FROM library_policy WHERE effective_from <= ? ORDER BY effective_from DESC, policy_id DESC LIMIT 1", [$date]);
    if (!$r) $r = fetch_one("SELECT * FROM library_policy ORDER BY policy_id ASC LIMIT 1");
    return $r;
}

// ---- Phân trang ----
function pager(int $total, int $per, int $page): string {
    $pages = (int)ceil($total / max(1, $per));
    if ($pages <= 1) return '';
    $qs = $_GET; unset($qs['page']);
    $mk = function (int $n) use ($qs) { return '?' . http_build_query($qs + ['page' => $n]); };
    $h = '<nav aria-label="Phân trang"><ul class="pagination pagination-sm justify-content-center mt-4">';
    for ($i = 1; $i <= $pages; $i++) {
        if ($i === 1 || $i === $pages || abs($i - $page) <= 2) {
            $h .= '<li class="page-item' . ($i === $page ? ' active' : '') . '"><a class="page-link" href="' . e($mk($i)) . '">' . $i . '</a></li>';
        } elseif (abs($i - $page) === 3) { $h .= '<li class="page-item disabled"><span class="page-link">…</span></li>'; }
    }
    return $h . '</ul></nav>';
}

// ---- Ảnh bìa ----
function cover_html(array $t, string $class = ''): string {
    if (!empty($t['cover_image']) && is_file(ROOT . '/uploads/covers/' . $t['cover_image'])) {
        return '<img class="cover ' . e($class) . '" src="' . e(url('uploads/covers/' . $t['cover_image'])) . '" alt="Bìa sách ' . e($t['title']) . '">';
    }
    $hue = ((int)$t['title_id'] * 47) % 360;
    $words = preg_split('/\s+/u', trim($t['title']));
    $ini = mb_strtoupper(mb_substr($words[0], 0, 1)) . (isset($words[1]) ? mb_strtoupper(mb_substr($words[1], 0, 1)) : '');
    return '<div class="cover cover-ph ' . e($class) . '" style="--h:' . $hue . '" aria-hidden="true"><span>' . e($ini) . '</span></div>';
}
function save_cover(array $f): ?string {
    if (empty($f['name']) || ($f['error'] ?? 4) === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Tải ảnh bìa thất bại (mã lỗi ' . $f['error'] . ').');
    if ($f['size'] > 2 * 1024 * 1024) throw new RuntimeException('Ảnh bìa tối đa 2MB.');
    $info = @getimagesize($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
    if (!$ext) throw new RuntimeException('Ảnh bìa chỉ nhận JPG, PNG hoặc WebP.');
    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], ROOT . '/uploads/covers/' . $name)) throw new RuntimeException('Không lưu được ảnh bìa. Kiểm tra quyền ghi thư mục uploads/covers.');
    return $name;
}

require_once ROOT . '/includes/lib.php';
try { expire_holds(); } catch (Throwable $t) { /* CSDL chưa sẵn sàng: bỏ qua */ }
require_once ROOT . '/includes/layout.php';
