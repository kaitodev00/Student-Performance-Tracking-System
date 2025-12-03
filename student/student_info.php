<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id'])) {
  header("Location: index.php");
  exit;
}
$user_id = (int)$_SESSION['user_id'];

// Success message after update (then clear it)
$success_message = $_SESSION['success_message'] ?? null;
unset($_SESSION['success_message']);

// Fetch student + program
$stmt = $conn->prepare(<<<'SQL'
  SELECT
    s.*,
    CONCAT_WS(' ', s.student_name) AS full_name,
    p.program_name,
    p.program_code
  FROM students s
  LEFT JOIN programs p ON p.program_id = s.program_id
  WHERE s.user_id = ?
  LIMIT 1
SQL);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

if (!$student) {
  echo "<p style='color:red;'>Student not found.</p>";
  exit;
}

// Helpers
function yearLabel($id) {
  $map = [1=>'1st Year', 2=>'2nd Year', 3=>'3rd Year', 4=>'4th Year'];
  return $map[(int)$id] ?? 'N/A';
}
function safe($v) {
  $v = trim((string)$v);
  return $v === '' ? 'N/A' : htmlspecialchars($v, ENT_QUOTES);
}

// Build address line (optional single-line usage below)
$addrParts = array_filter([
  trim((string)($student['barangay'] ?? '')),
  trim((string)($student['city_municipality'] ?? '')),
  trim((string)($student['province'] ?? ''))
], fn($x) => $x !== '');
$addressLine = $addrParts ? htmlspecialchars(implode(', ', $addrParts), ENT_QUOTES) : 'N/A';

// Profile picture path (aligns with edit/upload path)
$pic = $student['profile_picture'] ?? '';
$picSrc = "../student/uploads/" . htmlspecialchars($pic, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Student Info</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: 'Segoe UI', system-ui, -apple-system, Arial, sans-serif; background:#f4f6fb; margin:0; padding:0; overflow:hidden; }
    header {
      background:#fff; padding:12px 16px; border-bottom:1px solid #ddd;
      display:flex; height:70px; justify-content:space-between; align-items:center;
      position:sticky; top:0; z-index:100; box-shadow:0 2px 8px rgba(0,0,0,0.1);
    }
    .back-button, .edit-button { font-size:14px; color:#1a73e8; text-decoration:none; font-weight:bold; }
    .header-title { font-size:17px; font-weight:bold; margin:0; text-align:center; flex-grow:1; }
    .info { padding:16px; }
    .container { background:#fff; border-radius:12px; padding:16px; height:670px; box-shadow:0 4px 12px rgba(0,0,0,0.1); overflow-y:auto; }
    .success { background:#e8f7ee; border:1px solid #b6e6c7; color:#146c2e; padding:10px 12px; border-radius:8px; margin-bottom:12px; font-size:13px; }
    .profile-pic { width:100px; height:100px; border-radius:50%; margin:0 auto 15px; display:block; object-fit:cover; border:1px solid #eee; }
    h2 { font-size:16px; font-weight:bold; text-align:center; margin:8px 0 2px; }
    h5 { text-align:center; font-size:16px; color:#2684fc; margin-bottom:16px; }
    .highlight { border:0; border-top:2px solid #2684fc; margin:16px 0; }
    .stud_num { font-size:12px; text-align:center; color:#888; margin-bottom:16px; text-transform:uppercase; }
    .section-title { font-size:14px; font-weight:bold; color:#000; margin-top:30px; margin-bottom:8px; border-bottom:1px solid #ddd; padding-bottom:4px; }
    .info-row { font-size:13px; display:flex; justify-content:space-between; padding:4px 0; margin-top:4px; color:#444; }
    .info-label { font-weight:500; flex:1; }
    .info-value { text-align:right; flex:1; color:#555; }
    hr { border:0; border-top:1px solid #ddd; margin:16px 0; }
    footer { margin-top:30px; }
  </style>
</head>
<body>

<header>
  <a href="profile.php" class="back-button">&#8592;</a>
  <div class="header-title">Student Info</div>
  <a href="edit_student.php" class="edit-button">Edit</a>
</header>

<div class="info">
  <div class="container">
    <?php if (!empty($success_message)): ?>
      <div class="success"><?= htmlspecialchars($success_message, ENT_QUOTES) ?></div>
    <?php endif; ?>

    <h5>Student</h5>
    <hr class="highlight"/>

    <img
      src="<?= $picSrc ?>"
      onerror="this.onerror=null; this.src='../image/default_profile.jpg';"
      class="profile-pic"
      alt="Profile picture"
    />

    <?php
      $name = (!empty($student['student_name']) && $student['student_name'] !== 'N/A')
              ? $student['student_name'] : 'N/A';
    ?>
    <h2><?= htmlspecialchars($name, ENT_QUOTES) ?></h2>

    <h5 class="stud_num"><?= safe($student['student_number'] ?? '') ?></h5>

    <div class="section">
      <div class="section-title">Personal Information</div>
      <div class="info-row"><span class="info-label">Email:</span><span class="info-value"><?= safe($student['email'] ?? '') ?></span></div>
      <div class="info-row"><span class="info-label">Contact:</span><span class="info-value"><?= safe($student['contact'] ?? '') ?></span></div>
      <div class="info-row"><span class="info-label">Birthdate:</span><span class="info-value"><?= safe($student['dob'] ?? '') ?></span></div>
      <div class="info-row"><span class="info-label">Sex:</span><span class="info-value"><?= safe($student['sex'] ?? '') ?></span></div>
    </div>

    <div class="section">
      <div class="section-title">Address</div>
      <div class="info-row"><span class="info-label">Province:</span><span class="info-value"><?= safe($student['province'] ?? '') ?></span></div>
      <div class="info-row"><span class="info-label">City / Municipality:</span><span class="info-value"><?= safe($student['city_municipality'] ?? '') ?></span></div>
      <div class="info-row"><span class="info-label">Barangay:</span><span class="info-value"><?= safe($student['barangay'] ?? '') ?></span></div>
      <!-- Or show single line:
      <div class="info-row"><span class="info-label">Address:</span><span class="info-value"><?= $addressLine ?></span></div>
      -->
    </div>

    <div class="section">
      <div class="section-title">Student Information</div>
      <div class="info-row">
        <span class="info-label">Program:</span>
        <span class="info-value">
          <?= safe(($student['program_name'] ?? '') ?: ($student['program_code'] ?? '')) ?>
        </span>
      </div>
      <div class="info-row">
        <span class="info-label">Year Level:</span>
        <span class="info-value"><?= htmlspecialchars(yearLabel($student['year_level_id'] ?? ''), ENT_QUOTES) ?></span>
      </div>
    </div>

    <hr/>
  </div>
</div>

<footer>
  <?php include '../config/nav_bar.php'; ?>
</footer>

</body>
</html>
