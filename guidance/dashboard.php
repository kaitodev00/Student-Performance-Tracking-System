<?php
// guidance/dashboard.php
ini_set('display_errors', 1); error_reporting(E_ALL);
session_start();
include '../config/db.php';

define('ON_TRACK_MAX', 2.00);
define('AT_RISK_MAX',  2.50);

function track_from_avg(?float $avg): string {
  if ($avg === null) return 'no-data';
  if ($avg <= ON_TRACK_MAX) return 'on-track';
  if ($avg <= AT_RISK_MAX)  return 'at-risk';
  return 'off-track';
}

// Allow only guidance users
$userRole = strtolower($_SESSION['user_role'] ?? '');
if ($userRole !== 'guidance') {
  http_response_code(403);
  exit('Forbidden: guidance only');
}

// Pull ALL active students + their average grade (global view for guidance)
$sql = "
  SELECT 
    s.id AS student_id,
    s.student_name,
    s.track_status,
    s.program_id,
    s.year_level_id,
    AVG(
      CASE
        WHEN g.finalgrade IS NOT NULL THEN g.finalgrade
        WHEN g.midterm IS NOT NULL AND g.finalgrade IS NOT NULL THEN (g.midterm + g.finalgrade)/2
        ELSE NULL
      END
    ) AS avg_grade
  FROM students s
  LEFT JOIN tblstudentgrade g ON g.student_id = s.id
  WHERE s.is_active = 1
  GROUP BY s.id, s.student_name, s.track_status, s.program_id, s.year_level_id
  ORDER BY s.student_name ASC
";

$res = $conn->query($sql);
$rows = [];
$totalStudents = 0;
$sumAvgs = 0.0; $withAvg = 0;
$breakdown = ['on-track'=>0,'at-risk'=>0,'off-track'=>0,'no-data'=>0];
$gradeBuckets = [0,0,0,0,0];
$programData = [];
$trendData = []; // For year-level trend

while ($r = $res->fetch_assoc()) {
  $totalStudents++;

  $avg = $r['avg_grade'] !== null ? (float)$r['avg_grade'] : null;
  if ($avg !== null) { $sumAvgs += $avg; $withAvg++; }

  $computedTrack = track_from_avg($avg);
  $status = strtolower(trim((string)$r['track_status']));
  $status = $status !== '' ? str_replace('_','-',$status) : 'no-data';
  if ($status === 'no-data') $status = $computedTrack;
  if (!isset($breakdown[$status])) $status = 'no-data';
  $breakdown[$status]++;

  // Grade bucket count
  if ($avg !== null) {
    if ($avg <= 1.5) $gradeBuckets[0]++;
    elseif ($avg <= 2.0) $gradeBuckets[1]++;
    elseif ($avg <= 2.5) $gradeBuckets[2]++;
    elseif ($avg <= 3.0) $gradeBuckets[3]++;
    else $gradeBuckets[4]++;
  }

  // Average grade per program
  $program = $r['program_id'] ?? 'Unknown';
  if (!isset($programData[$program])) {
    $programData[$program] = ['sum' => 0, 'count' => 0];
  }
  if ($avg !== null) {
    $programData[$program]['sum'] += $avg;
    $programData[$program]['count']++;
  }

  // Year level trend
  $yearLevel = $r['year_level_id'] ?? 'Unknown';
  if (!isset($trendData[$yearLevel])) {
    $trendData[$yearLevel] = ['on-track'=>0,'at-risk'=>0,'off-track'=>0,'no-data'=>0];
  }
  $trendData[$yearLevel][$status]++;

  $rows[] = [
    'student_id'   => (int)$r['student_id'],
    'student_name' => $r['student_name'],
    'program'      => $r['program_id'],
    'year_level'   => $r['year_level_id'],
    'track_status' => $status,
    'avg_grade'    => $avg,
  ];
}

$overallAvg = $withAvg > 0 ? round($sumAvgs / $withAvg, 2) : null;

// Compute average per program
$programNames = [];
$programAverages = [];
foreach ($programData as $p => $d) {
  $programNames[] = "Program " . $p;
  $programAverages[] = $d['count'] > 0 ? round($d['sum'] / $d['count'], 2) : null;
}

