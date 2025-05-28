<?php

session_start();

// แสดงข้อผิดพลาดทั้งหมดเพื่อดีบัก
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';  // เชื่อมต่อฐานข้อมูลและตัวแปร $conn


if (! isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

// 2. ดึง role จาก DB
$stmt = $conn->prepare('SELECT role FROM users WHERE id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$stmt->bind_result($role);
$stmt->fetch();
$stmt->close();

// 3. Normalize ให้เป็นตัวพิมพ์ใหญ่ แล้วเช็คในลิสต์
$roleNorm = strtoupper(trim((string)$role));
if (! in_array($roleNorm, ['ADMIN','EXEC'], true)) {
    header('Location: index.php');
    exit;
}

// ─── รับพารามิเตอร์ช่วงเดือนและแผนก ─────────────────────────
$startInput = $_GET['start'] ?? date('Y-m', strtotime('-1 year'));
$endInput   = $_GET['end']   ?? date('Y-m');
$deptInput  = $_GET['dept']  ?? '';

if (!preg_match('/^\d{4}-\d{2}$/', $startInput)) {
    $startInput = date('Y-m', strtotime('-1 year'));
}
if (!preg_match('/^\d{4}-\d{2}$/', $endInput)) {
    $endInput = date('Y-m');
}

// แปลงเป็นวันที่เต็ม
$startDate = $startInput . '-01';
$endDate   = date('Y-m-t', strtotime($endInput . '-01'));

// ─── ดึงรายชื่อแผนกทั้งหมด ─────────────────────────────────
$deptList = [];
$result   = $conn->query(
    "SELECT DISTINCT department
       FROM reports
      WHERE deleted_at IS NULL
   ORDER BY department"
);

while ($row = $result->fetch_assoc()) {
    $deptList[] = $row['department'];
}
$result->free();

if ($deptInput !== '' && !in_array($deptInput, $deptList, true)) {
    $deptInput = '';
}

$whereDept = $deptInput !== ''
    ? ' AND department = ?'
    : '';

// ─── QUERY สรุปตามแผนก ────────────────────────────────────────
$sqlDept = "
    SELECT department, COUNT(*) AS total
      FROM reports
     WHERE created_at BETWEEN ? AND ?
       AND deleted_at IS NULL
      {$whereDept}
     GROUP BY department
";

$stmt = $conn->prepare($sqlDept);

if ($deptInput !== '') {
    $stmt->bind_param('sss', $startDate, $endDate, $deptInput);
} else {
    $stmt->bind_param('ss', $startDate, $endDate);
}

$stmt->execute();
$data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ─── QUERY สรุปตามสถานะ ───────────────────────────────────────
$sqlStatus = "
    SELECT status, COUNT(*) AS cnt
      FROM reports
     WHERE created_at BETWEEN ? AND ?
       AND deleted_at IS NULL
      {$whereDept}
     GROUP BY status
";

$stmt2 = $conn->prepare($sqlStatus);

if ($deptInput !== '') {
    $stmt2->bind_param('sss', $startDate, $endDate, $deptInput);
} else {
    $stmt2->bind_param('ss', $startDate, $endDate);
}

$stmt2->execute();
$statusRows = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt2->close();

// ─── จัดเก็บค่าตามสถานะ ────────────────────────────────────
$received   = 0; // รอรับเรื่อง
$inProgress = 0; // กำลังดำเนินการ
$completed  = 0; // เสร็จสิ้น

foreach ($statusRows as $row) {
    switch ($row['status']) {
        case 'รอรับเรื่อง':
            $received = (int) $row['cnt'];
            break;
        case 'กำลังดำเนินการ':
            $inProgress = (int) $row['cnt'];
            break;
        case 'เสร็จสิ้น':
            $completed = (int) $row['cnt'];
            break;
    }
}

$totalJobs = $received + $inProgress + $completed;

// ─── เตรียมข้อมูลกราฟ ───────────────────────────────────────
$labels = [];
$values = [];

foreach ($data as $row) {
    $labels[] = $row['department'];
    $values[] = $totalJobs > 0
        ? round($row['total'] * 100 / $totalJobs, 1)
        : 0;
}

// ─── Export CSV ──────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header(
        'Content-Disposition: attachment; filename="report_'
        . $startInput . '_to_' . $endInput . '.csv"'
    );

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Department', 'Jobs', 'Percentage']);

    foreach ($data as $row) {
        $pct = $totalJobs > 0
            ? round($row['total'] * 100 / $totalJobs, 1)
            : 0;
        fputcsv($out, [
            $row['department'],
            $row['total'],
            $pct . '%'
        ]);
    }

    fclose($out);
    exit;
}

