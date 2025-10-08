<?php

include 'vars.php'; 
// Define aggregation intervals
define('INTERVAL_10_MIN', 600);   // 10 minutes in seconds
define('INTERVAL_30_MIN', 1800);  // 30 minutes in seconds

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$dataType_string = $_GET["dataType"];
$days_string = $_GET["days"];
$dataType = trim($dataType_string,'"\''); 
$days = intval(trim($days_string,'"\''));

$stmt = null;
// Will average over 10 minutes for one day and 30 minutes for one week or there is too much noise
if ($days <= 8) {
    $interval = ($days <= 1) ? INTERVAL_10_MIN : INTERVAL_30_MIN;
    $sql = "SELECT room_id, ROUND(AVG(value),3) AS value, type,
            FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(dt) / ?) * ?) AS dt
            FROM rec_data
            WHERE type = ? AND dt >= NOW() - INTERVAL ? DAY
            GROUP BY room_id, FLOOR(UNIX_TIMESTAMP(dt) / ?)
            ORDER BY room_id, dt";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iisii", $interval, $interval, $dataType, $days, $interval);
} elseif ($days <= 32) {
    $sql = "SELECT room_id, ROUND(avg_value,2) AS value, type, dt_hour AS dt
            FROM hourly_avg
            WHERE type = ? AND dt_hour >= NOW() - INTERVAL ? DAY
            ORDER BY room_id, dt";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $dataType, $days);
} else {
    $sql = "SELECT room_id, ROUND(avg_value,2) AS value, type, dt_day AS dt
            FROM daily_avg
            WHERE type = ? AND dt_day >= NOW() - INTERVAL ? DAY
            ORDER BY room_id, dt";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $dataType, $days);
}

$data = array();

if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    
    $stmt->close();
} else {
    error_log("Query preparation failed: " . ($conn->error ?: "Unknown error"));
    http_response_code(500);
    echo json_encode(["error" => "Unable to retrieve data"]);
    exit;
}

$conn->close();
echo json_encode($data);
?>