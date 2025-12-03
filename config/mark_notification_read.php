<?php
// /capstone/config/mark_notification_read.php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
header('Content-Type: application/json');
require 'db.php';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) { http_response_code(401); echo json_encode(['error'=>'Not logged in']); exit; }

$in = json_decode(file_get_contents('php://input'), true);
$id = isset($in['id']) ? (int)$in['id'] : 0;
if ($id <= 0) { http_response_code(400); echo json_encode(['error'=>'Invalid ID']); exit; }

$stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND receiver_id = ?");
$stmt->bind_param('ii', $id, $userId);
$stmt->execute();
$stmt->close();

echo json_encode(['ok' => true]);
