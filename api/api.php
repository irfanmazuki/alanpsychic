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
    case 'get_booking_details':
        getBookingDetails($conn);
        break;
    case 'cancel_booking':
        cancelBooking($conn);
        break;
    case 'get_recent_bookings':
        getRecentBookings($conn);
        break;
    default:
        echo json_encode(["error" => "No valid action provided."]);
        break;
}

$conn->close();

function getTimeslots($conn) {
    $sql = "
        SELECT 
            t.id,
            t.date,
            t.time,
            t.availability,
            MAX(b.booking_number) AS booking_number
        FROM 
            timeslots t
        LEFT JOIN 
            booking_slot bs ON bs.timeslot_id = t.id
        LEFT JOIN 
            booking b ON bs.booking_id = b.id AND b.isCancelled = 0
        WHERE 
            t.date >= CURDATE()
        GROUP BY 
            t.id, t.date, t.time, t.availability
        ORDER BY 
            t.date ASC, t.time ASC

        ";
  
    $result = $conn->query($sql);
    $timeslots = [];
  
    while ($row = $result->fetch_assoc()) {
      $timeslots[] = [
        "id" => $row['id'],
        "date" => $row['date'],
        "time" => date("g:i A", strtotime($row['time'])), // Optional: format to 12-hour
        "availability" => $row['availability'],
        "booking_number" => $row['booking_number'] ?? null
      ];
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
    $bookingNumber = generateBookingNumber();
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
      $stmt = $conn->prepare("INSERT INTO booking (name, phone_number, email, pax_number, created_date, isCancelled, booking_number) VALUES (?, ?, ?, ?, ?, 0, ?)");
      $stmt->bind_param("sssiss", $name, $phone, $email, $pax, $createdDate, $bookingNumber);
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
      echo json_encode(["success" => true, "booking_number" => $bookingNumber]);
    } catch (Exception $e) {
      $conn->rollback();
      echo json_encode(["success" => false, "message" => $e->getMessage()]);
    }
  }

  function getBookingDetails($conn) {
    $bookingNumber = $_GET['booking_number'] ?? '';
  
    if (!$bookingNumber) {
      echo json_encode(["success" => false, "message" => "Booking number missing."]);
      return;
    }
  
    // Fetch booking main info
    $stmt = $conn->prepare("SELECT id, name, phone_number, email, pax_number, created_date, isCancelled FROM booking WHERE booking_number = ?");
    $stmt->bind_param("s", $bookingNumber);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows == 0) {
      echo json_encode(["success" => false, "message" => "Booking not found."]);
      return;
    }
    $booking = $result->fetch_assoc();
  
    // Fetch booked timeslots
    $stmt2 = $conn->prepare("
      SELECT t.date, t.time
      FROM booking_slot bs
      JOIN timeslots t ON t.id = bs.timeslot_id
      WHERE bs.booking_id = ?
      ORDER BY t.date, t.time
    ");
    $stmt2->bind_param("i", $booking['id']);
    $stmt2->execute();
    $result2 = $stmt2->get_result();
  
    $timeslots = [];
    while ($row = $result2->fetch_assoc()) {
        $time24 = date('g:i A', strtotime($row['time']));
        $timeslots[] = $time24; // Only time needed
        $booking['date'] = $row['date']; // Date (all same date assumed for now)
    }
  
    $booking['timeslots'] = $timeslots;
  
    echo json_encode(["success" => true, "booking" => $booking]);
  }
  
  function cancelBooking($conn) {
    $bookingNumber = $_POST['booking_number'] ?? '';
  
    if (!$bookingNumber) {
      echo json_encode(["success" => false, "message" => "Booking number missing."]);
      return;
    }
  
    // Find the booking id
    $stmt = $conn->prepare("SELECT id FROM booking WHERE booking_number = ? AND isCancelled = 0");
    $stmt->bind_param("s", $bookingNumber);
    $stmt->execute();
    $result = $stmt->get_result();
  
    if ($result->num_rows == 0) {
      echo json_encode(["success" => false, "message" => "Booking not found or already cancelled."]);
      return;
    }
  
    $booking = $result->fetch_assoc();
    $bookingId = $booking['id'];
  
    $conn->begin_transaction();
    try {
      // 1. Set booking as cancelled
      $stmt1 = $conn->prepare("UPDATE booking SET isCancelled = 1 WHERE id = ?");
      $stmt1->bind_param("i", $bookingId);
      $stmt1->execute();
  
      // 2. Find all linked timeslots and mark them available again
      $stmt2 = $conn->prepare("
        UPDATE timeslots
        SET availability = 1
        WHERE id IN (
          SELECT timeslot_id FROM booking_slot WHERE booking_id = ?
        )
      ");
      $stmt2->bind_param("i", $bookingId);
      $stmt2->execute();
  
      $conn->commit();
      echo json_encode(["success" => true]);
    } catch (Exception $e) {
      $conn->rollback();
      echo json_encode(["success" => false, "message" => $e->getMessage()]);
    }
  }
  
  function getRecentBookings($conn) {
    $sql = "
      SELECT 
        b.id AS booking_id,
        b.booking_number,
        b.name,
        b.created_date,
        b.isCancelled,
        MAX(t.date) AS date
      FROM 
        booking b
      LEFT JOIN 
        booking_slot bs ON bs.booking_id = b.id
      LEFT JOIN 
        timeslots t ON t.id = bs.timeslot_id
      GROUP BY 
        b.id
      ORDER BY 
        b.created_date DESC
      LIMIT 50
    ";
  
    $result = $conn->query($sql);
    $bookings = [];
  
    while ($row = $result->fetch_assoc()) {
      $bookingId = $row['booking_id'];
  
      // 🆕 Now get timeslots for each booking
      $timeslotQuery = "
        SELECT time
        FROM timeslots
        WHERE id IN (SELECT timeslot_id FROM booking_slot WHERE booking_id = $bookingId)
        ORDER BY time ASC
      ";
      $timeslotResult = $conn->query($timeslotQuery);
      $timeslots = [];
      while ($ts = $timeslotResult->fetch_assoc()) {
        $timeslots[] = date("g:i A", strtotime($ts['time'])); // format nicely
      }
  
      $bookings[] = [
        "booking_number" => $row["booking_number"],
        "name" => $row["name"],
        "created_date" => $row["created_date"],
        "date" => $row["date"] ?? "-",
        "status" => $row["isCancelled"] == 1 ? "Cancelled" : "Booked",
        "timeslots" => $timeslots
      ];
    }
  
    echo json_encode(["success" => true, "bookings" => $bookings]);
  }
  
  

  function generateBookingNumber($length = 6) {
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
      $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
  }
  
?>
