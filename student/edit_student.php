<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id'])) {
  header("Location: index.php");
  exit;
}
$user_id = (int)$_SESSION['user_id'];

// CSRF token
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* Fetch student + program */
$stmt = $conn->prepare(<<<'SQL'
  SELECT
    s.*,
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
  echo "<p style='color:red; font:14px/1.4 system-ui;'>Student not found.</p>";
  exit;
}

// Display name
$display_name = $student['student_name'] ?? '';

// Saved address values (shown but read-only)
$saved_province = $student['province'] ?? '';
$saved_city     = $student['city_municipality'] ?? '';
$saved_barangay = $student['barangay'] ?? '';

// Year level
$year_level_id = (string)($student['year_level_id'] ?? '');

// Program label (read-only)
$program_label = trim(($student['program_name'] ?? '') . (empty($student['program_code']) ? '' : " ({$student['program_code']})"));
if ($program_label === '') $program_label = 'N/A';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Profile</title>
  <link rel="stylesheet" href="../design/edit_student.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    .page-bg { max-width: 960px; margin: 0 auto; padding: 12px; }
    .profile-card { background:#fff; border:1px solid #eee; border-radius:12px; padding:16px; }
    .section-head { font-weight:700; font-size:18px; }
    .section-title { margin-top:16px; font-weight:600; }
    .form-group { margin:10px 0; }
    .form-group input, .form-group select { width:100%; padding:10px; }
    .button-group { display:flex; gap:10px; margin-top:14px; }
    .btn { padding:10px 14px; border-radius:8px; text-decoration:none; border:1px solid transparent; display:inline-block; }
    .btn-primary { background:#0d6efd; color:#fff; }
    .btn-light { background:#f8f9fa; color:#222; border-color:#e5e7eb; }
    .avatar-wrapper { position:relative; width:132px; height:132px; margin:0 auto 8px; }
    .avatar { width:132px; height:132px; border-radius:50%; object-fit:cover; border:1px solid #e5e7eb; }
    .upload-icon { position:absolute; right:4px; bottom:4px; background:#0d6efd; color:#fff; width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; }
    header { display:flex; align-items:center; gap:8px; padding:10px 12px; }
    .back-button { text-decoration:none; font-size:22px; }
    .header-title { font-weight:700; }
    /* Read-only look */
    input[readonly] {
      background:#f9fafb;
      border-color:#e5e7eb;
      color:#374151;
      cursor:not-allowed;
    }
  </style>
</head>
<body>
  <header>
    <a href="student_info.php" class="back-button" aria-label="Back">&#8592;</a>
    <div class="header-title">Edit</div>
  </header>

  <div class="page-bg">
    <div class="profile-card">
      <div class="section-head">Student</div>
      <hr class="highlight"/>

      <form action="update_student.php" method="POST" enctype="multipart/form-data" id="editForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES) ?>">

        <div class="avatar-wrapper">
          <img
            src="../student/uploads/<?= htmlspecialchars($student['profile_picture'] ?? '', ENT_QUOTES) ?>"
            onerror="this.onerror=null; this.src='../image/default_profile.jpg';"
            class="avatar" alt="Profile picture">
          <label for="upload" class="upload-icon" title="Upload new photo">
            <i class="bi bi-camera-fill"></i>
            <input type="file" name="profile_picture" id="upload" hidden accept="image/*">
          </label>
        </div>

        <h4 class="full-name" style="text-align:center; margin:6px 0 2px;">
          <?= htmlspecialchars($display_name, ENT_QUOTES) ?>
        </h4>
        <p class="student-id" style="text-align:center; color:#6b7280;">
          <?= htmlspecialchars($student['student_number'] ?? '', ENT_QUOTES) ?>
        </p>

        <div style="text-align:center; margin-bottom:10px;">
          <label><input type="checkbox" name="remove_picture"> Remove current picture</label>
        </div>

        <!-- Program (READ-ONLY) -->
        <div class="section-title">Program</div>
        <div class="form-group">
          <input type="text" value="<?= htmlspecialchars($program_label, ENT_QUOTES) ?>" readonly>
          <!-- keep the id in a hidden field (not edited) -->
          <input type="hidden" name="program_id" value="<?= htmlspecialchars($student['program_id'] ?? '', ENT_QUOTES) ?>">
        </div>

        <div class="section-title">Personal Information</div>
        <!-- Email (READ-ONLY) -->
        <div class="form-group">
          <input type="email" name="email" placeholder="Email Address"
                 value="<?= htmlspecialchars($student['email'] ?? '', ENT_QUOTES) ?>" readonly>
        </div>
        <!-- Contact (READ-ONLY) -->
        <div class="form-group">
          <input type="text" name="contact" placeholder="Contact"
                 value="<?= htmlspecialchars($student['contact'] ?? '', ENT_QUOTES) ?>" readonly>
        </div>
        <!-- Birthdate (editable) -->
        <div class="form-group">
          <input type="date" name="dob" value="<?= htmlspecialchars($student['dob'] ?? '', ENT_QUOTES) ?>">
        </div>
        <!-- Sex (editable) -->
        <div class="form-group">
          <label for="sex">Sex</label>
          <select id="sex" name="sex" required>
            <option value="">Select Sex</option>
            <?php foreach (['Male','Female'] as $sx): ?>
              <option value="<?= $sx ?>" <?= (($student['sex'] ?? '') === $sx) ? 'selected' : '' ?>><?= $sx ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Address (READ-ONLY) -->
        <div class="section-title">Address</div>
        <div class="form-group">
          <input type="text" name="province" placeholder="Province"
                 value="<?= htmlspecialchars($saved_province, ENT_QUOTES) ?>" readonly>
        </div>
        <div class="form-group">
          <input type="text" name="city_municipality" placeholder="City / Municipality"
                 value="<?= htmlspecialchars($saved_city, ENT_QUOTES) ?>" readonly>
        </div>
        <div class="form-group">
          <input type="text" name="barangay" placeholder="Barangay"
                 value="<?= htmlspecialchars($saved_barangay, ENT_QUOTES) ?>" readonly>
        </div>

        <div class="section-title">Academic</div>
        <!-- Student Number (READ-ONLY) -->
        <div class="form-group">
          <label for="student_number">Student Number</label>
          <input type="text" name="student_number" id="student_number"
                 value="<?= htmlspecialchars($student['student_number'] ?? '', ENT_QUOTES) ?>" readonly>
        </div>

        <!-- Year Level (editable) -->
        <div class="form-group">
          <label for="year_level_id">Year Level</label>
          <select name="year_level_id" id="year_level_id" required>
            <option value="">Select Year</option>
            <?php
              $levels = ['1'=>'1st Year','2'=>'2nd Year','3'=>'3rd Year','4'=>'4th Year'];
              foreach ($levels as $id => $label):
            ?>
              <option value="<?= $id ?>" <?= ($year_level_id === (string)$id ? 'selected' : '') ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="button-group">
          <a href="student_info.php" class="btn btn-light">Discard</a>
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
      </form>

      <!-- PROFILE PIC PREVIEW -->
      <script>
        document.getElementById('upload').addEventListener('change', function(e) {
          const file = e.target.files?.[0];
          if (!file) return;
          const reader = new FileReader();
          reader.onload = evt => { document.querySelector('.avatar').src = evt.target.result; };
          reader.readAsDataURL(file);
        });
      </script>

      <?php include '../config/nav_bar.php'; ?>
    </div>
  </div>
</body>
</html>
