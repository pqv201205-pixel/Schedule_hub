<?php
declare(strict_types=1);

final class Conflict
{
    public static function overlaps(string $startA, string $endA, string $startB, string $endB): bool
    {
        return $startA < $endB && $startB < $endA;
    }

    /** @param list<array<string,mixed>> $existing */
    public static function detect(array $existing, int $teacherId, int $branchId, string $room, int $day, string $start, string $end, int $excludeId = 0): ?string
    {
        foreach ($existing as $row) {
            if ((int)$row['id'] === $excludeId || (int)$row['day_of_week'] !== $day) continue;
            if (!self::overlaps($start, $end, substr((string)$row['start_time'], 0, 5), substr((string)$row['end_time'], 0, 5))) continue;
            if ((int)$row['teacher_id'] === $teacherId) {
                return 'Giảng viên đã có lịch dạy trùng khung giờ (kể cả ở cơ sở khác).';
            }
            if ((int)$row['branch_id'] === $branchId && strtolower(trim((string)$row['room'])) === strtolower(trim($room))) {
                return 'Phòng học tại cơ sở này đã được sử dụng trong khung giờ đó.';
            }
        }
        return null;
    }
}
