<?php
session_start();
require_once '../config/db.php';

// 1️⃣ Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Invalid request method.");
}

// 2️⃣ Verify CSRF token
if (!isset($_POST['csrf_token'], $_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    die("Invalid CSRF token.");
}

// 3️⃣ Verify session
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// 4️⃣ Fetch current profile picture
$old_pic = '';
$stmt = $conn->prepare("SELECT profile_picture FROM students WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $row = $res->fetch_assoc()) {
    $old_pic = $row['profile_picture'] ?? '';
}
$stmt->close();

$upload_dir = "../student/uploads/";
$profile_picture = null;

// 5️⃣ Handle “Remove picture” checkbox
if (isset($_POST['remove_picture'])) {
    $profile_picture = '';
    if (!empty($old_pic) && file_exists($upload_dir . $old_pic)) {
        @unlink($upload_dir . $old_pic);
    }
}

// 6️⃣ Handle new uploaded profile picture
if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif'];
    $ext = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));

    if (in_array($ext, $allowedExts, true)) {
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        $new_name = uniqid("img_", true) . '.' . $ext;
        $target = $upload_dir . $new_name;

        if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $target)) {
            $profile_picture = $new_name;

            // Remove old pic if exists
            if (!empty($old_pic) && file_exists($upload_dir . $old_pic)) {
                @unlink($upload_dir . $old_pic);
            }
        }
    }
}

// 7️⃣ Collect form fields that match your `students` table
$email             = trim($_POST['email'] ?? '');
$contact           = trim($_POST['contact'] ?? '');
$dob               = trim($_POST['dob'] ?? '');
$sex               = trim($_POST['sex'] ?? '');
$province          = trim($_POST['province'] ?? '');
$city_municipality = trim($_POST['city_municipality'] ?? '');
$barangay          = trim($_POST['barangay'] ?? '');
$student_number    = trim($_POST['student_number'] ?? '');
$year_level_id     = (int)($_POST['year_level_id'] ?? 0);

// 8️⃣ Prepare SQL query (include profile_picture only when needed)
$sql = "UPDATE students 
        SET email = ?, contact = ?, dob = ?, sex = ?, province = ?, 
            city_municipality = ?, barangay = ?, student_number = ?, year_level_id = ?";
$types = "ssssssssi";
$params = [
    $email, $contact, $dob, $sex, $province,
    $city_municipality, $barangay, $student_number, $year_level_id
];

if ($profile_picture !== null) {
    $sql .= ", profile_picture = ?";
    $types .= "s";
    $params[] = $profile_picture;
}

$sql .= " WHERE user_id = ?";
$types .= "i";
$params[] = $user_id;

// 9️⃣ Execute
$stmt = $conn->prepare($sql);
if ($stmt === false) {
    die("SQL prepare error: " . $conn->error);
}

$stmt->bind_param($types, ...$params);

if (!$stmt->execute()) {
    die("SQL execute error: " . $stmt->error);
}

$stmt->close();
$conn->close();

// 🔟 Redirect to student_info.php after success
$_SESSION['success_message'] = "Profile updated successfully.";
header("Location: student_info.php");
exit;
?>
