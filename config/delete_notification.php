<?php
// /capstone/config/delete_notification.php
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

// hard delete (or swap to soft delete with deleted_at)
$stmt = $conn->prepare("DELETE FROM notifications WHERE id = ? AND receiver_id = ?");
$stmt->bind_param('ii', $id, $userId);
if ($stmt->execute()) echo json_encode(['message' => 'Notification deleted.']);
else { http_response_code(500); echo json_encode(['error'=>'Delete failed']); }
$stmt->close();
