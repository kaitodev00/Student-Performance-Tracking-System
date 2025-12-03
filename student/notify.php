<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id'])) {
  header("Location: index.php");
  exit;
}
$user_id = (int)$_SESSION['user_id'];

// fetch student (optional for header bits)
$student = null;
if ($stmt = $conn->prepare("SELECT * FROM students WHERE user_id = ? LIMIT 1")) {
  $stmt->bind_param("i", $user_id);
  $stmt->execute();
  $student = $stmt->get_result()->fetch_assoc();
  $stmt->close();
}

$notifications = [];
$sql = "
  SELECT n.id, n.title, n.body, n.is_read, n.created_at,
         f.faculty_name AS sender_name
  FROM notifications n
  LEFT JOIN tblfaculty f ON f.user_id = n.sender_id
  WHERE n.receiver_id = ?
  ORDER BY n.created_at DESC, n.id DESC
";
if ($stmt = $conn->prepare($sql)) {
  $stmt->bind_param("i", $user_id);
  $stmt->execute();
  $notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

// unread count
$unreadCount = 0;
if ($stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE receiver_id = ? AND is_read = 0")) {
  $stmt->bind_param("i", $user_id);
  $stmt->execute();
  $unreadCount = (int)($stmt->get_result()->fetch_column() ?? 0);
  $stmt->close();
}

function e_attr($s) { return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function e_html($s) { return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>User Notifications</title>
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <link rel="stylesheet" href="design/notify.css">
  <style>
    .notification {
      display:flex; align-items:center; justify-content:space-between;
      padding:.9rem 1rem; border-radius:.5rem; border:1px solid #e5e7eb;
      margin-bottom:.5rem; cursor:pointer;
    }
    .notification.unread { background:#f8fafc; border-color:#cbd5e1; }
    .notification .notification-main { flex:1; display:flex; flex-direction:column; }
    .notification .title-line { font-weight:600; color:#111827; }
    .notification .meta-line { font-size:.85rem; color:#6b7280; }
    .notification .notification-actions { color:#6b7280; font-size:.9rem; white-space:nowrap; margin-left:.75rem; }
    .notification-icon::before { content:"•"; color:#3b82f6; margin-right:.5rem; font-size:1.3rem; line-height:0; vertical-align:middle; }
    .notification.read .notification-icon::before { color:transparent; }

    /* selection UI */
    .row-check { margin-right:.6rem; }
    .row-check.d-none { display:none !important; }
    .select-mode .notification { cursor:default; }

    /* top toolbar */
    .select-toolbar { display:flex; justify-content:space-between; align-items:center; }
    .toolbar-right { display:flex; align-items:center; gap:.5rem; }

    /* round ellipsis button */
    .btn-ellipses {
      width:36px; height:36px; border-radius:50%;
      display:inline-flex; align-items:center; justify-content:center;
      border:1px solid #ced4da; background:#fff; font-size:18px; line-height:1;
      padding:0; cursor:pointer;
    }
    .btn-ellipses:focus { outline:none; box-shadow:0 0 0 .2rem rgba(108,117,125,.25); }
    .btn-ellipses.active { background:#f1f3f5; }

    /* select-all & bulk delete pills */
    .pill {
      display:inline-flex; align-items:center; gap:.35rem;
      height:36px; padding:0 .6rem; border-radius:999px; border:1px solid #ced4da; background:#fff;
      font-size:.9rem;
    }
    .pill.d-none { display:none !important; }
    .count-badge {
      display:inline-block; min-width:1.25rem; padding:0 .35rem;
      text-align:center; border-radius:999px; font-size:.8rem;
      background:#e9ecef;
    }

    /* bell badge (header.php should have #notifBadge) */
    #notifBadge {
      position: absolute; top: -4px; right: -6px;
      background:#dc3545; color:#fff; border-radius:999px;
      min-width:20px; height:20px; line-height:20px; font-size:12px;
      text-align:center; padding:0 6px; display:inline-block;
    }
  </style>
</head>
<body>
  <header>
    <?php
      $activeTab = 1;
      include '../config/header.php';
    ?>
  </header>

  <div class="container mt-4">
    <div class="mb-2 select-toolbar">
      <h4 class="mb-0">Messages</h4>
      <div class="toolbar-right">
        <button id="toggleSelectMode" type="button" class="btn-ellipses" aria-label="Toggle selection mode" title="Select messages">…</button>

        <label id="selectAllWrap" class="pill d-none mb-0" for="selectAllBox" title="Select all">
          <input type="checkbox" id="selectAllBox" class="mr-1">
          <span>All</span>
        </label>

        <button id="bulkDeleteBtn" type="button" class="pill d-none" disabled title="Delete selected">
          🗑 <span>Delete</span> <span id="selCount" class="count-badge">0</span>
        </button>
      </div>
    </div>

    <div id="unreadAlert" class="alert alert-info text-center" style="<?= $unreadCount > 0 ? '' : 'display:none' ?>">
      You have <span id="unreadCountSpan"><?= (int)$unreadCount ?></span>
      unread <?= $unreadCount === 1 ? 'notification' : 'notifications' ?>.
    </div>

    <div class="notifications" id="notifList">
      <?php if (empty($notifications)): ?>
        <div class="text-center text-muted py-4">You have no notifications.</div>
      <?php else: ?>
        <?php foreach ($notifications as $n):
          $sender = trim((string)($n['sender_name'] ?? ''));
          if ($sender === '') $sender = 'System';
        ?>
          <div class="notification <?= ($n['is_read'] == 0 ? 'unread' : 'read') ?>"
               data-id="<?= (int)$n['id'] ?>"
               data-title="<?= e_attr($n['title']) ?>"
               data-body="<?= e_attr($n['body']) ?>"
               data-sender="<?= e_attr($sender) ?>">
            <input type="checkbox" class="row-check d-none" aria-label="Select this notification">
            <span class="notification-icon"></span>

            <div class="notification-main">
              <div class="title-line"><?= e_html($n['title']) ?></div>
              <div class="meta-line">From: <?= e_html($sender) ?></div>
            </div>

            <div class="notification-actions"><?= e_html(date("M d, Y H:i", strtotime($n['created_at']))) ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Modal -->
  <div class="modal fade" id="notificationModal" tabindex="-1" role="dialog" aria-labelledby="notificationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <h5 id="notificationModalLabel" class="modal-title mb-0">Notification</h5>
            <small id="notificationModalFrom" class="text-muted"></small>
          </div>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <p id="modalMessageContent" style="white-space:pre-wrap;"></p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-primary" data-dismiss="modal">Close</button>
          <button type="button" class="btn btn-secondary" id="modalDeleteButton">Delete Message</button>
        </div>
      </div>
    </div>
  </div>

  <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
  <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script>
    var unread = <?= (int)$unreadCount ?>;

    function renderUnreadUI() {
      var alertEl = document.getElementById('unreadAlert');
      var countSpan = document.getElementById('unreadCountSpan');
      var badge = document.getElementById('notifBadge');
      if (countSpan) countSpan.textContent = unread;

      if (alertEl) {
        if (unread > 0) {
          alertEl.style.display = '';
          alertEl.innerHTML = 'You have <span id="unreadCountSpan">' + unread + '</span> unread ' + (unread === 1 ? 'notification' : 'notifications') + '.';
        } else {
          alertEl.style.display = 'none';
        }
      }
      if (badge) {
        if (unread > 0) { badge.style.display = 'inline-block'; badge.textContent = (unread > 99 ? '99+' : unread); }
        else { badge.style.display = 'none'; badge.textContent = ''; }
      }
    }

    function markAsRead(notificationId, elem, wasUnread) {
  var xhr = new XMLHttpRequest();
  xhr.open("POST", "mark_as_read.php", true);
  xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
  xhr.onreadystatechange = function () {
    if (xhr.readyState === 4) {
      if (xhr.status === 200) {
        // Flip state to "read" only — do NOT remove the row.
        elem.classList.remove('unread');
        elem.classList.add('read');

        if (wasUnread && unread > 0) unread--;
        renderUnreadUI();

        // If you’re currently in select mode, keep selection UI consistent
        if (selectMode) updateSelectStateUI();
      } else {
        console.warn('mark_as_read failed', xhr.responseText);
      }
    }
  };
  xhr.send("id=" + encodeURIComponent(notificationId));
}


    function deleteNotification(notificationId, notifElem) {
      var xhr = new XMLHttpRequest();
      xhr.open("POST", "delete_notif.php", true);
      xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
      xhr.onreadystatechange = function () {
        if (xhr.readyState === 4) {
          if (xhr.status === 200) {
            if (notifElem.classList.contains('unread') && unread > 0) unread--;
            $(notifElem).slideUp(200, function(){ this.remove(); renderUnreadUI(); updateSelectStateUI(); });
            $('#notificationModal').modal('hide');
          } else { console.warn('delete_notif failed', xhr.responseText); }
        }
      };
      xhr.send("id=" + encodeURIComponent(notificationId));
    }

    function bulkDelete(ids) {
      return new Promise(function(resolve, reject) {
        var xhr = new XMLHttpRequest();
        xhr.open("POST", "delete_bulk.php", true); // this file is below
        xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
        xhr.onreadystatechange = function () {
          if (xhr.readyState === 4) {
            if (xhr.status === 200) resolve(xhr.responseText);
            else reject(xhr.responseText || 'Request failed');
          }
        };
        xhr.send("ids=" + encodeURIComponent(ids.join(',')));
      });
    }

    var selectMode = false;

    function setSelectMode(on) {
      selectMode = !!on;
      var list = document.getElementById('notifList');
      var checks = list.querySelectorAll('.row-check');
      var btn = document.getElementById('toggleSelectMode');
      var selectAllWrap = document.getElementById('selectAllWrap');
      var bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
      var selCount = document.getElementById('selCount');
      var selectAllBox = document.getElementById('selectAllBox');

      if (selectMode) {
        list.classList.add('select-mode');
        checks.forEach(c => c.classList.remove('d-none'));
        btn.classList.add('active'); btn.textContent = '×'; btn.title = 'Cancel selection';
        selectAllWrap.classList.remove('d-none');
        bulkDeleteBtn.classList.remove('d-none');
      } else {
        list.classList.remove('select-mode');
        checks.forEach(c => { c.checked = false; c.classList.add('d-none'); });
        btn.classList.remove('active'); btn.textContent = '…'; btn.title = 'Select messages';
        selectAllWrap.classList.add('d-none');
        bulkDeleteBtn.classList.add('d-none'); bulkDeleteBtn.disabled = true;
        selCount.textContent = '0';
        selectAllBox.checked = false; selectAllBox.indeterminate = false;
      }
    }

    function updateSelectStateUI() {
      var list = document.getElementById('notifList');
      var checks = Array.from(list.querySelectorAll('.row-check'));
      var selected = checks.filter(c => c.checked).length;
      var selCount = document.getElementById('selCount');
      var bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
      var selectAllBox = document.getElementById('selectAllBox');

      if (!selectMode) return;

      selCount.textContent = selected;
      bulkDeleteBtn.disabled = (selected === 0);

      if (checks.length === 0) { setSelectMode(false); return; }
      if (selected === 0) { selectAllBox.checked = false; selectAllBox.indeterminate = false; }
      else if (selected === checks.length) { selectAllBox.checked = true; selectAllBox.indeterminate = false; }
      else { selectAllBox.checked = false; selectAllBox.indeterminate = true; }
    }

    $(document).ready(function () {
      renderUnreadUI();

      $('#notifList').on('click', '.notification', function (e) {
        if (selectMode) {
          var cb = this.querySelector('.row-check');
          if (e.target !== cb) cb.checked = !cb.checked;
          updateSelectStateUI();
          return;
        }
        var id = $(this).data('id');
        var title = $(this).data('title');
        var body  = $(this).data('body');
        var sender= $(this).data('sender') || 'System';
        var wasUnread = $(this).hasClass('unread');

        markAsRead(id, this, wasUnread);
        $('#notificationModalLabel').text(title);
        $('#notificationModalFrom').text('From: ' + sender);
        $('#modalMessageContent').text(body);
        $('#notificationModal').data('notificationId', id);
        $('#notificationModal').modal('show');
      });

      $('#notifList').on('change', '.row-check', updateSelectStateUI);

      $('#toggleSelectMode').on('click', function () { setSelectMode(!selectMode); });

      $('#selectAllBox').on('change', function () {
        var all = this.checked;
        $('#notifList .row-check').each(function(){ this.checked = all; });
        updateSelectStateUI();
      });

      $('#bulkDeleteBtn').on('click', function () {
        var ids = [];
        var unreadToDecrement = 0;
        $('#notifList .notification').each(function () {
          var cb = this.querySelector('.row-check');
          if (cb && cb.checked) {
            ids.push($(this).data('id'));
            if (this.classList.contains('unread')) unreadToDecrement++;
          }
        });
        if (ids.length === 0) return;

        Swal.fire({
          title: 'Delete selected messages?',
          text: 'This action cannot be undone.',
          icon: 'warning',
          showCancelButton: true,
          confirmButtonText: 'Yes, delete',
          cancelButtonText: 'Cancel'
        }).then((result) => {
          if (!result.isConfirmed) return;
          bulkDelete(ids).then(function () {
            ids.forEach(function (id) {
              var row = document.querySelector('.notification[data-id="' + id + '"]');
              if (row) $(row).slideUp(150, function(){ this.remove(); renderUnreadUI(); updateSelectStateUI(); });
            });
            if (unreadToDecrement > 0) { unread = Math.max(0, unread - unreadToDecrement); renderUnreadUI(); }
            Swal.fire('Deleted!', 'Selected notifications have been removed.', 'success');
          }).catch(function (err) {
            console.warn('bulk delete error', err);
            Swal.fire('Error', 'Failed to delete some notifications.', 'error');
          });
        });
      });

      $('#modalDeleteButton').on('click', function () {
        var id = $('#notificationModal').data('notificationId');
        var elem = $('.notification[data-id="' + id + '"]')[0];

        Swal.fire({
          title: 'Are you sure you want to delete this message?',
          text: 'This action cannot be undone.',
          icon: 'warning',
          showCancelButton: true,
          confirmButtonText: 'Yes, delete it!',
          cancelButtonText: 'Cancel'
        }).then((result) => {
          if (result.isConfirmed) {
            deleteNotification(id, elem);
            Swal.fire('Deleted!', 'Your notification has been removed.', 'success');
          }
        });
      });
    });
  </script>
</body>
<?php include '../config/nav_bar.php'; ?>
</html>
