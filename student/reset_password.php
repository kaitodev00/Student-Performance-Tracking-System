<?php
// reset_password.php
session_start();
require 'database/db.php';

if (empty($_SESSION['reset_user_id']) || empty($_SESSION['otp_verified'])) {
    header('Location: forgot_password.php');
    exit;
}
$user_id = $_SESSION['reset_user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p1 = $_POST['password'];
    $p2 = $_POST['confirm_password'];

    if (strlen($p1) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($p1 !== $p2) {
        $error = 'Passwords do not match.';
    } else {
        // 1) Hash & update
        $hash = password_hash($p1, PASSWORD_DEFAULT);
        $up = $conn->prepare("
          UPDATE users 
          SET password = ?, 
              token = NULL, 
              token_expires = NULL,
              must_change_password = 0
          WHERE id = ?
        ");
        $up->bind_param('si', $hash, $user_id);
        $up->execute();

        // 2) Clean up session
        unset($_SESSION['reset_user_id'], $_SESSION['otp_verified']);

        echo '<p>Your password has been reset. <a href="login.php">Log in</a>.</p>';
        exit;
    }
}
?>
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Reset Password</title></head>
<body>
  <h2>Choose a New Password</h2>
  <?php if (!empty($error)) echo "<p style='color:red;'>$error</p>"; ?>
  <form method="post">
    <label>New Password:<br>
      <input type="password" name="password" required>
    </label><br><br>
    <label>Confirm Password:<br>
      <input type="password" name="confirm_password" required>
    </label><br><br>
    <button type="submit">Change Password</button>
  </form>
</body>
</html>
