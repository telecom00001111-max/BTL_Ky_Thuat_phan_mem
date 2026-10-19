# BTL_Ky_Thuat_phan_mem
Nhóm gồm 3 thành viên :
22010132 – Nguyễn Việt Quang
24103783 – Nguyễn Đình Quân <Trưởng nhóm>
24100226 – Nguyễn Tiến Vinh

Phân công chi tiết:
Nguyễn Việt Quang : Dựng website, vẽ biểu đồ Use case
Nguyễn Đình Quân : Dựng khung SRS, vẽ Sequence Diagram + ERD, Thuyết trình
Nguyễn Tiến Vinh : Dựng Slide , viết mô tả chi tiết từng FR & NFR
Link video thuyết trình : https://drive.google.com/file/d/1hGTZGjXHtzRuv439_UfPAo4GmiiD60YC/view?usp=sharing&t=7.388
# 📚 Hệ thống quản lý thư viện sách

## 1. Giới thiệu dự án

Dự án **Hệ thống quản lý thư viện sách** được xây dựng nhằm hỗ trợ việc quản lý tài liệu, sách, độc giả và quá trình mượn trả sách trong thư viện.

Hệ thống giúp tự động hóa các công việc như:

- Quản lý thông tin sách.
- Quản lý độc giả.
- Theo dõi việc mượn và trả sách.
- Kiểm tra tình trạng sách.
- Tìm kiếm sách nhanh chóng.
- Lưu trữ lịch sử giao dịch.

Mục tiêu của dự án là xây dựng một hệ thống đơn giản, dễ sử dụng, giúp nâng cao hiệu quả quản lý thư viện.

---

# 2. Mục tiêu dự án

## Mục tiêu chính

- Xây dựng phần mềm quản lý thư viện.
- Giảm việc quản lý thủ công bằng giấy tờ.
- Tăng tốc độ tìm kiếm và xử lý thông tin.
- Đảm bảo dữ liệu sách và độc giả được lưu trữ chính xác.

## Mục tiêu kỹ thuật

- Thiết kế cơ sở dữ liệu quản lý thư viện.
- Xây dựng giao diện người dùng.
- Xử lý các nghiệp vụ mượn/trả sách.
- Áp dụng phân quyền người dùng.

---

# 3. Chức năng hệ thống

## 3.1 Quản lý sách

Hệ thống cho phép:

- Thêm sách mới.
- Cập nhật thông tin sách.
- Xóa sách.
- Tìm kiếm sách.
- Kiểm tra số lượng sách còn lại.

Thông tin sách bao gồm:

|Thuộc tính|Mô tả|
|-|-|
|Mã sách|ID duy nhất|
|Tên sách|Tên tài liệu|
|Tác giả|Người viết|
|Thể loại|Loại sách|
|Nhà xuất bản|Thông tin xuất bản|
|Năm xuất bản|Năm phát hành|
|Số lượng|Số sách hiện có|

---

# 3.2 Quản lý độc giả

Chức năng:

- Thêm độc giả.
- Sửa thông tin độc giả.
- Xóa độc giả.
- Tra cứu thông tin người đọc.

Thông tin độc giả:

|Thuộc tính|Mô tả|
|-|-|
|Mã độc giả|ID người dùng|
|Họ tên|Tên người đọc|
|Ngày sinh|Thông tin cá nhân|
|Số điện thoại|Liên hệ|
|Email|Thông tin liên lạc|

---

# 3.3 Quản lý mượn trả sách

Hệ thống quản lý:

- Người mượn sách.
- Ngày mượn.
- Ngày trả.
- Trạng thái sách.
- Sách quá hạn.

Quy trình:

```
Độc giả
   |
   ↓
Chọn sách
   |
   ↓
Kiểm tra số lượng
   |
   ↓
Tạo phiếu mượn
   |
   ↓
Trả sách
   |
   ↓
Cập nhật trạng thái
```

---

# 4. Kiến trúc hệ thống

Mô hình:

```
              Người dùng

                  |
                  |

              Giao diện

                  |
                  |

          Xử lý nghiệp vụ

                  |
                  |

             Database
```

---

# 5. Công nghệ sử dụng

## Backend

Ví dụ:

- Java Spring Boot / Node.js / PHP / Python Django

## Frontend

- HTML
- CSS
- JavaScript
- ReactJS (nếu có)

## Database

- MySQL
- PostgreSQL

---

# 6. Thiết kế cơ sở dữ liệu

Các bảng chính:

## Bảng SACH

```
SACH
|
|-- MaSach
|-- TenSach
|-- TacGia
|-- TheLoai
|-- SoLuong
```

---

## Bảng DOCGIA

```
DOCGIA
|
|-- MaDocGia
|-- HoTen
|-- SDT
|-- Email
```

---

## Bảng MUON_TRA

```
MUON_TRA
|
|-- MaPhieu
|-- MaSach
|-- MaDocGia
|-- NgayMuon
|-- NgayTra
|-- TrangThai
```

---

# 7. Quy trình hoạt động

## Đăng nhập

```
Người dùng
     |
     ↓
Nhập tài khoản
     |
     ↓
Kiểm tra thông tin
     |
     ↓
Truy cập hệ thống
```

---

## Mượn sách

```
Tìm sách

↓

Kiểm tra còn sách

↓

Tạo phiếu mượn

↓

Giảm số lượng sách

↓

Lưu dữ liệu
```

---

## Trả sách

```
Nhận sách trả

↓

Kiểm tra hạn trả

↓

Cập nhật trạng thái

↓

Tăng số lượng sách
```

---

# 8. Các chức năng mo rộng

Có thể phát triển thêm:

- Đăng nhập cho sinh viên.
- Đăng ký mượn sách online.
- Gửi email nhắc trả sách.
- Quản lý tiền phạt.
- Thống kê sách được mượn nhiều nhất.
- Xuất báo cáo PDF.
- Quản lý nhiều chi nhánh thư viện.

---

# 9. Kết quả mong muốn

Sau khi hoàn thành dự án:

- Người quản lý có thể quản lý toàn bộ sách.
- Độc giả có thể tìm kiếm và mượn sách dễ dàng.
- Giảm thời gian xử lý thủ công.
- Dữ liệu được lưu trữ tập trung.
- Có thể mở rộng thành hệ thống thư viện thực tế.

---

# 10. Hướng dẫn chạy dự án


# 12. Tác giả

**Tên sinh viên:** 

**Môn học:** Phát triển phần mềm 

**Năm thực hiện:** 2026
