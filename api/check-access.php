<?php
header("Content-Type: application/json");

$input = json_decode(file_get_contents("php://input"), true);

if(!isset($input["card_number"]) || !isset($input["door_id"])) {
    echo json_encode(["access"=>false]);
    exit;
}

$card = $input["card_number"];
$door_id = intval($input["door_id"]);

$conn = new mysqli("localhost","root","","doorlock_db");

if($conn->connect_error){
    echo json_encode(["access"=>false]);
    exit;
}

date_default_timezone_set("Asia/Tehran");

$current_time = date("H:i:s");

$php_day = date('w');

$day_map = [
6 => 1,
0 => 2,
1 => 3,
2 => 4,
3 => 5,
4 => 6,
5 => 7
];

$current_day = $day_map[$php_day];

$access = false;
$person_id = null;

/* پیدا کردن person_id */

$stmt = $conn->prepare("SELECT id FROM persons WHERE card_number=? LIMIT 1");
$stmt->bind_param("s",$card);
$stmt->execute();
$res = $stmt->get_result();

if($row = $res->fetch_assoc()){
    $person_id = $row["id"];
}


/* بررسی دسترسی */

$stmt2 = $conn->prepare("
SELECT p.id
FROM persons p
JOIN schedules s ON s.person_id = p.id
WHERE p.card_number = ?
AND p.enabled = 1
AND s.enabled = 1
AND s.door_id = ?
AND FIND_IN_SET(?, s.days)
AND ? BETWEEN s.start_time AND s.end_time
LIMIT 1
");

$stmt2->bind_param("siis",$card,$door_id,$current_day,$current_time);
$stmt2->execute();

$result = $stmt2->get_result();

if($result->num_rows > 0){
    $access = true;
}


/* ثبت لاگ */

$status = $access ? "granted" : "denied";

$stmt3 = $conn->prepare("
INSERT INTO access_logs (person_id,door_id,status,access_time)
VALUES (?,?,?,NOW())
");

$stmt3->bind_param("iis",$person_id,$door_id,$status);
$stmt3->execute();


echo json_encode(["access"=>$access]);

$conn->close();
?>
