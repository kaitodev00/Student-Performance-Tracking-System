<?php
// survey.php — broadcast surveys to faculties or students (gender & year-level filters)
ini_set('display_errors', 1); error_reporting(E_ALL);
session_start();
require_once '../config/db.php';

// -------------------------
// Authorize sender (Admin/Guidance/Dean)
// -------------------------
$roles = $_SESSION['roles'] ?? [];
if (!is_array($roles) || empty($roles)) {
    if (!empty($_SESSION['user_role'])) { $roles = [$_SESSION['user_role']]; }
}
$roleNames = array_map('strtolower', $roles);

$canSend = in_array('guidance', $roleNames, true) ;
if (!$canSend) {
    http_response_code(403);
    exit('Forbidden: admin, guidance, or dean role required.');
}

// Resolve sender (user_id)
$sender_user_id = $_SESSION['user_id'] ?? null;
if (!$sender_user_id) {
    http_response_code(401);
    exit('Missing session user.');
}

// -------------------------
// CSRF
// -------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// -------------------------
// Prefetch pickers
// -------------------------
$facultyRows = [];
$yearOptions = [];

// Faculties (for specific faculty broadcast)
$facultyRows = [];
if ($stmt = $conn->prepare("
    SELECT 
        f.faculty_id,
        COALESCE(f.faculty_name, CONCAT('Faculty #', f.faculty_id)) AS name,
        u.email,
        u.id AS user_id
    FROM tblfaculty f
    JOIN users u ON u.id = f.user_id
    ORDER BY name ASC
")) {
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) { $facultyRows[] = $r; }
    $stmt->close();
}

// Year levels available from students table
if ($stmt = $conn->prepare("SELECT DISTINCT s.year_level_id FROM students s WHERE s.year_level_id IS NOT NULL ORDER BY s.year_level_id ASC")) {
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) { $yearOptions[] = (int)$r['year_level_id']; }
    $stmt->close();
}

