-- =====================================================================
--  CSDL: library_db  |  Hệ thống Quản lý Thư viện "Thư viện Tri Thức"
--  Import bằng phpMyAdmin: tab Import -> chọn file này
--  (file sẽ tự tạo CSDL library_db; nếu đã tồn tại sẽ XÓA và tạo lại)
-- =====================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
DROP DATABASE IF EXISTS library_db;
CREATE DATABASE library_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE library_db;

-- ---------- PHÂN HỆ 1: TÀI KHOẢN & PHÂN QUYỀN ----------
CREATE TABLE roles (
  role_id    INT AUTO_INCREMENT PRIMARY KEY,
  role_code  VARCHAR(20)  NOT NULL UNIQUE,      -- READER / LIBRARIAN / ADMIN
  role_name  VARCHAR(50)  NOT NULL
) ENGINE=InnoDB;

CREATE TABLE users (
  user_id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  role_id            INT NOT NULL,
  username           VARCHAR(50)  NOT NULL UNIQUE,
  email              VARCHAR(100) NOT NULL UNIQUE,
  password_hash      VARCHAR(255) NOT NULL,
  full_name          VARCHAR(100) NOT NULL,
  phone              VARCHAR(20)  NULL,
  address            VARCHAR(255) NULL,
  status             ENUM('ACTIVE','LOCKED','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  failed_login_count INT NOT NULL DEFAULT 0,
  locked_until       DATETIME NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(role_id)
) ENGINE=InnoDB;

CREATE TABLE password_resets (
  token_id   BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id    BIGINT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expired_at DATETIME NOT NULL,
  used_at    DATETIME NULL,
  INDEX idx_pr_hash (token_hash),
  CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE library_cards (
  card_id     BIGINT AUTO_INCREMENT PRIMARY KEY,
  reader_id   BIGINT NOT NULL,
  card_no     VARCHAR(20) NOT NULL UNIQUE,
  issue_date  DATE NOT NULL,
  expiry_date DATE NOT NULL,
  status      ENUM('ACTIVE','LOCKED','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  CONSTRAINT fk_card_reader FOREIGN KEY (reader_id) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE library_policy (
  policy_id          INT AUTO_INCREMENT PRIMARY KEY,
  loan_days          INT NOT NULL,
  max_books_per_loan INT NOT NULL,
  max_renewals       INT NOT NULL,
  renew_days         INT NOT NULL,
  grace_days         INT NOT NULL DEFAULT 0,
  fine_per_day       DECIMAL(12,2) NOT NULL,
  lost_fine_rate     DECIMAL(5,2)  NOT NULL DEFAULT 1.00,   -- 1.00 = 100% giá sách
  hold_days          INT NOT NULL DEFAULT 3,
  max_failed_login   INT NOT NULL DEFAULT 5,
  lock_minutes       INT NOT NULL DEFAULT 15,
  card_valid_years   INT NOT NULL DEFAULT 4,
  effective_from     DATE NOT NULL,
  updated_by         BIGINT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_policy_user FOREIGN KEY (updated_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

-- ---------- PHÂN HỆ 2: DANH MỤC SÁCH ----------
CREATE TABLE categories (
  category_id INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(100) NOT NULL,
  parent_id   INT NULL,
  status      ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  CONSTRAINT fk_cat_parent FOREIGN KEY (parent_id) REFERENCES categories(category_id)
) ENGINE=InnoDB;

CREATE TABLE publishers (
  publisher_id INT AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(150) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE authors (
  author_id INT AUTO_INCREMENT PRIMARY KEY,
  name      VARCHAR(150) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE book_titles (
  title_id     BIGINT AUTO_INCREMENT PRIMARY KEY,
  isbn         VARCHAR(20)  NOT NULL UNIQUE,
  title        VARCHAR(255) NOT NULL,
  category_id  INT NULL,
  publisher_id INT NULL,
  publish_year INT NULL,
  description  TEXT NULL,
  cover_image  VARCHAR(255) NULL,
  status       ENUM('ACTIVE','DISCONTINUED') NOT NULL DEFAULT 'ACTIVE',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FULLTEXT KEY ft_title (title),
  CONSTRAINT fk_bt_cat FOREIGN KEY (category_id)  REFERENCES categories(category_id),
  CONSTRAINT fk_bt_pub FOREIGN KEY (publisher_id) REFERENCES publishers(publisher_id)
) ENGINE=InnoDB;

CREATE TABLE title_authors (
  title_id  BIGINT NOT NULL,
  author_id INT NOT NULL,
  PRIMARY KEY (title_id, author_id),
  CONSTRAINT fk_ta_title  FOREIGN KEY (title_id)  REFERENCES book_titles(title_id),
  CONSTRAINT fk_ta_author FOREIGN KEY (author_id) REFERENCES authors(author_id)
) ENGINE=InnoDB;

CREATE TABLE storage_locations (
  location_id INT AUTO_INCREMENT PRIMARY KEY,
  warehouse   VARCHAR(50) NOT NULL,
  shelf       VARCHAR(20) NOT NULL,
  slot        VARCHAR(20) NOT NULL DEFAULT '',
  UNIQUE KEY uq_loc (warehouse, shelf, slot)
) ENGINE=InnoDB;

CREATE TABLE book_copies (
  copy_id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  title_id         BIGINT NOT NULL,
  location_id      INT NULL,
  barcode          VARCHAR(30) NOT NULL UNIQUE,
  price            DECIMAL(12,2) NOT NULL DEFAULT 0,
  condition_status ENUM('NORMAL','DAMAGED') NOT NULL DEFAULT 'NORMAL',
  status           ENUM('AVAILABLE','RESERVED','BORROWED','LOST','DAMAGED','INACTIVE') NOT NULL DEFAULT 'AVAILABLE',
  acquired_date    DATE NOT NULL,
  INDEX idx_copy_title_status (title_id, status),
  CONSTRAINT fk_copy_title FOREIGN KEY (title_id)    REFERENCES book_titles(title_id),
  CONSTRAINT fk_copy_loc   FOREIGN KEY (location_id) REFERENCES storage_locations(location_id)
) ENGINE=InnoDB;

-- ---------- PHÂN HỆ 3: MƯỢN - TRẢ ----------
CREATE TABLE reservations (
  reservation_id BIGINT AUTO_INCREMENT PRIMARY KEY,
  reader_id      BIGINT NOT NULL,
  title_id       BIGINT NOT NULL,
  copy_id        BIGINT NULL,                      -- gán khi thủ thư duyệt
  status         ENUM('PENDING','APPROVED','REJECTED','CANCELLED','FULFILLED','EXPIRED') NOT NULL DEFAULT 'PENDING',
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_by   BIGINT NULL,
  processed_at   DATETIME NULL,
  hold_expire_at DATETIME NULL,
  reject_reason  VARCHAR(255) NULL,
  INDEX idx_res_title_status (title_id, status),
  INDEX idx_res_reader (reader_id, status),
  CONSTRAINT fk_res_reader FOREIGN KEY (reader_id)    REFERENCES users(user_id),
  CONSTRAINT fk_res_title  FOREIGN KEY (title_id)     REFERENCES book_titles(title_id),
  CONSTRAINT fk_res_copy   FOREIGN KEY (copy_id)      REFERENCES book_copies(copy_id),
  CONSTRAINT fk_res_proc   FOREIGN KEY (processed_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE loans (
  loan_id      BIGINT AUTO_INCREMENT PRIMARY KEY,
  reader_id    BIGINT NOT NULL,
  card_id      BIGINT NOT NULL,
  librarian_id BIGINT NOT NULL,
  loan_date    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  note         VARCHAR(255) NULL,
  CONSTRAINT fk_loan_reader FOREIGN KEY (reader_id)    REFERENCES users(user_id),
  CONSTRAINT fk_loan_card   FOREIGN KEY (card_id)      REFERENCES library_cards(card_id),
  CONSTRAINT fk_loan_lib    FOREIGN KEY (librarian_id) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE return_receipts (
  return_id    BIGINT AUTO_INCREMENT PRIMARY KEY,
  librarian_id BIGINT NOT NULL,
  return_date  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  note         VARCHAR(255) NULL,
  CONSTRAINT fk_rr_lib FOREIGN KEY (librarian_id) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE loan_items (
  loan_item_id        BIGINT AUTO_INCREMENT PRIMARY KEY,
  loan_id             BIGINT NOT NULL,
  copy_id             BIGINT NOT NULL,
  reservation_id      BIGINT NULL,
  return_id           BIGINT NULL,
  due_date            DATE NOT NULL,
  return_date         DATETIME NULL,
  renewal_count       INT NOT NULL DEFAULT 0,
  condition_on_loan   ENUM('NORMAL','DAMAGED') NOT NULL DEFAULT 'NORMAL',
  condition_on_return ENUM('NORMAL','DAMAGED','LOST') NULL,
  status              ENUM('BORROWING','RETURNED','LOST') NOT NULL DEFAULT 'BORROWING',
  INDEX idx_li_status_due (status, due_date),
  INDEX idx_li_copy (copy_id, status),
  CONSTRAINT fk_li_loan FOREIGN KEY (loan_id)        REFERENCES loans(loan_id),
  CONSTRAINT fk_li_copy FOREIGN KEY (copy_id)        REFERENCES book_copies(copy_id),
  CONSTRAINT fk_li_res  FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id),
  CONSTRAINT fk_li_ret  FOREIGN KEY (return_id)      REFERENCES return_receipts(return_id)
) ENGINE=InnoDB;

CREATE TABLE renewals (
  renewal_id    BIGINT AUTO_INCREMENT PRIMARY KEY,
  loan_item_id  BIGINT NOT NULL,
  requested_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  old_due_date  DATE NOT NULL,
  new_due_date  DATE NULL,
  approve_mode  ENUM('AUTO') NOT NULL DEFAULT 'AUTO',
  status        ENUM('APPROVED','REJECTED') NOT NULL,
  reject_reason VARCHAR(255) NULL,
  CONSTRAINT fk_rn_item FOREIGN KEY (loan_item_id) REFERENCES loan_items(loan_item_id)
) ENGINE=InnoDB;

CREATE TABLE fines (
  fine_id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  loan_item_id    BIGINT NOT NULL,
  reader_id       BIGINT NOT NULL,
  policy_id       INT NOT NULL,
  overdue_days    INT NOT NULL DEFAULT 0,
  overdue_amount  DECIMAL(12,2) NOT NULL DEFAULT 0,
  damage_level    TINYINT NULL,                    -- 25 / 50 / 75 (NULL nếu mất hoặc không hỏng)
  damage_amount   DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_amount    DECIMAL(12,2) NOT NULL DEFAULT 0,
  reason          VARCHAR(100) NOT NULL,           -- vd: "Quá hạn", "Quá hạn + Hỏng", "Mất sách"
  status          ENUM('UNPAID','PAID') NOT NULL DEFAULT 'UNPAID',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_fine_reader_status (reader_id, status),
  CONSTRAINT fk_fine_item   FOREIGN KEY (loan_item_id) REFERENCES loan_items(loan_item_id),
  CONSTRAINT fk_fine_reader FOREIGN KEY (reader_id)    REFERENCES users(user_id),
  CONSTRAINT fk_fine_policy FOREIGN KEY (policy_id)    REFERENCES library_policy(policy_id)
) ENGINE=InnoDB;

CREATE TABLE payments (
  payment_id  BIGINT AUTO_INCREMENT PRIMARY KEY,
  fine_id     BIGINT NOT NULL,
  received_by BIGINT NOT NULL,
  amount      DECIMAL(12,2) NOT NULL,
  method      ENUM('CASH','TRANSFER') NOT NULL DEFAULT 'CASH',
  paid_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pay_fine FOREIGN KEY (fine_id)     REFERENCES fines(fine_id),
  CONSTRAINT fk_pay_user FOREIGN KEY (received_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
  log_id      BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT NULL,
  action      VARCHAR(40) NOT NULL,
  entity_name VARCHAR(40) NULL,
  entity_id   BIGINT NULL,
  detail      TEXT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_time (created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(user_id)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
-- ======================== DỮ LIỆU MẪU ========================

INSERT INTO roles (role_id, role_code, role_name) VALUES
  (1, 'READER', 'Độc giả'),
  (2, 'LIBRARIAN', 'Thủ thư'),
  (3, 'ADMIN', 'Quản trị viên');

INSERT INTO users (user_id, role_id, username, email, password_hash, full_name, phone, address, status, failed_login_count) VALUES
  (1, 3, 'admin', 'admin@example.com', '$2b$10$uCq5UcDpctRLv6GT0fq01uDKc7uArPBstqMJ/Dy5uaSLHNPxKm7Lm', 'Quản trị viên hệ thống', '0900000001', 'Hà Nội', 'ACTIVE', 0),
  (2, 2, 'thuthu1', 'thuthu1@example.com', '$2b$10$O0EoYmPdI4IcIn7VcrOy7OyiWggaoMpF9tbIvJ6vVji7BdfLpi9Y2', 'Nguyễn Thị Hương', '0900000002', 'Hà Nội', 'ACTIVE', 0),
  (3, 2, 'thuthu2', 'thuthu2@example.com', '$2b$10$O0EoYmPdI4IcIn7VcrOy7OyiWggaoMpF9tbIvJ6vVji7BdfLpi9Y2', 'Trần Văn Khoa', '0900000003', 'Hà Nội', 'ACTIVE', 0),
  (4, 1, 'docgia1', 'docgia1@example.com', '$2b$10$MU6xaMkjFiV/JVtpSe3a6eYk8g/3K3aHjaZ.L8WlIpQnhLcAPILR.', 'Nguyễn Minh Anh', '0911000001', 'Cầu Giấy, Hà Nội', 'ACTIVE', 0),
  (5, 1, 'docgia2', 'docgia2@example.com', '$2b$10$MU6xaMkjFiV/JVtpSe3a6eYk8g/3K3aHjaZ.L8WlIpQnhLcAPILR.', 'Trần Quốc Bảo', '0911000002', 'Đống Đa, Hà Nội', 'ACTIVE', 0),
  (6, 1, 'docgia3', 'docgia3@example.com', '$2b$10$MU6xaMkjFiV/JVtpSe3a6eYk8g/3K3aHjaZ.L8WlIpQnhLcAPILR.', 'Lê Thị Cẩm Linh', '0911000003', 'Thanh Xuân, Hà Nội', 'ACTIVE', 0),
  (7, 1, 'docgia4', 'docgia4@example.com', '$2b$10$MU6xaMkjFiV/JVtpSe3a6eYk8g/3K3aHjaZ.L8WlIpQnhLcAPILR.', 'Phạm Hoàng Dũng', '0911000004', 'Hai Bà Trưng, Hà Nội', 'ACTIVE', 0),
  (8, 1, 'docgia5', 'docgia5@example.com', '$2b$10$MU6xaMkjFiV/JVtpSe3a6eYk8g/3K3aHjaZ.L8WlIpQnhLcAPILR.', 'Võ Thanh Hà', '0911000005', 'Hoàn Kiếm, Hà Nội', 'ACTIVE', 0),
  (9, 1, 'docgia6', 'docgia6@example.com', '$2b$10$MU6xaMkjFiV/JVtpSe3a6eYk8g/3K3aHjaZ.L8WlIpQnhLcAPILR.', 'Đặng Gia Huy', '0911000006', 'Long Biên, Hà Nội', 'ACTIVE', 0);

INSERT INTO library_cards (card_id, reader_id, card_no, issue_date, expiry_date, status) VALUES
  (1, 4, 'TV2026-0001', DATE_ADD(CURDATE(), INTERVAL -365 DAY), DATE_ADD(CURDATE(), INTERVAL 1095 DAY), 'ACTIVE'),
  (2, 5, 'TV2026-0002', DATE_ADD(CURDATE(), INTERVAL -365 DAY), DATE_ADD(CURDATE(), INTERVAL 1095 DAY), 'ACTIVE'),
  (3, 6, 'TV2026-0003', DATE_ADD(CURDATE(), INTERVAL -365 DAY), DATE_ADD(CURDATE(), INTERVAL 1095 DAY), 'ACTIVE'),
  (4, 7, 'TV2026-0004', DATE_ADD(CURDATE(), INTERVAL -365 DAY), DATE_ADD(CURDATE(), INTERVAL 1095 DAY), 'ACTIVE'),
  (5, 8, 'TV2026-0005', DATE_ADD(CURDATE(), INTERVAL -1825 DAY), DATE_ADD(CURDATE(), INTERVAL -365 DAY), 'ACTIVE');

INSERT INTO library_policy (policy_id, loan_days, max_books_per_loan, max_renewals, renew_days, grace_days, fine_per_day, lost_fine_rate, hold_days, max_failed_login, lock_minutes, card_valid_years, effective_from, updated_by) VALUES
  (1, 14, 5, 2, 7, 0, 2000, 1.00, 3, 5, 15, 4, '2020-01-01', 1);

INSERT INTO categories (category_id, name, status) VALUES
  (1, 'Văn học Việt Nam', 'ACTIVE'),
  (2, 'Văn học nước ngoài', 'ACTIVE'),
  (3, 'Công nghệ thông tin', 'ACTIVE'),
  (4, 'Khoa học tự nhiên', 'ACTIVE'),
  (5, 'Kinh tế - Kỹ năng', 'ACTIVE'),
  (6, 'Lịch sử', 'ACTIVE'),
  (7, 'Ngoại ngữ', 'ACTIVE');

INSERT INTO publishers (publisher_id, name) VALUES
  (1, 'NXB Kim Đồng'),
  (2, 'NXB Văn học'),
  (3, 'NXB Trẻ'),
  (4, 'NXB Tổng hợp TP.HCM'),
  (5, 'NXB Lao động'),
  (6, 'NXB Thế giới'),
  (7, 'NXB Giáo dục Việt Nam'),
  (8, 'MIT Press'),
  (9, 'Pearson'),
  (10, 'Addison-Wesley'),
  (11, 'Prentice Hall'),
  (12, 'Cambridge University Press'),
  (13, 'McGraw-Hill'),
  (14, 'No Starch Press'),
  (15, 'NXB Khoa học Xã hội'),
  (16, 'NXB Hội Nhà văn');

INSERT INTO authors (author_id, name) VALUES
  (1, 'Tô Hoài'),
  (2, 'Vũ Trọng Phụng'),
  (3, 'Ngô Tất Tố'),
  (4, 'Nguyễn Du'),
  (5, 'Paulo Coelho'),
  (6, 'Antoine de Saint-Exupéry'),
  (7, 'Victor Hugo'),
  (8, 'Nguyễn Nhật Ánh'),
  (9, 'Robert C. Martin'),
  (10, 'Thomas H. Cormen'),
  (11, 'Charles E. Leiserson'),
  (12, 'Erich Gamma'),
  (13, 'Richard Helm'),
  (14, 'Abraham Silberschatz'),
  (15, 'James F. Kurose'),
  (16, 'Keith W. Ross'),
  (17, 'Eric Matthes'),
  (18, 'Stephen Hawking'),
  (19, 'Charles Darwin'),
  (20, 'Dale Carnegie'),
  (21, 'Robert T. Kiyosaki'),
  (22, 'Daniel Kahneman'),
  (23, 'Napoleon Hill'),
  (24, 'Yuval Noah Harari'),
  (25, 'Ngô Sĩ Liên'),
  (26, 'Trần Trọng Kim'),
  (27, 'Raymond Murphy'),
  (28, 'N. Gregory Mankiw'),
  (29, 'Nhiều tác giả');

INSERT INTO book_titles (title_id, isbn, title, category_id, publisher_id, publish_year, description, status) VALUES
  (1, '9786041000037', 'Dế Mèn phiêu lưu ký', 1, 1, 2019, 'Cuộc phiêu lưu của chú dế Mèn qua thế giới loài vật, tác phẩm kinh điển dành cho thiếu nhi.', 'ACTIVE'),
  (2, '9786041000074', 'Số đỏ', 1, 2, 2018, 'Tiểu thuyết trào phúng về xã hội thành thị Việt Nam những năm 1930.', 'ACTIVE'),
  (3, '9786041000111', 'Tắt đèn', 1, 2, 2017, 'Bức tranh chân thực về đời sống người nông dân dưới thời Pháp thuộc.', 'ACTIVE'),
  (4, '9786041000148', 'Truyện Kiều', 1, 2, 2016, 'Truyện thơ Nôm nổi tiếng nhất của văn học Việt Nam.', 'ACTIVE'),
  (5, '9786041000185', 'Nhà giả kim', 2, 16, 2020, 'Hành trình đi tìm kho báu và ý nghĩa cuộc sống của chàng chăn cừu Santiago.', 'ACTIVE'),
  (6, '9786041000222', 'Hoàng tử bé', 2, 1, 2019, 'Câu chuyện triết lý nhẹ nhàng dành cho cả trẻ em và người lớn.', 'ACTIVE'),
  (7, '9786041000259', 'Những người khốn khổ', 2, 2, 2015, 'Đại tiểu thuyết về tình người, công lý và sự chuộc lỗi.', 'ACTIVE'),
  (8, '9786041000296', 'Cho tôi xin một vé đi tuổi thơ', 1, 3, 2018, 'Những ký ức tuổi thơ trong veo qua lời kể của một người lớn.', 'ACTIVE'),
  (9, '9786041000333', 'Clean Code', 3, 11, 2008, 'Cẩm nang viết mã sạch, dễ đọc và dễ bảo trì.', 'ACTIVE'),
  (10, '9786041000370', 'Introduction to Algorithms', 3, 8, 2022, 'Giáo trình thuật toán toàn diện, thường gọi là CLRS.', 'ACTIVE'),
  (11, '9786041000407', 'Design Patterns', 3, 10, 1994, '23 mẫu thiết kế hướng đối tượng kinh điển.', 'ACTIVE'),
  (12, '9786041000444', 'Database System Concepts', 3, 13, 2019, 'Giáo trình cơ sở dữ liệu: mô hình quan hệ, SQL, giao dịch.', 'ACTIVE'),
  (13, '9786041000481', 'Computer Networking: A Top-Down Approach', 3, 9, 2021, 'Mạng máy tính tiếp cận từ tầng ứng dụng xuống tầng vật lý.', 'ACTIVE'),
  (14, '9786041000518', 'Python Crash Course', 3, 14, 2023, 'Học lập trình Python qua các dự án thực hành.', 'ACTIVE'),
  (15, '9786041000555', 'Lược sử thời gian', 4, 3, 2018, 'Từ Big Bang đến hố đen, vũ trụ học cho người đọc phổ thông.', 'ACTIVE'),
  (16, '9786041000592', 'Vũ trụ trong vỏ hạt dẻ', 4, 3, 2017, 'Tiếp nối Lược sử thời gian với các lý thuyết vật lý hiện đại.', 'ACTIVE'),
  (17, '9786041000629', 'Nguồn gốc các loài', 4, 6, 2016, 'Công trình đặt nền móng cho thuyết tiến hóa.', 'ACTIVE'),
  (18, '9786041000666', 'Đắc nhân tâm', 5, 4, 2020, 'Nghệ thuật giao tiếp và gây thiện cảm với mọi người.', 'ACTIVE'),
  (19, '9786041000703', 'Cha giàu cha nghèo', 5, 5, 2019, 'Bài học về tiền bạc và tư duy tài chính cá nhân.', 'ACTIVE'),
  (20, '9786041000740', 'Tư duy nhanh và chậm', 5, 5, 2021, 'Hai hệ thống tư duy chi phối phán đoán và quyết định của con người.', 'ACTIVE'),
  (21, '9786041000777', 'Nghĩ giàu làm giàu', 5, 5, 2018, 'Các nguyên tắc thành công được đúc kết từ nhiều doanh nhân.', 'ACTIVE'),
  (22, '9786041000814', 'Sapiens: Lược sử loài người', 6, 6, 2019, 'Lịch sử loài người từ thời săn bắt hái lượm đến hiện đại.', 'ACTIVE'),
  (23, '9786041000851', 'Đại Việt sử ký toàn thư', 6, 15, 2013, 'Bộ quốc sử chính thống của Đại Việt thời phong kiến.', 'ACTIVE'),
  (24, '9786041000888', 'Việt Nam sử lược', 6, 15, 2014, 'Cuốn sử Việt Nam viết bằng chữ quốc ngữ đầu tiên theo lối hiện đại.', 'ACTIVE'),
  (25, '9786041000925', 'English Grammar in Use', 7, 12, 2019, 'Sách ngữ pháp tiếng Anh có bài tập, dành cho trình độ trung cấp.', 'ACTIVE'),
  (26, '9786041000962', 'Principles of Economics', 5, 13, 2020, 'Nguyên lý kinh tế học vi mô và vĩ mô.', 'ACTIVE'),
  (27, '9786041000999', 'Lập trình Pascal căn bản', 3, 7, 2005, 'Giáo trình cũ, đã ngừng lưu hành.', 'DISCONTINUED');

INSERT INTO title_authors (title_id, author_id) VALUES
  (1, 1),
  (2, 2),
  (3, 3),
  (4, 4),
  (5, 5),
  (6, 6),
  (7, 7),
  (8, 8),
  (9, 9),
  (10, 10),
  (10, 11),
  (11, 12),
  (11, 13),
  (12, 14),
  (13, 15),
  (13, 16),
  (14, 17),
  (15, 18),
  (16, 18),
  (17, 19),
  (18, 20),
  (19, 21),
  (20, 22),
  (21, 23),
  (22, 24),
  (23, 25),
  (24, 26),
  (25, 27),
  (26, 28),
  (27, 29);

INSERT INTO storage_locations (location_id, warehouse, shelf, slot) VALUES
  (1, 'Kho A', 'A1', '1'),
  (2, 'Kho A', 'A1', '2'),
  (3, 'Kho A', 'A2', '1'),
  (4, 'Kho A', 'A2', '2'),
  (5, 'Kho B', 'B1', '1'),
  (6, 'Kho B', 'B1', '2'),
  (7, 'Kho B', 'B2', '1'),
  (8, 'Kho C (Tham khảo)', 'C1', '1');

INSERT INTO book_copies (copy_id, title_id, location_id, barcode, price, condition_status, status, acquired_date) VALUES
  (1, 1, 1, 'BK0001-01', 45000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -391 DAY)),
  (2, 1, 1, 'BK0001-02', 45000, 'NORMAL', 'BORROWED', DATE_ADD(CURDATE(), INTERVAL -214 DAY)),
  (3, 1, 1, 'BK0001-03', 45000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -464 DAY)),
  (4, 1, 1, 'BK0001-04', 45000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -726 DAY)),
  (5, 2, 1, 'BK0002-01', 68000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -109 DAY)),
  (6, 2, 1, 'BK0002-02', 68000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -134 DAY)),
  (7, 2, 1, 'BK0002-03', 68000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -900 DAY)),
  (8, 3, 1, 'BK0003-01', 52000, 'NORMAL', 'BORROWED', DATE_ADD(CURDATE(), INTERVAL -608 DAY)),
  (9, 3, 1, 'BK0003-02', 52000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -156 DAY)),
  (10, 3, 1, 'BK0003-03', 52000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -434 DAY)),
  (11, 4, 1, 'BK0004-01', 75000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -656 DAY)),
  (12, 4, 1, 'BK0004-02', 75000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -119 DAY)),
  (13, 5, 3, 'BK0005-01', 79000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -579 DAY)),
  (14, 5, 3, 'BK0005-02', 79000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -279 DAY)),
  (15, 5, 3, 'BK0005-03', 79000, 'NORMAL', 'BORROWED', DATE_ADD(CURDATE(), INTERVAL -98 DAY)),
  (16, 5, 3, 'BK0005-04', 79000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -148 DAY)),
  (17, 6, 3, 'BK0006-01', 60000, 'NORMAL', 'RESERVED', DATE_ADD(CURDATE(), INTERVAL -504 DAY)),
  (18, 6, 3, 'BK0006-02', 60000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -488 DAY)),
  (19, 6, 3, 'BK0006-03', 60000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -131 DAY)),
  (20, 7, 3, 'BK0007-01', 220000, 'NORMAL', 'LOST', DATE_ADD(CURDATE(), INTERVAL -306 DAY)),
  (21, 7, 3, 'BK0007-02', 220000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -152 DAY)),
  (22, 8, 1, 'BK0008-01', 85000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -624 DAY)),
  (23, 8, 1, 'BK0008-02', 85000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -494 DAY)),
  (24, 8, 1, 'BK0008-03', 85000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -120 DAY)),
  (25, 8, 1, 'BK0008-04', 85000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -639 DAY)),
  (26, 9, 5, 'BK0009-01', 520000, 'NORMAL', 'BORROWED', DATE_ADD(CURDATE(), INTERVAL -186 DAY)),
  (27, 9, 5, 'BK0009-02', 520000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -288 DAY)),
  (28, 9, 5, 'BK0009-03', 520000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -705 DAY)),
  (29, 10, 5, 'BK0010-01', 890000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -702 DAY)),
  (30, 10, 5, 'BK0010-02', 890000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -656 DAY)),
  (31, 10, 5, 'BK0010-03', 890000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -123 DAY)),
  (32, 11, 5, 'BK0011-01', 650000, 'NORMAL', 'BORROWED', DATE_ADD(CURDATE(), INTERVAL -650 DAY)),
  (33, 11, 5, 'BK0011-02', 650000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -659 DAY)),
  (34, 12, 5, 'BK0012-01', 780000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -466 DAY)),
  (35, 12, 5, 'BK0012-02', 780000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -110 DAY)),
  (36, 12, 5, 'BK0012-03', 780000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -286 DAY)),
  (37, 13, 5, 'BK0013-01', 820000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -107 DAY)),
  (38, 13, 5, 'BK0013-02', 820000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -630 DAY)),
  (39, 14, 5, 'BK0014-01', 560000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -196 DAY)),
  (40, 14, 5, 'BK0014-02', 560000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -356 DAY)),
  (41, 14, 5, 'BK0014-03', 560000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -489 DAY)),
  (42, 15, 4, 'BK0015-01', 98000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -207 DAY)),
  (43, 15, 4, 'BK0015-02', 98000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -613 DAY)),
  (44, 15, 4, 'BK0015-03', 98000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -180 DAY)),
  (45, 16, 4, 'BK0016-01', 120000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -644 DAY)),
  (46, 16, 4, 'BK0016-02', 120000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -375 DAY)),
  (47, 17, 4, 'BK0017-01', 185000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -633 DAY)),
  (48, 17, 4, 'BK0017-02', 185000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -895 DAY)),
  (49, 18, 7, 'BK0018-01', 76000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -758 DAY)),
  (50, 18, 7, 'BK0018-02', 76000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -245 DAY)),
  (51, 18, 7, 'BK0018-03', 76000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -165 DAY)),
  (52, 18, 7, 'BK0018-04', 76000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -655 DAY)),
  (53, 19, 7, 'BK0019-01', 98000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -644 DAY)),
  (54, 19, 7, 'BK0019-02', 98000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -714 DAY)),
  (55, 19, 7, 'BK0019-03', 98000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -252 DAY)),
  (56, 20, 7, 'BK0020-01', 189000, 'DAMAGED', 'DAMAGED', DATE_ADD(CURDATE(), INTERVAL -441 DAY)),
  (57, 20, 7, 'BK0020-02', 189000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -159 DAY)),
  (58, 20, 7, 'BK0020-03', 189000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -620 DAY)),
  (59, 21, 7, 'BK0021-01', 110000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -789 DAY)),
  (60, 21, 7, 'BK0021-02', 110000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -124 DAY)),
  (61, 22, 8, 'BK0022-01', 229000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -637 DAY)),
  (62, 22, 8, 'BK0022-02', 229000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -121 DAY)),
  (63, 22, 8, 'BK0022-03', 229000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -693 DAY)),
  (64, 22, 8, 'BK0022-04', 229000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -270 DAY)),
  (65, 23, 8, 'BK0023-01', 350000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -568 DAY)),
  (66, 23, 8, 'BK0023-02', 350000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -756 DAY)),
  (67, 24, 8, 'BK0024-01', 135000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -604 DAY)),
  (68, 24, 8, 'BK0024-02', 135000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -497 DAY)),
  (69, 25, 2, 'BK0025-01', 215000, 'NORMAL', 'BORROWED', DATE_ADD(CURDATE(), INTERVAL -855 DAY)),
  (70, 25, 2, 'BK0025-02', 215000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -381 DAY)),
  (71, 25, 2, 'BK0025-03', 215000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -536 DAY)),
  (72, 26, 7, 'BK0026-01', 700000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -659 DAY)),
  (73, 26, 7, 'BK0026-02', 700000, 'NORMAL', 'AVAILABLE', DATE_ADD(CURDATE(), INTERVAL -524 DAY)),
  (74, 27, 5, 'BK0027-01', 40000, 'NORMAL', 'INACTIVE', DATE_ADD(CURDATE(), INTERVAL -430 DAY));

