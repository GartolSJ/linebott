<?php
declare(strict_types=1);

// Show all errors immediately
ini_set('display_errors',  '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../config.php';   // must define $conn (mysqli)

// Google Chat webhook URL
define('GOOGLE_NOTIFY_URL',
    'https://chat.googleapis.com/v1/spaces/AAQAFyUpo3I/messages'
  . '?key=AIzaSyDdI0hCZtE6vySjMm-WEfRq3CPzqKqqsHI'
  . '&token=Hdd7S6QQvcI1pvDfUiy-ocrlwhtpJCrgB7n6Dd1y0H0'
);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']  ?? '');
    $password =          $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please fill in both fields.';
    } else {
        // Only select the columns that actually exist
        $sql = "
            SELECT id, name, pwd_hash, force_password_reset
              FROM users
             WHERE username = ?
        ";
        $stmt = $conn->prepare($sql)
            or die('MySQL prepare failed: ' . $conn->error);

        $stmt->bind_param('s', $username);
        $stmt->execute()
            or die('MySQL execute failed: ' . $stmt->error);

        $user = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        if (!$user || !password_verify($password, $user['pwd_hash'])) {
            $error = 'Invalid credentials.';
        } else {
            $name = $user['name'];
            $_SESSION['user_id'] = (int)$user['id'];

            // Build & send notification (now logging user ID instead of name)
            $ip    = $_SERVER['REMOTE_ADDR']    ?? 'n/a';
            $agent = $_SERVER['HTTP_USER_AGENT'] ?? 'n/a';

            $message = [
                "*SBAC Login*",
                "• **Username:** {$username}",
                "• **Name:** {$user['name']}",
                "• **User ID:** {$user['id']}",
                "• **IP:** {$ip}",
                "• **Device:** {$agent}"
            ];

            $ch = curl_init(GOOGLE_NOTIFY_URL);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
                CURLOPT_POSTFIELDS     => json_encode(['text' => implode("\n", $message)]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 2,
            ]);
            curl_exec($ch);
            curl_close($ch);

            // Redirect based on reset flag
            $dest = (int)$user['force_password_reset']
                  ? 'change_password.php'
                  : 'index.php';

            header("Location: {$dest}");
            exit;
        }
    }
}
?>

<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>SBAC Login</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body{
      background:linear-gradient(135deg,#ffffff 0%,#bcbcbc 100%);
      min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:'Segoe UI',sans-serif}
    .card-white{
      background:#fff;border-radius:1rem;box-shadow:0 8px 24px rgba(0,0,0,.1);
      padding:2rem 1.5rem 1.5rem;max-width:360px;width:100%;
      transition:transform .3s,box-shadow .3s}
    .card-white:hover{transform:translateY(-5px);box-shadow:0 12px 40px rgba(0,0,0,.15)}
    /* — logo — */
    .logo-wrapper{display:flex;justify-content:center;margin-bottom:1.5rem}
    .logo-wrapper img{max-width:120px}
    /* — form — */
    .form-floating>.form-control{border-radius:.5rem;padding-right:3rem}
    .input-icon{position:absolute;top:0;right:0;height:100%;width:3rem;display:flex;align-items:center;justify-content:center;color:#6c757d;cursor:pointer}
    .btn-primary{border-radius:.5rem;padding:.75rem;font-weight:600;transition:background .3s}
    .btn-primary:hover{background:#5a32a3}
  </style>
</head>
<body>

  <div class="card-white text-dark">

    <!-- logo now inside card -->
    <div class="logo-wrapper">
      <img src="../logo/sbacfix.png" alt="SBAC Logo">
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger text-center"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" novalidate>
      <div class="form-floating mb-3 position-relative">
        <input type="text" id="username" name="username" class="form-control"
               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" placeholder="Username" required>
        <label for="username">Username</label>
        <i class="bi bi-person-fill input-icon"></i>
      </div>

      <div class="form-floating mb-4 position-relative">
        <input type="password" id="password" name="password" class="form-control"
               placeholder="Password" required>
        <label for="password">Password</label>
        <div class="input-icon" id="togglePassword">
          <i class="bi bi-eye-fill"></i>
        </div>
      </div>

      <button type="submit" class="btn btn-primary w-100 mb-3">Log In</button>
    </form>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    document.getElementById('togglePassword').addEventListener('click', () => {
      const pwd = document.getElementById('password');
      const icon = event.currentTarget.querySelector('i');
      pwd.type = pwd.type === 'password' ? 'text' : 'password';
      icon.classList.toggle('bi-eye-fill');
      icon.classList.toggle('bi-eye-slash-fill');
    });
  </script>
</body>
</html>
