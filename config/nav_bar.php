<?php
if (!isset($activeTab)) $activeTab = 'home'; // fallback to dashboard

function isActive($page) {
  global $activeTab;
  return $activeTab === $page ? 'active' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Phone Bottom Navigation Bar</title>
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet" />
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: Arial, sans-serif;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      margin-bottom: 60px;
    }

    .content {
      flex: 1;
      padding: 20px;
    }

    .bottom-nav {
      position: fixed;
      left: 0;
      bottom: 0;
      width: 100%;
      height: 60px;
      background-color: #fff;
      border-top: 1px solid #ccc;
      display: flex;
      justify-content: space-around;
      align-items: center;
      z-index: 100;
    }

    .nav-item {
      text-align: center;
      flex-grow: 1;
      cursor: pointer;
      padding: 5px 0;
    }

    .nav-item .material-icons {
      font-size: 24px;
      display: block;
    }

    .nav-item.active {
      color: #007BFF;
    }
    .notif-badge {
  position: absolute;
  top: 5px;
  right: 30%;  /* adjust depending on icon alignment */
  background: #dc3545;
  color: #fff;
  border-radius: 999px;
  min-width: 18px;
  height: 18px;
  line-height: 18px;
  font-size: 12px;
  text-align: center;
  padding: 0 5px;
  display: inline-block;
}

  </style>
</head>
<body>

  <div class="bottom-nav">
  <div class="nav-item <?= isActive('home') ?>" data-page="home">
    <span class="material-icons">home</span>
  </div>
  <div class="nav-item <?= isActive('grades') ?>" data-page="grades" style="position: relative;">
    <span class="material-icons">notifications</span>
    <span id="notifBadge" class="notif-badge" style="display:none;">0</span>
  </div>

  <div class="nav-item <?= isActive('status') ?>" data-page="status">
    <span class="material-icons">bar_chart</span>
  </div>
  <div class="nav-item <?= isActive('profile') ?>" data-page="profile">
    <span class="material-icons">person</span>
  </div>
</div>

  <script>
    const navItems = document.querySelectorAll('.nav-item');

    // Handle login reset logic
    let activeNav = localStorage.getItem('activeNav');

    // If just logged in, reset to 'home'
    if (sessionStorage.getItem('justLoggedIn') === 'true') {
      activeNav = 'home';
      localStorage.setItem('activeNav', 'home');
      sessionStorage.removeItem('justLoggedIn');
    }

    // Set active class
    if (activeNav) {
      navItems.forEach(nav => nav.classList.remove('active'));
      const activeItem = document.querySelector(`.nav-item[data-page="${activeNav}"]`);
      if (activeItem) {
        activeItem.classList.add('active');
      }
    } else {
      const defaultItem = document.querySelector(`.nav-item[data-page="home"]`);
      defaultItem && defaultItem.classList.add('active');
    }

    // Handle navigation click and store state
    navItems.forEach(item => {
      item.addEventListener('click', () => {
        const page = item.getAttribute('data-page');
        localStorage.setItem('activeNav', page);

        let url = '';
        switch (page) {
          case 'home':
            url = 'dashboard.php';
            break;
          case 'grades':
            url = '../student/notify.php';
            break;
          case 'status':
            url = '../student/performance.php';
            break;
          case 'survey':
            url = '../student/survey.php';
            break;
          case 'profile':
            url = 'profile.php';
            break;
        }

        if (url) window.location.href = url;
      });
    });
  </script>
</body>
</html>
