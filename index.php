<?php
declare(strict_types=1);
require __DIR__ . '/config/bootstrap.php';
$page = (string)($_GET['page'] ?? 'dashboard');
$action = (string)($_POST['action'] ?? '');

if ($page === 'logout') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }
    csrf_check();
    $_SESSION = [];
    session_regenerate_id(true);
    go('login');
}

if ($page === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $user = query_one('SELECT * FROM users WHERE email = ?', [input('email')]);
    if ($user && password_verify(input('password'), $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = ['id'=>(int)$user['id'], 'teacher_id'=>$user['teacher_id'] !== null ? (int)$user['teacher_id'] : null, 'name'=>$user['full_name'], 'role'=>$user['role']];
        go('dashboard');
    }
    errors('Email hoặc mật khẩu chưa đúng.');
    go('login');
}

if ($page === 'login' && me()) go('dashboard');
if ($page !== 'login') require_login();

if ($page === 'suggest') {
    require_admin();
    header('Content-Type: application/json; charset=utf-8');
    $subject = trim((string)($_GET['subject'] ?? ''));
    $day = (int)($_GET['day'] ?? 0);
    $shift = (string)($_GET['shift'] ?? '');
    $exclude = (int)($_GET['exclude'] ?? 0);
    $options = shift_options();
    if ($subject === '' || $day < 1 || $day > 7 || !isset($options[$shift])) {
        echo json_encode(['teachers'=>[], 'message'=>'Nhập tên môn, thứ và buổi học để xem gợi ý.'], JSON_UNESCAPED_UNICODE); exit;
    }
    [$start, $end] = $options[$shift];
    $teachers = query_all('SELECT id, full_name, expertise FROM teachers ORDER BY full_name');
    $scheduled = query_all('SELECT * FROM schedules WHERE day_of_week=? AND start_time < ? AND end_time > ?', [$day,$end,$start]);
    $match = [];
    foreach ($teachers as $teacher) {
        if (!subject_matches($teacher['expertise'], $subject)) continue;
        $busy = false;
        foreach ($scheduled as $entry) {
            if ((int)$entry['id'] !== $exclude && (int)$entry['teacher_id'] === (int)$teacher['id']) { $busy = true; break; }
        }
        if (!$busy) $match[] = ['id'=>(int)$teacher['id'],'name'=>$teacher['full_name'],'expertise'=>$teacher['expertise']];
    }
    echo json_encode(['teachers'=>$match, 'message'=>count($match) ? 'Giảng viên phù hợp và còn trống lịch:' : 'Không tìm thấy giảng viên phù hợp còn trống lịch.'], JSON_UNESCAPED_UNICODE); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page !== 'login') {
    csrf_check();
    require_admin();
    try {
        if ($action === 'branch_save') {
            $id = positive_id('id'); $name=input('branch_name'); $address=input('address');
            if ($name === '' || $address === '' || mb_strlen($name) > 160 || mb_strlen($address) > 255) throw new InvalidArgumentException('Tên cơ sở và địa chỉ không được trống hoặc quá dài.');
            if ($id) execute_sql('UPDATE branches SET branch_name=?,address=? WHERE id=?',[$name,$address,$id]);
            else execute_sql('INSERT INTO branches(branch_name,address) VALUES (?,?)',[$name,$address]);
            flash('success','Đã lưu thông tin cơ sở.'); go('branches');
        }
        if ($action === 'branch_delete') {
            execute_sql('DELETE FROM branches WHERE id=?',[positive_id('id')]);
            flash('success','Đã xóa cơ sở.'); go('branches');
        }
        if ($action === 'teacher_save') {
            $id=positive_id('id'); $name=input('full_name'); $email=input('email'); $phone=input('phone'); $expertise=input('expertise');
            if ($name === '' || !validate_email($email) || $expertise === '' || mb_strlen($expertise) > 500 || mb_strlen($name) > 160 || mb_strlen($phone) > 30) throw new InvalidArgumentException('Vui lòng nhập tên, email hợp lệ và chuyên môn (phân cách bằng dấu phẩy).');
            if ($id) execute_sql('UPDATE teachers SET full_name=?,email=?,phone=?,expertise=? WHERE id=?',[$name,$email,$phone,$expertise,$id]);
            else execute_sql('INSERT INTO teachers(full_name,email,phone,expertise) VALUES (?,?,?,?)',[$name,$email,$phone,$expertise]);
            flash('success','Đã lưu giảng viên.'); go('teachers');
        }
        if ($action === 'teacher_delete') {
            execute_sql('DELETE FROM teachers WHERE id=?',[positive_id('id')]);
            flash('success','Đã xóa giảng viên.'); go('teachers');
        }
        if ($action === 'schedule_delete') {
            execute_sql('DELETE FROM schedules WHERE id=?',[positive_id('id')]);
            flash('success','Đã xóa lịch dạy.'); go('schedules');
        }
        if ($action === 'schedule_save') {
            $id=positive_id('id'); $teacher=positive_id('teacher_id'); $branch=positive_id('branch_id');
            $subject=input('subject_name'); $room=input('room'); $day=(int)input('day_of_week'); $shift=input('shift_periods');
            $options=shift_options();
            if (!$teacher || !$branch || $subject==='' || $room==='' || $day<1 || $day>7 || !isset($options[$shift]) || mb_strlen($subject)>180 || mb_strlen($room)>60) {
                throw new InvalidArgumentException('Dữ liệu lịch dạy chưa đầy đủ hoặc không hợp lệ.');
            }
            if (!query_one('SELECT id FROM teachers WHERE id=?',[$teacher]) || !query_one('SELECT id FROM branches WHERE id=?',[$branch])) {
                throw new InvalidArgumentException('Cơ sở hoặc giảng viên không tồn tại.');
            }
            [$start,$end]=$options[$shift];
            $pdo=db();
            // Khoá theo thứ trong tuần để hai quản trị viên không thể cùng tạo lịch xung đột.
            $lockName='huit_schedule_day_'.$day;
            $lock=query_one('SELECT GET_LOCK(?,5) AS acquired',[$lockName]);
            if ((int)($lock['acquired'] ?? 0)!==1) throw new RuntimeException('Hệ thống đang bận cập nhật lịch. Vui lòng thử lại.');
            try {
                $pdo->beginTransaction();
                $existing=query_all('SELECT id,teacher_id,branch_id,room,day_of_week,start_time,end_time FROM schedules WHERE day_of_week=?',[$day]);
                $conflict=Conflict::detect($existing,$teacher,$branch,$room,$day,$start,$end,$id);
                if ($conflict) throw new InvalidArgumentException($conflict);
                if ($id) execute_sql('UPDATE schedules SET teacher_id=?,branch_id=?,subject_name=?,room=?,day_of_week=?,shift_periods=?,start_time=?,end_time=? WHERE id=?',[$teacher,$branch,$subject,$room,$day,$shift,$start,$end,$id]);
                else execute_sql('INSERT INTO schedules(teacher_id,branch_id,subject_name,room,day_of_week,shift_periods,start_time,end_time) VALUES (?,?,?,?,?,?,?,?)',[$teacher,$branch,$subject,$room,$day,$shift,$start,$end]);
                $pdo->commit();
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $ex;
            } finally {
                query_one('SELECT RELEASE_LOCK(?) AS released',[$lockName]);
            }
            flash('success','Đã lưu lịch dạy, không phát hiện lịch trùng.'); go('schedules');
        }
        throw new InvalidArgumentException('Thao tác không hợp lệ.');
    } catch (PDOException $ex) {
        // Không lộ chi tiết kết nối/câu lệnh SQL đến giao diện.
        errors($ex->getCode()==='23000' ? 'Dữ liệu đang được sử dụng hoặc bị trùng (ví dụ: email, cơ sở/giảng viên đã có lịch).': 'Không thể lưu dữ liệu. Kiểm tra kết nối và ràng buộc dữ liệu.');
        go($page, ['edit'=>(int)($_POST['id'] ?? 0)]);
    } catch (Throwable $ex) {
        errors($ex->getMessage());
        go($page, ['edit'=>(int)($_POST['id'] ?? 0)]);
    }
}

$admin = (me()['role'] ?? '') === 'admin';
$allowed=['login','dashboard','branches','teachers','schedules','my-schedule'];
if (!in_array($page,$allowed,true)) { http_response_code(404); $page='not-found'; }
if (!$admin && in_array($page,['branches','teachers','schedules'],true)) { http_response_code(403); $page='forbidden'; }
$flash=$_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$headings=['dashboard'=>'Tổng quan','branches'=>'Quản lý cơ sở','teachers'=>'Quản lý giảng viên','schedules'=>'Phân lịch giảng dạy','my-schedule'=>'Lịch dạy của tôi','login'=>'Đăng nhập'];
$title=$headings[$page] ?? 'Thông báo';
?>
<!doctype html>
<html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($title)?> | HUIT Schedule Hub</title>
<link rel="stylesheet" href="assets/css/style.css"></head>
<body class="<?= $page==='login'?'login-page':'' ?>">
<?php if ($page !== 'login'): ?>
<div class="shell"><aside class="sidebar"><a class="brand" href="<?=url('dashboard')?>"><span class="brand-logo">H</span><span>HUIT <b>Schedule Hub</b><small>SMART CAMPUS MANAGEMENT</small></span></a>
<div class="nav-caption">ĐIỀU HƯỚNG</div>
<nav><a class="<?= $page==='dashboard'?'active':'' ?>" href="<?=url('dashboard')?>">◫ &nbsp; Tổng quan</a>
<?php if ($admin): ?><a class="<?= $page==='branches'?'active':'' ?>" href="<?=url('branches')?>">▦ &nbsp; Cơ sở đào tạo</a><a class="<?= $page==='teachers'?'active':'' ?>" href="<?=url('teachers')?>">♙ &nbsp; Giảng viên</a><a class="<?= $page==='schedules'?'active':'' ?>" href="<?=url('schedules')?>">▤ &nbsp; Phân lịch dạy</a><?php endif; ?>
<a class="<?= $page==='my-schedule'?'active':'' ?>" href="<?=url('my-schedule')?>">▣ &nbsp; Lịch dạy của tôi</a></nav>
<div class="sidebar-foot"><span class="avatar"><?=e(mb_substr(me()['name'],0,1))?></span><div class="userbox"><b><?=e(me()['name'])?></b><small><?=$admin?'Quản trị viên':'Giảng viên'?></small></div><form method="post" action="<?=url('logout')?>"><?=csrf_field()?><button class="logout" title="Đăng xuất">↪</button></form></div></aside>
<main class="main"><header class="topbar"><div class="crumb">HUIT / <strong><?=e($title)?></strong></div><div class="pill">● &nbsp; Hệ thống hoạt động</div></header><div class="content">
<div class="page-title"><div><div class="eyebrow">HỆ THỐNG QUẢN LÝ ĐÀO TẠO</div><h1><?=e($title)?></h1><p>Giải pháp quản lý và phân lịch giảng dạy đa cơ sở.</p></div><div class="date-chip"><?=date('d/m/Y')?></div></div>
<?php if ($flash): ?><div class="alert <?=e($flash['type'])?>"><?=e($flash['message'])?></div><?php endif; ?>
<?php endif; ?>
<?php if ($page==='login'): ?>
<div class="login-wrap"><div class="login-side"><div class="brand large"><span class="brand-logo">H</span><span>HUIT <b>Schedule Hub</b></span></div><h1>Quản lý lịch dạy<br><em>thông minh hơn.</em></h1><p>Tối ưu phân công giảng viên giữa các cơ sở, chủ động tránh lịch trùng và theo dõi thời khóa biểu rõ ràng.</p><div class="login-ornament">◫ &nbsp; MULTI-CAMPUS SCHEDULING</div></div>
<div class="login-panel"><div class="login-card"><div class="eyebrow">CHÀO MỪNG TRỞ LẠI</div><h2>Đăng nhập tài khoản</h2><p>Sử dụng tài khoản được quản trị viên cấp.</p>
<?php if ($flash): ?><div class="alert <?=e($flash['type'])?>"><?=e($flash['message'])?></div><?php endif; ?>
<form method="post" action="<?=url('login')?>"><?=csrf_field()?><label>Email</label><input type="email" name="email" required autocomplete="username" placeholder="ten@huit.edu.vn"><label>Mật khẩu</label><input type="password" name="password" required autocomplete="current-password" placeholder="••••••••"><button class="btn primary full">Đăng nhập →</button></form><div class="login-note">Tài khoản demo được tạo bằng lệnh <code>php scripts/seed.php</code>.</div></div></div></div>
<?php elseif ($page==='dashboard'):
    $bCount=(int)(query_one('SELECT COUNT(*) AS n FROM branches')['n']??0);
    $tCount=(int)(query_one('SELECT COUNT(*) AS n FROM teachers')['n']??0);
    $sCount=(int)(query_one('SELECT COUNT(*) AS n FROM schedules')['n']??0);
    $mineId=(int)(me()['teacher_id']??0);
    $recent=$admin ? query_all('SELECT s.*,t.full_name,b.branch_name FROM schedules s JOIN teachers t ON t.id=s.teacher_id JOIN branches b ON b.id=s.branch_id ORDER BY s.created_at DESC,s.id DESC LIMIT 8') : query_all('SELECT s.*,t.full_name,b.branch_name FROM schedules s JOIN teachers t ON t.id=s.teacher_id JOIN branches b ON b.id=s.branch_id WHERE s.teacher_id=? ORDER BY s.day_of_week,s.start_time LIMIT 8',[$mineId]);
?>
<div class="stats"><div class="stat"><span class="stat-icon blue">▦</span><div><small>Cơ sở đào tạo</small><strong><?=$bCount?></strong><span>Tổng số cơ sở</span></div></div><div class="stat"><span class="stat-icon violet">♙</span><div><small>Giảng viên</small><strong><?=$tCount?></strong><span>Hồ sơ đã quản lý</span></div></div><div class="stat"><span class="stat-icon green">▤</span><div><small>Lịch dạy cố định</small><strong><?=$sCount?></strong><span>Buổi học mỗi tuần</span></div></div></div>
<div class="split"><section class="panel"><div class="section-top"><div><h2>Lịch giảng dạy gần đây</h2><p>Các buổi học được phân công trong tuần</p></div><?php if ($admin): ?><a class="btn outline" href="<?=url('schedules')?>">Xem tất cả</a><?php endif ?></div>
<div class="table-scroll"><table><thead><tr><th>Giảng viên</th><th>Môn học</th><th>Thời gian</th><th>Cơ sở</th></tr></thead><tbody><?php foreach ($recent as $row): ?><tr><td><b><?=e($row['full_name'])?></b></td><td><?=e($row['subject_name'])?></td><td><?=e(weekday((int)$row['day_of_week']))?><small class="subtext"><?=e(substr($row['start_time'],0,5))?>–<?=e(substr($row['end_time'],0,5))?></small></td><td><?=e($row['branch_name'])?></td></tr><?php endforeach; ?><?php if (!$recent): ?><tr><td colspan="4" class="empty">Chưa có lịch dạy.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="panel highlight"><div class="eyebrow">THAO TÁC NHANH</div><h2>Quản lý lịch dạy<br>tập trung, hiệu quả.</h2><p>Kiểm tra lịch trống trước khi phân công, phát hiện xung đột giữa các cơ sở và chọn đúng chuyên môn.</p><div class="quick-links"><?php if ($admin): ?><a class="btn primary" href="<?=url('schedules',['new'=>1])?>">+ Tạo lịch mới</a><a class="btn outline" href="<?=url('teachers')?>">Danh sách giảng viên</a><?php else: ?><a class="btn primary" href="<?=url('my-schedule')?>">Xem lịch cá nhân</a><?php endif; ?></div></section></div>
<?php elseif ($page==='branches'):
    $edit=isset($_GET['edit']) ? query_one('SELECT * FROM branches WHERE id=?',[(int)$_GET['edit']]) : null;
    $rows=query_all('SELECT b.*,COUNT(s.id) AS schedules_count FROM branches b LEFT JOIN schedules s ON s.branch_id=b.id GROUP BY b.id ORDER BY b.id DESC');
?>
<div class="split manage"><section class="panel"><div class="section-top"><h2>Danh sách cơ sở <span class="count"><?=count($rows)?></span></h2></div><div class="table-scroll"><table><thead><tr><th>Tên cơ sở</th><th>Địa chỉ</th><th>Lịch dạy</th><th>Thao tác</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><b><?=e($row['branch_name'])?></b></td><td><?=e($row['address'])?></td><td><?=$row['schedules_count']?></td><td class="actions"><a href="<?=url('branches',['edit'=>$row['id']])?>">Sửa</a><form method="post" action="<?=url('branches')?>" onsubmit="return confirm('Xóa cơ sở này?')"><?=csrf_field()?><input type="hidden" name="action" value="branch_delete"><input type="hidden" name="id" value="<?=$row['id']?>"><button class="link-danger">Xóa</button></form></td></tr><?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="4" class="empty">Chưa có cơ sở nào.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="panel"><div class="section-top"><h2><?=$edit?'Cập nhật cơ sở':'Thêm cơ sở mới'?></h2><?php if ($edit): ?><a href="<?=url('branches')?>">+ Thêm mới</a><?php endif ?></div><form class="form" method="post" action="<?=url('branches')?>"><?=csrf_field()?><input type="hidden" name="action" value="branch_save"><input type="hidden" name="id" value="<?=$edit['id']??0?>"><label>Tên cơ sở *</label><input required maxlength="160" name="branch_name" value="<?=e($edit['branch_name']??'')?>" placeholder="Ví dụ: Cơ sở Tân Phú"><label>Địa chỉ *</label><textarea required maxlength="255" name="address" rows="3" placeholder="Nhập địa chỉ cơ sở"><?=e($edit['address']??'')?></textarea><button class="btn primary full">Lưu thông tin</button></form></section></div>
<?php elseif ($page==='teachers'):
    $edit=isset($_GET['edit']) ? query_one('SELECT * FROM teachers WHERE id=?',[(int)$_GET['edit']]) : null;
    $search=trim((string)($_GET['q']??''));
    $rows=$search!=='' ? query_all('SELECT * FROM teachers WHERE full_name LIKE ? OR expertise LIKE ? OR email LIKE ? ORDER BY id DESC',['%'.$search.'%','%'.$search.'%','%'.$search.'%']) : query_all('SELECT * FROM teachers ORDER BY id DESC');
?>
<div class="split manage"><section class="panel"><div class="section-top"><h2>Danh sách giảng viên <span class="count"><?=count($rows)?></span></h2></div><form method="get" class="searchbar"><input type="hidden" name="page" value="teachers"><input name="q" value="<?=e($search)?>" placeholder="Tìm theo tên, email, chuyên môn..."><button class="btn outline">Tìm kiếm</button></form>
<div class="table-scroll"><table><thead><tr><th>Giảng viên</th><th>Liên hệ</th><th>Chuyên môn</th><th>Thao tác</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><b><?=e($row['full_name'])?></b></td><td><?=e($row['email'])?><small class="subtext"><?=e($row['phone'])?></small></td><td><?=e($row['expertise'])?></td><td class="actions"><a href="<?=url('teachers',['edit'=>$row['id']])?>">Sửa</a><form method="post" action="<?=url('teachers')?>" onsubmit="return confirm('Xóa giảng viên?')"><?=csrf_field()?><input type="hidden" name="action" value="teacher_delete"><input type="hidden" name="id" value="<?=$row['id']?>"><button class="link-danger">Xóa</button></form></td></tr><?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="4" class="empty">Không có giảng viên phù hợp.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="panel"><div class="section-top"><h2><?=$edit?'Cập nhật giảng viên':'Thêm giảng viên'?></h2><?php if ($edit): ?><a href="<?=url('teachers')?>">+ Thêm mới</a><?php endif ?></div><form method="post" action="<?=url('teachers')?>" class="form"><?=csrf_field()?><input type="hidden" name="action" value="teacher_save"><input type="hidden" name="id" value="<?=$edit['id']??0?>"><label>Họ và tên *</label><input required maxlength="160" name="full_name" value="<?=e($edit['full_name']??'')?>"><label>Email *</label><input required type="email" maxlength="190" name="email" value="<?=e($edit['email']??'')?>"><label>Điện thoại</label><input maxlength="30" name="phone" value="<?=e($edit['phone']??'')?>"><label>Chuyên môn * <span class="muted">(phân cách bằng dấu phẩy)</span></label><textarea required maxlength="500" name="expertise" rows="3" placeholder="Lập trình Web, Cơ sở dữ liệu"><?=e($edit['expertise']??'')?></textarea><button class="btn primary full">Lưu giảng viên</button></form><p class="hint">Để giảng viên đăng nhập, tạo tài khoản liên kết bằng script <code>scripts/create-user.php</code>.</p></section></div>
<?php elseif ($page==='schedules'):
    $edit=isset($_GET['edit']) ? query_one('SELECT * FROM schedules WHERE id=?',[(int)$_GET['edit']]) : null;
    $teachers=query_all('SELECT * FROM teachers ORDER BY full_name'); $branches=query_all('SELECT * FROM branches ORDER BY branch_name');
    $filterBranch=(int)($_GET['branch']??0); $filterTeacher=(int)($_GET['teacher']??0); $filterDay=(int)($_GET['day']??0);
    $where=[];$params=[];
    if ($filterBranch) { $where[]='s.branch_id=?';$params[]=$filterBranch; }
    if ($filterTeacher) { $where[]='s.teacher_id=?';$params[]=$filterTeacher; }
    if ($filterDay>=1 && $filterDay<=7) { $where[]='s.day_of_week=?';$params[]=$filterDay; }
    $rows=query_all('SELECT s.*,t.full_name,b.branch_name FROM schedules s JOIN teachers t ON t.id=s.teacher_id JOIN branches b ON b.id=s.branch_id '.($where?'WHERE '.implode(' AND ',$where):'').' ORDER BY s.day_of_week,s.start_time,b.branch_name',$params);
?>
<div class="panel"><div class="section-top"><div><h2>Danh sách lịch dạy <span class="count"><?=count($rows)?></span></h2><p>Lịch định kỳ hàng tuần — kiểm tra trùng giáo viên và phòng học.</p></div><a class="btn primary" href="<?=url('schedules',['new'=>1])?>">+ Phân lịch mới</a></div>
<form method="get" class="filters"><input type="hidden" name="page" value="schedules"><select name="branch"><option value="0">Tất cả cơ sở</option><?php foreach ($branches as $b): ?><option value="<?=$b['id']?>" <?=$filterBranch===$b['id']?'selected':''?>><?=e($b['branch_name'])?></option><?php endforeach ?></select><select name="teacher"><option value="0">Tất cả giảng viên</option><?php foreach ($teachers as $t): ?><option value="<?=$t['id']?>" <?=$filterTeacher===$t['id']?'selected':''?>><?=e($t['full_name'])?></option><?php endforeach ?></select><select name="day"><option value="0">Tất cả các thứ</option><?php for ($d=1;$d<=7;$d++): ?><option value="<?=$d?>" <?=$filterDay===$d?'selected':''?>><?=e(weekday($d))?></option><?php endfor ?></select><button class="btn outline">Lọc lịch</button><a class="btn ghost" href="<?=url('schedules')?>">Xóa lọc</a></form>
<div class="table-scroll"><table><thead><tr><th>Thứ</th><th>Khung giờ</th><th>Môn học</th><th>Giảng viên</th><th>Cơ sở / Phòng</th><th>Thao tác</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><span class="tag"><?=e(weekday((int)$row['day_of_week']))?></span></td><td><b><?=e($row['shift_periods'])?></b><small class="subtext"><?=e(substr($row['start_time'],0,5))?>–<?=e(substr($row['end_time'],0,5))?></small></td><td><b><?=e($row['subject_name'])?></b></td><td><?=e($row['full_name'])?></td><td><?=e($row['branch_name'])?><small class="subtext">Phòng <?=e($row['room'])?></small></td><td class="actions"><a href="<?=url('schedules',['edit'=>$row['id']])?>">Sửa</a><form method="post" action="<?=url('schedules')?>" onsubmit="return confirm('Xóa lịch dạy này?')"><?=csrf_field()?><input type="hidden" name="action" value="schedule_delete"><input type="hidden" name="id" value="<?=$row['id']?>"><button class="link-danger">Xóa</button></form></td></tr><?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="6" class="empty">Chưa có lịch dạy phù hợp với bộ lọc.</td></tr><?php endif; ?></tbody></table></div></div>
<?php if ($edit || isset($_GET['new'])): ?><div class="panel schedule-form" id="schedule-form"><div class="section-top"><div><h2><?=$edit?'Chỉnh sửa lịch dạy':'Phân công lịch dạy mới'?></h2><p>Chọn môn học, thứ và ca để hiển thị gợi ý giảng viên còn trống.</p></div><a class="btn ghost" href="<?=url('schedules')?>">Đóng ✕</a></div><form method="post" action="<?=url('schedules')?>" class="form" id="scheduleForm"><?=csrf_field()?><input type="hidden" name="action" value="schedule_save"><input type="hidden" name="id" id="scheduleId" value="<?=$edit['id']??0?>"><div class="form-grid"><div><label>Tên học phần *</label><input required maxlength="180" id="subject" name="subject_name" value="<?=e($edit['subject_name']??'')?>" placeholder="Ví dụ: Lập trình Web"></div><div><label>Thứ *</label><select required id="day" name="day_of_week"><option value="">Chọn thứ</option><?php for ($d=1;$d<=7;$d++): ?><option value="<?=$d?>" <?=($edit['day_of_week']??'')==$d?'selected':''?>><?=e(weekday($d))?></option><?php endfor ?></select></div><div><label>Ca học *</label><select required id="shift" name="shift_periods"><option value="">Chọn ca học</option><?php foreach (shift_options() as $key=>$item): ?><option value="<?=e($key)?>" <?=($edit['shift_periods']??'')===$key?'selected':''?>><?=e($item[2])?></option><?php endforeach; ?></select></div><div><label>Cơ sở *</label><select required name="branch_id"><option value="">Chọn cơ sở</option><?php foreach ($branches as $b): ?><option value="<?=$b['id']?>" <?=($edit['branch_id']??'')==$b['id']?'selected':''?>><?=e($b['branch_name'])?></option><?php endforeach ?></select></div><div><label>Phòng học *</label><input required maxlength="60" name="room" value="<?=e($edit['room']??'')?>" placeholder="Ví dụ: A101"></div><div><label>Giảng viên *</label><select required id="teacher" name="teacher_id"><option value="">Chọn giảng viên</option><?php foreach ($teachers as $t): ?><option value="<?=$t['id']?>" <?=($edit['teacher_id']??'')==$t['id']?'selected':''?>><?=e($t['full_name'])?> — <?=e($t['expertise'])?></option><?php endforeach ?></select></div></div><div class="suggestion" id="suggestion"><b>✦ Gợi ý giảng viên thông minh</b><p>Nhập tên môn học, chọn thứ và ca học để tìm giảng viên phù hợp chuyên môn, chưa có lịch trùng.</p><div id="suggestedTeachers"></div></div><div class="form-buttons"><button class="btn primary">Lưu lịch giảng dạy</button><a class="btn outline" href="<?=url('schedules')?>">Hủy</a></div></form></div><script src="assets/js/suggest.js"></script><?php endif; ?>
<?php elseif ($page==='my-schedule'):
    $teacherId=(int)(me()['teacher_id']??0); $selectedTeacher=$admin ? (int)($_GET['teacher']??$teacherId) : $teacherId;
    $teachers=$admin ? query_all('SELECT id,full_name FROM teachers ORDER BY full_name') : [];
    $rows=$selectedTeacher ? query_all('SELECT s.*,b.branch_name FROM schedules s JOIN branches b ON b.id=s.branch_id WHERE s.teacher_id=? ORDER BY s.day_of_week,s.start_time',[$selectedTeacher]) : [];
    $mode=(string)($_GET['view']??'week'); if (!in_array($mode,['week','month'],true)) $mode='week';
    $dateValue=(string)($_GET['date']??date('Y-m-d'));
    try { $focus=DateTimeImmutable::createFromFormat('!Y-m-d',$dateValue); if (!$focus || $focus->format('Y-m-d')!==$dateValue) throw new Exception('invalid'); } catch (Throwable) { $focus=new DateTimeImmutable('today'); }
    $monday=$focus->modify('monday this week');
    $gridStart=$mode==='month' ? $focus->modify('first day of this month')->modify('monday this week') : $monday;
    $cellCount=$mode==='week'?7:42;
    $prev=$mode==='week'?$focus->modify('-7 days'):$focus->modify('first day of previous month');
    $next=$mode==='week'?$focus->modify('+7 days'):$focus->modify('first day of next month');
    $linkArgs=['view'=>$mode,'teacher'=>$selectedTeacher];
?>
<div class="panel"><div class="section-top"><div><h2>Thời khóa biểu cá nhân</h2><p>Lịch tuần lặp lại theo các ngày trong tháng.</p></div><div class="view-toggle"><a class="<?=$mode==='week'?'selected':''?>" href="<?=url('my-schedule',['view'=>'week','teacher'=>$selectedTeacher,'date'=>$focus->format('Y-m-d')])?>">Theo tuần</a><a class="<?=$mode==='month'?'selected':''?>" href="<?=url('my-schedule',['view'=>'month','teacher'=>$selectedTeacher,'date'=>$focus->format('Y-m-d')])?>">Theo tháng</a></div></div>
<?php if ($admin): ?><form class="filters" method="get"><input type="hidden" name="page" value="my-schedule"><input type="hidden" name="view" value="<?=e($mode)?>"><input type="hidden" name="date" value="<?=e($focus->format('Y-m-d'))?>"><select name="teacher"><option value="0">Chọn giảng viên</option><?php foreach ($teachers as $t): ?><option value="<?=$t['id']?>" <?=$selectedTeacher===$t['id']?'selected':''?>><?=e($t['full_name'])?></option><?php endforeach ?></select><button class="btn outline">Xem lịch</button></form><?php endif; ?>
<div class="calendar-tools"><a class="btn outline" href="<?=url('my-schedule',$linkArgs+['date'=>$prev->format('Y-m-d')])?>">‹ Trước</a><h3><?=$mode==='week'?'Tuần '.$monday->format('d/m').' – '.$monday->modify('+6 days')->format('d/m/Y'):'Tháng '.$focus->format('m/Y')?></h3><a class="btn outline" href="<?=url('my-schedule',$linkArgs+['date'=>$next->format('Y-m-d')])?>">Sau ›</a></div>
<?php if (!$selectedTeacher): ?><div class="empty">Chưa có giảng viên liên kết với tài khoản. Vui lòng liên hệ quản trị viên.</div><?php else: ?><div class="calendar <?=$mode==='month'?'monthly':''?>"><?php for ($i=0;$i<$cellCount;$i++): $date=$gridStart->modify('+'.$i.' days'); $day=(int)$date->format('N'); ?><div class="calendar-day <?=($date->format('Y-m')!==$focus->format('Y-m') && $mode==='month')?'outside':''?>"><div class="calendar-date"><b><?=e(weekday($day))?></b><span><?=$date->format('d/m')?></span></div><?php foreach ($rows as $row): if ((int)$row['day_of_week']!==$day) continue; ?><div class="lesson"><b><?=e($row['subject_name'])?></b><small><?=e(substr($row['start_time'],0,5))?>–<?=e(substr($row['end_time'],0,5))?></small><small><?=e($row['branch_name'])?> · <?=e($row['room'])?></small></div><?php endforeach; ?></div><?php endfor; ?></div><?php endif; ?></div>
<?php elseif ($page==='forbidden'): ?><div class="panel empty">Bạn không có quyền truy cập trang này.</div>
<?php elseif ($page==='not-found'): ?><div class="panel empty">Không tìm thấy trang yêu cầu.</div>
<?php endif; ?>
<?php if ($page!=='login'): ?></div><footer>© <?=date('Y')?> HUIT Schedule Hub · Đồ án thực hành quản lý lịch dạy</footer></main></div><?php endif; ?>
</body></html>
