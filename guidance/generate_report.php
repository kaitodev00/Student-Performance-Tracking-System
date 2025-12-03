<?php
// guidance/generate_report.php
ini_set('display_errors', 1); error_reporting(E_ALL);
session_start();
require_once '../config/db.php';

$roles = $_SESSION['roles'] ?? [];
if (!isset($_SESSION['user_id']) || !is_array($roles) || !in_array('guidance', array_map('strtolower',$roles), true)) {
    header('Location: ../index.php');
    exit();
}

function fetch_all(mysqli_stmt $stmt): array {
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) { $rows[] = $row; }
    $stmt->close();
    return $rows;
}

$report = $_POST['report_type'] ?? $_GET['report_type'] ?? '';
$download = isset($_GET['download']) ? (int)$_GET['download'] : 0;

if ($report === '') {
    http_response_code(400);
    echo 'Missing report_type.';
    exit;
}

$filename = 'report_' . preg_replace('/[^a-z0-9_\-]/i','_', $report) . '_' . date('Ymd_His');
$headers = [];
$rows    = [];

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
    case 'at_risk': {
        $sql = "SELECT s.student_number, s.student_name, s.sex, s.email, s.year_level_id,
                       p.program_name, s.track_status
                FROM students s
                LEFT JOIN programs p ON p.program_id = s.program_id
                WHERE s.track_status = 'at_risk'
                ORDER BY s.student_name ASC";
        $stmt = $conn->prepare($sql);
        $rows = fetch_all($stmt);
        $headers = ['#','student_number','student_name','sex','email','year_level_id','program_name','track_status'];
        break;
    }
    case 'off_track': {
        $sql = "SELECT s.student_number, s.student_name, s.sex, s.email, s.year_level_id,
                       p.program_name, s.track_status
                FROM students s
                LEFT JOIN programs p ON p.program_id = s.program_id
                WHERE s.track_status = 'off_track'
                ORDER BY s.student_name ASC";
        $stmt = $conn->prepare($sql);
        $rows = fetch_all($stmt);
        $headers = ['#','student_number','student_name','sex','email','year_level_id','program_name','track_status'];
        break;
    }
    case 'on_track': {
        $sql = "SELECT s.student_number, s.student_name, s.sex, s.email, s.year_level_id,
                       p.program_name, s.track_status
                FROM students s
                LEFT JOIN programs p ON p.program_id = s.program_id
                WHERE s.track_status = 'on_track'
                ORDER BY s.student_name ASC";
        $stmt = $conn->prepare($sql);
        $rows = fetch_all($stmt);
        $headers = ['#','student_number','student_name','sex','email','year_level_id','program_name','track_status'];
        break;
    }
      case 'average_grades': {
      $sql = "SELECT
                  s.student_number,
                  s.student_name,
                  ROUND(AVG(
                      (CAST(NULLIF(sg.midterm,'NA') AS DECIMAL(5,2)) +
                      CAST(NULLIF(sg.finalgrade,'NA') AS DECIMAL(5,2)))/2
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
    default: {
        http_response_code(400);
        echo 'Unknown report type';
        exit;
    }
}

if ($download === 1) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    $i = 1;
    foreach ($rows as $r) {
        $line = [$i++];
        foreach (array_slice($headers, 1) as $h) {
            $line[] = $r[$h] ?? '';
        }
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
<title><?= htmlspecialchars(ucwords(str_replace('_',' ', $report))) ?> Report</title>
<link rel="stylesheet" href="../design/admin/admin_layout.css">
<style>
  body { font-family: Arial, sans-serif; overflow-x:hidden; background:#f4f4f4; }
  main { max-width: 100%; margin: 0 0 0 280px; padding: 16px; }
  h2 { margin: 0 0 0 0; }
  .actions { margin: 10px 0 16px 0; }
  table { border-collapse: collapse; width: 100%; }
  th, td { padding: 8px 10px; font-size: 14px; border-bottom: 1px solid #e5e7eb; }
  th { background:#2684fc; text-align: left; color: white; }
</style>
</head>
<body>
<?php include '../config/aside.php'; include '../config/head_section.php'; ?>
<main>
  <h1><?= htmlspecialchars(ucwords(str_replace('_',' ', $report))) ?> Report</h1>
  <div class="actions">
    <a class="btn" href="generate_report.php?report_type=<?= urlencode($report) ?>&download=1">Download CSV</a>
    <a class="btn" href="reports.php" style="margin-left:12px">Back</a>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <?php foreach ($headers as $h): ?>
            <th><?= htmlspecialchars($h) ?></th>
          <?php endforeach; ?>
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
</main>
</body>
</html>