INSERT INTO reservations (reservation_id, reader_id, title_id, copy_id, status, created_at, processed_by, processed_at, hold_expire_at) VALUES
  (1, 4, 6, 17, 'APPROVED', DATE_ADD(NOW(), INTERVAL -1 DAY), 2, DATE_ADD(NOW(), INTERVAL -1 DAY), DATE_ADD(NOW(), INTERVAL 2 DAY)),
  (2, 6, 3, NULL, 'PENDING', DATE_ADD(NOW(), INTERVAL 0 DAY), NULL, NULL, NULL),
  (3, 7, 15, NULL, 'EXPIRED', DATE_ADD(NOW(), INTERVAL -14 DAY), 2, DATE_ADD(NOW(), INTERVAL -13 DAY), DATE_ADD(NOW(), INTERVAL -10 DAY)),
  (4, 4, 25, 69, 'FULFILLED', DATE_ADD(NOW(), INTERVAL -7 DAY), 2, DATE_ADD(NOW(), INTERVAL -7 DAY), DATE_ADD(NOW(), INTERVAL -4 DAY));

INSERT INTO loans (loan_id, reader_id, card_id, librarian_id, loan_date, note) VALUES
  (1, 6, 3, 2, DATE_ADD(NOW(), INTERVAL -60 DAY), NULL),
  (2, 4, 1, 2, DATE_ADD(NOW(), INTERVAL -58 DAY), NULL),
  (3, 5, 2, 3, DATE_ADD(NOW(), INTERVAL -55 DAY), NULL),
  (4, 6, 3, 2, DATE_ADD(NOW(), INTERVAL -45 DAY), NULL),
  (5, 4, 1, 3, DATE_ADD(NOW(), INTERVAL -40 DAY), NULL),
  (6, 7, 4, 2, DATE_ADD(NOW(), INTERVAL -30 DAY), NULL),
  (7, 6, 3, 2, DATE_ADD(NOW(), INTERVAL -28 DAY), NULL),
  (8, 5, 2, 3, DATE_ADD(NOW(), INTERVAL -35 DAY), NULL),
  (9, 4, 1, 2, DATE_ADD(NOW(), INTERVAL -20 DAY), NULL),
  (10, 6, 3, 3, DATE_ADD(NOW(), INTERVAL -12 DAY), NULL),
  (11, 4, 1, 2, DATE_ADD(NOW(), INTERVAL -5 DAY), NULL),
  (12, 5, 2, 3, DATE_ADD(NOW(), INTERVAL -20 DAY), NULL),
  (13, 6, 3, 2, DATE_ADD(NOW(), INTERVAL -3 DAY), NULL),
  (14, 6, 3, 2, DATE_ADD(NOW(), INTERVAL -10 DAY), NULL);

