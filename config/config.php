<?php
/**
 * CẤU HÌNH HỆ THỐNG - Thư viện Tri Thức
 * Sửa file này cho khớp máy của bạn (XAMPP mặc định: user=root, mật khẩu rỗng).
 */
return [
    // ---- Kết nối MySQL (phpMyAdmin) ----
    'db' => [
        'host'    => 'localhost',
        'name'    => 'library_db',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    // ---- Ứng dụng ----
    'app' => [
        'name'     => 'Thư viện Tri Thức',
        'timezone' => 'Asia/Ho_Chi_Minh',
        'base_url' => '',     // để trống = tự nhận (vd: /library). Chỉ điền nếu bị lỗi đường dẫn.
        'debug'    => false,  // true = hiện lỗi PHP chi tiết (chỉ dùng khi đang lập trình)
    ],

    // ---- Gửi email quên mật khẩu (PHPMailer + Gmail SMTP) ----
    // Xem README.md mục "Cấu hình Gmail" để tạo Mật khẩu ứng dụng (App Password).
    'mail' => [
        'host'       => 'smtp.gmail.com',
        'port'       => 587,
        'secure'     => 'tls',
        'username'   => 'yiangq2004@gmail.com',     // <-- Gmail của bạn
        'password'   => 'hayc ctgc ikso csro',      // <-- Mật khẩu ứng dụng 16 ký tự (KHÔNG phải mật khẩu Gmail)
        'from_email' => '',                          // để trống = dùng username
        'from_name'  => 'Thư viện Tri Thức',
        // Chỉ bật khi test trên máy cá nhân chưa cấu hình Gmail: nếu gửi mail thất bại,
        // link đặt lại mật khẩu sẽ hiện trên màn hình. TUYỆT ĐỐI tắt khi đưa lên hosting thật.
        'show_link_on_fail' => true,
    ],

    // Các mức phạt hỏng sách (% giá sách) cho thủ thư chọn khi lập phiếu trả
    'damage_levels' => [25, 50, 75],

    // Số bản ghi mỗi trang
    'per_page' => 12,
];
