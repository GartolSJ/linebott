<?php
declare(strict_types=1);
session_start();

// แสดงข้อผิดพลาดทั้งหมดเพื่อดีบัก
ini_set('display_errors', '1');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . '/../config.php';

// ตรวจสอบว่าเข้าระบบแล้วหรือไม่
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$userId = (int) $_SESSION['user_id'];

// ดึง username และ role จากฐานข้อมูล
$stmt = $conn->prepare('SELECT username, name, role FROM users WHERE id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$stmt->bind_result($userName, $name, $role);
$stmt->fetch();
$stmt->close();

// ถ้าไม่ใช่ ADMIN ให้กลับไปหน้า index
if ($role !== 'ADMIN') {
    header('Location: index.php');
    exit;
}

// URL ของ Google Chat webhook
define('GOOGLE_NOTIFY_URL',
    'https://chat.googleapis.com/v1/spaces/AAQAFyUpo3I/messages'
  . '?key=AIzaSyDdI0hCZtE6vySjMm-WEfRq3CPzqKqqsHI'
  . '&token=Hdd7S6QQvcI1pvDfUiy-ocrlwhtpJCrgB7n6Dd1y0H0'
);

// ฟังก์ชันส่งข้อความไป Google Chat
function notifyGoogleChat(string $action, string $word, string $byName, string $datetime): void {
    $verb    = $action === 'add' ? '➕ เพิ่ม' : '🗑️ ลบ';
    $message = "{$verb}คำไม่สุภาพ\n"
             . "• คำ: {$word}\n"
             . "• โดย: {$byName}\n"
             . "• เวลา: {$datetime}";
    $payload = json_encode(['text' => $message], JSON_UNESCAPED_UNICODE);
    $opts    = [
        'http' => [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/json',
            'content' => $payload,
        ]
    ];
    file_get_contents(GOOGLE_NOTIFY_URL, false, stream_context_create($opts));
}

// จัดการการเพิ่มคำ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $word     = trim((string) $_POST['word']);
    $language = $_POST['language'] ?? 'th';

    if ($word !== '') {
        try {
            $stmt = $conn->prepare(
                'INSERT INTO bad_words (word, language, added_by) VALUES (?, ?, ?)'
            );
            $stmt->bind_param('ssi', $word, $language, $userId);
            $stmt->execute();
            $stmt->close();

            $message = '✅ เพิ่มคำไม่สุภาพเรียบร้อยแล้ว';
            notifyGoogleChat('add', $word, $name, date('d/m/Y H:i'));
        }
        catch (mysqli_sql_exception $e) {
            // โค้ด 1062 = duplicate entry
            if ($e->getCode() === 1062) {
                $message = '⚠️ คำนี้ถูกเพิ่มไปแล้ว';
            } else {
                throw $e;
            }
        }
    }
}
// จัดการการลบคำ
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && isset($_POST['id'])) {
    $id = (int) $_POST['id'];

    // ดึงคำก่อนลบ
    $fetch = $conn->prepare('SELECT word FROM bad_words WHERE id = ?');
    $fetch->bind_param('i', $id);
    $fetch->execute();
    $fetch->bind_result($deletedWord);
    $fetch->fetch();
    $fetch->close();

    // ลบคำ
    $stmt = $conn->prepare('DELETE FROM bad_words WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    $message = '✅ ลบคำไม่สุภาพเรียบร้อยแล้ว';
    notifyGoogleChat('delete', $deletedWord, $name, date('d/m/Y H:i'));
}

// ดึงรายการคำไม่สุภาพทั้งหมด
$result = $conn->query(
    'SELECT bw.id, bw.word, bw.language, u.username AS added_by, bw.created_at
     FROM bad_words bw
     LEFT JOIN users u ON bw.added_by = u.id
     ORDER BY bw.id DESC'
);
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>จัดการคำไม่สุภาพ</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    body { font-family: 'Segoe UI', sans-serif; background: #f8f9fa; }
    .table-wrapper { background: #fff; padding: 1.5rem; border-radius: .75rem; box-shadow: 0 4px 16px rgba(0,0,0,0.05); }
  </style>
</head>
<body>
  <?php require 'navbar.php'; ?>
  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
      <h1 class="h4">จัดการคำไม่สุภาพ</h1>
      <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addModal">
        <i class="bi bi-plus-lg me-1"></i>เพิ่มคำไม่สุภาพ
      </button>
    </div>
    <?php if (!empty($message)): ?>
      <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <div class="table-wrapper table-responsive">
      <table class="table table-striped table-hover align-middle mb-0">
        <thead class="table-dark">
          <tr>
            <th>ID</th><th>คำ</th><th>ภาษา</th><th>เพิ่มโดย</th><th>วันที่</th><th class="text-center">จัดการ</th>
          </tr>
        </thead>
        <tbody>
          <?php while ($row = $result->fetch_assoc()): ?>
          <tr>
            <td><?= $row['id'] ?></td>
            <td><?= htmlspecialchars($row['word']) ?></td>
            <td><?= strtoupper($row['language']) ?></td>
            <td><?= htmlspecialchars($row['added_by'] ?? '-') ?></td>
            <td><?= date('d/m/Y H:i', strtotime($row['created_at'])) ?></td>
            <td class="text-center">
              <form method="post" class="d-inline" onsubmit="return confirm('ยืนยันการลบหรือไม่?')">
                <input type="hidden" name="action" value="delete"/>
                <input type="hidden" name="id" value="<?= $row['id'] ?>"/>
                <button type="submit" class="btn btn-sm btn-danger">
                  <i class="bi bi-trash-fill"></i>
                </button>
              </form>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Add Modal -->
  <div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <form method="post">
          <div class="modal-header">
            <h5 class="modal-title">เพิ่มคำไม่สุภาพ</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="action" value="add"/>
            <div class="mb-3">
              <label for="word" class="form-label">คำไม่สุภาพ</label>
              <input type="text" id="word" name="word" class="form-control" placeholder="กรอกคำไม่สุภาพ" required/>
            </div>
            <div class="mb-3">
              <label for="language" class="form-label">ภาษา</label>
              <select id="language" name="language" class="form-select" required>
                <option value="th">ไทย</option>
                <option value="en">อังกฤษ</option>
              </select>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
            <button type="submit" class="btn btn-primary">บันทึก</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>