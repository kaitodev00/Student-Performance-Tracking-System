<?php
session_start();

session_unset(); // Unset all session variables
session_destroy(); // Destroy the session

// Clear nav bar selection from localStorage using JS
echo "<script>
  localStorage.removeItem('activeNav');
  window.location.href = '../index.php';
</script>";
exit;
?>
