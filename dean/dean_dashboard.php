<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include '../config/db.php';

$roles = $_SESSION['roles'] ?? [];
$roles_lc = array_map('strtolower', $roles);
if (!isset($_SESSION['user_id']) || !in_array('dean', $roles_lc, true)) {
    header('Location: ../index.php'); exit();
}

/* ---------------- Consolidated quick counts (1 roundtrip) ---------------- */
$sql_counts = "
  SELECT
    (SELECT COUNT(*) FROM students)   AS student_count,
    (SELECT COUNT(*) FROM tblfaculty) AS faculty_count,
    (SELECT COUNT(*) FROM programs)   AS program_count,
    (SELECT COUNT(*) FROM courses)    AS course_count
";
$counts_res = $conn->query($sql_counts);
$student_count = $faculty_count = $program_count = $course_count = 0;
if ($counts_res && $row = $counts_res->fetch_assoc()) {
  $student_count = (int)$row['student_count'];
  $faculty_count = (int)$row['faculty_count'];
  $program_count = (int)$row['program_count'];
  $course_count  = (int)$row['course_count'];
}

/* ---------------- Latest students ---------------- */
$latest_students = $conn->query("
    SELECT s.student_number, s.student_name, p.program_name
    FROM students s
    LEFT JOIN programs p ON p.program_id = s.program_id
    ORDER BY s.id DESC
    LIMIT 5
");
$latest_students = $latest_students ? $latest_students->fetch_all(MYSQLI_ASSOC) : [];

/* ---------------- Latest faculty ---------------- */
$latest_faculty = $conn->query("
    SELECT facultyno, faculty_name, email
    FROM tblfaculty
    ORDER BY faculty_id DESC
    LIMIT 5
");
$latest_faculty = $latest_faculty ? $latest_faculty->fetch_all(MYSQLI_ASSOC) : [];

/* ---------------- Top courses by risk ---------------- */
$top_flagged_courses = $conn->query("
    SELECT
      x.courseCode,
      x.courseDesc,
      SUM(
        CASE
          WHEN x.fg_text IN ('INC','INCOMPLETE') THEN 1
          WHEN x.fg_text = 'NA' AND x.remarks = 'PASSED' THEN 0
          WHEN x.num_final IS NULL AND x.num_mid IS NOT NULL AND x.num_mid >= 2.75 THEN 1
          ELSE 0
        END
      ) AS at_risk_count,
      SUM(
        CASE
          WHEN x.remarks IN ('UD','DRP') OR x.fg_text IN ('UD','DRP','DROP') THEN 1
          WHEN x.num_final IS NOT NULL AND x.num_final >= 5.00 THEN 1
          ELSE 0
        END
      ) AS off_track_count,
      SUM(
        CASE
          WHEN x.fg_text IN ('INC','INCOMPLETE') THEN 1
          WHEN x.remarks IN ('UD','DRP') OR x.fg_text IN ('UD','DRP','DROP') THEN 1
          WHEN x.num_final IS NOT NULL AND x.num_final >= 5.00 THEN 1
          WHEN x.fg_text = 'NA' AND x.remarks = 'PASSED' THEN 0
          WHEN x.num_final IS NULL AND x.num_mid IS NOT NULL AND x.num_mid >= 2.75 THEN 1
          ELSE 0
        END
      ) AS total_flagged
    FROM (
      SELECT
        c.courseCode,
        c.courseDesc,
        UPPER(TRIM(sg.remarks))     AS remarks,
        UPPER(TRIM(sg.finalgrade))  AS fg_text,
        CASE
          WHEN sg.finalgrade IS NOT NULL
           AND REPLACE(sg.finalgrade, ',', '.') REGEXP '^[0-9]+(\\.[0-9]+)?$'
          THEN CAST(REPLACE(sg.finalgrade, ',', '.') AS DECIMAL(5,2))
          ELSE NULL
        END AS num_final,
        CASE
          WHEN sg.midterm IS NOT NULL
           AND REPLACE(sg.midterm, ',', '.') REGEXP '^[0-9]+(\\.[0-9]+)?$'
          THEN CAST(REPLACE(sg.midterm, ',', '.') AS DECIMAL(5,2))
          ELSE NULL
        END AS num_mid,
        sg.course_id
      FROM tblstudentgrade sg
      JOIN courses c
        ON (c.currID = sg.course_id OR c.courseID = sg.course_id)
    ) AS x
    GROUP BY x.course_id, x.courseCode, x.courseDesc
    HAVING total_flagged > 0
    ORDER BY total_flagged DESC, x.courseCode ASC
    LIMIT 5
");
$top_flagged_courses = $top_flagged_courses ? $top_flagged_courses->fetch_all(MYSQLI_ASSOC) : [];

/* ---------------- Totals for risk chart ---------------- */
$at_risk_total   = array_sum(array_map(fn($r)=>(int)$r['at_risk_count'], $top_flagged_courses));
$off_track_total = array_sum(array_map(fn($r)=>(int)$r['off_track_count'], $top_flagged_courses));

/* ---------------- Enrollment by Program (pie) ---------------- */
$program_enrollment = $conn->query("
  SELECT COALESCE(p.program_name,'Unassigned') AS program_name, COUNT(s.id) AS total
  FROM students s
  LEFT JOIN programs p ON p.program_id = s.program_id
  GROUP BY COALESCE(p.program_name,'Unassigned')
  ORDER BY total DESC
");
$program_enrollment = $program_enrollment ? $program_enrollment->fetch_all(MYSQLI_ASSOC) : [];

/* ---------------- Monthly Student Additions (line) ----------------
   We’ll check which date column exists: created_at | date_created | enrollment_date.
-------------------------------------------------------------------*/
$date_col = null;
$col_check = $conn->query("
  SELECT COLUMN_NAME
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'students'
    AND COLUMN_NAME IN ('created_at','date_created','enrollment_date','createdAt','dateAdded')
  LIMIT 1
");
if ($col_check && $r = $col_check->fetch_assoc()) {
  $date_col = $r['COLUMN_NAME'];
}

$monthly_series = [];
if ($date_col) {
  $monthly_sql = "
    SELECT DATE_FORMAT($date_col, '%Y-%m') AS ym,
           DATE_FORMAT($date_col, '%b %Y') AS label,
           COUNT(*) AS total
    FROM students
    WHERE $date_col IS NOT NULL
    GROUP BY DATE_FORMAT($date_col, '%Y-%m')
    ORDER BY ym ASC
    LIMIT 24
  ";
  $monthly_res = $conn->query($monthly_sql);
  $monthly_series = $monthly_res ? $monthly_res->fetch_all(MYSQLI_ASSOC) : [];
}

/* ---------------- Insight banner message ---------------- */
$insight_message = '';
if ($at_risk_total + $off_track_total === 0) {
  $insight_message = "✅ No flagged enrollments in top courses right now.";
} elseif ($at_risk_total > $off_track_total) {
  $insight_message = "⚠️ More students are currently At-Risk than Off-Track. Consider early interventions.";
} elseif ($off_track_total > 0) {
  $insight_message = "🚩 Off-Track cases present—review drop/UD patterns and final grades ≥ 5.00.";
} else {
  $insight_message = "📊 Risk levels are stable. Keep monitoring midterm trends.";
}

/* ---------------- Helpers for safe JSON ---------------- */
function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function to_json($v){ return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dean Dashboard</title>
  <link rel="stylesheet" href="../design/admin/admin_layout.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

  <style>
    :root{
      --bg: #f4f4f4;
      --card: #ffffff;
      --text: #0f172a;
      --muted:#64748b;
      --primary:#2684fc;
      --success:#36b37e;
      --warning:#f59e0b;
      --danger:#dc2626;
      --purple:#6554c0;
      --shadow: 0 2px 6px rgba(0,0,0,0.08);
      --shadow-lg: 0 8px 20px rgba(0,0,0,0.12);
      --table-stripe:#f9fbff;
    }

    body { background: var(--bg); color: var(--text); }
    main { margin: 50px 0 0 280px; padding: 24px; }
    h1 { margin: 10px 0 16px; font-size: 28px; }
    .topbar {
      display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom: 8px;
    }
    .muted { color: var(--muted); font-size: 14px; }

    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 16px;
      margin-top: 12px;
    }
    .card {
      background: var(--card);
      padding: 18px;
      border-radius: 14px;
      box-shadow: var(--shadow);
      transition: transform .15s ease, box-shadow .15s ease;
    }
    .card:hover { transform: translateY(-4px); box-shadow: var(--shadow-lg); }
    .stat { text-align:center; }
    .stat h2 { font-size: 34px; margin: 4px 0 2px; color: var(--primary); }
    .stat p { margin: 0; color: var(--muted); font-weight: 500; }
    .stat i { font-size: 20px; margin-right: 8px; color: var(--muted); }

    .chart-row {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 16px;
      margin-top: 18px;
    }
    .chart-card { padding: 14px; }
    .chart-title { font-size: 16px; margin: 0 0 8px; color: var(--muted); }

    .insight {
      border-left: 6px solid var(--warning);
      background: rgba(245, 158, 11, 0.1);
      color: var(--text);
      padding: 14px 16px;
      border-radius: 12px;
      margin-top: 18px;
    }

    .table-row { display:flex; flex-wrap:wrap; gap:16px; margin-top:18px; }
    .table-card { flex:1 1 420px; }
    table {
      width: 100%;
      border-collapse: collapse;
      border-radius: 12px;
      overflow: hidden;
      background: var(--card);
      box-shadow: var(--shadow);
    }
    th, td { padding: 12px; border-bottom: 1px solid rgba(0,0,0,0.04); font-size: 14px; }
    th { background: var(--primary); color: #fff; text-align: left; }
    tbody tr:nth-child(odd) td { background: var(--table-stripe); }
    tbody tr:hover td { filter: brightness(1.02); }

    .section-title { margin: 24px 0 8px; }
    .toggle {
      background: var(--card); border: 1px solid rgba(0,0,0,0.08);
      padding:8px 12px; border-radius: 10px; cursor: pointer; box-shadow: var(--shadow);
      color: var(--text);
    }
  </style>
</head>
<body>
  <?php include '../config/aside.php';
        include '../config/head_section.php'; ?>

  <main>
    <div class="topbar">
      <div>
        <h1>Dashboard</h1>
        <div class="muted">Quick overview of enrollment, faculty, and risk signals.</div>
      </div>
    </div>

    <!-- Quick stats -->
    <div class="grid">
      <div class="card stat">
        <h2><?= $student_count ?></h2>
        <p><i class="fa-solid fa-user-graduate"></i> Total Students</p>
      </div>
      <div class="card stat">
        <h2><?= $faculty_count ?></h2>
        <p><i class="fa-solid fa-user-tie"></i> Total Faculty</p>
      </div>
      <div class="card stat">
        <h2><?= $program_count ?></h2>
        <p><i class="fa-solid fa-layer-group"></i> Programs</p>
      </div>
      <div class="card stat">
        <h2><?= $course_count ?></h2>
        <p><i class="fa-solid fa-book-open"></i> Courses</p>
      </div>
    </div>

    <!-- Insight -->
    <div class="insight"><?= e($insight_message) ?></div>

    <!-- Charts -->
    <div class="chart-row">
      <div class="card chart-card">
        <div class="chart-title">Institution Overview</div>
        <canvas id="overviewChart" height="320"></canvas>
      </div>

      <div class="card chart-card">
        <div class="chart-title">At-Risk vs Off-Track</div>
        <canvas id="riskChart" height="320"></canvas>
      </div>

      <div class="card chart-card">
        <div class="chart-title">Enrollment by Program</div>
        <canvas id="programChart" height="320"></canvas>
      </div>

      <?php if (!empty($monthly_series)): ?>
      <div class="card chart-card">
        <div class="chart-title">Monthly Student Additions</div>
        <canvas id="monthlyChart" height="320"></canvas>
      </div>
      <?php endif; ?>
    </div>

    <!-- Tables side-by-side -->
    <div class="table-row">
      <div class="table-card card">
        <h2 class="section-title">Recently Added Students</h2>
        <table>
          <thead><tr><th>Student No</th><th>Name</th><th>Program</th></tr></thead>
          <tbody>
          <?php if ($latest_students): foreach ($latest_students as $s): ?>
            <tr>
              <td><?= e($s['student_number']) ?></td>
              <td><?= e($s['student_name']) ?></td>
              <td><?= e($s['program_name']) ?></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="3">No recent students.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="table-card card">
        <h2 class="section-title">Recently Added Faculty</h2>
        <table>
          <thead><tr><th>Faculty No</th><th>Name</th><th>Email</th></tr></thead>
          <tbody>
          <?php if ($latest_faculty): foreach ($latest_faculty as $f): ?>
            <tr>
              <td><?= e($f['facultyno']) ?></td>
              <td><?= e($f['faculty_name']) ?></td>
              <td><?= e($f['email']) ?></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="3">No recent faculty.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Top courses -->
    <div class="card" style="margin-top:18px;">
      <h2 class="section-title">Top Courses (At-Risk / Off-Track)</h2>
      <table>
        <thead>
          <tr>
            <th style="width:20%;">Course Code</th>
            <th>Course Description</th>
            <th style="width:12%;">At-Risk</th>
            <th style="width:12%;">Off-Track</th>
            <th style="width:12%;">Total</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($top_flagged_courses): foreach ($top_flagged_courses as $c): ?>
            <tr>
              <td><?= e($c['courseCode']) ?></td>
              <td><?= e($c['courseDesc']) ?></td>
              <td><?= (int)$c['at_risk_count'] ?></td>
              <td><?= (int)$c['off_track_count'] ?></td>
              <td><strong><?= (int)$c['total_flagged'] ?></strong></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="5">No at-risk or off-track enrollments found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </main>

  <script>

    /* ---------------- Chart helpers ---------------- */
    const primary = getComputedStyle(document.documentElement).getPropertyValue('--primary').trim();
    const success = getComputedStyle(document.documentElement).getPropertyValue('--success').trim();
    const warning = getComputedStyle(document.documentElement).getPropertyValue('--warning').trim();
    const danger  = getComputedStyle(document.documentElement).getPropertyValue('--danger').trim();
    const purple  = getComputedStyle(document.documentElement).getPropertyValue('--purple').trim();

    // Overview
    new Chart(document.getElementById('overviewChart'), {
      type: 'bar',
      data: {
        labels: ['Students','Faculty','Programs','Courses'],
        datasets: [{
          label: 'Count',
          data: [<?= $student_count ?>, <?= $faculty_count ?>, <?= $program_count ?>, <?= $course_count ?>],
          backgroundColor: [primary, success, warning, purple],
          borderRadius: 6
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { display:false }, tooltip:{ mode:'index' } },
        scales: { y: { beginAtZero: true, grid: { display:false } } }
      }
    });

    // Risk
    new Chart(document.getElementById('riskChart'), {
      type: 'doughnut',
      data: {
        labels: ['At-Risk','Off-Track'],
        datasets: [{ data: [<?= $at_risk_total ?>, <?= $off_track_total ?>], backgroundColor: [warning, danger] }]
      },
      options: {
        plugins: { legend:{ position:'bottom' } },
        cutout: '62%'
      }
    });

    // Program Pie
    const programLabels = <?= to_json(array_map(fn($r)=>$r['program_name'], $program_enrollment)) ?>;
    const programData   = <?= to_json(array_map(fn($r)=>(int)$r['total'], $program_enrollment)) ?>;
    new Chart(document.getElementById('programChart'), {
      type: 'pie',
      data: {
        labels: programLabels,
        datasets: [{
          data: programData,
          // nice palette reusing theme colors
          backgroundColor: [primary, success, warning, danger, purple, '#14b8a6', '#f472b6', '#94a3b8', '#22c55e', '#f97316']
        }]
      },
      options: {
        plugins: { legend: { position: 'bottom' } }
      }
    });

    // Monthly Additions (only if data available)
    <?php if (!empty($monthly_series)): ?>
      const monthlyLabels = <?= to_json(array_map(fn($r)=>$r['label'], $monthly_series)) ?>;
      const monthlyData   = <?= to_json(array_map(fn($r)=>(int)$r['total'], $monthly_series)) ?>;
      new Chart(document.getElementById('monthlyChart'), {
        type: 'line',
        data: {
          labels: monthlyLabels,
          datasets: [{
            label: 'New Students',
            data: monthlyData,
            borderColor: primary,
            backgroundColor: primary,
            tension: .35,
            fill: false,
            pointRadius: 3
          }]
        },
        options: {
          plugins: { legend: { display:false }},
          scales: { y: { beginAtZero: true } }
        }
      });
    <?php endif; ?>
  </script>
</body>
</html>