// -------------------------
// Handle POST (send)
// -------------------------
$resultMessage = '';
$resultClass = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        $resultMessage = 'Invalid request token.';
        $resultClass = 'error';
    } else {
        // audience values: all_students | students_by_gender | students_by_year | students_by_year_gender | all_faculties | specific_faculty
        $audience  = $_POST['audience'] ?? 'all_students';
        $title     = trim($_POST['title'] ?? '');
        $body      = trim($_POST['body'] ?? '');
        $facultyId = isset($_POST['faculty_user_id']) ? (int)$_POST['faculty_user_id'] : null; // users.id of the faculty
        $gender    = isset($_POST['gender']) ? trim($_POST['gender']) : '';
        $yearId    = isset($_POST['year_level']) && $_POST['year_level'] !== '' ? (int)$_POST['year_level'] : null;

        if ($title === '' || $body === '') {
            $resultMessage = 'Title and message are required.';
            $resultClass = 'error';
        } else {
            $userIds = [];
            $stmt = null;

            if ($audience === 'all_students') {
                $stmt = $conn->prepare("SELECT u.id FROM students s JOIN users u ON u.id = s.user_id");
            } elseif ($audience === 'students_by_gender' && in_array(strtolower($gender), ['male','female'], true)) {
                $stmt = $conn->prepare("SELECT u.id FROM students s JOIN users u ON u.id = s.user_id WHERE LOWER(s.sex) = ?");
                $g = strtolower($gender);
                $stmt->bind_param('s', $g);
            } elseif ($audience === 'students_by_year' && $yearId !== null) {
                $stmt = $conn->prepare("SELECT u.id FROM students s JOIN users u ON u.id = s.user_id WHERE s.year_level_id = ?");
                $stmt->bind_param('i', $yearId);
            } elseif ($audience === 'students_by_year_gender' && $yearId !== null && in_array(strtolower($gender), ['male','female'], true)) {
                $stmt = $conn->prepare("SELECT u.id FROM students s JOIN users u ON u.id = s.user_id WHERE s.year_level_id = ? AND LOWER(s.sex) = ?");
                $g = strtolower($gender);
                $stmt->bind_param('is', $yearId, $g);
            } elseif ($audience === 'all_faculties') {
                $stmt = $conn->prepare("SELECT u.id FROM tblfaculty f JOIN users u ON u.id = f.user_id");
            } elseif ($audience === 'specific_faculty' && $facultyId) {
                $stmt = $conn->prepare("SELECT u.id FROM users u WHERE u.id = ? LIMIT 1");
                $stmt->bind_param('i', $facultyId);
            }

            if ($stmt) {
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) { $userIds[] = (int)$row['id']; }
                $stmt->close();
            }

            if (!empty($userIds)) {
    // Group this send
    $batch_token = bin2hex(random_bytes(8)); // e.g., "f3a1c9e2b0d4c7a1"

    // Prefer insert with batch_token if column exists; otherwise use fallback
    $hasBatch = true;
    $check = $conn->query("SHOW COLUMNS FROM notifications LIKE 'batch_token'");
    if (!$check || $check->num_rows === 0) { $hasBatch = false; }

    if ($hasBatch) {
        $ins = $conn->prepare("INSERT INTO notifications (batch_token, receiver_id, title, body, sender_id, created_at, is_read) VALUES (?, ?, ?, ?, ?, NOW(), 0)");
        foreach ($userIds as $uid) {
            $ins->bind_param('sissi', $batch_token, $uid, $title, $body, $sender_user_id);
            $ins->execute();
        }
        $ins->close();
    } else {
        // Fallback if you haven’t added batch_token yet
        $ins = $conn->prepare("INSERT INTO notifications (receiver_id, title, body, sender_id, created_at, is_read) VALUES (?, ?, ?, ?, NOW(), 0)");
        foreach ($userIds as $uid) {
            $ins->bind_param('issi', $uid, $title, $body, $sender_user_id);
            $ins->execute();
        }
        $ins->close();
    }

    $resultMessage = '✅ Sent to ' . count($userIds) . ' recipient' . (count($userIds) > 1 ? 's' : '') . '.';
    $resultClass = 'success';


                $resultMessage = '✅ Sent to ' . count($userIds) . ' recipient' . (count($userIds) > 1 ? 's' : '') . '.';
                $resultClass = 'success';
            } else {
                $resultMessage = '⚠️ No recipients matched your selection.';
                $resultClass = 'warning';
            }
        }
    }
}

// -------------------------
// Sent tab data (for the current sender)
// -------------------------
$tab = $_GET['tab'] ?? 'send'; // 'send' | 'sent' | 'recipients'
$batch = $_GET['batch'] ?? null;

// Detect if batch_token exists (schema might not be migrated yet)
$hasBatchToken = true;
$chk = $conn->query("SHOW COLUMNS FROM notifications LIKE 'batch_token'");
if (!$chk || $chk->num_rows === 0) { $hasBatchToken = false; }

// List of batches (grouped sends)
$sentBatches = [];
if ($tab === 'sent' || ($tab === 'recipients' && !$batch)) {
    if ($hasBatchToken) {
        $sql = "
            SELECT 
                n.batch_token,
                MIN(n.created_at) AS sent_at,
                n.title,
                COUNT(*) AS total,
                SUM(n.is_read = 1) AS read_count
            FROM notifications n
            WHERE n.sender_id = ?
            GROUP BY n.batch_token, n.title
            ORDER BY sent_at DESC
            LIMIT 200
        ";
    } else {
        // Fallback grouping: by minute of created_at + title+body (approx)
        $sql = "
            SELECT 
                DATE_FORMAT(n.created_at, '%Y-%m-%d %H:%i') AS minute_bucket,
                MIN(n.created_at) AS sent_at,
                n.title,
                n.body,
                COUNT(*) AS total,
                SUM(n.is_read = 1) AS read_count
            FROM notifications n
            WHERE n.sender_id = ?
            GROUP BY minute_bucket, n.title, n.body
            ORDER BY sent_at DESC
            LIMIT 200
        ";
    }
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $sender_user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) { $sentBatches[] = $r; }
    $stmt->close();
}

