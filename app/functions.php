<?php
declare(strict_types=1);

function e(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $page, array $query = []): string { return 'index.php?' . http_build_query(array_merge(['page' => $page], $query)); }
function go(string $page, array $query = []): never { header('Location: ' . url($page, $query)); exit; }
function me(): ?array { return $_SESSION['user'] ?? null; }
function require_login(): void { if (!me()) go('login'); }
function require_admin(): void { require_login(); if ((me()['role'] ?? '') !== 'admin') { http_response_code(403); exit('403 - Chỉ quản trị viên được thực hiện thao tác này.'); } }
function token(): string { if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(token()) . '">'; }
function csrf_check(): void { if (!hash_equals(token(), (string)($_POST['csrf'] ?? ''))) { http_response_code(419); exit('419 - Mã bảo vệ biểu mẫu không hợp lệ.'); } }
function flash(string $type, string $message): void { $_SESSION['flash'] = compact('type', 'message'); }
function errors(string $message): void { flash('error', $message); }
function input(string $name): string { return trim((string)($_POST[$name] ?? '')); }
function positive_id(string $name): int { return max(0, (int)($_POST[$name] ?? 0)); }
function weekday(int $day): string { return [1=>'Thứ Hai',2=>'Thứ Ba',3=>'Thứ Tư',4=>'Thứ Năm',5=>'Thứ Sáu',6=>'Thứ Bảy',7=>'Chủ Nhật'][$day] ?? 'Không rõ'; }
function query_all(string $sql, array $params = []): array { $s = db()->prepare($sql); $s->execute($params); return $s->fetchAll(); }
function query_one(string $sql, array $params = []): ?array { $s = db()->prepare($sql); $s->execute($params); return $s->fetch() ?: null; }
function execute_sql(string $sql, array $params = []): int { $s = db()->prepare($sql); $s->execute($params); return $s->rowCount(); }
function validate_email(string $email): bool { return filter_var($email, FILTER_VALIDATE_EMAIL) !== false; }
function shift_options(): array {
    return [
        '1-3' => ['07:00','09:30','Tiết 1–3 · 07:00–09:30'],
        '4-6' => ['09:40','12:10','Tiết 4–6 · 09:40–12:10'],
        '7-9' => ['13:00','15:30','Tiết 7–9 · 13:00–15:30'],
        '10-12' => ['15:40','18:10','Tiết 10–12 · 15:40–18:10'],
        '13-15' => ['18:20','20:50','Tiết 13–15 · 18:20–20:50'],
    ];
}
function subject_matches(string $expertise, string $subject): bool {
    $subject = mb_strtolower(trim($subject), 'UTF-8');
    if ($subject === '') return false;
    foreach (preg_split('/[,;|]/u', mb_strtolower($expertise, 'UTF-8')) as $item) {
        $item = trim($item);
        if ($item !== '' && ($item === $subject || mb_str_contains($item, $subject) || mb_str_contains($subject, $item))) return true;
    }
    return false;
}
