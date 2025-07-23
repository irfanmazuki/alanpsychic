<?php
// api.php
header('Content-Type: application/json');


// Database connection
$servername = "localhost";
$username = "irfan";
$password = "irfan123";
$dbname = "alanpsychic";

$host = $_SERVER['HTTP_HOST'];
if($host != 'localhost'){
  $servername = "localhost";
  $username = "psychicr_irfan";
  $password = "Psychic5245@@";
  $dbname = "psychicr_alanpsychic";
}

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
    case 'get_users_list':
        getUsersList($conn);
        break;  
    case 'toggle_blacklist':
        toggleBlacklist($conn);
        break;
    case 'admin_login':
      handleAdminLogin();
      break;
    case "submit_review":
      submitReview($conn);
      break;
    case 'increase_counter':
      increaseCounter($conn);
      break;
    case 'delete_slots_by_date':
      deleteSlotsByDate($conn);
      break;
    case 'add_slot_manually':
      addSlotManually($conn);
      break;
    case 'check_user_exists':
      checkUserExists($conn);
      break;
    case 'register_user':
      registerUser($conn);
      break;
    case 'book_slot':
      bookSlot($conn);
      break;
    case 'update_is_shown':
      updateIsShown($conn);
      break;
    default:
      echo json_encode(["error" => "No valid action provided."]);
      break;
}

$conn->close();

function submitReview($conn) {
  $bookingNumber = $_POST['booking_number'] ?? '';
  $review = $_POST['review'] ?? '';

  if (!$bookingNumber || !$review) {
      echo json_encode([
          "success" => false,
          "message" => "Missing booking number or review content."
      ]);
      return;
  }

  $stmt = $conn->prepare("UPDATE booking SET review = ? WHERE booking_number = ?");
  $stmt->bind_param("ss", $review, $bookingNumber);

  if ($stmt->execute()) {
      echo json_encode([
          "success" => true,
          "message" => "Review updated successfully."
      ]);
  } else {
      echo json_encode([
          "success" => false,
          "message" => "Database update failed: " . $stmt->error
      ]);
  }

  $stmt->close();
}


function getTimeslots($conn) {
    $sql = "
        SELECT 
            t.id,
            t.date,
            t.time,
            t.availability,
            t.IsShown,
            b.ws_count,
            b.calendar_count,
            b.booking_number,
            b.name,
            u.isBlacklisted,
            b.created_date
        FROM 
            timeslots t
        LEFT JOIN (
            SELECT bs.timeslot_id, b.booking_number, b.user_id, b.name, b.ws_count, b.calendar_count, b.created_date
            FROM booking_slot bs
            JOIN booking b ON bs.booking_id = b.id
            WHERE b.isCancelled = 0
            GROUP BY bs.timeslot_id
        ) AS b ON b.timeslot_id = t.id
        LEFT JOIN users u ON b.user_id = u.id
        WHERE 
            t.date >= CURDATE()
        ORDER BY 
            t.date ASC, t.time ASC
    ";

    $result = $conn->query($sql);
    $timeslots = [];

    while ($row = $result->fetch_assoc()) {
      $timeslots[] = [
        "id" => $row['id'],
        "date" => $row['date'],
        "time" => date("g:i A", strtotime($row['time'])),
        "availability" => $row['availability'],
        "IsShown" => isset($row['IsShown']) ? intval($row['IsShown']) : 1,
        "booking_number" => $row['booking_number'] ?? null,
        "isBlacklisted" => $row['isBlacklisted'],
        "name" => $row['name'],
        "ws_count" => $row['ws_count'] ?? 0,
        "calendar_count" => $row['calendar_count'] ?? 0,
        "created_date" => $row['created_date'] ?? null // <-- add this
      ];
    }

    echo json_encode($timeslots);
  }
  

