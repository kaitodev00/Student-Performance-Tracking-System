<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
include '../config/db.php';
session_start();

/* ---------------- Resolve logged-in student ---------------- */
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) { echo "Student is not logged in."; exit; }

// Find the students.id linked to this user
$student_id = null;
if ($stmt = $conn->prepare("SELECT id FROM students WHERE user_id = ? LIMIT 1")) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->bind_result($student_id);
    $stmt->fetch();
    $stmt->close();
}
if (!$student_id) { echo "No student record linked to this account."; exit; }

/* -------- Combined Year–Sem options -------- */
$labelsYear = ['1'=>'First Year','2'=>'Second Year','3'=>'Third Year','4'=>'Fourth Year'];
$labelsSem  = ['1'=>'1st Semester','2'=>'2nd Semester'];

// accepted values e.g. 1-1, 1-2, 2-1, 2-2, ...
$validYS = [];
foreach ($labelsYear as $y => $_) {
  foreach ($labelsSem as $s => $_2) {
    $validYS["$y-$s"] = "{$labelsYear[$y]} — {$labelsSem[$s]}";
  }
}

/* -------- read selected (default 1-1) -------- */
$selectedYS = $_POST['ys'] ?? '1-1';
if (!isset($validYS[$selectedYS])) $selectedYS = '1-1';
[$selectedYearLevel, $selectedSemester] = explode('-', $selectedYS, 2);

/* -------- query courses for selected Y/S -------- */
$queryCourses = "SELECT c.courseID, c.courseCode, c.courseDesc, c.yearlevel_id, c.semester_id, c.courseUnit
                 FROM courses c
                 WHERE c.yearlevel_id = ? AND c.semester_id = ?
                 ORDER BY c.courseCode";
$stmt = $conn->prepare($queryCourses);
$stmt->bind_param('ii', $selectedYearLevel, $selectedSemester);
$stmt->execute();
$coursesResult = $stmt->get_result();

