<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require dirname(__DIR__) . '/config/bootstrap.php';
$pdo=db();
if ((int)query_one('SELECT COUNT(*) AS c FROM users')['c']>0) {
    exit("Database đã có tài khoản. Seed không chạy để tránh ghi đè.\n");
}
$pdo->beginTransaction();
try {
    $branches=[['Cơ sở Tân Phú','140 Lê Trọng Tấn, Phường Tây Thạnh, TP. Hồ Chí Minh'],['Cơ sở Trung tâm','12 Nguyễn Văn Bảo, Phường Gò Vấp, TP. Hồ Chí Minh'],['Cơ sở Quận 7','25 Nguyễn Hữu Thọ, TP. Hồ Chí Minh']];
    $insertBranch=$pdo->prepare('INSERT INTO branches(branch_name,address) VALUES (?,?)');
    foreach ($branches as $b) $insertBranch->execute($b);
    $faker=class_exists(Faker\Factory::class)?Faker\Factory::create('vi_VN'):null;
    $subjects=['Lập trình Web','Cơ sở dữ liệu','Cấu trúc dữ liệu','Mạng máy tính','Trí tuệ nhân tạo','Kỹ thuật phần mềm','Lập trình Java'];
    $insertTeacher=$pdo->prepare('INSERT INTO teachers(full_name,email,phone,expertise) VALUES (?,?,?,?)');
    $names=['Nguyễn Văn An','Trần Thị Bình','Lê Minh Châu','Phạm Quốc Dũng','Võ Thị Hà','Đỗ Anh Khoa','Huỳnh Ngọc Lan','Bùi Minh Quân'];
    $teacherIds=[];
    foreach ($names as $i=>$name) {
        $exp=$subjects[$i%count($subjects)].', '.$subjects[($i+1)%count($subjects)];
        $insertTeacher->execute([$name,'giangvien'.($i+1).'@huit.test','090'.str_pad((string)(1234567+$i),7,'0',STR_PAD_LEFT),$exp]);
        $teacherIds[]=(int)$pdo->lastInsertId();
    }
    $password=password_hash('Admin@123',PASSWORD_DEFAULT);
    $pdo->prepare('INSERT INTO users(teacher_id,full_name,email,password_hash,role) VALUES (NULL,?,?,?,?)')->execute(['HUIT Admin','admin@huit.test',$password,'admin']);
    $pdo->prepare('INSERT INTO users(teacher_id,full_name,email,password_hash,role) VALUES (?,?,?,?,?)')->execute([$teacherIds[0],$names[0],'teacher@huit.test',password_hash('Teacher@123', PASSWORD_DEFAULT),'teacher']);
    $s=$pdo->prepare('INSERT INTO schedules(teacher_id,branch_id,subject_name,room,day_of_week,shift_periods,start_time,end_time) VALUES (?,?,?,?,?,?,?,?)');
    $sample=[[$teacherIds[0],1,$subjects[0],'A101',1,'1-3'],[$teacherIds[1],1,$subjects[1],'A102',1,'1-3'],[$teacherIds[2],2,$subjects[2],'B201',2,'4-6'],[$teacherIds[3],2,$subjects[3],'B202',3,'7-9'],[$teacherIds[4],3,$subjects[4],'C301',4,'1-3'],[$teacherIds[5],1,$subjects[5],'A103',5,'7-9'],[$teacherIds[6],2,$subjects[6],'B203',6,'4-6']];
    foreach ($sample as $entry) { [$start,$end]=shift_options()[$entry[5]]; $s->execute([...$entry,$start,$end]); }
    $pdo->commit();
    echo "Seed thành công.\nAdmin: admin@huit.test / Admin@123\nGiảng viên: teacher@huit.test / Teacher@123\nĐỔI MẬT KHẨU trước khi triển khai thực tế.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
