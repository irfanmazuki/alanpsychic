<?php
// api.php
header('Content-Type: application/json');

// Database connection
$servername = "localhost";
$username = "irfan";
$password = "irfan123";
$dbname = "alanpsychic";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
  echo json_encode(["error" => "Connection failed: " . $conn->connect_error]);
  exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'get_timeslots':
        getTimeslots($conn);
        break;
    case 'get_booking_slots':
        getBookingSlots($conn);
        break;      
    default:
        echo json_encode(["error" => "No valid action provided."]);
        break;
}

$conn->close();

function getTimeslots($conn) {
    $today = date('Y-m-d');
    $sql = "SELECT id, date, time, availability FROM timeslots WHERE date > '$today' ORDER BY date ASC, time ASC";
    $result = $conn->query($sql);
  
    $timeslots = [];
    if ($result->num_rows > 0) {
      while($row = $result->fetch_assoc()) {
        // Convert time to 12-hour format
        $time24 = $row['time'];
        $row['time'] = date('g:i A', strtotime($time24)); // 1:00 PM, 2:00 PM, etc.
  
        $timeslots[] = $row;
      }
    }
  
    echo json_encode($timeslots);
}

function getBookingSlots($conn) {
    $today = date('Y-m-d');
    $sql = "SELECT date, time, availability FROM timeslots WHERE date >= '$today' ORDER BY date ASC, time ASC";
    $result = $conn->query($sql);
  
    $rawSlots = [];
    if ($result->num_rows > 0) {
      while($row = $result->fetch_assoc()) {
        // Format time nicely (e.g., 1:00 PM)
        $row['time'] = date('g:i A', strtotime($row['time']));
        $rawSlots[] = $row;
      }
    }
  
    // Group by date
    $grouped = [];
    foreach ($rawSlots as $slot) {
      $date = $slot['date'];
      if (!isset($grouped[$date])) {
        $grouped[$date] = [];
      }
      $grouped[$date][] = [
        "time" => $slot['time'],
        "available" => $slot['availability'] == 1 ? true : false
      ];
    }
  
    // Prepare final slotsData structure
    $slotsData = [];
    foreach ($grouped as $date => $slots) {
      $slotsData[] = [
        "date" => $date,
        "slots" => $slots
      ];
    }
  
    echo json_encode($slotsData);
  }
  
?>