$currentFilter = $validYS[$selectedYS];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Grades</title>
  <style>
    /* Base */
    body {
      font-family: Arial, sans-serif;
      background-color: #f4f7fa;
      margin: 0;
      padding: 0;
      overflow-x: hidden;
    }

    .container {
      width: 100%;
      max-width: 1000px;
      margin: 10px auto 20px;
      padding: 20px;
    }

    h3 { color:#333; font-size:24px; margin-bottom:20px; }

    .filters {
      display:flex; gap:10px; align-items:center; margin-bottom:10px;
    }
    select {
      padding: 8px 12px; font-size:14px; border:1px solid #ced4da; border-radius:4px; background:#fff; min-width: 210px;
    }
    .filter-btn {
      background:#2684fc; color:#fff; padding:8px 16px; border:none; border-radius:4px; cursor:pointer; font-size:14px;
    }
    .filter-btn:hover { background:#1a73e8; }

    .current-filter {
      background:#e3f2fd; padding:10px 15px; border-radius:6px; margin-top:10px; border-left:4px solid #2684fc;
    }
    .current-filter h4 { margin:0; color:#1565c0; font-size:16px; }

    /* Table */
    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 20px;
      table-layout: fixed;
    }
    th, td {
      padding: 12px;
      text-align: left;
      border: none;
      border-bottom: 1px solid #ddd;
      word-wrap: break-word;
    }
    th {
      background: #2684fc;
      color: #fff;
      font-weight: 600;
      border-bottom: 2px solid #1565c0;
    }
    tr:last-child td { border-bottom: none; }
    tr:nth-child(even){ background:#f8f9fa; }
    tr:hover { background:#e9ecef; }
    .no-grades { color:#6c757d; font-style:italic; }
    .grade-value { font-weight:600; }
    .courseCode { font-weight:600; color:black; }
    .no-courses { text-align:center; color:#6c757d; font-style:italic; padding:40px; }

    /* Debug info styling */
    .debug-info {
      background: #fff3cd;
      border: 1px solid #ffeaa7;
      padding: 10px;
      margin: 10px 0;
      border-radius: 4px;
      font-size: 12px;
    }

    /* Responsive */
    @media (max-width: 768px) {
      body{ overflow: hidden;}
      .container { margin: 10px 10px 0 0; height: auto; padding:20px; }
      table { font-size:14px; table-layout: auto;}
      th, td { padding:8px; }
      option { font-size:12px; padding:6px; min-width: 80%; margin-left: 40px; max-width: 100px; }
      .filter-btn { font-size:12px; padding:6px; min-width: 100%; }
      .filters { flex-direction: column; align-items: stretch; min-width: 90px; }
      .filter-select { width: 70%; }
    }
    @media (max-width: 480px) {
      body{ overflow: hidden;}
      .container { padding:20px; }
      table { font-size:10px; }
      th, td { padding:6px; }
      option { font-size:12px; padding:6px; min-width: 10%; max-width: 150px; margin-left: 40px;}
      .filter-btn { font-size:12px; padding:6px; min-width: 100%; }
      .filters { flex-direction: column; align-items: stretch; }
      .filter-select { width: 100%; }
    }
  </style>
</head>
<body>
  <header>
    <?php $activeTab = 2; include '../config/header.php'; ?>
  </header>

  <div class="container">
  
    <!-- Filter Form -->
    <form id="filterForm" method="POST" action="">
      <div class="filters">
        <select name="ys" id="ys" class="filter-select">
          <?php foreach ($validYS as $val => $label): ?>
            <option value="<?= htmlspecialchars($val) ?>" <?= $selectedYS === $val ? 'selected' : '' ?>>
              <?= htmlspecialchars($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>

    <!-- RESULTS WRAPPER: we replace only this via JS -->
    <div id="results">
      <?php if ($coursesResult && $coursesResult->num_rows > 0): ?>
        <table id="gradesTable">
          <thead>
            <tr>
              <th>Course</th>
              <th>Course Description</th>
              <th>Units</th>
              <th>Midterm</th>
              <th>Final Grade</th>
              <th>Remarks</th>
            </tr>
          </thead>
          <tbody>
            <?php while ($row = $coursesResult->fetch_assoc()): ?>
              <?php
                $courseID = (int)$row['courseID'];

                // Use correct column names and the resolved student_id
                $gsql = "SELECT g.midterm, g.finalgrade, g.remarks
                         FROM tblstudentgrade g
                         WHERE g.course_id = ? AND g.student_id = ?";
                $gs = $conn->prepare($gsql);

                if (!$gs) {
                    echo "<tr><td colspan='6'>Database prepare error: " . htmlspecialchars($conn->error) . "</td></tr>";
                    continue;
                }

                $gs->bind_param('ii', $courseID, $student_id);
                $gs->execute();
                $gradesResult = $gs->get_result();
                $grades = $gradesResult ? $gradesResult->fetch_assoc() : null;
                $gs->close();

                $mid = $grades['midterm'] ?? '';
                $fin = $grades['finalgrade'] ?? $grades['final'] ?? '';
                $rem = $grades['remarks'] ?? '';
              ?>
              <tr>
                <td class="courseCode"><strong><?= htmlspecialchars($row['courseCode']) ?></strong></td>
                <td><?= htmlspecialchars($row['courseDesc'] ?? '') ?></td>
                <td><?= htmlspecialchars($row['courseUnit'] ?? '') ?></td>
                <td class="grade-value"><?= $mid !== '' && $mid !== null ? htmlspecialchars($mid) : '<span class="no-grades">No Grade</span>' ?></td>
                <td class="grade-value"><?= $fin !== '' && $fin !== null ? htmlspecialchars($fin) : '<span class="no-grades">No Grade</span>' ?></td>
                <td class="grade-value"><?= $rem !== '' && $rem !== null ? htmlspecialchars($rem) : '<span class="no-grades">No Remarks</span>' ?></td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      <?php else: ?>
        <div class="no-courses">
          <p>No courses available for <?= htmlspecialchars($currentFilter) ?>.</p>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <footer>
    <?php include '../config/nav_bar.php'; ?>
  </footer>

  <!-- JS: AJAX filter -->
  <script>
  document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('filterForm');
    const select = document.getElementById('ys');
    const results = document.getElementById('results');

    function updateResults() {
      const fd = new FormData(form);
      const prev = results.innerHTML;
      results.innerHTML = '<p style="padding:12px">Loading…</p>';

      fetch(window.location.href, {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
      .then(r => r.text())
      .then(html => {
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const newResults = doc.getElementById('results');
        if (newResults) {
          results.innerHTML = newResults.innerHTML;
        } else {
          results.innerHTML = prev;
          console.error('Could not find #results in response.');
        }
      })
      .catch(err => {
        results.innerHTML = prev;
        console.error(err);
      });
    }

    select.addEventListener('change', updateResults);
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      updateResults();
    });
  });
  </script>
</body>
</html>
