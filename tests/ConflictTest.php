<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/Conflict.php';
$rows = [
    ['id'=>1,'teacher_id'=>1,'branch_id'=>1,'room'=>'A101','day_of_week'=>2,'start_time'=>'07:00:00','end_time'=>'09:30:00'],
    ['id'=>2,'teacher_id'=>2,'branch_id'=>2,'room'=>'B203','day_of_week'=>2,'start_time'=>'10:00:00','end_time'=>'11:30:00'],
];
$assert = static function (bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); } };
$assert(Conflict::overlaps('07:00','09:00','08:00','10:00'), 'overlap');
$assert(!Conflict::overlaps('07:00','09:00','09:00','10:00'), 'adjacent shifts');
$assert(Conflict::detect($rows,1,2,'C200',2,'08:00','09:00') !== null, 'teacher across branches');
$assert(Conflict::detect($rows,3,1,'a101',2,'08:00','09:00') !== null, 'room conflict');
$assert(Conflict::detect($rows,3,1,'A102',2,'08:00','09:00') === null, 'free room');
$assert(Conflict::detect($rows,1,1,'A101',2,'07:00','09:30',1) === null, 'edit existing excluded');
$assert(Conflict::detect($rows,1,2,'X',3,'08:00','09:00') === null, 'other day');
echo "Conflict tests PASSED (7 assertions).\n";
