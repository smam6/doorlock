<?php
header("Content-Type: application/json");

require_once __DIR__ . "/../includes/config.php";

/* ── ورودی ── */
$input = json_decode(file_get_contents("php://input"), true);

if (empty($input["card_number"]) || empty($input["door_id"])) {
    echo json_encode(["access" => false, "reason" => "missing_params"]);
    exit;
}

$card    = trim($input["card_number"]);
$door_id = intval($input["door_id"]);

/* ── زمان جاری (تهران) ── */
date_default_timezone_set("Asia/Tehran");
$now         = new DateTime();
$current_time = $now->format("H:i:s");
$php_day      = (int)$now->format("w"); // 0=Sun … 6=Sat

// نگاشت PHP → روزهای هفته فارسی (شنبه=1 … جمعه=7)
$day_map = [6=>1, 0=>2, 1=>3, 2=>4, 3=>5, 4=>6, 5=>7];
$current_day = $day_map[$php_day];

$access    = false;
$reason    = "denied";
$person_id = null;

/* ══════════════════════════════════════════
   تابع کمکی: بررسی بازه زمانی (overnight-safe)
   start_time و end_time به فرمت "H:i:s"
   ══════════════════════════════════════════ */
function timeInRange(string $start, string $end, string $current): bool
{
    if ($start <= $end) {
        // بازه عادی: مثلاً 08:00 → 18:00
        return $current >= $start && $current <= $end;
    }
    // بازه شبانه (overnight): مثلاً 22:00 → 02:00
    return $current >= $start || $current <= $end;
}

/* ══════════════════
   مرحله ۱: پیدا کردن شخص
   ══════════════════ */
$stmt = $conn->prepare(
    "SELECT id FROM persons WHERE card_number = ? AND enabled = 1 LIMIT 1"
);
$stmt->bind_param("s", $card);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    // کارت ناشناس یا غیرفعال — لاگ می‌زنیم و خارج می‌شیم
    logAccess($conn, null, $door_id, "denied");
    echo json_encode(["access" => false, "reason" => "unknown_card"]);
    $conn->close();
    exit;
}
$person_id = (int)$row["id"];

/* ══════════════════
   مرحله ۲: بررسی درب فعال
   ══════════════════ */
$stmt = $conn->prepare(
    "SELECT is_active FROM doors WHERE id = ? LIMIT 1"
);
$stmt->bind_param("i", $door_id);
$stmt->execute();
$door = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$door || !$door["is_active"]) {
    logAccess($conn, $person_id, $door_id, "denied");
    echo json_encode(["access" => false, "reason" => "door_inactive"]);
    $conn->close();
    exit;
}

/* ══════════════════
   مرحله ۳: بررسی blacklist
   ══════════════════ */
$stmt = $conn->prepare(
    "SELECT id FROM blacklist WHERE person_id = ? LIMIT 1"
);
$stmt->bind_param("i", $person_id);
$stmt->execute();
$blacklisted = $stmt->get_result()->num_rows > 0;
$stmt->close();

if ($blacklisted) {
    logAccess($conn, $person_id, $door_id, "denied");
    echo json_encode(["access" => false, "reason" => "blacklisted"]);
    $conn->close();
    exit;
}

/* ══════════════════
   مرحله ۴: temp_access
   ══════════════════ */
$now_dt = $now->format("Y-m-d H:i:s");

$stmt = $conn->prepare(
    "SELECT id, single_use FROM temp_access
     WHERE person_id  = ?
       AND door_id    = ?
       AND valid_from <= ?
       AND valid_until >= ?
       AND used = 0
     LIMIT 1"
);
$stmt->bind_param("iiss", $person_id, $door_id, $now_dt, $now_dt);
$stmt->execute();
$temp = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($temp) {
    // اگر single_use است، مصرف شده علامت بزن
    if ($temp["single_use"]) {
        $upd = $conn->prepare("UPDATE temp_access SET used = 1 WHERE id = ?");
        $upd->bind_param("i", $temp["id"]);
        $upd->execute();
        $upd->close();
    }
    $access = true;
    $reason = "temp_access";
}

/* ══════════════════════════════════════════════════════════
   مرحله ۵: دسترسی گروهی بدون محدودیت زمانی
   اگر یکی از گروه‌های شخص برای این درب مجوز پایه (group_door_permissions)
   داشته باشد و هیچ زمان‌بندی‌ای (group_schedules) برای آن ترکیب گروه/درب
   تعریف نشده باشد، یعنی آن گروه محدودیت زمانی ندارد و دسترسی همیشگی است
   (مثلاً گروه Admin). اگر زمان‌بندی تعریف شده باشد، تصمیم نهایی به مرحله ۶
   (بررسی بازه زمانی) سپرده می‌شود.
   ══════════════════════════════════════════════════════════ */
if (!$access) {
    $stmt = $conn->prepare(
        "SELECT gdp.group_id,
                EXISTS(
                    SELECT 1 FROM group_schedules gs
                    WHERE gs.group_id = gdp.group_id AND gs.door_id = gdp.door_id
                ) AS has_schedule
         FROM group_door_permissions gdp
         JOIN person_groups pg ON pg.group_id = gdp.group_id
         WHERE pg.person_id = ? AND gdp.door_id = ?"
    );
    $stmt->bind_param("ii", $person_id, $door_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    while ($row = $result->fetch_assoc()) {
        if (!$row["has_schedule"]) {
            $access = true;
            $reason = "group_permission_unrestricted";
            break;
        }
    }
}

/* ══════════════════
   مرحله ۶: schedules شخصی (overnight-safe)
   ══════════════════ */
if (!$access) {
    $stmt = $conn->prepare(
        "SELECT start_time, end_time FROM schedules
         WHERE person_id = ?
           AND door_id   = ?
           AND enabled   = 1
           AND FIND_IN_SET(?, days)"
    );
    $stmt->bind_param("iis", $person_id, $door_id, $current_day);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    while ($s = $result->fetch_assoc()) {
        if (timeInRange($s["start_time"], $s["end_time"], $current_time)) {
            $access = true;
            $reason = "personal_schedule";
            break;
        }
    }
}

/* ══════════════════
   مرحله ۷: group_schedules (overnight-safe)
   ══════════════════ */
if (!$access) {
    $stmt = $conn->prepare(
        "SELECT gs.start_time, gs.end_time
         FROM group_schedules gs
         JOIN person_groups pg ON pg.group_id = gs.group_id
         WHERE pg.person_id = ?
           AND gs.door_id   = ?
           AND gs.enabled   = 1
           AND FIND_IN_SET(?, gs.days)"
    );
    $stmt->bind_param("iis", $person_id, $door_id, $current_day);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    while ($s = $result->fetch_assoc()) {
        if (timeInRange($s["start_time"], $s["end_time"], $current_time)) {
            $access = true;
            $reason = "group_schedule";
            break;
        }
    }
}

/* ══════════════════
   مرحله ۸: ثبت لاگ و پاسخ
   ══════════════════ */
$status = $access ? "granted" : "denied";
logAccess($conn, $person_id, $door_id, $status);

echo json_encode(["access" => $access]);

$conn->close();

/* ── تابع ثبت لاگ ── */
function logAccess(mysqli $conn, ?int $person_id, int $door_id, string $status): void
{
    $stmt = $conn->prepare(
        "INSERT INTO access_logs (person_id, door_id, status, access_time)
         VALUES (?, ?, ?, NOW())"
    );
    $stmt->bind_param("iis", $person_id, $door_id, $status);
    $stmt->execute();
    $stmt->close();
}