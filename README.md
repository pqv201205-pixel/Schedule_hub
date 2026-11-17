# HUIT Schedule Hub

Ứng dụng PHP + MySQL để **quản lý và phân lịch giảng dạy đa cơ sở**, theo đề thực hành 180 phút.

## Chức năng

- **Quản trị viên:** CRUD cơ sở, giảng viên, lịch dạy; lọc lịch theo cơ sở/giảng viên/thứ.
- **Phân lịch thông minh:** kiểm tra giảng viên trùng giờ **kể cả khác cơ sở** và kiểm tra phòng trùng giờ trong cùng cơ sở. Các ca liền nhau không tính là trùng.
- **Gợi ý giảng viên:** nhập tên môn, thứ và ca; hệ thống gợi ý giảng viên có chuyên môn trùng/bao hàm tên môn (phân cách chuyên môn bằng dấu phẩy), chưa bận ca đó. Đây là **gợi ý dựa trên từ khóa**, không phải mô hình AI.
- **Giảng viên:** đăng nhập và xem thời khóa biểu cá nhân theo **tuần/tháng** (lịch định kỳ lặp lại mỗi tuần).
- **Bảo mật cơ bản:** `password_hash`/`password_verify`, phiên đăng nhập, phân quyền server-side, CSRF cho biểu mẫu, truy vấn PDO prepared statements, escape đầu ra HTML.
- **GitHub Actions:** `composer validate`, PHP syntax lint và kiểm thử bộ phát hiện lịch trùng.

## Yêu cầu môi trường

- Windows + Laragon (PHP **8.1+**, MySQL 8+ hoặc tương đương) và Composer 2.
- Bật extensions `pdo_mysql`, `mbstring`.
- Khuyến nghị dùng Git để push lên GitHub.

## Cài đặt trên Laragon

1. Giải nén thư mục project vào `C:\\laragon\\www\\huit-schedule-hub` (đúng tên `huit-schedule-hub`).
2. Mở Laragon → **Start All**. Vào Terminal (Laragon) ở thư mục project:
   ```bash
   composer install
   ```
3. Sao chép `.env.example` thành `.env`. Sửa `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` tương ứng MySQL máy bạn. `.env` **không được push lên GitHub**.
4. Mở HeidiSQL → kết nối MySQL → mở và chạy `database/schema.sql` (tạo `huit_db` và 4 bảng).
5. Trong Laragon Terminal, chạy:
   ```bash
   php scripts/seed.php
   ```
6. Laragon → **Menu → www → huit-schedule-hub**, hoặc truy cập `http://huit-schedule-hub.test` sau khi bật Auto Virtual Hosts và restart Laragon nếu cần. Có thể dùng `http://localhost/huit-schedule-hub/` nếu không có virtual host.
7. Đăng nhập bằng tài khoản demo:
   - Quản trị viên: **admin@huit.test** / **Admin@123**
   - Giảng viên: **teacher@huit.test** / **Teacher@123**

> **Lưu ý về tên miền trong đề:** đề ghi `huit-schedule.test`, còn tên thư mục `huit-schedule-hub` thường sinh virtual host `huit-schedule-hub.test`. Để dùng chính xác **http://huit-schedule.test**, tạo hoặc sửa Apache Virtual Host trong Laragon trỏ `DocumentRoot` tới `C:/laragon/www/huit-schedule-hub` và đặt `ServerName huit-schedule.test`, thêm hosts entry `127.0.0.1 huit-schedule.test`, rồi restart Apache. Hoặc đổi tên thư mục thành `huit-schedule` (khi Laragon tự tạo host `huit-schedule.test`).

## Tạo tài khoản cho giảng viên khác

Xem ID trong bảng `teachers` bằng HeidiSQL, sau đó (trong terminal, không chạy qua trình duyệt):

```bash
php scripts/create-user.php 2 teacher2@example.com "Tên giảng viên" "MatKhauMoi@123"
```

Mỗi giảng viên chỉ được liên kết một tài khoản. Mật khẩu tối thiểu 8 ký tự.

## Dữ liệu và quy tắc phân lịch

- `branches(id, branch_name, address)`
- `teachers(id, full_name, email, phone, expertise)`
- `schedules(id, teacher_id, branch_id, subject_name, room, day_of_week, shift_periods, start_time, end_time)`
- `users(id, teacher_id, full_name, email, password_hash, role)` bổ sung để đăng nhập phân quyền.
- `day_of_week`: 1=Thứ Hai ... 7=Chủ Nhật.
- Các ca mặc định: tiết 1–3 (07:00–09:30), 4–6 (09:40–12:10), 7–9 (13:00–15:30), 10–12 (15:40–18:10), 13–15 (18:20–20:50). **Đây là khung giờ minh họa, chỉnh trong `app/functions.php` theo lịch HUIT thực tế nếu cần.**
- Lịch **lặp lại hàng tuần**, chưa có học kỳ, ngày bắt đầu/kết thúc, ngày nghỉ hoặc tính thời gian di chuyển giữa hai cơ sở. Đề gốc không nêu các trường đó.
- Khi lưu lịch, dùng MySQL advisory lock theo ngày để hạn chế hai người cùng tạo ca trùng song song.

## Kiểm thử

```bash
composer lint
composer test
```

`composer test` thực thi 7 kiểm tra thuật toán phát hiện lịch trùng, **không cần kết nối database**. Bạn cần kiểm thử thủ công trên Laragon để xác nhận CRUD, đăng nhập, gợi ý và giao diện với MySQL thật.

## Git & GitHub

```bash
git init
git add .
git commit -m "Initial HUIT Schedule Hub project"
git branch -M main
git remote add origin https://github.com/USERNAME/huit-schedule-hub.git
git push -u origin main
```

Thay `USERNAME` bằng tài khoản GitHub của bạn; tạo repository trống trước khi push. File `.gitignore` đã loại `/vendor` và `.env`. Mỗi lần push/PR, workflow `.github/workflows/php-ci.yml` sẽ tự chạy trên GitHub Actions.

## Giới hạn và lưu ý

- `fakerphp/faker` chỉ dùng để tạo tên dữ liệu mẫu khi có cài dependency dev; nếu không có sẽ dùng tên cố định.
- Chuyên môn gợi ý theo từ khóa, không hiểu từ đồng nghĩa; có thể mở rộng sang bảng `subjects` + bảng `teacher_subjects` để so khớp chính xác hơn.
- Sử dụng tên email demo và thay đổi mật khẩu trước khi triển khai thực tế.
- Chưa có chức năng quản lý tài khoản qua giao diện, reset mật khẩu, thông báo email, điều phối theo khoảng cách giữa cơ sở hay tổ chức học kỳ.

## Cấu trúc thư mục

```text
huit-schedule-hub/
├── .github/workflows/php-ci.yml
├── app/                 # Kết nối, hàm chung, thuật toán kiểm tra trùng
├── assets/css/          # Giao diện responsive
├── assets/js/           # Gợi ý giảng viên qua fetch
├── config/bootstrap.php # Load .env / session / helpers
├── database/schema.sql  # Cơ sở dữ liệu MySQL
├── scripts/             # Seed, tạo user, lint
├── tests/               # Unit test lịch trùng
├── index.php            # Router + trang quản trị / giảng viên
├── .env.example
├── .gitignore
├── composer.json
├── README.md
└── LICENSE
```
