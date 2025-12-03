<?php
ini_set('display_errors', 1); error_reporting(E_ALL);
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id'])) { http_response_code(401); exit('Not authenticated'); }
$user_id = (int)$_SESSION['user_id'];

$idsCsv = $_POST['ids'] ?? '';
$ids = array_values(array_filter(array_map('intval', explode(',', $idsCsv)), fn($v)=>$v>0));
if (empty($ids)) { http_response_code(400); exit('No ids'); }

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types = str_repeat('i', count($ids)+1);
$params = array_merge([$types, $user_id], $ids);

$sql = "DELETE FROM notifications WHERE receiver_id = ? AND id IN ($placeholders)";
$stmt = $conn->prepare($sql);
$stmt->bind_param(...array_reduce($params, function($carry,$item){
  if (!$carry) return [$item];
  $carry[] = $item; return $carry;
}));
$stmt->execute();
echo 'OK';
$stmt->close();
