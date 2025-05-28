<?php
// user_manage.php
// — only ADMIN can access
// — ADMIN can add/edit/remove any of the four roles: EXEC, ADMIN, MIS, BUILDING
// — Reset password & force user to change on next login
declare(strict_types=1);
session_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';   // provides $conn (mysqli)

// Google Chat helper
define('GOOGLE_NOTIFY_URL',
    'https://chat.googleapis.com/v1/spaces/AAQAFyUpo3I/messages'
  . '?key=AIzaSyDdI0hCZtE6vySjMm-WEfRq3CPzqKqqsHI'
  . '&token=Hdd7S6QQvcI1pvDfUiy-ocrlwhtpJCrgB7n6Dd1y0H0'
);
function sendGoogleNotification(array $lines): void {
    $ch = curl_init(GOOGLE_NOTIFY_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_POSTFIELDS     => json_encode(['text'=>implode("\n", $lines)], JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 2,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// 1) Access control: only logged‐in ADMIN
if (empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
$currentUserId = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT role, name FROM users WHERE id = ?");
$stmt->bind_param('i', $currentUserId);
$stmt->execute();
$curr = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

if (($curr['role'] ?? '') !== 'ADMIN') {
    header('Location: index.php');
    exit;
}
$adminName   = $curr['name'] ?? '';
$allowedRoles = ['EXEC','ADMIN','MIS','BUILDING'];

$error    = '';
$success  = '';
$addUser    = false;
$editUser   = null;
$resetUser  = null;
$removeUser = null;

// ─── Handle “Add User” POST ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['add_username'], $_POST['add_name'], $_POST['add_role'], $_POST['add_password'])
) {
    $u = trim($_POST['add_username']);
    $n = trim($_POST['add_name']);
    $r = $_POST['add_role'];
    $p = trim($_POST['add_password']);

    // ตรวจสอบความซ้ำซ้อนของ Username และ Name
    $dup = $conn->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR name = ?");
    $dup->bind_param('ss', $u, $n);
    $dup->execute();
    $dup->bind_result($countDup);
    $dup->fetch();
    $dup->close();

    if ($countDup > 0) {
        // ถ้ามีซ้ำ ให้ขึ้น error และเปิดฟอร์ม Add ใหม่
        $error   = 'Username หรือ Name นี้ถูกใช้งานแล้ว';
        $addUser = true;

    } elseif ($u === '' || $n === '' || strlen($p) < 8 || !in_array($r, $allowedRoles, true)) {
        // เช็คเงื่อนไขเดิม
        $error   = 'All fields required; password ≥8 chars; valid role.';
        $addUser = true;

    } else {
        // ถ้าไม่ซ้ำและถูกต้อง ก็เข้าสู่การ INSERT
        $hash = password_hash($p, PASSWORD_DEFAULT);
        $ins  = $conn->prepare("
            INSERT INTO users (username,name,pwd_hash,role,force_password_reset,created_at)
            VALUES (?,?,?,?,1,NOW())
        ");
        $ins->bind_param('ssss', $u, $n, $hash, $r);
        if ($ins->execute()) {
            $success = "User {$u} added.";
            sendGoogleNotification([
                "*New User Added*",
                "• **Username:** {$u}",
                "• **Name:** {$n}",
                "• **Added By:** {$adminName}"
            ]);
        } else {
            $error   = 'Add failed: ' . $ins->error;
            $addUser = true;
        }
        $ins->close();
    }
}

// ─── Handle “Edit User” POST ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST'
    && isset($_POST['id'], $_POST['username'], $_POST['name'], $_POST['role'])
    && !isset($_POST['reset_id'], $_POST['add_username'], $_POST['remove_id'])
) {
    $id   = (int)$_POST['id'];
    // fetch old
    $st   = $conn->prepare("SELECT username,name,role FROM users WHERE id=?");
    $st->bind_param('i', $id);
    $st->execute();
    $old  = $st->get_result()->fetch_assoc() ?: [];
    $st->close();

    $uNew = trim($_POST['username']);
    $nNew = trim($_POST['name']);
    $rNew = $_POST['role'];

    if ($uNew==='' || $nNew==='' || !in_array($rNew, $allowedRoles, true)) {
        $error    = 'Invalid input.';
        $editUser = ['id'=>$id,'username'=>$uNew,'name'=>$nNew,'role'=>$rNew];
    } else {
        $up = $conn->prepare("
            UPDATE users SET username=?, name=?, role=? WHERE id=?
        ");
        $up->bind_param('sssi', $uNew, $nNew, $rNew, $id);

        if ($up->execute()) {
            $success = "User #{$id} updated.";

            // detect changes with “old → new”
            $changes = [];
            if ($old['username'] !== $uNew) {
                $changes[] = "Username: {$old['username']} → {$uNew}";
            }
            if ($old['name'] !== $nNew) {
                $changes[] = "Name: {$old['name']} → {$nNew}";
            }
            if ($old['role'] !== $rNew) {
                $changes[] = "Role: {$old['role']} → {$rNew}";
            }
            $desc = $changes ? implode('; ', $changes) : 'None';

            sendGoogleNotification([
                "*User Profile Updated*",
                "• **Username:** {$uNew}",
                "• **Name:** {$nNew}",
                "• **Changed:** {$desc}",
                "• **Changed By:** {$adminName}"
            ]);
        } else {
            $error    = 'Update failed: '.$up->error;
            $editUser = ['id'=>$id,'username'=>$uNew,'name'=>$nNew,'role'=>$rNew];
        }

        $up->close();
    }
}

// ─── Handle “Reset Password” POST ────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['reset_id'], $_POST['new_password'])) {
    $rid = (int)$_POST['reset_id'];
    $npw = trim($_POST['new_password']);
    // fetch user
    $st = $conn->prepare("SELECT username,name FROM users WHERE id=?");
    $st->bind_param('i',$rid);
    $st->execute();
    $usr = $st->get_result()->fetch_assoc() ?: [];
    $st->close();

    if (strlen($npw)<8) {
        $error = 'Password must be ≥ 8 chars.';
        $resetUser = ['id'=>$rid,'username'=>$usr['username']];
    } else {
        $ph = password_hash($npw,PASSWORD_DEFAULT);
        $rp = $conn->prepare("
            UPDATE users SET pwd_hash=?, force_password_reset=1 WHERE id=?
        ");
        $rp->bind_param('si',$ph,$rid);
        if ($rp->execute()) {
            $success = "Password for {$usr['username']} reset.";
            sendGoogleNotification([
                "*Password Reset*",
                "• **Username:** {$usr['username']}",
                "• **Name:** {$usr['name']}",
                "• **Action:** Reset Password",
                "• **Changed By:** {$adminName}"
            ]);
        } else {
            $error = 'Reset failed: '.$rp->error;
            $resetUser = ['id'=>$rid,'username'=>$usr['username']];
        }
        $rp->close();
    }
}

// ─── Handle “Remove User” POST ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['remove_id'])) {
    $rid = (int)$_POST['remove_id'];
    // fetch user
    $st = $conn->prepare("SELECT username,name FROM users WHERE id=?");
    $st->bind_param('i',$rid);
    $st->execute();
    $usr = $st->get_result()->fetch_assoc() ?: [];
    $st->close();

    $del = $conn->prepare("DELETE FROM users WHERE id=?");
    $del->bind_param('i',$rid);
    if ($del->execute()) {
        $success = "User {$usr['username']} removed.";
        sendGoogleNotification([
            "*User Removed*",
            "• **Username:** {$usr['username']}",
            "• **Name:** {$usr['name']}",
            "• **Removed By:** {$adminName}"
        ]);
    } else {
        $error = 'Remove failed: '.$del->error;
        $removeUser = ['id'=>$rid,'username'=>$usr['username']];
    }
    $del->close();
}

// ─── Detect GET actions ─────────────────────────────────────────
if (isset($_GET['add'])) {
    $addUser = true;
}
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $st = $conn->prepare("SELECT id,username,name,role FROM users WHERE id=?");
    $st->bind_param('i',$eid);
    $st->execute();
    $editUser = $st->get_result()->fetch_assoc() ?: null;
    $st->close();
}
if (isset($_GET['reset'])) {
    $rid = (int)$_GET['reset'];
    $st = $conn->prepare("SELECT id,username FROM users WHERE id=?");
    $st->bind_param('i',$rid);
    $st->execute();
    $resetUser = $st->get_result()->fetch_assoc() ?: null;
    $st->close();
}
if (isset($_GET['remove'])) {
    $rid = (int)$_GET['remove'];
    $st = $conn->prepare("SELECT id,username FROM users WHERE id=?");
    $st->bind_param('i',$rid);
    $st->execute();
    $removeUser = $st->get_result()->fetch_assoc() ?: null;
    $st->close();
}

// ─── Load table users ────────────────────────────────────────────
$in = "'" . implode("','", $allowedRoles) . "'";
$stmt = $conn->prepare("
    SELECT id,username,name,role,created_at,force_password_reset
    FROM users
    WHERE role IN ({$in})
    ORDER BY created_at DESC
");
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>User Management</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
        rel="stylesheet">
</head>
<body>
<?php require('navbar.php'); ?>

<body class="bg-light d-flex flex-column min-vh-100">
  <div class="container my-4 flex-grow-1">
    <h1 class="mb-4">User Management</h1>

    <?php if ($error):   ?>
      <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if ($addUser): ?>
      <!-- ADD USER -->
      <div class="card mb-4">
        <div class="card-header">Add New User</div>
        <div class="card-body">
          <form method="post">
            <div class="mb-3">
              <label class="form-label">Username</label>
              <input type="text" name="add_username" class="form-control" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Name</label>
              <input type="text" name="add_name" class="form-control" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Role</label>
              <select name="add_role" class="form-select" required>
                <?php foreach($allowedRoles as $r): ?>
                  <option value="<?= $r ?>"><?= $r ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Temporary Password</label>
              <input type="password" name="add_password" class="form-control" required minlength="8" placeholder="≥ 8 characters">
            </div>
            <div class="d-grid gap-2">
              <button type="submit" class="btn btn-success">Add User</button>
              <a href="user_manage.php" class="btn btn-secondary">Cancel</a>
            </div>
          </form>
        </div>
      </div>

    <?php elseif ($editUser): ?>
      <!-- EDIT USER -->
      <div class="card mb-4">
        <div class="card-header">Edit User #<?= $editUser['id'] ?></div>
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="id" value="<?= $editUser['id'] ?>">
            <div class="mb-3">
              <label class="form-label">Username</label>
              <input type="text" name="username" class="form-control" required value="<?= htmlspecialchars($editUser['username']) ?>">
            </div>
            <div class="mb-3">
              <label class="form-label">Name</label>
              <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($editUser['name']) ?>">
            </div>
            <div class="mb-3">
              <label class="form-label">Role</label>
              <select name="role" class="form-select" required>
                <?php foreach($allowedRoles as $r): ?>
                  <option value="<?= $r ?>" <?= $r===$editUser['role']?'selected':'' ?>><?= $r ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="d-grid gap-2">
              <button type="submit" class="btn btn-primary">Save Changes</button>
              <a href="user_manage.php" class="btn btn-secondary">Cancel</a>
            </div>
          </form>
        </div>
      </div>

    <?php elseif ($resetUser): ?>
      <!-- RESET PASSWORD -->
      <div class="card mb-4 border-warning">
        <div class="card-header bg-warning text-dark">
          Reset Password for #<?= $resetUser['id'] ?> (<?= htmlspecialchars($resetUser['username']) ?>)
        </div>
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="reset_id" value="<?= $resetUser['id'] ?>">
            <div class="mb-3">
              <label class="form-label">New Temporary Password</label>
              <input type="password" name="new_password" class="form-control" required minlength="8" placeholder="≥ 8 characters">
            </div>
            <div class="d-grid gap-2">
              <button type="submit" class="btn btn-warning">Reset &amp; Force Change</button>
              <a href="user_manage.php" class="btn btn-secondary">Cancel</a>
            </div>
          </form>
        </div>
      </div>

    <?php elseif ($removeUser): ?>
      <!-- REMOVE USER -->
      <div class="card mb-4 border-danger">
        <div class="card-header bg-danger text-white">
          Remove User #<?= $removeUser['id'] ?> (<?= htmlspecialchars($removeUser['username']) ?>)
        </div>
        <div class="card-body">
          <p>Are you sure you want to <strong>permanently remove</strong> this user?</p>
          <form method="post">
            <input type="hidden" name="remove_id" value="<?= $removeUser['id'] ?>">
            <div class="d-grid gap-2">
              <button type="submit" class="btn btn-danger">Yes, Remove User</button>
              <a href="user_manage.php" class="btn btn-secondary">Cancel</a>
            </div>
          </form>
        </div>
      </div>

    <?php else: ?>
      <!-- USERS TABLE + Add Button -->
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0">All Users</h2>
        <a href="?add=1" class="btn btn-sm btn-success">Add User</a>
      </div>
      <div class="card">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-striped mb-0">
              <thead>
                <tr>
                  <th>Username</th>
                  <th>Name</th>
                  <th>Role</th>
                  <th>Created at</th>
                  <th>Force Reset?</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($users as $u): ?>
                  <tr>
                    <td><?= htmlspecialchars($u['username']) ?></td>
                    <td><?= htmlspecialchars($u['name']) ?></td>
                    <td><?= $u['role'] ?></td>
                    <td><?= $u['created_at'] ?></td>
                    <td>
                      <?php if ($u['force_password_reset']): ?>
                        <span class="badge bg-danger">Yes</span>
                      <?php else: ?>
                        <span class="badge bg-success">No</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <a href="?edit=<?= $u['id'] ?>"   class="btn btn-sm btn-outline-primary">Edit</a>
                      <a href="?reset=<?= $u['id'] ?>"  class="btn btn-sm btn-warning ms-1"
                          onclick="return confirm('Reset password and force user to change on next login?')">
                        Reset
                      </a>
                      <a href="?remove=<?= $u['id'] ?>" class="btn btn-sm btn-danger ms-1"
                          onclick="return confirm('Permanently remove this user?');">
                        Remove
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>