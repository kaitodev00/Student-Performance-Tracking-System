<?php
session_start();

// Use the include that matches the rest of your app:
require_once __DIR__ . '/../config/db.php';
// If your project really stores the connection in ../database/db.php, switch back:
// require_once __DIR__ . '/../database/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header("Location: guardian-info.php");
  exit;
}

/* ---- Inputs ---- */
$student_id  = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;

$first_name  = trim(preg_replace('/\s+/', ' ', $_POST['first_name']  ?? ''));
$middle_name = trim(preg_replace('/\s+/', ' ', $_POST['middle_name'] ?? ''));
$last_name   = trim(preg_replace('/\s+/', ' ', $_POST['last_name']   ?? ''));

/* Fallback: split "name" if the form still sends it */
if ((!$first_name || !$last_name) && !empty($_POST['name'])) {
  $full  = trim(preg_replace('/\s+/', ' ', $_POST['name']));
  $parts = $full === '' ? [] : preg_split('/\s+/', $full);
  if (count($parts) === 1) {
    $first_name = $first_name ?: $parts[0];
  } elseif (count($parts) === 2) {
    $first_name = $first_name ?: $parts[0];
    $last_name  = $last_name  ?: $parts[1];
  } else {
    $first_name  = $first_name  ?: array_shift($parts);
    $last_name   = $last_name   ?: array_pop($parts);
    $middle_name = $middle_name ?: implode(' ', $parts);
  }
}

/* Other fields */
$email        = trim($_POST['email'] ?? '');
$contact_raw  = trim($_POST['contact_number'] ?? ($_POST['contact'] ?? ''));
$address      = trim($_POST['address'] ?? '');
$relationship = trim($_POST['relationship'] ?? ($_POST['relationship_select'] ?? ''));

/* ---- Validation ---- */
if ($student_id <= 0 || $first_name === '' || $last_name === '' || $relationship === '') {
  echo "<p style='color:red; text-align:center;'>Missing required fields (first &amp; last name, relationship).</p>";
  exit;
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  echo "<p style='color:red; text-align:center;'>Invalid email format.</p>";
  exit;
}
/* Allow digits, spaces, + ( ) - ; keep as typed */
if ($contact_raw !== '' && !preg_match('/^[0-9+\s()-]{7,25}$/', $contact_raw)) {
  echo "<p style='color:red; text-align:center;'>Invalid contact number. Use digits, +, spaces, ( ), - only.</p>";
  exit;
}

/* ---- Upsert by student_id ---- */
$existsStmt = $conn->prepare("SELECT id FROM guardians WHERE student_id = ?");
$existsStmt->bind_param("i", $student_id);
$existsStmt->execute();
$existing = $existsStmt->get_result()->fetch_assoc();
$existsStmt->close();

if ($existing) {
  $sql = "UPDATE guardians
            SET first_name = ?, middle_name = ?, last_name = ?,
                contact_number = ?, email = ?, relationship = ?, address = ?
          WHERE student_id = ?";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param(
    "sssssssi",
    $first_name, $middle_name, $last_name,
    $contact_raw, $email, $relationship, $address,
    $student_id
  );
} else {
  $sql = "INSERT INTO guardians
            (student_id, first_name, middle_name, last_name, contact_number, email, relationship, address)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param(
    "isssssss",
    $student_id, $first_name, $middle_name, $last_name,
    $contact_raw, $email, $relationship, $address
  );
}

/* ---- Execute ---- */
if ($stmt->execute()) {
  $stmt->close();
  $conn->close();
  header("Location: guardian-info.php?success=1");
  exit;
} else {
  $err = htmlspecialchars($stmt->error);
  $stmt->close();
  $conn->close();
  echo "<p style='color:red; text-align:center;'>Error saving guardian info: {$err}</p>";
  exit;
}
