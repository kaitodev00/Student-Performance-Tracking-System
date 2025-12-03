<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include '../config/db.php';

if (!isset($_SESSION['user_id'])) {
  header("Location: index.php");
  exit;
}
$user_id = (int)$_SESSION['user_id'];

/* Fetch student by user_id */
$stmt = $conn->prepare("SELECT * FROM students WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
  echo "<p style='color:red;'>Student not found.</p>";
  exit;
}
$student_pk = (int)$student['id'];

/* Fetch guardian linked to this student (guardians.student_id -> students.id) */
$stmt = $conn->prepare("SELECT * FROM guardians WHERE student_id = ? LIMIT 1");
$stmt->bind_param("i", $student_pk);
$stmt->execute();
$guardian = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* Helpers */
$h = fn($v) => htmlspecialchars((string)$v ?? '', ENT_QUOTES, 'UTF-8');
$show = fn($v) => ($v !== null && trim((string)$v) !== '') ? $h($v) : '—';

/* Build full name from parts */
$full_name = '—';
if ($guardian) {
  $parts = array_filter([
    $guardian['first_name'] ?? null,
    $guardian['middle_name'] ?? null,
    $guardian['last_name'] ?? null
  ], fn($x) => $x !== null && trim($x) !== '' );
  $full_name = $parts ? $h(implode(' ', $parts)) : '—';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Guardian Info</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body {
      font-family: 'Segoe UI', sans-serif;
      background-color: #f6f8fb;
      margin: 0;
    }
    header {
      background: white;
      height: 60px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 16px;
      border-bottom: 1px solid #ddd;
      font-weight: 600;
      position: sticky;
      top: 0;
      z-index: 10;
    }
    .back-button, .edit-button {
      color: #1a73e8;
      text-decoration: none;
      font-size: 14px;
    }
    .main {
      display: flex;
      justify-content: center;
      margin: 24px 12px 100px;
    }
    .card {
      width: 100%;
      max-width: 420px;
      background: white;
      border-radius: 16px;
      padding: 24px 20px;
      box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
    }
    .card h5 {
      text-align: center;
      color: #2684fc;
      font-weight: 600;
      margin-bottom: 4px;
    }
    .guardian-name {
      text-align: center;
      font-size: 18px;
      font-weight: bold;
      margin: 8px 0 2px;
    }
    .guardian-role {
      text-align: center;
      font-size: 13px;
      color: #999;
      margin-bottom: 16px;
    }
    .section { margin-top: 20px; }
    .section-title {
      font-size: 14px; font-weight: bold; margin-bottom: 10px; color: #333; padding-bottom: 4px;
      border-bottom: 1px solid #eee;
    }
    .info-row {
      display: flex; justify-content: space-between; font-size: 13px;
      padding: 8px 0; border-bottom: 1px solid #f1f1f1;
    }
    .info-label { font-weight: 500; color: #666; flex: 1; }
    .info-value { flex: 1; text-align: right; color: #222; }
    footer { position: fixed; bottom: 0; width: 100%; }
  </style>
</head>
<body>

<header>
  <a href="../student/profile.php" class="back-button" aria-label="Back"><i class="bi bi-arrow-left"></i></a>
  <div>Guardian Info</div>
  <a href="../guardian/edit-guardian.php" class="edit-button">Edit</a>
</header>

<main class="main">
  <div class="card">
    <h5>Guardian</h5>

    <?php if ($guardian): ?>
      <div class="guardian-name"><?= $full_name ?></div>
      <div class="guardian-role"><?= $show($guardian['relationship'] ?? '') ?></div>

      <div class="section">
        <div class="section-title">Guardian Information</div>
        <div class="info-row">
          <span class="info-label">Name of Guardian:</span>
          <span class="info-value"><?= $full_name ?></span>
        </div>
        <div class="info-row">
          <span class="info-label">Email Address:</span>
          <span class="info-value"><?= $show($guardian['email'] ?? '') ?></span>
        </div>
        <div class="info-row">
          <span class="info-label">Contact:</span>
          <span class="info-value"><?= $show($guardian['contact_number'] ?? '') ?></span>
        </div>
        <div class="info-row">
          <span class="info-label">Current Address:</span>
          <span class="info-value"><?= $show($guardian['address'] ?? '') ?></span>
        </div>
        <div class="info-row">
          <span class="info-label">Relationship:</span>
          <span class="info-value"><?= $show($guardian['relationship'] ?? '') ?></span>
        </div>
      </div>
    <?php else: ?>
      <p class="text-muted" style="text-align:center; margin: 16px 0 0;">
        No guardian information available.
        <br>
        <a href="../guardian/edit-guardian.php" class="edit-button">Add guardian</a>
      </p>
    <?php endif; ?>
  </div>
</main>

<footer>
  <?php include '../config/nav_bar.php'; ?>
</footer>

</body>
</html>
