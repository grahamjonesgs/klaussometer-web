<?php

include 'vars.php'; 

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$dataType_string = $_GET["dataType"];
$days_string = $_GET["days"];
$dataType = trim($dataType_string,'"\''); 
$days = intval(trim($days_string,'"\''));

$stmt = null;
// Will avrage over 10 minutes for less than 6 days or there is too much noise
if ($days <= 8) {
    $sql = "SELECT room_id, ROUND(AVG(value),3) AS value, type,
            FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(dt) / 600) * 600) AS dt
            FROM rec_data
            WHERE type = ? AND dt >= NOW() - INTERVAL ? DAY
            GROUP BY room_id, FLOOR(UNIX_TIMESTAMP(dt) / 600)
            ORDER BY room_id, dt";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $dataType, $days);
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
    echo "Query preparation failed or no data to retrieve.<br>\n";
    if ($conn->error) {
        echo "MySQL Error: " . $conn->error;
    }
}

$conn->close();
echo json_encode($data);
?>