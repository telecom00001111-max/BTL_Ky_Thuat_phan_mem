<?php
function nav_link(string $path, string $label, string $cur): string {
    $active = $cur === url($path) ? ' active' : '';
    return '<li class="nav-item"><a class="nav-link' . $active . '" href="' . e(url($path)) . '">' . e($label) . '</a></li>';
}
function nav_dropdown(string $label, array $items, string $cur): string {
    $on = false; $li = '';
    foreach ($items as $path => $text) {
        $a = ($cur === url($path)); $on = $on || $a;
        $li .= '<li><a class="dropdown-item' . ($a ? ' active' : '') . '" href="' . e(url($path)) . '">' . e($text) . '</a></li>';
    }
    return '<li class="nav-item dropdown"><a class="nav-link dropdown-toggle' . ($on ? ' active' : '') . '" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">' . e($label) . '</a><ul class="dropdown-menu">' . $li . '</ul></li>';
}

function page_start(string $title, array $o = []): void {
    $u = user();
    $cur = $_SERVER['SCRIPT_NAME'] ?? '';
    $app = cfg('app.name');
    ?><!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e($app) ?></title>
<link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body>
<nav class="navbar navbar-expand-lg app-nav no-print">
  <div class="container">
    <a class="navbar-brand" href="<?= e(url($u && is_staff($u) ? 'staff/index.php' : 'index.php')) ?>">
      <span class="spines" aria-hidden="true"><i></i><i></i><i></i></span><?= e($app) ?>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-expanded="false" aria-label="Mở menu">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav me-auto">
<?php
    if ($u && is_staff($u)) {
        echo nav_link('staff/index.php', 'Tổng quan', $cur);
        echo nav_link('index.php', 'Tra cứu sách', $cur);
        echo nav_link('staff/readers.php', 'Độc giả', $cur);
        echo nav_dropdown('Kho sách', ['staff/titles.php' => 'Đầu sách', 'staff/copies.php' => 'Bản sách', 'staff/locations.php' => 'Kho / kệ / vị trí'], $cur);
        echo nav_dropdown('Mượn - Trả', ['staff/loan_new.php' => 'Lập phiếu mượn', 'staff/return.php' => 'Lập phiếu trả', 'staff/reservations.php' => 'Duyệt đặt giữ', 'staff/fines.php' => 'Tiền phạt'], $cur);
        echo nav_link('staff/reports.php', 'Báo cáo', $cur);
        if (is_admin($u)) echo nav_dropdown('Quản trị', ['admin/users.php' => 'Tài khoản & phân quyền', 'admin/policy.php' => 'Quy định thư viện'], $cur);
    } elseif ($u) {
        echo nav_link('index.php', 'Tra cứu sách', $cur);
        echo nav_link('reader/reservations.php', 'Đặt giữ của tôi', $cur);
        echo nav_link('reader/history.php', 'Mượn - trả - phạt', $cur);
    } else {
        echo nav_link('index.php', 'Tra cứu sách', $cur);
    }
?>
      </ul>
      <ul class="navbar-nav align-items-lg-center">
<?php if ($u): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"><?= e($u['full_name']) ?> <small class="opacity-75">(<?= e($u['role_name']) ?>)</small></a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="<?= e(url('profile.php')) ?>">Hồ sơ cá nhân</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><form method="post" action="<?= e(url('logout.php')) ?>"><?= csrf_field() ?><button class="dropdown-item" type="submit">Đăng xuất</button></form></li>
          </ul>
        </li>
<?php else: ?>
        <li class="nav-item"><a class="nav-link" href="<?= e(url('login.php')) ?>">Đăng nhập</a></li>
        <li class="nav-item"><a class="btn btn-sm btn-accent ms-lg-2" href="<?= e(url('register.php')) ?>">Đăng ký</a></li>
<?php endif; ?>
      </ul>
    </div>
  </div>
</nav>
<main class="container py-4">
<?php foreach (flashes() as [$type, $msg]): ?>
  <div class="alert alert-<?= e($type) ?> alert-dismissible fade show no-print" role="alert"><?= e($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Đóng"></button></div>
<?php endforeach; ?>
<?php
}

function page_end(): void {
    ?></main>
<footer class="app-footer no-print"><div class="container"><?= e(cfg('app.name')) ?> · Giờ hệ thống <?= date('H:i d/m/Y') ?></div></footer>
<script src="<?= e(url('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
<?php
}

/** Tiêu đề trang + dòng mô tả ngắn. */
function page_head(string $h, string $sub = '', string $actions = ''): void {
    echo '<div class="page-head"><div><h1>' . e($h) . '</h1>' . ($sub !== '' ? '<p class="text-muted mb-0">' . e($sub) . '</p>' : '') . '</div>' . ($actions !== '' ? '<div class="no-print">' . $actions . '</div>' : '') . '</div>';
}
