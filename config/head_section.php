<?php
include 'db.php';

$headerName = 'Unknown User';
$headerRole = $_SESSION['user_role'] ?? 'Unknown';
$headerProfilePic = '/capstone/image/default_profile.jpg';

if (isset($_SESSION['user_id'], $_SESSION['user_role'])) {
  $headerUserId = $_SESSION['user_id'];

  if (in_array($headerRole, ['adviser', 'dean', 'guidance', 'system_admin'])) {
    $stmt = $conn->prepare("
      SELECT f.faculty_name, f.profile_picture 
      FROM tblfaculty f
      JOIN users u ON f.faculty_id = u.faculty_id
      WHERE u.id = ?
    ");
    $stmt->bind_param('i', $headerUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($faculty = $result->fetch_assoc()) {
      $headerName = trim("{$faculty['faculty_name']}");
      if (!empty($faculty['profile_picture'])) {
        $headerProfilePic = $faculty['profile_picture'];
      }
    }
    $stmt->close();
  }
}
?>
<header class="dashboard-header">
  <div class="left-section">
    <img src="/capstone/image/logo.png" alt="Logo" class="logo">
    <span class="system-name">PerfoMetrics</span>
  </div>

  <div class="right-container">

<?php if (strtolower($headerRole) === 'adviser'): ?>
<div class="notification-wrapper">

  <!-- Notification Icon -->
  <button type="button" id="notificationIcon" class="notification-icon" title="Notifications">
    <i class="fa fa-bell"></i>
    <span class="badge" id="notificationBadge" style="display:none;">0</span>
  </button>

  <!-- Dropdown -->
  <div class="notification-dropdown fade" id="notificationDropdown">

    <ul id="notificationList">
      <!-- JS will insert items here in this structure:
      <li class="unread" data-id="1">
        <div class="notif-content">
          <span class="notif-text">Message here</span>
          <span class="notif-time">Just now</span>
        </div>
      </li>
      -->
    </ul>

    <div class="no-notifs">No new notifications</div>

  </div>
</div>
<?php endif; ?>


  <!-- profile link -->
  <div class="user-dropdown">
    <button id="userToggle" class="user-info">
      <div class="user-text">
        <strong><?= htmlspecialchars($headerName) ?></strong><br>
        <small><?= ucfirst(htmlspecialchars($headerRole)) ?></small>
      </div>
      <img src="<?= htmlspecialchars($headerProfilePic) ?>" alt="Profile" class="profile-pic">
    </button>
    <div class="user-dropdown-menu" id="userDropdownMenu">
      <div class="dropdown-arrow"></div>
      <a href="/capstone/profile.php"><i class="fa-solid fa-user"></i> My Profile</a>
      <a href="/capstone/change_password.php"><i class="fa-solid fa-lock"></i> Change Password</a>
      <a href="../../capstone/index.php" class="logout" id="logoutBtn">
        <i class="fas fa-sign-out-alt"></i> Logout
      </a>
    </div>
  </div>
</div>
</header>

<!-- Notification + user dropdown script -->
<script>
document.addEventListener('DOMContentLoaded', () => {
  const notificationIcon   = document.getElementById('notificationIcon');
  const notificationDropdown = document.getElementById('notificationDropdown');
  const notificationList   = document.getElementById('notificationList');
  const notificationBadge  = document.getElementById('notificationBadge');

  async function refreshBadge() {
    const unread = notificationList.querySelectorAll('li.unread').length;
    if (unread > 0) {
      notificationBadge.textContent = unread;
      notificationBadge.style.display = 'inline-block';
    } else {
      notificationBadge.style.display = 'none';
    }
  }

  if (notificationIcon && notificationDropdown && notificationList && notificationBadge) {
    const noNotifs = notificationDropdown.querySelector('.no-notifs');
    let notificationsLoaded = false;
    let autoCloseTimer = null;

    notificationIcon.addEventListener('click', async (e) => {
      e.stopPropagation();

      const userMenu = document.getElementById('userDropdownMenu');
      if (userMenu) userMenu.style.display = 'none';

      const isVisible = notificationDropdown.classList.contains('show');
      notificationDropdown.classList.toggle('show', !isVisible);

      clearTimeout(autoCloseTimer);
      if (!isVisible) autoCloseTimer = setTimeout(() => notificationDropdown.classList.remove('show'), 10000);

      if (!notificationsLoaded && !isVisible) {
        try {
          const res = await fetch('/capstone/config/get_notifications.php');
          const data = await res.json();

          if (Array.isArray(data) && data.length) {
            notificationList.innerHTML = data.map(n => `
              <li class="${n.is_read ? 'read' : 'unread'}" data-id="${n.id}">
                <div class="notif-row">
                  <img src="${n.sender_profile || '/capstone/image/default_profile.jpg'}" class="notif-avatar" alt="${n.sender_name || 'Profile'}">
                  <div class="notif-info">
                    <span class="notif-name">${n.sender_name || 'Unknown'}</span>
                    <span class="notif-text">${(n.title ? n.title + ' — ' : '')}${n.body || ''}</span>
                  </div>
                  <span class="notif-time">${n.time_ago || ''}</span>
                </div>
              </li>
            `).join('');
            if (noNotifs) noNotifs.style.display = 'none';
          } else {
            notificationList.innerHTML = '';
            if (noNotifs) noNotifs.style.display = 'block';
          }
          notificationsLoaded = true;
          refreshBadge();
        } catch (err) {
          console.error(err);
          if (noNotifs) {
            noNotifs.textContent = 'Error loading notifications';
            noNotifs.style.display = 'block';
          }
        }
      }
    });

    // CLICK → open modal
    notificationList.addEventListener('click', async (e) => {
      const li = e.target.closest('li[data-id]');
      if (!li) return;

      const id = li.dataset.id;

      // mark read locally & server
      li.classList.remove('unread'); li.classList.add('read'); refreshBadge();
      fetch('/capstone/config/mark_notification_read.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id })
      }).catch(() => {});

      // fetch detail
      let n;
      try {
        const r = await fetch(`/capstone/config/get_notifications.php?id=${encodeURIComponent(id)}`);
        n = await r.json();
      } catch {
        Swal.fire('Error','Unable to open the notification.','error'); return;
      }

      const title = n.title || 'Notification';
      const body  = n.body  || '';
      const from  = n.sender_name || 'Unknown';
      const when  = n.created_at || '';
      const timeAgo = n.time_ago || '';

      const choice = await Swal.fire({
      title: '',
      html: `
        <div style="display:flex; gap:15px; align-items:flex-start;">
          <img src="${n.sender_profile}" 
              style="width:60px; height:60px; border-radius:50%; object-fit:cover;">
          <div style="text-align:left; font-size:14px;">
            <div style="font-size:16px; font-weight:600; margin-bottom:3px;">
              ${n.sender_name}
            </div>

            <div style="color:#666; font-size:13px; margin-bottom:10px;">
              ${n.time_ago} &bullet; ${n.created_at}
            </div>

            <div style="font-weight:600; margin-bottom:5px;">
              ${n.title}
            </div>

            <div style="white-space:pre-wrap; color:#444;">
              ${n.body}
            </div>
          </div>
        </div>
      `,
      showDenyButton: true,
      showCancelButton: true,
      confirmButtonText: 'Forward',
      denyButtonText: 'Delete',
      cancelButtonText: 'Close',
      width: 600,
      backdrop: true,
      allowOutsideClick: false,
    });


      // Delete flow
      if (choice.isDenied) {
        const c = await Swal.fire({
          title: 'Delete this notification?',
          text: 'This action cannot be undone.',
          icon: 'warning',
          showCancelButton: true,
          confirmButtonText: 'Delete'
        });
        if (!c.isConfirmed) return;

        try {
          const del = await fetch('/capstone/config/delete_notification.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
          });
          const j = await del.json();
          if (!del.ok) throw new Error(j.error || 'Delete failed');
          li.remove(); refreshBadge();
          Swal.fire('Deleted', j.message || 'Notification deleted.', 'success');
        } catch (err) {
          Swal.fire('Error', err.message || 'Failed to delete notification.', 'error');
        }
        return;
      }

      // Forward flow
      if (choice.isConfirmed) {
        const scope = await Swal.fire({
          title: 'Forward notification',
          input: 'radio',
          inputOptions: { all: 'All advisees', specific: 'Specific advisee(s)' },
          inputValue: 'all',
          showCancelButton: true,
          confirmButtonText: 'Next'
        });
        if (!scope.isConfirmed) return;

        let payload = { id, scope: scope.value, recipient_ids: [] };

        if (scope.value === 'specific') {
          // load advisees
          let advisees = [];
          try {
            const r = await fetch('/capstone/config/get_advisees.php');
            advisees = await r.json();
          } catch { advisees = []; }

          if (!Array.isArray(advisees) || !advisees.length) {
            Swal.fire('No advisees', 'You have no advisees to forward to.', 'info'); return;
          }

          // BUILD NICE CHECKLIST
const opts = advisees.map(a => `
  <div class="adv-item" data-id="${a.user_id}">
    <input type="checkbox" class="adv-check" value="${a.user_id}">
    <label>${a.full_name}</label>
  </div>
`).join('');

const { value: selected } = await Swal.fire({
  title: 'Choose advisees',
  html: `
    <div id="advWrapper">
      ${opts}
    </div>
    <small style="color:#777;">Click to select student(s).</small>
  `,
  showCancelButton: true,
  confirmButtonText: "Next",
  focusConfirm: false,
  width: 500,
  didRender: () => {
    // CLICK ROW TO CHECK THE BOX
    document.querySelectorAll(".adv-item").forEach(row => {
      row.addEventListener("click", (e) => {
        if (e.target.tagName !== "INPUT") {
          const box = row.querySelector(".adv-check");
          box.checked = !box.checked;
        }
      });
    });

    // Inject CSS for styling
    const style = document.createElement("style");
    style.textContent = `
      #advWrapper {
        max-height: 280px;
        overflow-y: auto;
        border: 1px solid #dcdcdc;
        border-radius: 8px;
        padding: 6px;
        margin-bottom: 8px;
      }
      .adv-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px;
        border-radius: 6px;
        cursor: pointer;
        transition: background 0.15s;
      }
      .adv-item:hover {
        background: #eef4ff;
      }
      .adv-check {
        width: 18px;
        height: 18px;
        cursor: pointer;
      }
    `;
    document.head.appendChild(style);
  },
  preConfirm: () => {
    const checks = [...document.querySelectorAll(".adv-check:checked")];
    if (!checks.length) {
      Swal.showValidationMessage("Select at least one advisee.");
      return false;
    }
    return checks.map(c => c.value);
  }
});

// If user canceled
if (!selected) return;

payload.recipient_ids = selected;

          if (!selected) return;
          payload.recipient_ids = selected;
        }

        try {
          const f = await fetch('/capstone/config/forward_notification.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
          });
          const j = await f.json();
          if (!f.ok) throw new Error(j.error || 'Forward failed');
          Swal.fire('Forwarded', j.message || 'Notification forwarded.', 'success');
        } catch (err) {
          Swal.fire('Error', err.message || 'Failed to forward notification.', 'error');
        }
      }
    });

    document.addEventListener('click', () => {
      notificationDropdown.classList.remove('show');
      clearTimeout(autoCloseTimer);
    });
  }

  // user dropdown (unchanged)
  const userToggle = document.getElementById('userToggle');
  const userMenu   = document.getElementById('userDropdownMenu');
  if (userToggle && userMenu) {
    userToggle.addEventListener('click', (e) => {
      e.stopPropagation();
      const nd = document.getElementById('notificationDropdown');
      if (nd) nd.classList.remove('show');
      userMenu.style.display = (userMenu.style.display === 'block') ? 'none' : 'block';
    });
    document.addEventListener('click', () => userMenu.style.display = 'none');
    userMenu.querySelectorAll('a').forEach(a => a.addEventListener('click', () => userMenu.style.display = 'none'));
  }
});
</script>



<!-- SweetAlert2 logout confirmation -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const logoutBtn = document.getElementById('logoutBtn');
  if (!logoutBtn) return;

  logoutBtn.addEventListener('click', (e) => {
    e.preventDefault();
    if (typeof Swal === 'undefined' || !Swal.fire) {
      window.location.href = logoutBtn.getAttribute('href');
      return;
    }

    Swal.fire({
      title: 'Are you sure?',
      text: 'You will be logged out of the system.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#2684fc',
      cancelButtonColor: '#d33',
      confirmButtonText: 'Yes, logout',
      cancelButtonText: 'Cancel',
      focusCancel: true
    }).then((result) => {
      if (result.isConfirmed) {
        window.location.href = logoutBtn.getAttribute('href');
      }
    });
  });
});
</script>
