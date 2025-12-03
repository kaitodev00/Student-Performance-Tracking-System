<?php
// /capstone/config/get_notifications.php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
header('Content-Type: application/json');
require 'db.php';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) { http_response_code(401); echo json_encode(['error'=>'Not logged in']); exit; }
// If specific ID is provided, return that single notification
if (isset($_GET['id'])) {
    $nid = intval($_GET['id']);

    $sql = "
      SELECT 
        n.id, n.title, n.body, n.sender_id, n.receiver_id, n.is_read, n.created_at,
        f.faculty_name AS sender_name,
        COALESCE(f.profile_picture, '/capstone/image/default_profile.jpg') AS sender_profile
      FROM notifications n
      LEFT JOIN users u ON n.sender_id = u.id
      LEFT JOIN tblfaculty f ON u.faculty_id = f.faculty_id
      WHERE n.receiver_id = ? AND n.id = ?
      LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $userId, $nid);
    $stmt->execute();
    $res = $stmt->get_result();
    $n = $res->fetch_assoc();

    if ($n) {
        // compute time_ago (same as your list)
        $created = strtotime($n['created_at']);
        $now     = time();
        $diff    = $now - $created;

        if ($diff < 60) $time_ago = 'Just now';
        elseif ($diff < 3600) $time_ago = floor($diff / 60) . ' min' . (floor($diff/60)>1?'s':'') . ' ago';
        elseif ($diff < 86400) $time_ago = floor($diff / 3600) . ' hour' . (floor($diff/3600)>1?'s':'') . ' ago';
        else $time_ago = floor($diff / 86400) . ' day' . (floor($diff/86400)>1?'s':'') . ' ago';

        $n['time_ago'] = $time_ago;
    }

    echo json_encode($n ?: []);
    exit;
}


$sql = "
  SELECT 
    n.id, n.title, n.body, n.sender_id, n.receiver_id, n.is_read, n.created_at,
    f.faculty_name AS sender_name,
    COALESCE(f.profile_picture, '/capstone/image/default_profile.jpg') AS sender_profile
  FROM notifications n
  LEFT JOIN users u ON n.sender_id = u.id
  LEFT JOIN tblfaculty f ON u.faculty_id = f.faculty_id
  WHERE n.receiver_id = ?
  ORDER BY n.created_at DESC
  LIMIT 20
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $userId);
$stmt->execute();
$res = $stmt->get_result();

$items = [];
while ($r = $res->fetch_assoc()) {
  // time ago (as you had)
  $created = strtotime($r['created_at']); $now = time(); $diff = $now - $created;
  if ($diff < 60) $time_ago = 'Just now';
  elseif ($diff < 3600) $time_ago = floor($diff/60) . ' min' . (floor($diff/60)>1?'s':'') . ' ago';
  elseif ($diff < 86400) $time_ago = floor($diff/3600) . ' hour' . (floor($diff/3600)>1?'s':'') . ' ago';
  else $time_ago = floor($diff/86400) . ' day' . (floor($diff/86400)>1?'s':'') . ' ago';

  $items[] = [
    'id'             => (int)$r['id'],
    'title'          => $r['title'] ?: 'Notification',
    'body'           => $r['body'] ?: '',
    'is_read'        => (int)$r['is_read'] === 1,
    'created_at'     => $r['created_at'],
    'time_ago'       => $time_ago,
    'sender_name'    => $r['sender_name'] ?: 'Unknown Sender',
    'sender_profile' => $r['sender_profile'] ?: '/capstone/image/default_profile.jpg'
  ];
}
$stmt->close();

echo json_encode($items);