function getBookingSlots($conn) {
    $today = date('Y-m-d');
    $sql = "SELECT id, date, time, availability FROM timeslots WHERE date >= '$today' and IsShown ORDER BY date ASC, time ASC";
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

  function handleAdminLogin() {
    // Replace this with your actual hardcoded password
    $validPassword = 'admin123';
  
    // Get password from POST body
    $inputPassword = $_POST['password'] ?? '';
  
    if ($inputPassword === $validPassword) {
      echo json_encode(['success' => true]);
    } else {
      echo json_encode(['success' => false, 'message' => 'Incorrect password.']);
    }
  }
  
  function addSlots($conn) {
    $fromDate = isset($_POST['from']) ? $_POST['from'] : null;
    $toDate = isset($_POST['to']) ? $_POST['to'] : null;

    if (!$fromDate || !$toDate) {
        echo json_encode(["success" => false, "message" => "Missing dates."]);
        return;
    }

    $from = new DateTime($fromDate);
    $to = new DateTime($toDate);

    $times = ['12:00:00', '13:00:00', '14:00:00', '15:00:00', '16:00:00', '17:00:00'];
    $createdDate = date('Y-m-d H:i:s');

    $conn->begin_transaction();
    try {
        while ($from <= $to) {
            $dateString = $from->format('Y-m-d');
            foreach ($times as $time) {
                // Check if slot already exists for this date and time
                $stmtCheck = $conn->prepare("SELECT id FROM timeslots WHERE date = ? AND time = ?");
                $stmtCheck->bind_param("ss", $dateString, $time);
                $stmtCheck->execute();
                $stmtCheck->store_result();
                if ($stmtCheck->num_rows == 0) {
                    // Only insert if not exists
                    $stmt = $conn->prepare("INSERT INTO timeslots (date, time, created_date, availability) VALUES (?, ?, ?, 1)");
                    $stmt->bind_param("sss", $dateString, $time, $createdDate);
                    $stmt->execute();
                }
                $stmtCheck->close();
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

    // check if the slot is booked
    $stmtCheck = $conn->prepare("SELECT id FROM timeslots WHERE id = ? and availability = 0");
    $stmtCheck->bind_param("i", $id);
    $stmtCheck->execute();
    $stmtCheck->store_result();
    if ($stmtCheck->num_rows > 0) {
      echo json_encode(["success" => false, "message" => "Cannot delete slot, it is already booked. Please cancel first before deleting."]);
      $stmtCheck->close();
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
    $pax = intval($_POST['pax'] ?? 1);
    $timeslotIds = $_POST['timeslot_ids'] ?? [];

    if (!$name || !$phone || empty($timeslotIds)) {
        echo json_encode(["success" => false, "message" => "Missing booking data."]);
        return;
    }

    // ✅ Check if user already has a booking from today onwards
    $today = date('Y-m-d');
    $stmtCheck = $conn->prepare("
        SELECT b.id
        FROM booking b
        JOIN booking_slot bs ON bs.booking_id = b.id
        JOIN timeslots t ON t.id = bs.timeslot_id
        WHERE b.phone_number = ? AND t.date >= ? AND b.isCancelled = 0
        LIMIT 1
    ");
    $stmtCheck->bind_param("ss", $phone, $today);
    $stmtCheck->execute();
    $stmtCheck->store_result();
    if ($stmtCheck->num_rows > 0) {
        echo json_encode([
            "success" => false,
            "message" => "You already have an active booking. Please cancel your existing booking before making a new one."
        ]);
        return;
    }
    $stmtCheck->close();

    $conn->begin_transaction();
    try {
        // ✅ Step 1: Check if user exists
        $stmtUser = $conn->prepare("SELECT id FROM users WHERE phone_number = ?");
        $stmtUser->bind_param("s", $phone);
        $stmtUser->execute();
        $resultUser = $stmtUser->get_result();
        $userId = null;

        if ($resultUser->num_rows > 0) {
            $userRow = $resultUser->fetch_assoc();
            $userId = $userRow['id'];
        } else {
            $stmtNewUser = $conn->prepare("INSERT INTO users (phone_number, name, isBlacklisted) VALUES (?, ?, 0)");
            $stmtNewUser->bind_param("ss", $phone, $name);
            $stmtNewUser->execute();
            $userId = $stmtNewUser->insert_id;
        }

        // ✅ Step 2: Check all selected slots are still available (atomic check)
        $placeholders = implode(',', array_fill(0, count($timeslotIds), '?'));
        $types = str_repeat('i', count($timeslotIds));
        $checkSql = "SELECT id FROM timeslots WHERE id IN ($placeholders) AND availability = 0";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param($types, ...$timeslotIds);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        if ($checkResult->num_rows > 0) {
            $conn->rollback();
            echo json_encode(["success" => false, "message" => "The chosen slot is booked, please try again with different slot"]);
            return;
        }

        // ✅ Step 3: Generate random booking number
        $bookingNumber = generateBookingNumber();

        // ✅ Step 4: Insert into booking table
        $stmtBooking = $conn->prepare("
            INSERT INTO booking (user_id, name, phone_number, pax_number, booking_number, created_date, isCancelled)
            VALUES (?, ?, ?, ?, ?, NOW(), 0)
        ");
        $stmtBooking->bind_param("issis", $userId, $name, $phone, $pax, $bookingNumber);
        $stmtBooking->execute();
        $bookingId = $stmtBooking->insert_id;

        // ✅ Step 5: Update selected timeslots and create booking_slot records
        $slotTimes = [];
        $date = "";

        foreach ($timeslotIds as $slotId) {
            // Get slot info
            $stmtSlot = $conn->prepare("SELECT date, time FROM timeslots WHERE id = ?");
            $stmtSlot->bind_param("i", $slotId);
            $stmtSlot->execute();
            $slotResult = $stmtSlot->get_result();
            if ($slotRow = $slotResult->fetch_assoc()) {
                $slotTimes[] = $slotRow['time'];
                $date = $slotRow['date']; // same date for all slots
            }

            // Update availability
            $stmtUpdateSlot = $conn->prepare("UPDATE timeslots SET availability = 0 WHERE id = ?");
            $stmtUpdateSlot->bind_param("i", $slotId);
            $stmtUpdateSlot->execute();

            // Link to booking
            $stmtLinkSlot = $conn->prepare("INSERT INTO booking_slot (booking_id, timeslot_id) VALUES (?, ?)");
            $stmtLinkSlot->bind_param("ii", $bookingId, $slotId);
            $stmtLinkSlot->execute();
        }

        $conn->commit();

        // ✅ Step 6: Format and send SMS
        sort($slotTimes); // sort times if multiple
        $formattedTimes = array_map(function($time) {
          return date("g:i A", strtotime($time)); // g:i A gives 12-hour format with AM/PM
        }, $slotTimes);
        
        $slotString = implode(", ", $formattedTimes);
        $formattedDate = date("d M", strtotime($date)); // e.g., 30 Apr

        $text = "RM0 Alan Psychic Reading: Your booking $bookingNumber is confirmed for $formattedDate, $slotString. Kindly cancel at least 1 day before if unable to attend.";

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
    $stmt = $conn->prepare("SELECT id, name, phone_number, pax_number, created_date, isCancelled, review, ws_count, calendar_count FROM booking WHERE booking_number = ?");
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
      ORDER BY t.time ASC;
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
    $stmt = $conn->prepare("SELECT id, phone_number FROM booking WHERE booking_number = ? AND isCancelled = 0");
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
      $stmt1 = $conn->prepare("UPDATE booking SET isCancelled = 1, cancelled_at = NOW() WHERE id = ?");
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
  
  function getUsersList($conn) {
    $sql = "SELECT * FROM users ORDER BY id DESC";
    $result = $conn->query($sql);
    $users = [];
  
    while ($row = $result->fetch_assoc()) {
      $userId = $row['id'];
  
      // Get all bookings for this user
      $bookingSql = "
        SELECT 
          b.id AS booking_id,
          b.booking_number,
          b.isCancelled,
          b.created_date,
          b.review
        FROM 
          booking b
        WHERE 
          b.user_id = $userId
        ORDER BY 
          b.created_date DESC
      ";
      $bookingResult = $conn->query($bookingSql);
      $bookings = [];
  
      while ($bookingRow = $bookingResult->fetch_assoc()) {
        $bookingId = $bookingRow['booking_id'];
  
        // 🆕 Get all timeslots under this booking
        $timeslotSql = "
          SELECT 
            t.date,
            t.time
          FROM 
            booking_slot bs
          JOIN 
            timeslots t ON bs.timeslot_id = t.id
          WHERE 
            bs.booking_id = $bookingId
          ORDER BY 
            t.date, t.time
        ";
        $timeslotResult = $conn->query($timeslotSql);
        $timeslots = [];
  
        while ($ts = $timeslotResult->fetch_assoc()) {
          $timeslots[] = [
            "date" => $ts["date"],
            "time" => date("g:i A", strtotime($ts["time"]))
          ];
        }
  
        $bookings[] = [
          "booking_number" => $bookingRow['booking_number'],
          "status" => $bookingRow['isCancelled'] == 1 ? "Cancelled" : "Booked",
          "created_date" => $bookingRow['created_date'],
          "review" => $bookingRow['review'],
          "timeslots" => $timeslots
        ];
      }
  
      $users[] = [
        "id" => $row['id'],
        "phone_number" => $row['phone_number'],
        "name" => $row['name'],
        "isBlacklisted" => $row['isBlacklisted'],
        "bookings" => $bookings
      ];
    }
  
    echo json_encode(["success" => true, "users" => $users]);
  }

  function toggleBlacklist($conn) {
    $userId = $_POST['user_id'] ?? null;
    $isBlacklisted = $_POST['isBlacklisted'] ?? null;
  
    if (!$userId || !isset($isBlacklisted)) {
      echo json_encode(["success" => false, "message" => "Missing parameters."]);
      return;
    }
  
    $stmt = $conn->prepare("UPDATE users SET isBlacklisted = ? WHERE id = ?");
    $stmt->bind_param("ii", $isBlacklisted, $userId);
  
    if ($stmt->execute()) {
      echo json_encode(["success" => true]);
    } else {
      echo json_encode(["success" => false, "message" => "Database error."]);
    }
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

  function increaseCounter($conn){
    $bookingId = $_POST['bookingId'];
    $type = $_POST['type'];

    if ($type == "whatsapp") {
      $conn->query("UPDATE booking SET ws_count = ws_count + 1 WHERE id = $bookingId");
    }
    else{
      $conn->query("UPDATE booking SET calendar_count = calendar_count + 1 WHERE id = $bookingId");
    }
    echo json_encode(["success" => true]);
  }

    function deleteSlotsByDate($conn) {
      $date = $_POST['date'];
      // Check if there are any booked slots (availability = 0)
      $checkStmt = $conn->prepare("SELECT COUNT(*) FROM timeslots WHERE date = ? AND availability = 0");
      $checkStmt->bind_param("s", $date);
      $checkStmt->execute();
      $checkStmt->bind_result($bookedCount);
      $checkStmt->fetch();
      $checkStmt->close();

      if ($bookedCount > 0) {
          echo json_encode([
              "success" => false,
              "message" => "Unable to delete. There are bookings on this date. Please cancel them first."
          ]);
          return;
      }
    
      $stmt = $conn->prepare("DELETE FROM timeslots WHERE date = ?");
      $stmt->bind_param("s", $date);
    
      if ($stmt->execute()) {
        echo json_encode(["success" => true]);
      } else {
        echo json_encode(["success" => false, "message" => $stmt->error]);
      }
  }
  
  function addSlotManually($conn) {
    $date = $_POST['date'] ?? '';
    $time = $_POST['time'] ?? '';

    if (!$date || !$time) {
        echo json_encode(["success" => false, "message" => "Date and time are required."]);
        return;
    }

    // Convert time to 24-hour format if needed
    $time24 = date("H:i:s", strtotime($time));
    $createdDate = date('Y-m-d H:i:s');

    // Check if slot already exists
    $stmt = $conn->prepare("SELECT id FROM timeslots WHERE date = ? AND time = ?");
    $stmt->bind_param("ss", $date, $time24);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        echo json_encode(["success" => false, "message" => "Slot already exists for this date and time."]);
        return;
    }

    $stmt->close();

    // Insert new slot
    $stmt = $conn->prepare("INSERT INTO timeslots (date, time, created_date, availability) VALUES (?, ?, ?, 1)");
    $stmt->bind_param("sss", $date, $time24, $createdDate);

    if ($stmt->execute()) {
        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["success" => false, "message" => $stmt->error]);
    }
  }
  
  function checkUserExists($conn) {
    $phone = $_POST['phone'] ?? '';
    if (!$phone) {
      echo json_encode(['exists' => false, 'message' => 'Phone number required.']);
      return;
    }

    $phone = '6'.$phone;
    
    $stmt = $conn->prepare("SELECT id, name FROM users WHERE phone_number = ?");
    $stmt->bind_param("s", $phone);
    $stmt->execute();
    $stmt->store_result();

    $user_id = null;
    $name = null;
    if ($stmt->num_rows > 0) {
      $stmt->bind_result($user_id, $name);
      $stmt->fetch();
      echo json_encode([
        'exists' => true,
        'user_id' => $user_id,
        'name' => $name
      ]);
    } else {
      echo json_encode(['exists' => false]);
    }
  }
  
  function registerUser($conn) {
    $phone = $_POST['phone'] ?? '';
    $name = $_POST['name'] ?? '';

    if (!$phone || !$name) {
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        return;
    }

    // Insert new user
    $phone = '6'.$phone;
    $stmt = $conn->prepare("INSERT INTO users (phone_number, name, isBlacklisted) VALUES (?, ?, 0)");
    $stmt->bind_param("ss", $phone, $name);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'userId' => $stmt->insert_id]);
    } else {
        echo json_encode(['success' => false, 'message' => $stmt->error]);
    }
  }
  
  function bookSlot($conn) {
    $slotId = $_POST['slot_id'] ?? null;
    $phone = $_POST['phone'] ?? null;
    $userId = $_POST['user_id'] ?? null;
    $name = $_POST['name'] ?? null;

    if (!$slotId || !$phone) {
        echo json_encode(['success' => false, 'message' => 'Missing slot or phone.']);
        return;
    }

    $phone = '6'.$phone;

    // Check if slot is available
    $stmt = $conn->prepare("SELECT availability FROM timeslots WHERE id = ?");
    $stmt->bind_param("i", $slotId);
    $stmt->execute();
    $stmt->bind_result($availability);
    if (!$stmt->fetch() || $availability != 1) {
        echo json_encode(['success' => false, 'message' => 'Slot not available.']);
        return;
    }
    $stmt->close();

    // Generate booking number
    $bookingNumber = generateBookingNumber();

    // Get user info
    $stmt = $conn->prepare("SELECT name FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->bind_result($dbName);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'User info not found.']);
        return;
    }
    $stmt->close();

    // Use provided name if given (for manual booking update)
    if ($name) {
        $dbName = $name;
    }

    $conn->begin_transaction();
    try {
        // Insert booking
        $stmt = $conn->prepare("INSERT INTO booking (user_id, name, phone_number, pax_number, booking_number, created_date, isCancelled) VALUES (?, ?, ?, 1, ?, NOW(), 0)");
        $stmt->bind_param("isss", $userId, $dbName, $phone, $bookingNumber);
        $stmt->execute();
        $bookingId = $stmt->insert_id;

        // Link slot to booking
        $stmt = $conn->prepare("INSERT INTO booking_slot (booking_id, timeslot_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $bookingId, $slotId);
        $stmt->execute();

        // Mark slot as unavailable
        $stmt = $conn->prepare("UPDATE timeslots SET availability = 0 WHERE id = ?");
        $stmt->bind_param("i", $slotId);
        $stmt->execute();

        $conn->commit();

        // Optionally, send SMS confirmation here

        echo json_encode(['success' => true, 'booking_number' => $bookingNumber]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
  }

  function updateIsShown($conn) {
    $slotId = $_POST['id'] ?? null;
    $isShown = isset($_POST['IsShown']) ? intval($_POST['IsShown']) : 0;

    if (!$slotId) {
        echo json_encode(['success' => false, 'message' => 'Missing slot ID.']);
        return;
    }

    $stmt = $conn->prepare("UPDATE timeslots SET IsShown = ? WHERE id = ?");
    $stmt->bind_param("ii", $isShown, $slotId);

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => $stmt->error]);
    }
  }
  
?>
