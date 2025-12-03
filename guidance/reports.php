<?php
// guidance/reports.php  (single file: topbar + results + CSV)
ini_set('display_errors', 1); error_reporting(E_ALL);
session_start();
require_once '../config/db.php';

$roles    = $_SESSION['roles'] ?? [];
$roles_lc = array_map('strtolower', is_array($roles) ? $roles : []);

// allow both Guidance and Dean
$allowed_roles = ['guidance', 'dean'];  // add more aliases if you use them, e.g. 'deans'
$has_access = !empty(array_intersect($allowed_roles, $roles_lc));

if (!isset($_SESSION['user_id']) || !$has_access) {
    header('Location: ../index.php'); 
    exit();
}


// -------- helpers --------
function fetch_all(mysqli_stmt $stmt): array {
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) { $rows[] = $row; }
    $stmt->close();
    return $rows;
}

function get_report_data(mysqli $conn, string $report): array {
    $headers = []; $rows = [];

    switch ($report) {
        case 'students': {
            $sql = "SELECT s.student_number, s.student_name, s.sex, s.email, s.contact, s.year_level_id,
                           p.program_name, s.academic_status, s.track_status,
                           CASE WHEN s.is_active = 1 THEN 'Yes' ELSE 'No' END AS Active
                    FROM students s
                    LEFT JOIN programs p ON p.program_id = s.program_id
                    ORDER BY s.student_name ASC";
            $stmt = $conn->prepare($sql);
            $rows = fetch_all($stmt);
            $headers = ['#','student_number','student_name','sex','email','contact','year_level_id','program_name','academic_status','track_status','Active'];
            break;
        }
        case 'faculty': {
            $stmt = $conn->prepare("SELECT f.facultyno, f.faculty_name, f.email, f.contact FROM tblfaculty f ORDER BY f.faculty_name ASC");
            $rows = fetch_all($stmt);
            $headers = ['#','facultyno','faculty_name','email','contact'];
            break;
        }
        case 'courses': {
            $stmt = $conn->prepare("SELECT c.currID, c.courseCode, c.courseDesc, c.courseUnit, c.courseLabHrs, c.courseLecHrs, c.yearlevel_id, c.semester_id FROM courses c ORDER BY c.courseCode ASC");
            $rows = fetch_all($stmt);
            $headers = ['#','currID','courseCode','courseDesc','courseUnit','courseLabHrs','courseLecHrs','yearlevel_id','semester_id'];
            break;
        }
        case 'programs': {
            $stmt = $conn->prepare("SELECT p.program_code, p.program_name, CASE WHEN p.is_active = 1 THEN 'Yes' ELSE 'No' END AS Active FROM programs p ORDER BY p.program_name ASC");
            $rows = fetch_all($stmt);
            $headers = ['#','program_code','program_name','Active'];
            break;
        }
        case 'users': {
            $sql = "SELECT u.email, u.created_at, CASE WHEN u.is_active = 1 THEN 'Yes' ELSE 'No' END AS Active,
                           u.student_id, u.faculty_id,
                           COALESCE(GROUP_CONCAT(r.role_name ORDER BY r.role_name SEPARATOR ', '), '') AS roles
                    FROM users u
                    LEFT JOIN user_roles ur ON ur.user_id = u.id
                    LEFT JOIN roles r ON r.role_id = ur.role_id
                    GROUP BY u.id, u.email, u.created_at, u.is_active, u.student_id, u.faculty_id
                    ORDER BY u.id ASC";
            $stmt = $conn->prepare($sql);
            $rows = fetch_all($stmt);
            $headers = ['#','email','created_at','Active','student_id','faculty_id','roles'];
            break;
        }
        case 'at_risk':
        case 'off_track':
        case 'on_track': {
            $sql = "SELECT s.student_number, s.student_name, s.sex, s.email, s.year_level_id,
                           p.program_name, s.track_status
                    FROM students s
                    LEFT JOIN programs p ON p.program_id = s.program_id
                    WHERE s.track_status = ?
                    ORDER BY s.student_name ASC";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('s', $report);
            $rows = fetch_all($stmt);
            $headers = ['#','student_number','student_name','sex','email','year_level_id','program_name','track_status'];
            break;
        }
        case 'average_grades': {
    // Treat 'NA' as NULL, then compute:
    // - (midterm + finalgrade)/2 if both exist
    // - finalgrade if only finalgrade exists
    // - midterm if only midterm exists
    $sql = "SELECT
                s.student_number,
                s.student_name,
                ROUND(AVG(
                    CASE
                      WHEN CAST(NULLIF(sg.midterm,'NA') AS DECIMAL(5,2)) IS NOT NULL
                       AND CAST(NULLIF(sg.finalgrade,'NA') AS DECIMAL(5,2)) IS NOT NULL
                        THEN (CAST(NULLIF(sg.midterm,'NA') AS DECIMAL(5,2))
                            + CAST(NULLIF(sg.finalgrade,'NA') AS DECIMAL(5,2)))/2
                      WHEN CAST(NULLIF(sg.finalgrade,'NA') AS DECIMAL(5,2)) IS NOT NULL
                        THEN CAST(NULLIF(sg.finalgrade,'NA') AS DECIMAL(5,2))
                      WHEN CAST(NULLIF(sg.midterm,'NA') AS DECIMAL(5,2)) IS NOT NULL
                        THEN CAST(NULLIF(sg.midterm,'NA') AS DECIMAL(5,2))
                      ELSE NULL
                    END
                ), 2) AS avg_grade
            FROM students s
            LEFT JOIN tblstudentgrade sg ON sg.student_id = s.id
            GROUP BY s.id, s.student_number, s.student_name
            ORDER BY s.student_name ASC";
    $stmt = $conn->prepare($sql);
    $rows = fetch_all($stmt);
    $headers = ['#','student_number','student_name','avg_grade'];
    break;
}

        default: break;
    }
    return [$headers, $rows];
}

