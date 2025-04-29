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
    case 'add_slots':
        addSlots($conn);
        break;  
    case 'delete_slot':
        deleteSlot($conn);
        break;  
    case 'submit_booking':
        submitBooking($conn);
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
    $sql = "SELECT id, date, time, availability FROM timeslots WHERE date >= '$today' ORDER BY date ASC, time ASC";
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
        "id" => $slot['id'],
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
  
  function addSlots($conn) {
    $fromDate = isset($_POST['from']) ? $_POST['from'] : null;
    $toDate = isset($_POST['to']) ? $_POST['to'] : null;
  
    if (!$fromDate || !$toDate) {
      echo json_encode(["success" => false, "message" => "Missing dates."]);
      return;
    }
  
    $from = new DateTime($fromDate);
    $from->modify('+1 day'); // Start from next day after latest filled
    $to = new DateTime($toDate);
  
    $times = ['12:00:00', '13:00:00', '14:00:00', '15:00:00', '16:00:00', '17:00:00'];
    $createdDate = date('Y-m-d H:i:s');
  
    $conn->begin_transaction();
    try {
      while ($from <= $to) {
        foreach ($times as $time) {
          $stmt = $conn->prepare("INSERT INTO timeslots (date, time, created_date, availability) VALUES (?, ?, ?, 1)");
          $dateString = $from->format('Y-m-d');
          $stmt->bind_param("sss", $dateString, $time, $createdDate);
          $stmt->execute();
        }
        $from->modify('+1 day');
      }
      $conn->commit();
      echo json_encode(["success" => true]);
    } catch (Exception $e) {
      $conn->rollback();
      echo json_encode(["success" => false, "message" => $e->getMessage()]);
    }
  }

  function deleteSlot($conn) {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if ($id <= 0) {
      echo json_encode(["success" => false, "message" => "Invalid ID."]);
      return;
    }
  
    $stmt = $conn->prepare("DELETE FROM timeslots WHERE id = ?");
    $stmt->bind_param("i", $id);
  
    if ($stmt->execute()) {
      echo json_encode(["success" => true]);
    } else {
      echo json_encode(["success" => false, "message" => $stmt->error]);
    }
  }

  function submitBooking($conn) {
    $name = $_POST['name'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $email = $_POST['email'] ?? '';
    $pax = $_POST['pax'] ?? 1;
    $timeslotIds = $_POST['timeslot_ids'] ?? [];
  
    if (!$name || !$phone || !$email || empty($timeslotIds)) {
      echo json_encode(["success" => false, "message" => "Missing required fields."]);
      return;
    }
  
    $conn->begin_transaction();
    try {
      // Check all requested slots are available
      $idsList = implode(",", array_map('intval', $timeslotIds));
      $checkSql = "SELECT COUNT(*) AS cnt FROM timeslots WHERE id IN ($idsList) AND availability = 1";
      $result = $conn->query($checkSql);
      $row = $result->fetch_assoc();
      
      if ($row['cnt'] != count($timeslotIds)) {
        throw new Exception("One or more selected timeslots are already booked.{$timeslotIds}");
      }
  
      // Insert into booking
      $createdDate = date('Y-m-d H:i:s');
      $stmt = $conn->prepare("INSERT INTO booking (name, phone_number, email, pax_number, created_date, isCancelled) VALUES (?, ?, ?, ?, ?, 0)");
      $stmt->bind_param("sssis", $name, $phone, $email, $pax, $createdDate);
      $stmt->execute();
      $bookingId = $stmt->insert_id;
  
      // Insert booking slots and mark timeslots as booked
      foreach ($timeslotIds as $timeslotId) {
        $slotIdInt = intval($timeslotId);
  
        $stmt2 = $conn->prepare("INSERT INTO booking_slot (booking_id, timeslot_id) VALUES (?, ?)");
        $stmt2->bind_param("ii", $bookingId, $slotIdInt);
        $stmt2->execute();
  
        $stmt3 = $conn->prepare("UPDATE timeslots SET availability = 0 WHERE id = ?");
        $stmt3->bind_param("i", $slotIdInt);
        $stmt3->execute();
      }
  
      $conn->commit();
      echo json_encode(["success" => true]);
    } catch (Exception $e) {
      $conn->rollback();
      echo json_encode(["success" => false, "message" => $e->getMessage()]);
    }
  }
  
  
?>
