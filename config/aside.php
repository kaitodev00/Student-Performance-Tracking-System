<?php
include '../config/db.php';  // make sure $conn is ready

/* ----------------------------- ROUTE MAP --------------------------------- */
/* Edit these paths to match your folders (absolute from site root) */
$ROUTES = [
  'system_admin' => [
    'dashboard' => '../admin_panel/admin_dash.php',
    'faculty_list' => '../admin_panel/faculty_list.php',
    'student_list' => '../admin_panel/student_list.php',
    'assign' => '../admin_panel/assign.php',
    'manage_acc' => '../admin_panel/manage_acc.php',
    'settings' => '../admin_panel/settings.php',
  ],
  'dean' => [
    'dashboard' => '../dean/dean_dashboard.php',
    'generate_reports' => '../dean/reports.php',
  ],
  'adviser' => [
    'dashboard' => '../adviser/adviser_dash.php',
    'import_grade' => '../adviser/import_grade.php',
    'grades' => '../adviser/advisee.php',
    'send_notif' => '../adviser/send_notif.php',
  ],
  'guidance' => [
    'dashboard' => '../guidance/dashboard.php',
    'survey' => '../guidance/survey.php',
    'reports' => '../guidance/reports.php',
  ],
  'logout' => '../config/logout.php',
];
/* ------------------------------------------------------------------------ */

// ---- roles from session (multi-role aware) ----
$roles = $_SESSION['roles'] ?? [];
if (!is_array($roles) || empty($roles)) {
    if (!empty($_SESSION['user_role'])) {  // legacy fallback
        $roles = [$_SESSION['user_role']];
    }
}
$primaryRole = $_SESSION['user_role'] ?? ($roles[0] ?? null); // original role

// If this user is in any faculty role, fetch their name/pic
if (array_intersect($roles, ['adviser', 'dean', 'guidance', 'system_admin'])) {
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId) {
        $stmt = $conn->prepare("
            SELECT f.faculty_name, f.profile_picture
              FROM tblfaculty f
              JOIN users      u ON f.faculty_id = u.faculty_id
             WHERE u.id = ?
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($faculty = $result->fetch_assoc()) {
            $name = trim("{$faculty['faculty_name']}");
            if (!empty($faculty['profile_picture'])) {
                $profilePic = '/faculty/uploads/' . ltrim($faculty['profile_picture'], '/');
            }
        }
        $stmt->close();
    }
}

