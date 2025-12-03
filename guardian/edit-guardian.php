<?php
session_start();
include '../config/db.php';

if (!isset($_SESSION['user_id'])) {
  header("Location: index.php");
  exit;
}
$user_id = (int)$_SESSION['user_id'];

/* Fetch student by user_id */
// Fetch student record
$stmt = $conn->prepare("SELECT * FROM students WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
  echo "<p style='color:red; text-align:center;'>Student not found.</p>";
  exit;
}

// Use students.id as the foreign key for guardians
$student_pk = (int)$student['id'];

// Fetch guardian record linked to this student
$stmt = $conn->prepare("SELECT * FROM guardians WHERE student_id = ?");
$stmt->bind_param("i", $student_pk);
$stmt->execute();
$guardian = $stmt->get_result()->fetch_assoc();
$stmt->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Edit Guardian Info</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body, html { margin:0; padding:0; background:#f4f6fb; font-family: 'Segoe UI', sans-serif; }
    header { background:#fff; padding:12px 16px; border-bottom:1px solid #ddd; display:flex; height:70px; justify-content:space-between; align-items:center; position:sticky; top:0; z-index:100; box-shadow:0 2px 8px rgba(0,0,0,.1); }
    .back-button { color:#1a73e8; text-decoration:none; font-weight:bold; font-size:14px; }
    .header-title { font-size:16px; font-weight:bold; margin:0; text-align:center; flex-grow:1; }
    .page-bg { display:flex; justify-content:center; padding:18px 16px; }
    .profile-card { background:#fff; width:100%; max-width:420px; border-radius:16px; padding:16px; box-shadow:0 4px 12px rgba(0,0,0,.1); }
    .section-head { text-align:center; font-size:16px; font-weight:bold; color:#2684fc; margin-bottom:16px; }
    hr.highlight { border:0; border-top:2px solid #2684fc; margin:14px 0; }
    .section-title { font-weight:bold; font-size:14px; text-align:left; margin:20px 0 8px; color:#1a1a1a; border-bottom:1px solid #ddd; padding-bottom:5px; }
    .form-row { display:flex; gap:8px; }
    .form-row > div { flex:1; }
    .form-group { margin-bottom:12px; }
    label { display:block; font-size:12px; color:#333; margin-bottom:6px; }
    input[type="text"], input[type="email"], select {
      width:100%; padding:10px; border:1px solid #ccc; border-radius:10px; font-size:14px; background:#fff;
    }
    .button-group { display:flex; justify-content:space-between; margin-top:20px; }
    .btn { padding:10px 20px; border-radius:10px; font-weight:600; font-size:14px; text-decoration:none; border:none; cursor:pointer; transition:.2s; text-align:center; }
    .btn-light { background:#e2e6ea; color:#333; } .btn-light:hover{ background:#d6d8db; }
    .btn-primary { background:#1a73e8; color:#fff; } .btn-primary:hover{ background:#1669d6; }
  </style>
</head>
<body>
<header>
  <a href="guardian-info.php" class="back-button">&#8592;</a>
  <div class="header-title">Edit Guardian</div>
</header>

<div class="page-bg">
  <div class="profile-card">
    <div class="section-head">Guardian Info</div>
    <hr class="highlight" />

    <form action="update-guardian.php" method="POST">
      <input type="hidden" name="student_id" value="<?= htmlspecialchars($student_pk) ?>">

      <div class="section-title">Guardian Details</div>

      <div class="form-row">
        <div class="form-group">
          <label for="first_name">First name</label>
          <input type="text" id="first_name" name="first_name" placeholder="First name"
                 value="<?= htmlspecialchars($guardian['first_name'] ?? '') ?>" required>
        </div>
        <div class="form-group">
          <label for="middle_name">Middle name</label>
          <input type="text" id="middle_name" name="middle_name" placeholder="Middle name"
                 value="<?= htmlspecialchars($guardian['middle_name'] ?? '') ?>">
        </div>
      </div>

      <div class="form-group">
        <label for="last_name">Last name</label>
        <input type="text" id="last_name" name="last_name" placeholder="Last name"
               value="<?= htmlspecialchars($guardian['last_name'] ?? '') ?>" required>
      </div>

      <div class="form-group">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" placeholder="Email address"
               value="<?= htmlspecialchars($guardian['email'] ?? '') ?>">
      </div>

      <div class="form-group">
        <label for="contact_number">Contact number</label>
        <input type="text" id="contact_number" name="contact_number" placeholder="Contact number"
               value="<?= htmlspecialchars($guardian['contact_number'] ?? '') ?>">
      </div>

      <div class="form-group">
        <label for="address">Address</label>
        <input type="text" id="address" name="address" placeholder="Address"
               value="<?= htmlspecialchars($guardian['address'] ?? '') ?>">
      </div>

      <div class="form-group">
        <label for="relationship_select">Relationship</label>
        <select name="relationship_select" id="relationship_select" required>
          <option value="">Select Relationship</option>
          <?php
            $opts = ["Mother","Father","Guardian","Aunt","Uncle","Grandparent","Sibling","Other"];
            $savedRel = $guardian['relationship'] ?? '';
            foreach ($opts as $opt) {
              $sel = ($opt === $savedRel) ? 'selected' : '';
              echo "<option value=\"{$opt}\" {$sel}>{$opt}</option>";
            }
          ?>
        </select>
      </div>

      <div class="form-group" id="custom-relationship-group" style="display:none;">
        <label for="custom_relationship">Specify Relationship</label>
        <input type="text" name="relationship" id="custom_relationship"
               value="<?= htmlspecialchars($guardian['relationship'] ?? '') ?>"
               placeholder="e.g., Cousin, Stepfather">
      </div>

      <div class="button-group">
        <a href="guardian_info.php" class="btn btn-light">Discard</a>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<?php include '../config/nav_bar.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const select   = document.getElementById('relationship_select');
  const group    = document.getElementById('custom-relationship-group');
  const custom   = document.getElementById('custom_relationship');

  const preset = ["Mother","Father","Guardian","Aunt","Uncle","Grandparent","Sibling"];
  const saved  = <?= json_encode($guardian['relationship'] ?? '') ?>;

  function syncUI(val){
    if (val === 'Other') {
      group.style.display = 'block';
      custom.required = true;
      if (!custom.value) custom.value = saved && !preset.includes(saved) ? saved : '';
    } else {
      group.style.display = 'none';
      custom.required = false;
      custom.value = val; // so POST always has `relationship` filled
    }
  }

  // initial state
  if (!preset.includes(saved) && saved) {
    select.value = 'Other';
  }
  syncUI(select.value || '');

  // changes
  select.addEventListener('change', () => syncUI(select.value));
});
</script>
</body>
</html>