// Recipients of a specific batch (for the Recipients tab)
// Recipients of a specific batch (for the Recipients tab)
$batchRecipients = [];
if ($tab === 'recipients' && $batch && $hasBatchToken) {
    $sql = "
        SELECT 
            n.id,
            u.id AS user_id,
            COALESCE(s.student_name, f.faculty_name, u.email) AS name,
            u.email,
            n.is_read,
            n.created_at,
            n.read_at
        FROM notifications n
        JOIN users u       ON u.id = n.receiver_id
        LEFT JOIN students s   ON s.user_id = u.id
        LEFT JOIN tblfaculty f ON f.user_id = u.id
        WHERE n.sender_id = ? AND n.batch_token = ?
        ORDER BY n.is_read DESC, name ASC
        LIMIT 2000
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('is', $sender_user_id, $batch);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) { $batchRecipients[] = $r; }
    $stmt->close();
}


// Small helper to print % read
function pct($part, $whole) {
    if (!$whole) return '0%';
    return round(($part/$whole)*100) . '%';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Send Survey</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="../design/admin/admin_layout.css">
<link rel="stylesheet" href="../design/admin/notif.css">
<style>
h1 { border-bottom:2px solid black; padding-bottom:8px; margin:0 0 16px 30px; }
.card { overflow-x:hidden; background:#fff; border-radius:16px; box-shadow:0 8px 24px rgba(0,0,0,.06); padding:16px 18px; margin:12px 0 0 20px; }
.field { margin:10px 0; }
.label { font-weight:600; display:block; margin-bottom:6px; }
.help { font-size:.9rem; color:#6b7280; margin-top:4px; }
.row { display:flex; gap:12px; flex-wrap:wrap; }
.row .col { flex:1 1 260px; }
.segmented { display:inline-flex; border-radius:12px; background:#f3f4f6; padding:4px; }
.segmented input { display:none; }
.segmented label { padding:8px 12px; border-radius:10px; cursor:pointer; user-select:none; }
.segmented input:checked + label { background:#2684fc; color:#fff; }
.input, textarea, select { width:95%; border:1px solid #e5e7eb; border-radius:10px; padding:10px 12px; font:inherit; }
textarea { min-height:140px; resize:vertical; }
.counter { font-size:.85rem; color:#6b7280; text-align:right; margin-top:4px; }
.actions { position:relative; justify-self:flex-end; margin-bottom:10px; right:59px; }
button.primary { background:#2684fc; color:#fff; border:none; padding:10px 16px; border-radius:10px; cursor:pointer; font-weight:600; }
button.primary[disabled] { opacity:.5; cursor:not-allowed; }
.toast { border-radius:12px; padding:10px 12px; margin:10px 0 0 20px; }
.toast.success { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
.toast.error { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
.toast.warning { background:#fffbeb; color:#92400e; border:1px solid #fde68a; }
.recipient-pill { display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:999px; background:#eef2ff; color:#3730a3; font-size:.9rem; margin-right:8px; margin-top:6px; }
.preview { font-size:.95rem; color:#374151; }
.preview .muted { color:#6b7280; }
.tabs { margin: 0 0 16px 20px; display:flex; gap:8px; border-bottom:1px solid #e5e7eb; }
.tablink { padding:10px 14px; border-radius:10px 10px 0 0; text-decoration:none; color:#374151; }
.tablink.active { background:#2684fc; border:1px solid #e5e7eb; border-bottom-color:#fff; color:white; font-weight:600; }
.table { width:100%; border-collapse: collapse; }
.table th, .table td { padding:10px 12px; border-bottom:1px solid #f1f5f9; text-align:left; }
.badge { display:inline-block; padding:4px 8px; border-radius:999px; background:#eef2ff; color:#3730a3; font-size:.85rem; }
.badge.ok { background:#ecfdf5; color:#065f46; }
.badge.warn { background:#fffbeb; color:#92400e; }

</style>
</head>
<body>
<?php include '../config/aside.php'; include '../config/head_section.php'; ?>
<main>
  <div class="container">
    <?php
function tabUrl($name, $extra = []) {
  $q = array_merge($_GET, ['tab' => $name], $extra);
  return '?' . http_build_query($q);
}
?>
<div class="tabs">
  <a class="tablink <?= $tab==='send' ? 'active':'' ?>" href="<?= htmlspecialchars(tabUrl('send')) ?>">
    <i class="fa-regular fa-paper-plane"></i> Send
  </a>
  <a class="tablink <?= $tab==='sent' ? 'active':'' ?>" href="<?= htmlspecialchars(tabUrl('sent')) ?>">
    <i class="fa-regular fa-folder-open"></i> Sent
  </a>
  <?php if ($tab === 'recipients' && $hasBatchToken): ?>
    <a class="tablink active" href="javascript:void(0)">
      <i class="fa-solid fa-users-viewfinder"></i> Recipients
    </a>
  <?php endif; ?>
</div>

    <?php if ($tab === 'send'): ?>
  <h1>Send Survey</h1>

  <?php if ($resultMessage): ?>
    <div class="toast <?= htmlspecialchars($resultClass) ?>"><?= htmlspecialchars($resultMessage) ?></div>
  <?php endif; ?>

  <form method="POST" id="surveyForm" class="card" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

      <!-- Audience -->
      <div class="field">
        <span class="label">Send to</span>
        <div class="segmented">
          <input type="radio" name="audience" id="a_all_students" value="all_students" checked>
          <label for="a_all_students"><i class="fa-solid fa-user-graduate"></i> All students</label>

          <input type="radio" name="audience" id="a_students_gender" value="students_by_gender">
          <label for="a_students_gender"><i class="fa-solid fa-venus-mars"></i> Students by sex</label>

          <input type="radio" name="audience" id="a_students_year" value="students_by_year">
          <label for="a_students_year"><i class="fa-regular fa-calendar"></i> Students by year</label>

          <input type="radio" name="audience" id="a_students_year_gender" value="students_by_year_gender">
          <label for="a_students_year_gender"><i class="fa-solid fa-filter"></i> Year + sex</label>

          <input type="radio" name="audience" id="a_all_faculties" value="all_faculties">
          <label for="a_all_faculties"><i class="fa-solid fa-people-group"></i> Members of the Faculty</label>

          <input type="radio" name="audience" id="a_specific_faculty" value="specific_faculty">
          <label for="a_specific_faculty"><i class="fa-solid fa-chalkboard-user"></i> Specific faculty</label>
        </div>
        <div class="help">Choose who receives this survey.</div>
      </div>

      <div class="row">
        <!-- Gender picker (students) -->
        <div class="col field" id="genderWrap" style="display:none">
          <label class="label" for="gender">Sex</label>
          <select id="gender" name="gender" class="input">
            <option value="">Select sex…</option>
            <option value="Male">Male</option>
            <option value="Female">Female</option>
          </select>
        </div>

        <!-- Year picker (students) -->
        <div class="col field" id="yearWrap" style="display:none">
          <label class="label" for="year_level">Year level</label>
          <select id="year_level" name="year_level" class="input">
            <option value="">Select year…</option>
            <?php foreach ($yearOptions as $y): ?>
              <option value="<?= (int)$y ?>">Year <?= (int)$y ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Faculty picker -->
        <div class="col field" id="facultyWrap" style="display:none">
          <label class="label" for="faculty_picker">Faculty</label>
          <input class="input" list="faculty_list" id="faculty_picker" placeholder="Search faculty name or email…">
          <datalist id="faculty_list">
            <?php foreach ($facultyRows as $fc): ?>
              <option data-id="<?= (int)$fc['user_id'] ?>" value="<?= htmlspecialchars(($fc['name'] ?: 'Faculty') . ' — ' . $fc['email']) ?>">
            <?php endforeach; ?>
          </datalist>
          <input type="hidden" name="faculty_user_id" id="faculty_user_id">
          <div class="help">Pick the faculty to receive this survey.</div>
        </div>
      </div>

      <div class="field">
        <label class="label" for="title">Title</label>
        <input type="text" id="title" name="title" class="input" maxlength="255" required>
        <div class="counter"><span id="titleCount">0</span>/255</div>
      </div>

      <div class="field">
        <label class="label" for="body">Message</label>
        <textarea id="body" name="body" class="input" maxlength="5000" placeholder="Include survey instructions and/or a link (e.g., https://… )" required></textarea>
        <div class="counter"><span id="bodyCount">0</span>/5000</div>
      </div>

      <div class="actions">
        <button type="submit" class="primary" id="sendBtn" disabled>
          <i class="fa-solid fa-paper-plane"></i> Send Survey
        </button>
      </div>

      <div class="card preview">
        <div><span class="muted">Recipients:</span> <span id="recipientPreview" class="recipient-pill">All students</span></div>
        <div class="muted" style="margin-top:6px">Live preview of who will get this survey.</div>
      </div>
    </form>

<?php elseif ($tab === 'sent'): ?>
  <h1>Sent Surveys</h1>

  <div class="card">
    <?php if (empty($sentBatches)): ?>
      <p class="help">You haven’t sent any surveys yet.</p>
    <?php else: ?>
      <div style="overflow:auto;">
        <table class="table">
          <thead>
            <tr>
              <th>Title</th>
              <th>Sent</th>
              <th>Total</th>
              <th>Viewed</th>
              <th>%</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sentBatches as $b): ?>
              <tr>
                <td><?= htmlspecialchars($b['title']) ?></td>
                <td><?= htmlspecialchars($b['sent_at']) ?></td>
                <td><span class="badge"><?= (int)$b['total'] ?></span></td>
                <td><span class="badge ok"><?= (int)$b['read_count'] ?></span></td>
                <td><?= htmlspecialchars(pct((int)$b['read_count'], (int)$b['total'])) ?></td>
                <td>
                  <?php if ($hasBatchToken): ?>
                    <a class="badge" href="<?= htmlspecialchars(tabUrl('recipients', ['batch' => $b['batch_token']])) ?>">
                      <i class="fa-solid fa-list"></i> Recipients
                    </a>
                  <?php else: ?>
                    <span class="help">Add <code>batch_token</code> to drill down.</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<?php elseif ($tab === 'recipients' && $hasBatchToken): ?>
  <h1>Recipients</h1>

  <div class="card">
    <?php if (empty($batchRecipients)): ?>
      <p class="help">No recipients for this batch (or invalid batch token).</p>
    <?php else: ?>
      <div style="overflow:auto;">
        <table class="table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Email</th>
              <th>Status</th>
              <th>Sent at</th>
              <th>Read at</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($batchRecipients as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['name'] ?: '—') ?></td>
                <td><?= htmlspecialchars($r['email'] ?: '—') ?></td>
                <td>
                  <?php if ((int)$r['is_read'] === 1): ?>
                    <span class="badge ok"><i class="fa-solid fa-eye"></i> Viewed</span>
                  <?php else: ?>
                    <span class="badge warn"><i class="fa-regular fa-eye-slash"></i> Not viewed</span>
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($r['created_at'] ?? '—') ?></td>
                <td><?= htmlspecialchars($r['read_at'] ?? '—') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div style="margin-top:10px">
        <a class="badge" href="<?= htmlspecialchars(tabUrl('sent')) ?>">
          <i class="fa-solid fa-arrow-left"></i> Back to Sent
        </a>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

    
  </div>
</main>

<script>
const aAllStudents = document.getElementById('a_all_students');
const aStudGender  = document.getElementById('a_students_gender');
const aStudYear    = document.getElementById('a_students_year');
const aStudYearGen = document.getElementById('a_students_year_gender');
const aAllFaculty  = document.getElementById('a_all_faculties');
const aSpecFaculty = document.getElementById('a_specific_faculty');

const genderWrap   = document.getElementById('genderWrap');
const yearWrap     = document.getElementById('yearWrap');
const facultyWrap  = document.getElementById('facultyWrap');

const genderSel    = document.getElementById('gender');
const yearSel      = document.getElementById('year_level');
const facultyPick  = document.getElementById('faculty_picker');
const facultyIdInp = document.getElementById('faculty_user_id');

const recipPrev    = document.getElementById('recipientPreview');
const sendBtn      = document.getElementById('sendBtn');
const titleInp     = document.getElementById('title');
const bodyInp      = document.getElementById('body');
const titleCount   = document.getElementById('titleCount');
const bodyCount    = document.getElementById('bodyCount');

function updateMode() {
  genderWrap.style.display  = 'none';
  yearWrap.style.display    = 'none';
  facultyWrap.style.display = 'none';

  if (aAllStudents.checked) {
    recipPrev.textContent = 'All students';
  } else if (aStudGender.checked) {
    genderWrap.style.display = '';
    recipPrev.textContent = genderSel.value ? ('Students — ' + genderSel.value) : 'Students by gender — (select)';
  } else if (aStudYear.checked) {
    yearWrap.style.display = '';
    recipPrev.textContent = yearSel.value ? ('Year ' + yearSel.value) : 'Students by year — (select)';
  } else if (aStudYearGen.checked) {
    yearWrap.style.display = '';
    genderWrap.style.display = '';
    const y = yearSel.value || '(year)';
    const g = genderSel.value || '(gender)';
    recipPrev.textContent = `Year ${y} — ${g}`;
  } else if (aAllFaculty.checked) {
    recipPrev.textContent = 'All faculties';
  } else {
    facultyWrap.style.display = '';
    recipPrev.textContent = facultyPick.value || 'Faculty — (select)';
  }
  validate();
}
[aAllStudents, aStudGender, aStudYear, aStudYearGen, aAllFaculty, aSpecFaculty].forEach(el => el.addEventListener('change', updateMode));

genderSel.addEventListener('change', updateMode);
yearSel.addEventListener('change', updateMode);

// Faculty datalist → hidden id
facultyPick.addEventListener('input', () => {
  const val = facultyPick.value;
  const options = document.querySelectorAll('#faculty_list option');
  let foundId = '';
  options.forEach(o => { if (o.value === val) foundId = o.getAttribute('data-id'); });
  facultyIdInp.value = foundId;
  recipPrev.textContent = val || 'Faculty — (select)';
  validate();
});

// Counters
function setCounts() {
  titleCount.textContent = titleInp.value.length;
  bodyCount.textContent  = bodyInp.value.length;
}
['input','change'].forEach(ev => {
  titleInp.addEventListener(ev, () => { setCounts(); validate(); });
  bodyInp.addEventListener(ev,  () => { setCounts(); validate(); });
});
setCounts();

function validate() {
  let ok = titleInp.value.trim() && bodyInp.value.trim();
  if (aStudGender.checked) ok = ok && !!genderSel.value;
  if (aStudYear.checked) ok = ok && !!yearSel.value;
  if (aStudYearGen.checked) ok = ok && !!yearSel.value && !!genderSel.value;
  if (aSpecFaculty.checked) ok = ok && !!facultyIdInp.value;
  sendBtn.disabled = !ok;
}
updateMode();
</script>
</body>
</html>