INSERT INTO return_receipts (return_id, librarian_id, return_date, note) VALUES
  (1, 2, DATE_ADD(NOW(), INTERVAL -50 DAY), NULL),
  (2, 2, DATE_ADD(NOW(), INTERVAL -48 DAY), NULL),
  (3, 2, DATE_ADD(NOW(), INTERVAL -47 DAY), NULL),
  (4, 2, DATE_ADD(NOW(), INTERVAL -45 DAY), NULL),
  (5, 3, DATE_ADD(NOW(), INTERVAL -44 DAY), NULL),
  (6, 3, DATE_ADD(NOW(), INTERVAL -43 DAY), NULL),
  (7, 3, DATE_ADD(NOW(), INTERVAL -42 DAY), NULL),
  (8, 2, DATE_ADD(NOW(), INTERVAL -33 DAY), NULL),
  (9, 2, DATE_ADD(NOW(), INTERVAL -33 DAY), NULL),
  (10, 3, DATE_ADD(NOW(), INTERVAL -30 DAY), NULL),
  (11, 3, DATE_ADD(NOW(), INTERVAL -28 DAY), NULL),
  (12, 3, DATE_ADD(NOW(), INTERVAL -26 DAY), NULL),
  (13, 2, DATE_ADD(NOW(), INTERVAL -10 DAY), NULL),
  (14, 2, DATE_ADD(NOW(), INTERVAL -9 DAY), NULL),
  (15, 3, DATE_ADD(NOW(), INTERVAL -20 DAY), NULL),
  (16, 2, DATE_ADD(NOW(), INTERVAL -8 DAY), NULL),
  (17, 2, DATE_ADD(NOW(), INTERVAL -7 DAY), NULL),
  (18, 3, DATE_ADD(NOW(), INTERVAL -3 DAY), NULL),
  (19, 3, DATE_ADD(NOW(), INTERVAL -2 DAY), NULL);