// tiny helper
function navLink(string $href, string $iconClass, string $label, bool $isActive = false): string {
    $activeClass = $isActive ? 'active' : '';  // If active, add 'active' class
    return '<a href="'.$href.'" class="'.$activeClass.'"><i class="'.$iconClass.'"></i> '.$label.'</a>';
}
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<aside class="sidebar">

  <nav>
    <?php if (in_array('system_admin', $roles, true) && isset($ROUTES['system_admin'])): ?>
    <?php if ($primaryRole === 'system_admin'): ?>
      <?= navLink($ROUTES['system_admin']['dashboard'], 'fas fa-home', 'Dashboard', basename($_SERVER['PHP_SELF']) == 'admin_dash.php') ?>
    <?php endif; ?>
    
    <!-- Manage List toggle -->
       <div class="toggle-dropdown">
      <button class="dropbtn <?= basename($_SERVER['PHP_SELF']) == 'faculty_list.php' || basename($_SERVER['PHP_SELF']) == 'student_list.php' ? 'active' : '' ?>" onclick="toggleManageList(event)">
        <i class="fas fa-list"></i> Manage List
      </button>
      <div class="dropdown-content <?= basename($_SERVER['PHP_SELF']) == 'faculty_list.php' || basename($_SERVER['PHP_SELF']) == 'student_list.php' ? 'show' : '' ?>">
        <?= navLink($ROUTES['system_admin']['faculty_list'], 'fas fa-chalkboard-teacher', 'Faculty Advisors', basename($_SERVER['PHP_SELF']) == 'faculty_list.php') ?>
        <?= navLink($ROUTES['system_admin']['student_list'], 'fas fa-users', 'CICT Students', basename($_SERVER['PHP_SELF']) == 'student_list.php') ?>
      </div>
    </div>

    
    <?= navLink($ROUTES['system_admin']['assign'], 'fas fa-tasks', 'Assigning Students', basename($_SERVER['PHP_SELF']) == 'assign.php') ?>
    <?= navLink($ROUTES['system_admin']['manage_acc'], 'fas fa-user-cog', 'Manage Accounts', basename($_SERVER['PHP_SELF']) == 'manage_acc.php' || (isset($_GET['prev']) && $_GET['prev'] == 'manage_acc')) ?>
    <?= navLink($ROUTES['system_admin']['settings'], 'fa-solid fa-gear', 'Configuration', basename($_SERVER['PHP_SELF']) == 'settings.php') ?>
  <?php endif; ?>

    <?php if (in_array('dean', $roles, true) && isset($ROUTES['dean'])): ?>
      <?php if ($primaryRole === 'dean'): ?>
        <?= navLink($ROUTES['dean']['dashboard'], 'fas fa-home', 'Dashboard', basename($_SERVER['PHP_SELF']) == 'dean_dashboard.php') ?>
      <?php endif; ?>
      <?= navLink($ROUTES['guidance']['reports'], 'fa-solid fa-file-lines', 'Generate Reports', basename($_SERVER['PHP_SELF']) == 'reports.php') ?>
    <?php endif; ?>

    <?php if (in_array('adviser', $roles, true) && isset($ROUTES['adviser'])): ?>
      <?php if ($primaryRole === 'adviser'): ?>
        <?= navLink($ROUTES['adviser']['dashboard'], 'fas fa-home', 'Dashboard', basename($_SERVER['PHP_SELF']) == 'adviser_dash.php') ?>
      <?php endif; ?>
      <?= navLink($ROUTES['adviser']['grades'], 'fas fa-user-graduate', 'My Advisees', basename($_SERVER['PHP_SELF']) == 'advisee.php') ?>
      <?= navLink($ROUTES['adviser']['import_grade'], 'fas fa-pen', 'Import Grades', basename($_SERVER['PHP_SELF']) == 'import_grade.php') ?>
      <?= navLink($ROUTES['adviser']['send_notif'], 'fa-solid fa-message', 'Messages', basename($_SERVER['PHP_SELF']) == 'send_notif.php') ?>
    <?php endif; ?>

    <?php if (in_array('guidance', $roles, true) && isset($ROUTES['guidance'])): ?>
      <?php if ($primaryRole === 'guidance'): ?>
        <?= navLink($ROUTES['guidance']['dashboard'], 'fas fa-home', 'Dashboard', basename($_SERVER['PHP_SELF']) == 'guidance_dashboard.php') ?>
      <?php endif; ?>
      <?= navLink($ROUTES['guidance']['reports'], 'fa-solid fa-file-lines', 'Generate Reports', basename($_SERVER['PHP_SELF']) == 'reports.php') ?>
      <?= navLink($ROUTES['guidance']['survey'], 'fa-solid fa-paper-plane', 'Announcement', basename($_SERVER['PHP_SELF']) == 'survey.php') ?>
    <?php endif; ?>

    <?php if (empty($roles)): ?>
      <p>No navigation available for this role.</p>
    <?php endif; ?>
  </nav>

  <script>
   function toggleManageList(event) {
      event.preventDefault();
      event.stopPropagation();
      
      const button = event.target;
      const dropdownContent = button.nextElementSibling;
      
      // Toggle the dropdown
      dropdownContent.classList.toggle('show');
      button.classList.toggle('open');
    }

    document.addEventListener('DOMContentLoaded', () => {
      // highlight active nav (works with absolute URLs)
      const links = document.querySelectorAll('.sidebar nav a');
      const currentPath = window.location.pathname.replace(/\/+$/, '');  // Remove trailing slashes
      const queryParams = new URLSearchParams(window.location.search);
      const prevPage = queryParams.get('prev');  // Get 'prev' parameter if it exists

      links.forEach(link => {
        const linkPath = new URL(link.href, window.location.origin).pathname.replace(/\/+$/, '');
        if (linkPath === currentPath || (prevPage && linkPath === '/admin_panel/manage_acc.php')) {
          link.classList.add('active');
        }
      });

      // Auto-open dropdown if one of its items is active and add open class to button
      const dropdownContent = document.querySelector('.dropdown-content');
      const dropdownBtn = document.querySelector('.dropbtn');
      
      if (dropdownContent && dropdownContent.classList.contains('show')) {
        dropdownBtn.classList.add('open');
      }

      // Close dropdown when clicking outside
      document.addEventListener('click', (event) => {
        const toggleDropdown = document.querySelector('.toggle-dropdown');
        if (toggleDropdown && !toggleDropdown.contains(event.target)) {
          const dropdownContent = toggleDropdown.querySelector('.dropdown-content');
          const dropdownBtn = toggleDropdown.querySelector('.dropbtn');
          
          dropdownContent.classList.remove('show');
          dropdownBtn.classList.remove('open');
        }
      });
    });
  </script>
</aside>
