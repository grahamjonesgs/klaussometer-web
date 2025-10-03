<?php

include 'vars.php';

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Prepare a single query to get the latest data for all required types
$sql="SELECT T1.room_id, T1.value, T1.type FROM rec_data T1
      INNER JOIN (
          SELECT room_id, type, MAX(dt) as maxdate FROM rec_data 
          WHERE type IN ('tempset-ambient', 'tempset-humidity') AND dt >= NOW() - INTERVAL 30 MINUTE 
          GROUP BY room_id, type
      ) T2 ON T1.room_id = T2.room_id AND T1.type = T2.type AND T1.dt = T2.maxdate";


$result = $conn->query($sql);
$data = array();

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
}

$conn->close();

echo json_encode($data);
?>