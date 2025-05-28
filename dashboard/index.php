<?php
/*─────────────────────────────────────────────────────────────*/
/*  dashboard.php – Report Management (Responsive Bootstrap)   */
/*─────────────────────────────────────────────────────────────*/
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config.php';   // $conn + constants

// 0) require login
if (empty($_SESSION['user_id'])) {
    header('Location: ../dashboard/login.php');
    exit;
}

// 1) error display in dev
ini_set('display_errors', '1');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// 2) read filters
$status = $_GET['status'] ?? 'all'; 
$valid  = ['all','กำลังดำเนินการ','เสร็จสิ้น', 'ยกเลิก'];
if (!in_array($status, $valid, true)) {
    $status = 'all';
}

// 3) role-based & dept-based filter
$role = strtoupper($_SESSION['role'] ?? '');
$canFilterDept = in_array($role, ['ADMIN','EXEC'], true);

// build WHERE parts
$whereParts = [];
// if MIS or BUILDING, force their own dept
if ($role === 'MIS') {
    $whereParts[] = "department = 'สารสนเทศ (MIS)'";
} elseif ($role === 'BUILDING') {
    $whereParts[] = "department = 'ฝ่ายอาคาร'";
}
// status filter
if ($status !== 'all') {
    $whereParts[] = "status = ?";
}
// optional dept filter for ADMIN/EXEC
$dept = '';
if ($canFilterDept && !empty($_GET['department'])) {
    $dept = $_GET['department'];
    $whereParts[] = "department = ?";
}

// 4) prepare query
$sql = "SELECT 
            id,
            message,
            department,
            location_text,
            image_url,
            updated_image_url,
            status,
            deleted_at,
            created_at
        FROM reports";
if (!empty($whereParts)) {
    $sql .= ' WHERE ' . implode(' AND ', $whereParts);
}
$sql .= ' ORDER BY created_at DESC';

$stmt = $conn->prepare($sql);
if ($status !== 'all' && $canFilterDept && $dept !== '') {
    // bind status + dept
    $stmt->bind_param('ss', $status, $dept);
} elseif ($status !== 'all') {
    $stmt->bind_param('s', $status);
} elseif ($canFilterDept && $dept !== '') {
    $stmt->bind_param('s', $dept);
}
$stmt->execute();
$reports = $stmt->get_result();