INSERT INTO loan_items (loan_item_id, loan_id, copy_id, reservation_id, return_id, due_date, return_date, renewal_count, condition_on_loan, condition_on_return, status) VALUES
  (1, 1, 61, NULL, 1, DATE_ADD(CURDATE(), INTERVAL -46 DAY), DATE_ADD(NOW(), INTERVAL -50 DAY), 0, 'NORMAL', 'NORMAL', 'RETURNED'),
  (2, 1, 13, NULL, 2, DATE_ADD(CURDATE(), INTERVAL -46 DAY), DATE_ADD(NOW(), INTERVAL -48 DAY), 0, 'NORMAL', 'NORMAL', 'RETURNED'),
  (3, 2, 49, NULL, 3, DATE_ADD(CURDATE(), INTERVAL -44 DAY), DATE_ADD(NOW(), INTERVAL -47 DAY), 0, 'NORMAL', 'NORMAL', 'RETURNED'),
  (4, 2, 27, NULL, 4, DATE_ADD(CURDATE(), INTERVAL -44 DAY), DATE_ADD(NOW(), INTERVAL -45 DAY), 0, 'NORMAL', 'NORMAL', 'RETURNED'),
  (5, 3, 62, NULL, 5, DATE_ADD(CURDATE(), INTERVAL -41 DAY), DATE_ADD(NOW(), INTERVAL -44 DAY), 0, 'NORMAL', 'NORMAL', 'RETURNED'),
  (6, 3, 1, NULL, 6, DATE_ADD(CURDATE(), INTERVAL -41 DAY), DATE_ADD(NOW(), INTERVAL -43 DAY), 0, 'NORMAL', 'NORMAL', 'RETURNED'),
  (7, 3, 22, NULL, 7, DATE_ADD(CURDATE(), INTERVAL -41 DAY), DATE_ADD(NOW(), INTERVAL -42 DAY), 0, 'NORMAL', 'NORMAL', 'RETURNED'),
  (8, 4, 50, NULL, 8, DATE_ADD(CURDATE(), INTERVAL -31 DAY), DATE_ADD(NOW(), INTERVAL -33 DAY), 0, 'NORMAL', 'NORMAL', 'RETURNED'),
  (9, 4, 42, NULL, 9, DATE_ADD(CURDATE(), INTERVAL -31 DAY), DATE_ADD(NOW(), INTERVAL -33 DAY), 0, 'NORMAL', 'NORMAL', 'RETURNED'),
  (10, 5, 14, NULL, 10, DATE_ADD(CURDATE(), INTERVAL -26 DAY), DATE_ADD(NOW(), INTERVAL -30 DAY), 0, 'NORMAL', 'NORMAL', 'RETURNED'),
  (11, 5, 61, NULL, 11, DATE_ADD(CURDATE(), INTERVAL -26 DAY), DATE_ADD(NOW(), INTERVAL -28 DAY), 0, 'NORMAL', 'NORMAL', 'RETURNED'),
  (12, 5, 56, NULL, 12, DATE_ADD(CURDATE(), INTERVAL -26 DAY), DATE_ADD(NOW(), INTERVAL -26 DAY), 0, 'NORMAL', 'DAMAGED', 'RETURNED'),
  (13, 6, 51, NULL, 13, DATE_ADD(CURDATE(), INTERVAL -16 DAY), DATE_ADD(NOW(), INTERVAL -10 DAY), 0, 'NORMAL', 'NORMAL', 'RETURNED'),
  (14, 7, 23, NULL, 14, DATE_ADD(CURDATE(), INTERVAL -14 DAY), DATE_ADD(NOW(), INTERVAL -9 DAY), 0, 'NORMAL', 'NORMAL', 'RETURNED'),
  (15, 8, 20, NULL, 15, DATE_ADD(CURDATE(), INTERVAL -21 DAY), DATE_ADD(NOW(), INTERVAL -20 DAY), 0, 'NORMAL', 'LOST', 'LOST'),
  (16, 9, 39, NULL, 16, DATE_ADD(CURDATE(), INTERVAL -6 DAY), DATE_ADD(NOW(), INTERVAL -8 DAY), 0, 'NORMAL', 'NORMAL', 'RETURNED'),
  (17, 9, 34, NULL, 17, DATE_ADD(CURDATE(), INTERVAL -6 DAY), DATE_ADD(NOW(), INTERVAL -7 DAY), 0, 'NORMAL', 'NORMAL', 'RETURNED'),
  (18, 10, 29, NULL, 18, DATE_ADD(CURDATE(), INTERVAL 2 DAY), DATE_ADD(NOW(), INTERVAL -3 DAY), 0, 'NORMAL', 'NORMAL', 'RETURNED'),
  (19, 10, 63, NULL, 19, DATE_ADD(CURDATE(), INTERVAL 2 DAY), DATE_ADD(NOW(), INTERVAL -2 DAY), 0, 'NORMAL', 'NORMAL', 'RETURNED'),
  (20, 11, 26, 4, NULL, DATE_ADD(CURDATE(), INTERVAL 9 DAY), NULL, 0, 'NORMAL', NULL, 'BORROWING'),
  (21, 11, 69, 4, NULL, DATE_ADD(CURDATE(), INTERVAL 9 DAY), NULL, 0, 'NORMAL', NULL, 'BORROWING'),
  (22, 12, 2, NULL, NULL, DATE_ADD(CURDATE(), INTERVAL -6 DAY), NULL, 0, 'NORMAL', NULL, 'BORROWING'),
  (23, 12, 15, NULL, NULL, DATE_ADD(CURDATE(), INTERVAL -6 DAY), NULL, 0, 'NORMAL', NULL, 'BORROWING'),
  (24, 13, 8, NULL, NULL, DATE_ADD(CURDATE(), INTERVAL 11 DAY), NULL, 0, 'NORMAL', NULL, 'BORROWING'),
  (25, 14, 32, NULL, NULL, DATE_ADD(CURDATE(), INTERVAL 11 DAY), NULL, 1, 'NORMAL', NULL, 'BORROWING');

