<?php
// change_password.php
// Force user to set a new password after admin reset

declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';   // $conn + constants

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$error  = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pwd1 = $_POST['password'] ?? '';
    $pwd2 = $_POST['password_confirm'] ?? '';

    if ($pwd1 === '' || $pwd2 === '') {
        $error = 'Please fill in both fields.';
    } elseif ($pwd1 !== $pwd2) {
        $error = 'Passwords do not match.';
    } elseif (strlen($pwd1) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        $hash = password_hash($pwd1, PASSWORD_DEFAULT);
        $stmt = $conn->prepare(
            "UPDATE users
             SET pwd_hash = ?, force_password_reset = 0
             WHERE id = ?"
        );
        $stmt->bind_param('si', $hash, $userId);
        if ($stmt->execute()) {
            $stmt->close();
            session_unset();
            session_destroy();
            header('Location: login.php?changed=1');
            exit;
        } else {
            $error = 'Update failed: ' . $stmt->error;
        }
        $stmt->close();
    }
}

// Fetch username (optional, for display)
$stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
$stmt->bind_param('i', $userId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc() ?: [];
$username = $row['username'] ?? '';
$stmt->close();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Change Password</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
        rel="stylesheet">
</head>
<body>
<div class="container py-5" style="max-width: 400px;">
  <h2 class="mb-4">Change Password for <?= htmlspecialchars($username) ?></h2>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <form method="post">
    <div class="mb-3">
      <label class="form-label">New Password</label>
      <input type="password" name="password" class="form-control">
    </div>
    <div class="mb-3">
      <label class="form-label">Confirm Password</label>
      <input type="password" name="password_confirm" class="form-control">
    </div>
    <button type="submit" class="btn btn-success">Set New Password</button>
  </form>
</div>
</body>
</html>

<!-- required minlength="8" -->