// 5) fetch departments for dropdown
$departments = [];
if ($canFilterDept) {
    $res = $conn->query("SELECT DISTINCT department FROM reports");
    while ($row = $res->fetch_assoc()) {
        $departments[] = $row['department'];
    }
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard – รายการแจ้งปัญหา</title>

  <!-- Bootstrap 5.3 + Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

  <style>
    .table img      { max-width:60px; height:auto; }
    .status-รอรับเรื่อง     { background:#ffe69c; color:#a57f00; }
    .status-กำลังดำเนินการ { background:#c6f6d5; color:#198754; }
    .status-เสร็จสิ้น       { background:#bcd0ff; color:#0d6efd; }
    .status-ยกเลิก          { background:#e0e0e0; color:#6c757d; }
    .status-pill {
      display: inline-flex;              /* ใช้ flex เพื่อจัดกึ่งกลางทั้งแกน x/y */
      align-items: center;
      justify-content: center;
      width: 7rem;                       /* กำหนดความกว้างเท่ากันทุกสถานะ */
      white-space: nowrap;               /* ห้ามตัดบรรทัด */
      padding: 0.25rem 0.5rem;           /* ปรับ padding ให้พอดี */
      border-radius: 50px;
      font-size: 0.875rem;
      font-weight: 600;
      box-sizing: border-box;            /* รวม padding ไว้ใน width */
    }

    /* ตัวอย่างสีตามสถานะ */
    .status-pill.status-เสร็จสิ้น {
      background-color: #28a745;
      color: #fff;
    }
    .status-pill.status-กำลังดำเนินการ {
      background-color: #ffc107;
      color: #212529;
    }
  </style>
</head>
<body class="bg-light d-flex flex-column min-vh-100">

  <?php require('navbar.php'); ?>

  <div class="container my-4 flex-grow-1">
    <h1 class="h4 mb-4">รายการแจ้งปัญหา</h1>

    <?php if ($reports->num_rows === 0): ?>
      <div class="alert alert-info">ยังไม่มีรายการ</div>
    <?php else: ?>
      <!-- Status tabs -->
      <ul class="nav nav-tabs mb-3">
        <?php 
          $tabs = ['all'=>'ทั้งหมด','กำลังดำเนินการ'=>'กำลังดำเนินการ','เสร็จสิ้น'=>'เสร็จสิ้น','ยกเลิก'=>'ยกเลิก'];
          foreach ($tabs as $key => $label):
            $qs = ['status'=>$key];
            if ($canFilterDept && $dept) {
              $qs['department'] = $dept;
            }
            $url = '?' . http_build_query($qs);
        ?>
        <li class="nav-item">
          <a class="nav-link <?= $status === $key ? 'active' : '' ?>"
             href="<?= $url ?>">
            <?= $label ?>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>

      <!-- Dept filter -->
      <?php if ($canFilterDept): ?>
      <form method="get" class="row g-2 mb-4 align-items-center">
        <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
        <div class="col-auto">
          <select name="department" class="form-select">
            <option value="">-- ทุกฝ่าย --</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?= htmlspecialchars($d) ?>"
                <?= $d === $dept ? 'selected' : '' ?>>
                <?= htmlspecialchars($d) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto">
          <button class="btn btn-outline-primary">กรอง</button>
        </div>
      </form>
      <?php endif; ?>

      <!-- Reports table -->
      <div class="table-responsive shadow-sm rounded-3 bg-white">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>เมื่อ</th>
              <th>ปัญหา</th>
              <th>ฝ่าย</th>
              <th>สถานที่</th>
              <th>รูปปัญหา</th>
              <th>รูปการแก้ปัญหา</th>
              <th>สถานะ</th>
              <th class="text-center">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php while ($r = $reports->fetch_assoc()): ?>
            <tr data-id="<?= $r['id'] ?>">
              <td><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
              <td class="text-wrap" style="min-width:180px;"><?= htmlspecialchars($r['message']) ?></td>
              <td><?= htmlspecialchars($r['department']) ?></td>
              <td><?= htmlspecialchars($r['location_text']) ?></td>
              <td>
                <?php if ($r['image_url']): ?>
                  <a href="#" data-src="<?= htmlspecialchars($r['image_url']) ?>" class="image-preview">
                    <img src="<?= htmlspecialchars($r['image_url']) ?>" class="img-thumbnail">
                  </a>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!empty($r['updated_image_url'])): ?>
                  <a href="#" data-src="<?= htmlspecialchars($r['updated_image_url']) ?>" class="image-preview">
                    <img src="<?= htmlspecialchars($r['updated_image_url']) ?>" class="img-thumbnail">
                  </a>
                <?php endif; ?>
              </td>
              <td>
                <span class="status-pill status-<?= htmlspecialchars($r['status']) ?>">
                  <?= htmlspecialchars($r['status']) ?>
                </span>
              </td>
              <td class="text-center">
              <?php if (!empty($r['deleted_at'])): ?>
                <em class="text-muted small">deleted</em>
              <?php else: ?>
                <?php if ($r['status'] === 'กำลังดำเนินการ'): ?>
                  <button type="button" class="btn btn-sm btn-primary update" title="แก้ไข">
                    <i class="bi bi-pencil-square"></i>
                  </button>
                  <?php elseif ($r['status'] === 'เสร็จสิ้น'): ?>
                    <span class="badge bg-success">Completed</span>
                  <?php else: ?>
                    <div class="d-flex justify-content-center align-items-center gap-2">
                      <button type="button" class="btn btn-sm btn-primary update" title="แก้ไข">
                        <i class="bi bi-pencil-square"></i>
                      </button>
                      <button type="button" class="btn btn-sm btn-danger remove" title="ลบงาน">
                        <i class="bi bi-trash"></i>
                      </button>
                    </div>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- footer -->
  <footer class="bg-dark text-white-50 text-center py-3 small mt-auto">
    &copy; <?= date('Y') ?> MIS Dashboard
  </footer>

  <!-- Remove Modal -->
  <div class="modal fade" id="removeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <form class="modal-content" id="removeForm">
        <div class="modal-header">
          <h5 class="modal-title">ยืนยันการลบงาน</h5>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="rid">
          <div class="mb-3">
            <label class="form-label">เหตุผล (บันทึกในประวัติ)</label>
            <textarea name="reason" class="form-control" rows="3" required></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-danger">ลบ</button>
          <button type="button" data-bs-dismiss="modal" class="btn btn-secondary">ยกเลิก</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Update Status Modal -->
  <div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <form class="modal-content" id="statusForm" enctype="multipart/form-data">
        <div class="modal-header">
          <h5 class="modal-title">ปรับสถานะงาน</h5>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="sid">
          <label class="form-label">เลือกสถานะใหม่</label>
          <select name="status" id="newStatus" class="form-select" required>
            <option value="" disabled hidden>-- เลือก --</option>
            <option value="รอรับเรื่อง">รอรับเรื่อง</option>
            <option value="กำลังดำเนินการ">กำลังดำเนินการ</option>
            <option value="เสร็จสิ้น">เสร็จสิ้น</option>
            <option value="ยกเลิก">ยกเลิก</option>
          </select>
          <div id="doneWrap" class="mt-3 d-none">
            <label class="form-label">แนบรูปหลักฐานเสร็จสิ้นงาน (จำเป็น)</label>
            <input type="file"
                   id="doneImage"
                   name="final_image"
                   accept="image/*"
                   class="form-control">
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">บันทึก</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Image Preview Modal -->
  <div class="modal fade" id="imageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 80vw; margin: 0 auto;">
      <div class="modal-content border-0 bg-transparent" data-bs-dismiss="modal">
        <div class="modal-body d-flex justify-content-center align-items-center p-3">
          <div style="position: relative; display: inline-block;">
            <img
              src=""
              id="modalImage"
              class="img-fluid"
              style="max-width: 100%; max-height: 80vh; object-fit: contain;"
            >
            <button
              type="button"
              data-bs-dismiss="modal"
              aria-label="Close"
              style="
                position: absolute;
                top: 0.5rem;
                right: 0.5rem;
                background: rgba(255,255,255,0.9);
                border: none;
                font-size: 1.8rem;
                line-height: 1;
                width: 2.4rem;
                height: 2.4rem;
                border-radius: 50%;
                padding: 0;
              "
            >&times;</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Scripts -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const API = 'api/';  // now correctly points to dashboard/api/

    // helper to show a Bootstrap modal
    function showModal(id) {
      new bootstrap.Modal(document.getElementById(id)).show();
    }

    // REMOVE
    document.querySelectorAll('.remove').forEach(btn => {
      btn.addEventListener('click', e => {
        const id = e.currentTarget.closest('tr').dataset.id;
        document.getElementById('rid').value = id;
        document.querySelector('#removeForm textarea').value = '';
        showModal('removeModal');
      });
    });

    document.getElementById('removeForm').addEventListener('submit', async e => {
      e.preventDefault();
      const ok = await fetch(API + 'delete_report.php', {
        method: 'POST',
        body: new FormData(e.target)
      }).then(r => r.json()).catch(() => false);
      ok ? location.reload() : alert('ลบไม่สำเร็จ');
    });

    // UPDATE STATUS
    document.querySelectorAll('.update').forEach(btn => {
      btn.addEventListener('click', e => {
        const id = e.currentTarget.closest('tr').dataset.id;
        document.getElementById('sid').value = id;
        document.getElementById('newStatus').value = '';
        toggleDone(false);
        showModal('statusModal');
      });
    });

    const newStatus = document.getElementById('newStatus');
    const doneWrap  = document.getElementById('doneWrap');
    const doneImage = document.getElementById('doneImage');

    function toggleDone(show) {
      doneWrap.classList.toggle('d-none', !show);
      doneImage.required = show;
      if (!show) doneImage.value = '';
    }

    newStatus.addEventListener('change', e => {
      toggleDone(e.target.value === 'เสร็จสิ้น');
    });

    document.getElementById('statusForm').addEventListener('submit', async e => {
      e.preventDefault();
      const ok = await fetch(API + 'update_status.php', {
        method: 'POST',
        body: new FormData(e.target)
      }).then(r => r.json()).catch(() => false);
      ok ? location.reload() : alert('อัปเดตไม่สำเร็จ');
    });

    // IMAGE PREVIEW
    const modalImage = document.getElementById('modalImage');
    const bsModal = new bootstrap.Modal(document.getElementById('imageModal'));

    document.querySelectorAll('.image-preview').forEach(link => {
      link.addEventListener('click', e => {
        e.preventDefault();
        modalImage.src = link.dataset.src;
        bsModal.show();
      });
    });
  </script>
</body>
</html>