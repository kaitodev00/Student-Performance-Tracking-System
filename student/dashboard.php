<?php 
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
include '../config/db.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];

/** Fetch student **/
$stmt = $conn->prepare("SELECT * FROM students WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    // Graceful fallback if no record found
    $student = [
        'student_id' => 0,
        'student_name' => 'Student',
        'year_level' => '',
        'section' => '',
        'specialization' => '',
        'profile_picture' => null
    ];
}

/** Aggregated grades **/
$gStmt = $conn->prepare("
  SELECT 
    AVG(NULLIF(midterm, 0))      AS avg_midterm,
    AVG(NULLIF(finalgrade, 0))   AS avg_gwa,
    SUM(remarks='DRP')           AS dropped_ct,
    COUNT(*)                     AS total_rows
  FROM tblstudentgrade
  WHERE student_id = ?
");
$gStmt->bind_param("i", $student['student_id']);
$gStmt->execute();
$gAgg = $gStmt->get_result()->fetch_assoc();
$gStmt->close();

$avgMid   = isset($gAgg['avg_midterm']) ? round((float)$gAgg['avg_midterm'], 2) : 0;
$avgFinal = isset($gAgg['avg_final'])   ? round((float)$gAgg['avg_final'], 2)   : 0;
$avgGWA   = isset($gAgg['avg_gwa'])     ? round((float)$gAgg['avg_gwa'], 2)     : 0;
$passCut  = 3.00;

$conn->close();

/** Clean full name **/
$full_name = trim(implode(' ', array_filter([
    $student['student_name'] !== 'N/A' ? $student['student_name'] : ''
])));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Student Home</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.5/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://unpkg.com/swiper/swiper-bundle.min.css" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    :root{
      --brand:#2684fc;
      --ink:#1e1e1e;
      --muted:#6b7280;
      --card:#ffffff;
      --bg:#f4f6fb;
      --radius:12px;
    }
    body{
      margin:0;
      padding:0;
      font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
      background:var(--bg);
      overflow-x:hidden; /* allow vertical scroll, hide horizontal */
    }
    .page{
      max-width: 1000px;
      margin: 20px auto 90px; /* space from fixed header/footer */
      padding: 0 16px;
    }

    /* Info / hero */
    .info{
      display:flex;align-items:center;gap:12px;
      background:var(--brand);
      color:#fff;
      border-radius:var(--radius);
      padding:12px 14px;
      box-shadow:0 6px 18px rgba(0,0,0,.12);
    }
    .info h5{margin:0;font-weight:700;font-size:16px;}
    .info p{margin:2px 0 0;font-size:13px;opacity:.95}
    .profile-pic{
      width:58px;height:58px;border-radius:50%;object-fit:cover;background:#cbd5e1;flex:0 0 auto
    }

    /* Quick stats chips */
    .stats{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:12px 0;}
    .chip{
      background:var(--card);border-radius:var(--radius);padding:12px;
      box-shadow:0 4px 10px rgba(0,0,0,.08);
    }
    .chip small{color:var(--muted);display:block;margin-bottom:2px}
    .chip b{font-size:18px;}

    /* Swiper */
    .swiper-container{
      width:100%;
      border-radius:var(--radius);
      box-shadow:0 4px 10px rgba(0,0,0,.10);
      overflow:hidden;
      background:white;
      aspect-ratio: 16 / 6;         /* responsive height */
      max-height: 280px;            /* cap height on large screens */
    }
    .swiper-slide{
      display:flex;align-items:center;justify-content:center;
    }
    .swiper-slide h1{
      font-size:22px;text-align:center;color:var(--ink);font-weight:700;margin:0 10px;
    }
    .pagination-dots{
      display:flex;justify-content:center;gap:8px;margin-top:10px
    }
    .pagination-dots .dot{
      width:10px;height:10px;border-radius:50%;background:var(--brand);opacity:.45;cursor:pointer
    }
    .pagination-dots .dot.active{opacity:1;background:#1e73be}

    /* Chart card */
    .chart-card{
      margin-top:16px;background:var(--card);border-radius:var(--radius);
      padding:16px;box-shadow:0 4px 10px rgba(0,0,0,.08);
    }
    .chart-card h5{margin:0 0 6px}
    .chart-foot{color:var(--muted);text-align:center;margin-top:6px}

    @media (max-width: 768px){
      .page{margin: 30px 10px 10px;}
      .stats{grid-template-columns: 1fr 1fr;}
    }
    @media (max-width: 480px){
      .info{padding:10px}
      .profile-pic{width:52px;height:52px}
      .swiper-slide h1{font-size:18px}
      .stats{grid-template-columns: 1fr}
    }
  </style>
</head>
<body>
  <header>
    <?php include '../config/header.php'; ?>
  </header>

  <main class="page">
    <!-- Hero -->
    <div class="info">
      <img src="uploads/<?= htmlspecialchars($student['profile_picture'] ?? 'default_profile.jpg') ?>"
           onerror="this.onerror=null; this.src='../image/default_profile.jpg';"
           alt="Profile Picture" class="profile-pic">
      <div>
        <h5><?= htmlspecialchars($full_name) ?></h5>
      </div>
    </div>

    <!-- Quick stats -->
    <div class="stats">
      <div class="chip">
        <small>GWA</small>
        <b><?= number_format($avgGWA,2) ?></b>
      </div>
      <div class="chip">
        <small>Avg Midterm</small>
        <b><?= number_format($avgMid,2) ?></b>
      </div>
      <div class="chip">
        <small>Avg Final</small>
        <b><?= number_format($avgFinal,2) ?></b>
      </div>
    </div>

    <!-- Swiper -->
    <div class="swiper-container">
      <div class="swiper-wrapper">
        <div class="swiper-slide"><h1>Ignite Potential,<br/>Achieve Excellence.</h1></div>
        <div class="swiper-slide"><h1>Empowering Minds,<br/>Elevating Futures.</h1></div>
        <div class="swiper-slide"><h1>Building Tomorrow,<br/>One Lesson at a Time.</h1></div>
      </div>
    </div>
    <div class="pagination-dots">
      <div class="dot" data-index="0"></div>
      <div class="dot" data-index="1"></div>
      <div class="dot" data-index="2"></div>
    </div>

    <!-- Performance Summary -->
    <div class="chart-card">
      <div class="d-flex justify-content-between align-items-center">
        <h5>Performance Summary</h5>
        <small class="text-muted">Scale: 1.0 → 5.0</small>
      </div>
      <div style="height:220px"><canvas id="performanceChart"></canvas></div>
      <div class="chart-foot">
        Avg Midterm: <b><?= $avgMid ?></b> •
        Avg Final: <b><?= $avgFinal ?></b> •
        GWA: <b><?= $avgGWA ?></b>
      </div>
    </div>
  </main>

  <footer>
    <?php include '../config/nav_bar.php'; ?>
  </footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.5/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://unpkg.com/swiper/swiper-bundle.min.js"></script>

  <script>
    // Swiper + dots
    const swiper = new Swiper('.swiper-container', { loop:true, autoplay:{delay:5000} });
    const dots = document.querySelectorAll('.pagination-dots .dot');
    function setDot(idx){ dots.forEach(d=>d.classList.remove('active')); dots[idx]?.classList.add('active'); }
    dots.forEach(dot => dot.addEventListener('click', () => swiper.slideToLoop(+dot.dataset.index)));
    swiper.on('slideChange', () => setDot(swiper.realIndex));
    setDot(swiper.realIndex || 0);
  </script>

  <script>
  (function(){
    const ctx = document.getElementById('performanceChart');
    if(!ctx) return;

    const avgMid   = <?= json_encode($avgMid) ?>;
    const avgFinal = <?= json_encode($avgFinal) ?>;
    const avgGWA   = <?= json_encode($avgGWA) ?>;
    const passCut  = <?= json_encode($passCut) ?>;

    const passingLine = {
      id: 'passingLine',
      afterDraw(chart){
        const {ctx, chartArea:{left,right}, scales:{y}} = chart;
        const yPos = y.getPixelForValue(passCut);
        ctx.save();
        ctx.setLineDash([6,4]); ctx.lineWidth=1; ctx.strokeStyle='#888';
        ctx.beginPath(); ctx.moveTo(left, yPos); ctx.lineTo(right, yPos); ctx.stroke();
        ctx.setLineDash([]);
        ctx.fillStyle='#666'; ctx.font='12px system-ui,-apple-system,Segoe UI';
        ctx.fillText(`Passing (${passCut.toFixed(2)})`, right-110, yPos-6);
        ctx.restore();
      }
    };

    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: ['Avg Midterm', 'Avg Final', 'GWA'],
        datasets: [{ label: 'Grade (1 best → 5 drop)', data: [avgMid, avgFinal, avgGWA], borderWidth: 1 }]
      },
      options: {
        responsive:true, maintainAspectRatio:false,
        plugins:{ legend:{display:false},
          tooltip:{ callbacks:{ label: (c)=> {
            const v=c.parsed.y; let r='Passing';
            if (v>=5) r='Drop'; else if (v>passCut) r='Below passing'; else if (v<=1.25) r='Excellent';
            return ` ${v.toFixed(2)} • ${r}`;
          }}} },
        scales:{
          y:{ min:1, max:5, reverse:true, ticks:{stepSize:0.5} },
          x:{ grid:{display:false} }
        }
      },
      plugins:[passingLine]
    });
  })();
  </script>

  <script>
    // mark 'home' active in bottom nav
    localStorage.setItem('activeNav', 'home');
  </script>
</body>
</html>
