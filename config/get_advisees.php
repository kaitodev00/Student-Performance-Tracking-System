<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
header('Content-Type: application/json');

require 'db.php';

$userId   = $_SESSION['user_id'] ?? null;
$userRole = strtolower($_SESSION['user_role'] ?? '');

if (!$userId || $userRole !== 'adviser') {
  http_response_code(403);
  echo json_encode(['error' => 'Forbidden']);
  exit;
}

/* Get adviser's faculty_id */
$facultyId = null;
$stmt = $conn->prepare("SELECT faculty_id FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $userId);
$stmt->execute();
$stmt->bind_result($facultyId);
$stmt->fetch();
$stmt->close();

if (!$facultyId) {
  echo json_encode([]);
  exit;
}

/* CORRECT ADVISEE QUERY */
$sql = "
  SELECT DISTINCT
    u.id AS user_id,
    COALESCE(s.student_name, u.email) AS full_name
  FROM advisory a
  JOIN students s 
      ON s.id = a.student_id
  JOIN users u 
      ON u.id = s.user_id
  WHERE a.faculty_id = ?
  ORDER BY full_name
";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $facultyId);
$stmt->execute();
$res = $stmt->get_result();

$out = [];
while ($row = $res->fetch_assoc()) {
  $out[] = [
    'user_id'   => (int)$row['user_id'],
    'full_name' => $row['full_name'] ?: ('Student #' . $row['user_id'])
  ];
}
$stmt->close();

echo json_encode($out);