ob_end_flush();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>
        รายงาน <?= htmlspecialchars($startInput) ?>
        ถึง <?= htmlspecialchars($endInput) ?>
        <?= $deptInput ? '– ' . htmlspecialchars($deptInput) : '' ?>
    </title>
    <link
      href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
      rel="stylesheet"
    >
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f8f9fa;
        }
        .card {
            border-radius: 0.75rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        canvas {
            max-height: 300px;
        }
    </style>
</head>
<body>
<?php require('navbar.php'); ?>

<div class="container py-4">
    <h2 class="mb-4">
        รายงาน <?= htmlspecialchars($startInput) ?>
        ถึง <?= htmlspecialchars($endInput) ?>
        <?= $deptInput ? '– ' . htmlspecialchars($deptInput) : '' ?>
    </h2>

    <!-- Filter & Export -->
    <form method="get" class="row g-3 align-items-end mb-4">
        <div class="col-auto">
            <label class="form-label">จากเดือน</label>
            <input
              type="month"
              name="start"
              class="form-control"
              value="<?= htmlspecialchars($startInput) ?>"
            >
        </div>
        <div class="col-auto">
            <label class="form-label">ถึงเดือน</label>
            <input
              type="month"
              name="end"
              class="form-control"
              value="<?= htmlspecialchars($endInput) ?>"
            >
        </div>
        <div class="col-auto">
            <label class="form-label">แผนก</label>
            <select name="dept" class="form-select">
                <option value="">ทุกแผนก</option>
                <?php foreach ($deptList as $d): ?>
                    <option
                      value="<?= htmlspecialchars($d) ?>"
                      <?= $d === $deptInput ? 'selected' : '' ?>
                    >
                        <?= htmlspecialchars($d) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-primary">
                กรอง
            </button>
            <a
              href="?start=<?= urlencode($startInput) ?>&end=<?= urlencode($endInput) ?>
                     &dept=<?= urlencode($deptInput) ?>&export=1"
              class="btn btn-outline-success"
            >
                Export CSV
            </a>
        </div>
    </form>

    <!-- Status Summary Cards -->
    <div class="row mb-4">
        <?php foreach ([
            ['title'=>'รับเรื่อง','count'=>$received,'bg'=>''],
            ['title'=>'กำลังดำเนินการ','count'=>$inProgress,'bg'=>''],
            ['title'=>'เสร็จสิ้น','count'=>$completed,'bg'=>''],
            ['title'=>'รวมทั้งหมด','count'=>$totalJobs,'bg'=>'bg-light']
        ] as $card): ?>
            <div class="col-sm-6 col-md-3 mb-2">
                <div class="card text-center py-3 <?= $card['bg'] ?>">
                    <div class="card-body">
                        <h5 class="card-title"><?= $card['title'] ?></h5>
                        <p class="display-6 mb-0"><?= $card['count'] ?></p>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row">
        <!-- Pie Chart -->
        <div class="col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-header">
                    สัดส่วนงานแยกแผนก (%)
                </div>
                <div
                  class="card-body d-flex justify-content-center align-items-center"
                  style="min-height:300px;"
                >
                    <?php if ($totalJobs > 0): ?>
                        <canvas id="departmentChart"></canvas>
                    <?php else: ?>
                        <p class="text-muted m-0">ไม่มีข้อมูลในช่วงนี้</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Data Table -->
        <div class="col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-header">ตารางสรุปงานแยกแผนก</div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>แผนก</th>
                                <th>จำนวนงาน</th>
                                <th>ร้อยละ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($data)): ?>
                                <tr>
                                    <td colspan="3" class="text-center text-muted">
                                        ไม่มีข้อมูล
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($data as $row): ?>
                                    <?php
                                    $pct = $totalJobs > 0
                                        ? round($row['total'] * 100 / $totalJobs, 1)
                                        : 0;
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['department']) ?></td>
                                        <td><?= intval($row['total']) ?></td>
                                        <td><?= $pct ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('departmentChart')?.getContext('2d');
    if (!ctx) return;

    new Chart(ctx, {
        type: 'pie',
        data: {
            labels: <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>,
            datasets: [{
                data: <?= json_encode($values) ?>,
                hoverOffset: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });
});
</script>
</body>
</html>