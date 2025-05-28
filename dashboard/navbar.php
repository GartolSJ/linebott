<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/../config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../dashboard/login.php');
    exit;
}

if (empty($_SESSION['role'])) {
    $stmt = $conn->prepare('SELECT role FROM users WHERE id = ?');
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $stmt->bind_result($sessionRole);
    $stmt->fetch();
    $_SESSION['role'] = $sessionRole ?: '';
    $stmt->close();
}

$role = $_SESSION['role'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Poodle Toy Farm</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"
          integrity="sha512-yZ8rIbo13W48iuHe04o+74EODmTEu7haPRlU7Z4z5iDl0x0dH4AVXJzQCT0hMjV/BYih+WDpGLnI+0+9JTb+2g=="
          crossorigin="anonymous" referrerpolicy="no-referrer">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #fff;
            margin: 0;
            padding: 0;
        }
        .navbar {
            border-bottom: 1px solid #ddd;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .nav-link {
            color: #000 !important;
            transition: color 0.3s;
        }
        .nav-link:hover,
        .nav-link.active {
            color: #66CCFF !important;
        }
        .offcanvas {
            --bs-offcanvas-width: 280px;
            background-color: #f8f9fa;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        }
        .offcanvas-header {
            border-bottom: 1px solid #ddd;
        }
        .offcanvas-title {
            color: #66CCFF;
            font-size: 1.25rem;
            font-weight: 600;
        }
        .social-icon {
            display: inline-flex;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: 1px solid #ddd;
            background-color: #f9f9f9;
            align-items: center;
            justify-content: center;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .social-icon:hover {
            transform: translateY(-2px);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.18);
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-light bg-white">
    <div class="container-xxl">
        <a class="navbar-brand" href="index.php">
            <img src="https://sjgrip.com/backend/logo/sbacfix.png" alt="Logo" height="60">
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="offcanvas"
                data-bs-target="#offcanvasMenu" aria-controls="offcanvasMenu"
                aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarMenu">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link" href="index.php">Report</a>
                </li>
                <?php if (in_array($role, ['ADMIN', 'EXEC'], true)): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="summary.php">Summary</a>
                    </li>
                <?php endif; ?>
                <?php if ($role === 'ADMIN'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="badwords_manage.php">BadWords Management</a>
                    </li>
                <?php endif; ?>
                <?php if ($role === 'ADMIN'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="user_management.php">User Management</a>
                    </li>
                <?php endif; ?>
                <li class="nav-item">
                    <a class="nav-link" style="color: red !important;" href="logout.php">Logout</a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="offcanvas offcanvas-start" tabindex="-1" id="offcanvasMenu"
     aria-labelledby="offcanvasMenuLabel">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="offcanvasMenuLabel">Menu</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <div class="text-center mb-4">
            <img src="logo.png" alt="Logo" width="80">
            <p class="mt-2 mb-1">Welcome to Poodle Toy Farm</p>
            <p class="text-muted small">Role: <?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" href="index.php">Report</a>
            </li>
            <?php if (in_array($role, ['ADMIN', 'EXEC'], true)): ?>
                <li class="nav-item">
                    <a class="nav-link" href="summary.php">Summary</a>
                </li>
            <?php endif; ?>
            <?php if ($role === 'ADMIN'): ?>
                <li class="nav-item">
                    <a class="nav-link" href="badwords_manage.php">BadWords Management</a>
                </li>
            <?php endif; ?>
            <?php if ($role === 'ADMIN'): ?>
                <li class="nav-item">
                    <a class="nav-link" href="user_management.php">User Management</a>
                </li>
            <?php endif; ?>
            <li class="nav-item">
                <a class="nav-link" style="color: red !important;" href="logout.php">Logout</a>
            </li>
        </ul>
        <hr>
        <div class="d-flex justify-content-center gap-2">
            <a href="#" class="social-icon" aria-label="Phone"><i class="fas fa-phone"></i></a>
            <a href="https://www.facebook.com/PoodleTriump" class="social-icon" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
            <a href="https://youtube.com/@triumphpoodle7896" class="social-icon" aria-label="YouTube"><i class="fab fa-youtube"></i></a>
            <a href="https://line.me/ti/p/~@229odffm" class="social-icon" aria-label="LINE"><i class="fab fa-line"></i></a>
            <a href="https://www.tiktok.com/@poodletoyfarmtriumph" class="social-icon" aria-label="TikTok"><i class="fab fa-tiktok"></i></a>
            <a href="https://www.instagram.com/poodletoy88" class="social-icon" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const offcanvasEl = document.getElementById('offcanvasMenu');
        offcanvasEl.addEventListener('hidden.bs.offcanvas', () => {
            document.querySelectorAll('.offcanvas-backdrop').forEach(el => el.remove());
        });
    });
</script>

</body>
</html>