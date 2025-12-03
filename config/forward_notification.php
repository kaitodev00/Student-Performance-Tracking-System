<?php
// /capstone/config/forward_notification.php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
header('Content-Type: application/json');

require 'db.php';

$adviserUserId = $_SESSION['user_id'] ?? null;
$role = strtolower($_SESSION['user_role'] ?? '');
if (!$adviserUserId || $role !== 'adviser') {
  http_response_code(403);
  echo json_encode(['error' => 'Forbidden']);
  exit;
}

$in       = json_decode(file_get_contents('php://input'), true);
$sourceId = isset($in['id']) ? (int)$in['id'] : 0;
$scope    = $in['scope'] ?? 'all';
$recips   = $in['recipient_ids'] ?? [];

if ($sourceId <= 0) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid source ID']);
  exit;
}

/* Load the source notification's title/body */
$stmt = $conn->prepare("SELECT title, body FROM notifications WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $sourceId);
$stmt->execute();
$src = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$src) {
  http_response_code(404);
  echo json_encode(['error' => 'Source notification not found']);
  exit;
}

$title = $src['title'] ?? 'Notification';
$body  = $src['body']  ?? '';

/* Get adviser's faculty_id */
$facultyId = null;
$stmt = $conn->prepare("SELECT faculty_id FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $adviserUserId);
$stmt->execute();
$stmt->bind_result($facultyId);
$stmt->fetch();
$stmt->close();

if (!$facultyId) {
  http_response_code(400);
  echo json_encode(['error' => 'Your account is not linked to a faculty profile.']);
  exit;
}

$targets = [];

if ($scope === 'all') {
  // all advisees via advisory → users
  $sql = "
    SELECT DISTINCT u.id AS user_id
    FROM advisory a
    JOIN users u ON u.student_id = a.student_id
    WHERE a.faculty_id = ?
  ";
  $q = $conn->prepare($sql);
  $q->bind_param('i', $facultyId);
  $q->execute();
  $res = $q->get_result();
  while ($r = $res->fetch_assoc()) $targets[] = (int)$r['user_id'];
  $q->close();

} elseif ($scope === 'specific') {
  if (!is_array($recips) || !count($recips)) {
    http_response_code(400);
    echo json_encode(['error' => 'No recipients provided']);
    exit;
  }
  // Validate that every selected user is indeed your advisee
  // Build placeholders for IN (...)
  $recips = array_values(array_unique(array_map('intval', $recips)));
  $placeholders = implode(',', array_fill(0, count($recips), '?'));

  $types = str_repeat('i', count($recips) + 1); // + faculty_id
  $sql = "
    SELECT DISTINCT u.id AS user_id
    FROM advisory a
    JOIN users u ON u.student_id = a.student_id
    WHERE a.faculty_id = ?
      AND u.id IN ($placeholders)
  ";

  $stmt = $conn->prepare($sql);
  // bind params dynamically: first faculty_id, then the list
  $params = array_merge([$types, $facultyId], $recips);
  // call_user_func_array needs refs
  $refs = [];
  foreach ($params as $k => $v) { $refs[$k] = &$params[$k]; }
  call_user_func_array([$stmt, 'bind_param'], $refs);

  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) $targets[] = (int)$r['user_id'];
  $stmt->close();

  if (!count($targets)) {
    http_response_code(400);
    echo json_encode(['error' => 'Selected users are not your advisees.']);
    exit;
  }

} else {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid scope']);
  exit;
}

if (!count($targets)) {
  http_response_code(400);
  echo json_encode(['error' => 'No recipients resolved']);
  exit;
}

/* Create a batch token for this forward action */
$batchToken = bin2hex(random_bytes(8));

$hasBatch = true;
$check = $conn->query("SHOW COLUMNS FROM notifications LIKE 'batch_token'");
if (!$check || $check->num_rows === 0) { 
    $hasBatch = false; 
}

if ($hasBatch) {
    $ins = $conn->prepare("
        INSERT INTO notifications (batch_token, title, body, sender_id, receiver_id, is_read, created_at)
        VALUES (?, ?, ?, ?, ?, 0, NOW())
    ");

    foreach ($targets as $rid) {
        $ins->bind_param('sssii', $batchToken, $title, $body, $adviserUserId, $rid);
        $ins->execute();
    }
    $ins->close();

} else {
    // Fallback if batch_token column doesn't exist
    $ins = $conn->prepare("
        INSERT INTO notifications (title, body, sender_id, receiver_id, is_read, created_at)
        VALUES (?, ?, ?, ?, 0, NOW())
    ");

    foreach ($targets as $rid) {
        $ins->bind_param('ssii', $title, $body, $adviserUserId, $rid);
        $ins->execute();
    }
    $ins->close();
}

echo json_encode([
  'message' => 'Notification forwarded.',
  'batch_token' => $batchToken
]);
