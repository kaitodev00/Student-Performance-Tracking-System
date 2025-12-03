<?php
ini_set('display_errors', 1); error_reporting(E_ALL);
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id'])) { http_response_code(401); exit('Not authenticated'); }
$user_id = (int)$_SESSION['user_id'];
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) { http_response_code(400); exit('Missing id'); }

$stmt = $conn->prepare("DELETE FROM notifications WHERE id = ? AND receiver_id = ? LIMIT 1");
$stmt->bind_param('ii', $id, $user_id);
$stmt->execute();
echo ($stmt->affected_rows === 1) ? 'OK' : 'NOOP';
$stmt->close();