// Prepare year-level data for trend chart
$yearLabels = array_keys($trendData);
sort($yearLabels, SORT_NUMERIC);
$onTrackData = [];
$atRiskData = [];
$offTrackData = [];
foreach ($yearLabels as $y) {
  $onTrackData[] = $trendData[$y]['on-track'];
  $atRiskData[]  = $trendData[$y]['at-risk'];
  $offTrackData[] = $trendData[$y]['off-track'];
}

// Optional: filter
$show = $_GET['filter'] ?? 'all';
if ($show === 'attention') {
  $rows = array_values(array_filter(
    $rows,
    fn($r) => in_array($r['track_status'], ['at-risk','off-track'], true)
  ));
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Guidance Dashboard</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="../design/admin/admin_layout.css">
  <style>
    body { font-family: Arial, sans-serif; margin:0 auto; background: #f4f4f4; }
    h1 { color: #111827; margin-bottom: 4px; }
    .subtle { color:#6b7280; margin: 0 0 20px; }

    /* Cards Row */
    .cards {
      display: flex;
      flex-wrap: nowrap;
      justify-content: space-between;
      gap: 15px;
      margin-bottom: 20px;
    }
    .card {
      flex: 1;
      min-width: 150px;
      background: #fff;
      border-radius: 12px;
      padding: 20px;
      box-shadow: 0 4px 12px rgba(0,0,0,.05);
    }
    .card h2 { margin: 0; font-size: 16px; color:#374151; font-weight:600; }
    .big { font-size: 28px; font-weight: 800; margin-top: 10px; color:#111827; }

    .progress-card h3 { margin: 0 0 8px; font-size: 14px; color:#374151; font-weight:600; }
    .progress-bar {
      display: flex; height: 22px; border-radius: 10px; overflow: hidden;
      background:#f3f4f6; border:1px solid #e5e7eb;
    }
    .seg { display:flex; align-items:center; justify-content:center; color:#fff; font-size:12px; padding:0 6px; }
    .on-track { background: #2684fc; }
    .at-risk  { background: #f59e0b; }
    .off-track{ background: #dc2626; }
    .no-data  { background: #6b7280; }

    /* Toolbar */
    .toolbar { display:flex; gap:10px; align-items:center; margin-bottom: 12px; }
    .toolbar a {
      display:inline-block; padding:6px 10px; border-radius:8px; border:1px solid #e5e7eb;
      background:#fff; color:#111827; text-decoration:none; font-size:13px;
    }
    .toolbar a.active { background:#2684fc; color:#fff; border-color:#2563eb; }

    /* Table */
    .table-wrap { background:#fff; border-radius:12px; box-shadow:0 4px 12px rgba(0,0,0,.05); overflow:hidden; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 12px 14px; border-bottom: 1px solid #e5e7eb; text-align: left; font-size:14px; }
    th { background: #f3f4f6; color:#374151; font-weight:600; }
    .status-pill { padding: 4px 8px; border-radius: 999px; font-size: 12px; color: #fff; display:inline-block; }
    .status-pill.on-track { background: #2684fc; }
    .status-pill.at-risk  { background: #f59e0b; }
    .status-pill.off-track{ background: #dc2626; }
    .status-pill.no-data  { background: #6b7280; }

    /* Analytics Section */
    .analytics {
      background:#fff;
      border-radius:12px;
      padding:20px;
      margin-bottom:20px;
      box-shadow:0 4px 12px rgba(0,0,0,.05);
    }

    .chart-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  gap: 20px;
  width: 100%;
  align-items: stretch;
}

.chart-row canvas {
  width: 100% !important;
  height: 300px !important;
  max-width: 100%;
}


    /* Optional: Responsive layout */
    @media (max-width: 1100px) {
      .cards, .chart-row {
        flex-wrap: wrap;
      }
      .card, .chart-row canvas {
        flex: 1 1 45%;
      }
    }

    @media (max-width: 700px) {
      .card, .chart-row canvas {
        flex: 1 1 100%;
      }
    }
  </style>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
  <?php include '../config/aside.php'; ?>
  <?php include '../config/head_section.php'; ?>

  <main style="margin:60px 0 0 280px; padding:20px;">
    <h1>Guidance Dashboard</h1>
    <p class="subtle">Overview of all active students across programs.</p>

    <!-- Cards -->
    <div class="cards">
      <div class="card"><h2>Total Students</h2><div class="big"><?= (int)$totalStudents ?></div></div>
      <div class="card"><h2>Average Grade</h2><div class="big"><?= $overallAvg !== null ? $overallAvg : '—' ?></div></div>
      <div class="card"><h2>Needs Attention</h2><div class="big"><?= $breakdown['at-risk'] + $breakdown['off-track'] ?></div></div>
      <div class="card"><h2>No Data</h2><div class="big"><?= $breakdown['no-data'] ?></div></div>
      <div class="card progress-card">
        <h3>Track Breakdown</h3>
        <div class="progress-bar" aria-label="Track status breakdown">
          <?php foreach ($breakdown as $k => $v): ?>
            <?php if ($v > 0): ?>
              <div class="seg <?= $k ?>" style="flex:<?= $v ?>"><?= $v ?></div>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Analytics -->
    <div class="analytics">
      <h2 style="font-size:18px; margin-bottom:10px; color:#374151;">Analytics</h2>
      <div class="chart-row">
        <canvas id="statusChart"></canvas>
        <canvas id="gradeChart"></canvas>
        <canvas id="trendChart"></canvas>
      </div>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
      <a href="?filter=all" class="<?= $show === 'all' ? 'active' : '' ?>">Show all</a>
      <a href="?filter=attention" class="<?= $show === 'attention' ? 'active' : '' ?>">Needs attention</a>
    </div>

    <!-- Table -->
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Student Name</th>
            <th>Program ID</th>
            <th>Year Level</th>
            <th>Average Grade</th>
            <th>Track Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="5" style="text-align:center; color:#6b7280; padding:18px;">No students found.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['student_name'] ?? '—') ?></td>
                <td><?= htmlspecialchars($r['program'] ?? '—') ?></td>
                <td><?= htmlspecialchars($r['year_level'] ?? '—') ?></td>
                <td><?= $r['avg_grade'] !== null ? number_format($r['avg_grade'], 2) : '—' ?></td>
                <td><span class="status-pill <?= $r['track_status'] ?>"><?= ucfirst(str_replace('-', ' ', $r['track_status'])) ?></span></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </main>

  <script>
    // Chart 1: Track Distribution
    new Chart(document.getElementById('statusChart'), {
      type: 'doughnut',
      data: {
        labels: ['On Track', 'At Risk', 'Off Track', 'No Data'],
        datasets: [{
          data: [
            <?= $breakdown['on-track'] ?>,
            <?= $breakdown['at-risk'] ?>,
            <?= $breakdown['off-track'] ?>,
            <?= $breakdown['no-data'] ?>
          ],
          backgroundColor: ['#2684fc','#f59e0b','#dc2626','#6b7280']
        }]
      },
      options: { plugins:{ title:{ display:true, text:'Student Track Distribution' }, legend:{ position:'bottom' }}, cutout:'65%' }
    });

    // Chart 2: Grade Distribution
    new Chart(document.getElementById('gradeChart'), {
      type: 'bar',
      data: {
        labels: ["1.0–1.5", "1.6–2.0", "2.1–2.5", "2.6–3.0", "3.1–4.0"],
        datasets: [{ label: 'Number of Students', data: <?= json_encode($gradeBuckets) ?>, backgroundColor: '#2684fc' }]
      },
      options: { plugins:{ title:{ display:true, text:'Average Grade Distribution' }}, scales:{ y:{ beginAtZero:true } } }
    });

    // Chart 3: Track Trend by Year Level
    new Chart(document.getElementById('trendChart'), {
      type: 'line',
      data: {
        labels: <?= json_encode($yearLabels) ?>,
        datasets: [
          { label: 'On Track', data: <?= json_encode($onTrackData) ?>, borderColor:'#2684fc', fill:false },
          { label: 'At Risk', data: <?= json_encode($atRiskData) ?>, borderColor:'#f59e0b', fill:false },
          { label: 'Off Track', data: <?= json_encode($offTrackData) ?>, borderColor:'#dc2626', fill:false }
        ]
      },
      options: {
        plugins: { title: { display: true, text: 'Track Status by Year Level' } },
        scales: {
          x: { title: { display: true, text: 'Year Level' } },
          y: { beginAtZero: true, title: { display: true, text: 'Students' } }
        }
      }
    });
  </script>
</body>
</html>