INSERT INTO renewals (renewal_id, loan_item_id, requested_at, old_due_date, new_due_date, approve_mode, status, reject_reason) VALUES
  (1, 25, DATE_ADD(NOW(), INTERVAL -1 DAY), DATE_ADD(CURDATE(), INTERVAL 4 DAY), DATE_ADD(CURDATE(), INTERVAL 11 DAY), 'AUTO', 'APPROVED', NULL);

INSERT INTO fines (fine_id, loan_item_id, reader_id, policy_id, overdue_days, overdue_amount, damage_level, damage_amount, total_amount, reason, status, created_at) VALUES
  (1, 12, 4, 1, 0, 0, 50, 94500.0, 94500.0, 'Hỏng 50%', 'PAID', DATE_ADD(NOW(), INTERVAL -26 DAY)),
  (2, 13, 7, 1, 6, 12000, NULL, 0, 12000, 'Quá hạn', 'UNPAID', DATE_ADD(NOW(), INTERVAL -10 DAY)),
  (3, 14, 6, 1, 5, 10000, NULL, 0, 10000, 'Quá hạn', 'PAID', DATE_ADD(NOW(), INTERVAL -9 DAY)),
  (4, 15, 5, 1, 1, 2000, NULL, 220000.0, 222000.0, 'Quá hạn + Mất sách', 'PAID', DATE_ADD(NOW(), INTERVAL -20 DAY));

INSERT INTO payments (payment_id, fine_id, received_by, amount, method, paid_at) VALUES
  (1, 1, 3, 94500.0, 'CASH', DATE_ADD(NOW(), INTERVAL -26 DAY)),
  (2, 3, 2, 10000, 'CASH', DATE_ADD(NOW(), INTERVAL -9 DAY)),
  (3, 4, 3, 222000.0, 'CASH', DATE_ADD(NOW(), INTERVAL -20 DAY));

SET FOREIGN_KEY_CHECKS = 1;
