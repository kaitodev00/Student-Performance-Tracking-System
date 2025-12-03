<?php
// header.php

// 1) all your titles
$titleHead = [
  0 => 'PerfoMetrics',
  1 => 'Notifications',
  2 => 'Grades',
  4 => 'Profile',
  5 => 'Account',
  6 => 'Student Info',
  7 => 'Guardian Info',
  8 => 'Edit',
  9 => 'Forgot Password',
  10 => 'New Password',
  11 => 'Change Password',
];

// 2) decide which one to show
$current = isset($activeTab) && array_key_exists($activeTab, $titleHead)
         ? $activeTab
         : 0;
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    /* Reset */
body, h1, ul { margin:0; padding:0; }

body {
  font-family: Arial, sans-serif;
  background-color: #f4f6f8;
  /* remove box-shadow from here */
}

/* Header styling with shadow */
header {
  background-color: white;
  font-family: Roboto, sans-serif;
  height: 70px; /* increased height */
  padding: 20px 20px; /* increased padding for more height */
  display: flex;
  align-items: center;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1); /* optional subtle shadow */
}

.logo {
  display: flex;
  align-items: center;
}

.logo img {
  width: 35px;  /* increased from 40px */
  height: auto;
  margin-right: 10px;
}

/* default for all spans */
.name {
  font-size: 25px;  /* increased from 26px */
  font-weight: bold;
  color: black;
  line-height: 1;
  margin: 0;
}

/* override when it's the PerfoMetrics tab */
.name.default {
  color: #1e73be;
}
  </style>
</head>
<body>

  <div id="site-header" role="banner">
    <div class="logo">
      <?php if ($current === 0): ?>
        <img src="../image/logo.png" alt="logo">
      <?php endif; ?>

      <!-- Notice the conditional 'default' class here -->
      <span class="name<?= $current === 0 ? ' default' : '' ?>">
        <?= htmlspecialchars($titleHead[$current]) ?>
      </span>
 </div>


</body>
</html>
