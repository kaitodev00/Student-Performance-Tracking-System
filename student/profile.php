<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
?>

<?php
session_start();
include '../config/db.php';

if (!isset($_SESSION['user_id'])) {
  header("Location: index.php");
  exit;
}
$user_id = (int)$_SESSION['user_id'];

// Only prepare *once*, including the CONCAT_WS alias:
$stmt = $conn->prepare(<<<'SQL'
  SELECT
    *,
    CONCAT_WS(' ',
      student_name
    ) AS full_name
  FROM students
  WHERE user_id = ?
SQL
);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

if (!$student) {
  echo "<p style='color:red;'>Student not found.</p>";
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Account</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      background-color: #f7f7f7;
      overflow: hidden;
      margin: 0;
    }

    .info {
        display: flex;
        justify-content: center;
        align-items: center;
        height: 700px;
        padding: 15px;
    }

    .container {
      background-color: #fff;
      border-radius: 15px;
      width: 350px;
      padding: 20px;
      height: 660px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    .profile-pic {
      width: 100px;
      height: 100px;
      border-radius: 50%;
      margin: 0 auto 15px;
      display: block;
      object-fit: cover;
    }

    h2 {
        text-align: center;
        font-size: 20px;
        font-weight: bold;
        margin: 10px 0 5px;
        }

        h5 {
        text-align: center;
        font-size: 14px;
        color: #333;
        opacity: 0.6;
        margin: 5px 0 10px;
        }


    .section {
      margin-top: 20px;
    }

    .section h3 {
      font-size: 14px;
      margin-bottom: 10px;
      font-weight: bold;
    }

    .info-box {
    background-color: #f5f5f5;
    border-radius: 10px;
    padding: 10px 15px;
    display: flex;
    flex-direction: column;
    gap: 5px;
    }

    .info-link {
    text-decoration: none;
    color: #333;
    font-size: 14px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    }

    .info-link:hover {
    color: #1e73be;
    }

    .info-box hr {
    border: none;
    border-top: 1px solid #ddd;
    margin: 0;
    }


    .logout-btn,
    .change-pass-btn {
      background-color: #f1f3f5;
      padding: 12px;
      border-radius: 10px;
      text-align: left;
      text-decoration: none;
      color: #1e73be;
      font-size: 14px;
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .logout-btn:hover,
    .change-pass-btn:hover {
      background-color: #e2e6ea;
    }

    hr {
      border: none;
      border-top: 1px solid #eee;
      margin: 10px 0;
    }
  </style>
</head>
<body>
  <header>
    <?php $activeTab = 4; include '../config/header.php'; ?>
  </header>

  <div class="info">
    <div class="container">
      <!-- Profile -->
      <img src="uploads/<?= htmlspecialchars($student['profile_picture']) ?>" 
     onerror="this.onerror=null; this.src='../image/default_profile.jpg';" 
     class="profile-pic">
      <?php
    $name = $student['student_name'];
    if (!empty($student['student_name']) && $student['student_name'] !== 'N/A') {
        $name = $student['student_name'];
    }
    
  ?>
      <!-- Display the full name -->
<h2><?= htmlspecialchars($name) ?></h2>

      <h5><?= htmlspecialchars($student['student_number']) ?></h5>
      <hr />

      <!-- Information -->
      <div class="section">
        <h3>Information</h3>
        <div class="info-box">
            <a href="student_info.php" class="info-link">Student Information ➔</a>
            <hr />
            <a href="../guardian/guardian-info.php" class="info-link">Guardian Information ➔</a>
        </div>
        </div>

      <!-- Security -->
      <div class="section">
        <h3>Security</h3>
        <a href="../change_password.php" class="change-pass-btn">🔒 Change Password</a>
      </div>

      <!-- Session -->
      <div class="section">
        <h3>Session</h3>
        <a href="../config/logout.php" class="logout-btn">🚪 Log out</a>
      </div>
    </div>
  </div>

  <footer>
    <?php include '../config/nav_bar.php'; ?>
  </footer>
</body>
</html>
