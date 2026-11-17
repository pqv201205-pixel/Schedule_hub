<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require dirname(__DIR__) . '/config/bootstrap.php';
if ($argc !== 5) { fwrite(STDERR,"Cách dùng: php scripts/create-user.php <teacher_id> <email> <ten_tai_khoan> <mat_khau>\n"); exit(1); }
[$script,$teacherId,$email,$name,$password]=$argv;
if ((int)$teacherId<=0 || !validate_email($email) || trim($name)==='' || strlen($password)<8) { fwrite(STDERR,"Dữ liệu không hợp lệ; mật khẩu tối thiểu 8 ký tự.\n");exit(1); }
if (!query_one('SELECT id FROM teachers WHERE id=?',[(int)$teacherId])) { fwrite(STDERR,"Không tìm thấy giảng viên.\n");exit(1); }
try {
    execute_sql('INSERT INTO users(teacher_id,full_name,email,password_hash,role) VALUES (?,?,?,?,?)',[(int)$teacherId,$name,$email,password_hash($password,PASSWORD_DEFAULT),'teacher']);
    echo "Đã tạo tài khoản giảng viên.\n";
} catch (PDOException $e) { fwrite(STDERR,"Không thể tạo tài khoản (kiểm tra email và teacher_id đã dùng chưa).\n");exit(1); }