// -------- inputs --------
$report   = $_POST['report_type'] ?? $_GET['report_type'] ?? '';
$download = isset($_GET['download']) ? (int)$_GET['download'] : 0;
list($headers, $rows) = $report ? get_report_data($conn, $report) : [[], []];

// stay on same page for posts/downloads
$self = basename($_SERVER['PHP_SELF']);

// -------- CSV --------
if ($download === 1 && $report !== '' && !empty($headers)) {
    $filename = 'report_' . preg_replace('/[^a-z0-9_\-]/i','_', $report) . '_' . date('Ymd_His');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    $i = 1;
    foreach ($rows as $r) {
        $line = [$i++];
        foreach (array_slice($headers, 1) as $h) { $line[] = $r[$h] ?? ''; }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Generate Reports<?= $report ? ' - ' . htmlspecialchars(ucwords(str_replace('_',' ', $report))) : '' ?></title>
<link rel="stylesheet" href="../design/admin/admin_layout.css">
<style>
  body { font-family: Arial, sans-serif; background:#f4f4f4; margin:0 0 0 10px; padding:10px;  }
  h2 { margin:0 0 15px; padding-bottom:5px; font-size:30px; overflow:auto; }
  .topbar { display:flex; justify-content:center; align-items:center; background:#fff; padding:1px; color:#000; box-shadow:0 2px 4px rgba(0,0,0,.1); border-radius:8px; margin-bottom:20px; height:50px; }
  .report-controls { display:flex; align-items:center; gap:10px; width:100%; max-width:900px; }
  .report-controls h4 { margin:0 6px 0 100px; white-space:nowrap; }
  .report-controls select {margin-left:18px; padding:8px 12px; border-radius:5px; border:1px solid #2684fc; font-size:16px; background:#fff; color:#333; }
  .report-controls button { padding:8px 16px; border-radius:5px; border:none; margin-left:50px; font-size:16px; font-weight:700; background:#2684fc; color:#fff; cursor:pointer; transition:background .3s; }
  .report-controls button:hover { background:#1869d0; }
  .actions { margin:10px 0 16px; }
  .btn { display:inline-block; padding:8px 14px; border-radius:6px; background:#2684fc; color:#fff; text-decoration:none; font-weight:600; }
  .btn:hover { background:#1869d0; }
  table { border-collapse:collapse; width:100%; background:#fff; border-radius:10px; overflow:hidden; }
  th, td { padding:8px 10px; font-size:14px; border-bottom:1px solid #e5e7eb; }
  th { background:#2684fc; color:#fff; text-align:left; }
  .table-wrap { border-radius:10px; overflow:hidden; box-shadow:0 2px 4px rgba(0,0,0,.08); }
  .empty { margin-top:8px; color:#666; }
</style>
</head>
<body>
<?php include '../config/aside.php'; include '../config/head_section.php'; ?>

<main>
  <h2>Generate Reports</h2>

  <!-- Topbar form (always visible) -->
  <div class="topbar">
    <form action="<?= htmlspecialchars($self) ?>" method="POST" class="report-controls">
      <h4>Select Report Type:</h4>
      <select name="report_type" required>
        <?php
          $opts = [
            'students' => 'Students',
            'faculty' => 'Faculty',
            'courses' => 'Courses',
            'programs' => 'Programs',
            'users' => 'Users',
            'at_risk' => 'At Risk Students',
            'off_track' => 'Off-Track Students',
            'on_track' => 'On-Track Students',
            'average_grades' => 'Average Grades',
          ];
          foreach ($opts as $val => $label):
        ?>
          <option value="<?= htmlspecialchars($val) ?>" <?= $report===$val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit">Generate</button>
    </form>
  </div>

  <!-- Results appear directly below the top bar -->
  <?php if ($report && !empty($headers)): ?>
    <div class="actions">
      <a class="btn" href="<?= htmlspecialchars($self) ?>?report_type=<?= urlencode($report) ?>&download=1">Download CSV</a>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <?php foreach ($headers as $h): ?><th><?= htmlspecialchars($h) ?></th><?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="<?= count($headers) ?>">No data.</td></tr>
          <?php else: ?>
            <?php $i=1; foreach ($rows as $r): ?>
              <tr>
                <td><?= $i++ ?></td>
                <?php foreach (array_slice($headers,1) as $h): ?>
                  <td><?= htmlspecialchars((string)($r[$h] ?? '')) ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php elseif (!$report): ?>
    <div class="empty">Select a report above to view results.</div>
  <?php else: ?>
    <div class="empty"><strong>Unknown report type:</strong> <?= htmlspecialchars($report) ?></div>
  <?php endif; ?>
</main>
</body>
</html>
