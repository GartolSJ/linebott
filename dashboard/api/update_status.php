<?php
    /**
     * update_status.php — push LINE Flex, audit DB, and Google Chat notify
     * rev-H 2025-05-21 — always show real admin name (users.name) in Chat & logs
     * --------------------------------------------------------------
     * POST:
     *   id            int      (report id)
     *   status        string   (รอรับเรื่อง|กำลังดำเนินการ|เสร็จสิ้น|ยกเลิก)
     *   final_image   file     (only when status = เสร็จสิ้น)
     */

    declare(strict_types=1);

    session_start();
    header('Content-Type: application/json; charset=utf-8');
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    require __DIR__ . '/../../config.php';   // $conn, $LINE_ACCESS_TOKEN, (optional) GOOGLE_NOTIFY_URL

    /* ──── Google Chat webhook (override here if not defined in config) ───────── */
    if (!defined('GOOGLE_NOTIFY_URL')) {
        define('GOOGLE_NOTIFY_URL',
            'https://chat.googleapis.com/v1/spaces/AAQAFyUpo3I/messages'
        . '?key=AIzaSyDdI0hCZtE6vySjMm-WEfRq3CPzqKqqsHI'
        . '&token=Hdd7S6QQvcI1pvDfUiy-ocrlwhtpJCrgB7n6Dd1y0H0'
        );
    }

    /* ──── constants ──────────────────────────────────────────────────────────── */
    const MAX_FILE_MB = 3;
    const LOG_FILE    = __DIR__ . '/logs/flex_push.log';
    const STATUSES    = ['รอรับเรื่อง', 'กำลังดำเนินการ', 'เสร็จสิ้น', 'ยกเลิก'];
    const COLORS      = ['#FF3B30', '#FFA500', '#2E7D32', '#9E9E9E'];

    if (!defined('BASE_URL')) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        define('BASE_URL', "{$scheme}://{$_SERVER['HTTP_HOST']}");
    }

    /* ──── tiny logger ────────────────────────────────────────────────────────── */
    function logLine(string $msg): void
    {
        if (!is_dir(dirname(LOG_FILE))) mkdir(dirname(LOG_FILE), 0755, true);
        file_put_contents(LOG_FILE, date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /* ──── LINE push helper ───────────────────────────────────────────────────── */
    function pushFlex(string $userId, array $bubble, string $altText): void
    {
        global $LINE_ACCESS_TOKEN;
        $payload = json_encode([
            'to'       => $userId,
            'messages' => [[
                'type'     => 'flex',
                'altText'  => $altText,
                'contents' => $bubble,
            ]],
        ], JSON_UNESCAPED_UNICODE);

        logLine('LINE SEND ➜ ' . $payload);
        $ch = curl_init('https://api.line.me/v2/bot/message/push');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "Authorization: Bearer {$LINE_ACCESS_TOKEN}",
            ],
            CURLOPT_POSTFIELDS     => $payload,
        ]);
        $res  = curl_exec($ch);
        $err  = curl_errno($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        logLine("LINE RECV ⇦ HTTP:$code body:$res");

        if ($err)                        throw new RuntimeException("cURL error ($err)");
        if ($code < 200 || $code >= 300) throw new RuntimeException("LINE push failed (HTTP $code)");
    }

    /* ──── build Flex bubble (unchanged) ──────────────────────── */
    function buildFlexBubble(array $rep): array
    {
        /* progress labels */
        $labels = array_map(
            static fn(string $lbl, int $i) => [
                'type'   => 'text',
                'text'   => $lbl,
                'flex'   => 1,
                'align'  => 'center',
                'weight' => 'bold',
                'size'   => 'sm',
                'color'  => $lbl === $rep['status'] ? COLORS[$i] : '#AAAAAA',
            ],
            STATUSES,
            array_keys(STATUSES)
        );

        /* choose best image (updated → original → placeholder) */
        $bestImage = $rep['updated_image_url']
            ?: ($rep['image_url'] ?: BASE_URL . '/assets/placeholder.png');

        return [
            'type'   => 'bubble',
            'styles' => ['body' => ['backgroundColor' => '#FFFFFF']],

            /* header banner */
            'header' => [
                'type'     => 'box',
                'layout'   => 'vertical',
                'contents' => [[
                    'type'            => 'box',
                    'layout'          => 'vertical',
                    'backgroundColor' => '#2A2558',
                    'cornerRadius'    => 'sm',
                    'paddingAll'      => '4px',
                    'contents'        => [[
                        'type'  => 'text',
                        'text'  => 'เปิดรับสมัครแล้ว DEKSBAC68 คลิกที่นี่!!',
                        'size'  => 'xs',
                        'color' => '#FFFFFF',
                        'align' => 'center',
                    ]],
                ]],
            ],

            /* hero (two-column layout) */
            'hero' => [
                'type'       => 'box',
                'layout'     => 'vertical',
                'spacing'    => 'none',
                'contents'   => [[
                    'type'       => 'box',
                    'layout'     => 'horizontal',
                    'spacing'    => 'md',
                    'paddingAll' => '12px',
                    'contents'   => [
                        /* left : logo --------------------------------------------------- */
                        [
                            'type'           => 'box',
                            'layout'         => 'horizontal',
                            'backgroundColor'=> '#FFFFFF',
                            'borderWidth'    => '2px',
                            'borderColor'    => '#ECECEC',
                            'cornerRadius'   => 'md',
                            'paddingAll'     => '8px',
                            'flex'           => 5,
                            'contents'       => [[
                                'type'        => 'image',
                                'url'         => 'https://sjgrip.com/backend/logo/sbacfix.png',
                                'size'        => 'full',
                                'aspectMode'  => 'cover',
                                'aspectRatio' => '16:9',
                            ]],
                        ],
                        /* right : status panel ----------------------------------------- */
                        [
                            'type'           => 'box',
                            'layout'         => 'vertical',
                            'backgroundColor'=> '#1565C0',   // deep blue
                            'cornerRadius'   => 'xl',
                            'paddingAll'     => '14px',
                            'flex'           => 4,
                            'alignItems'     => 'center',
                            'justifyContent' => 'center',
                            'spacing'        => 'sm',
                            'contents'       => [
                                [
                                    'type'   => 'text',
                                    'text'   => 'STATUS UPDATE',
                                    'weight' => 'bold',
                                    'size'   => 'xl',
                                    'color'  => '#FFFFFF',
                                    'align'  => 'center',
                                ],
                                [
                                    'type'   => 'text',
                                    'text'   => 'Updated',
                                    'size'   => 'lg',
                                    'color'  => '#E3F2FD',
                                    'align'  => 'center',
                                ],
                                [
                                    'type'   => 'separator',
                                    'margin' => 'md',
                                    'color'  => '#90CAF9',
                                ],
                            ],
                        ],
                    ],
                ]],
            ],

            /* body */
            'body'   => [
                'type'     => 'box',
                'layout'   => 'vertical',
                'spacing'  => 'md',
                'contents' => [
                    /* progress bar */
                    [
                        'type'     => 'box',
                        'layout'   => 'horizontal',
                        'contents' => $labels,
                    ],
                    ['type' => 'separator', 'margin' => 'md'],

                    /* main information */
                    [
                        'type'     => 'box',
                        'layout'   => 'horizontal',
                        'spacing'  => 'md',
                        'contents' => [
                            [
                                'type'           => 'box',
                                'layout'         => 'vertical',
                                'spacing'        => 'sm',
                                'backgroundColor'=> '#F7F7F7',
                                'cornerRadius'   => 'sm',
                                'paddingAll'     => '10px',
                                'flex'           => 4,
                                'contents'       => [
                                    [
                                        'type'  => 'text',
                                        'text'  => date('d/m/Y H:i', strtotime($rep['created_at'])),
                                        'size'  => 'xs',
                                        'color' => '#999999',
                                    ],
                                    [
                                        'type'   => 'text',
                                        'text'   => "💬 ปัญหา: {$rep['message']}",
                                        'size'   => 'sm',
                                        'weight' => 'bold',
                                        'wrap'   => true,
                                    ],
                                    [
                                        'type'  => 'text',
                                        'text'  => "📍 สถานที่: {$rep['location_text']}",
                                        'size'  => 'sm',
                                        'color' => '#666666',
                                        'wrap'  => true,
                                    ],
                                ],
                            ],
                            [
                                'type'        => 'image',
                                'url'         => $bestImage,
                                'size'        => 'lg',
                                'aspectRatio' => '3:4',
                                'aspectMode'  => 'cover',
                                'flex'        => 2,
                            ],
                        ],
                    ],
                ],
            ],

            /* footer */
            'footer' => [
                'type'     => 'box',
                'layout'   => 'vertical',
                'spacing'  => 'sm',
                'contents' => [[
                    'type'   => 'button',
                    'style'  => 'primary',
                    'action' => [
                        'type'  => 'uri',
                        'label' => 'แจ้งเรื่องใหม่',
                        'uri'   => BASE_URL . '/backend',
                    ],
                ]],
            ],
        ];
    }

    /* ──── MAIN workflow ──────────────────────────────────────────────────────── */
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new RuntimeException('Invalid request method');
        }

        /* current admin from session ------------------------------------------- */
        $adminId = (int)($_SESSION['user_id'] ?? 0);
        if (!$adminId) throw new RuntimeException('Unauthenticated — no admin session');

        /* preferred session keys for the name */
        $adminName = '';
        foreach (['name', 'fullname', 'display_name', 'username'] as $k) {
            if (!empty($_SESSION[$k])) { $adminName = trim((string)$_SESSION[$k]); break; }
        }

        /* DB fallback: users.name ---------------------------------------------- */
        if ($adminName === '') {
            $stmt = $conn->prepare('SELECT name FROM users WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $adminId);
            $stmt->execute();
            $adminName = (string)$stmt->get_result()->fetch_column();
            $stmt->close();
        }
        if ($adminName === '' || $adminName === null) $adminName = 'User#' . $adminId;

        /* validate POST input --------------------------------------------------- */
        $id     = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
        $status = trim((string)filter_input(INPUT_POST, 'status', FILTER_UNSAFE_RAW));
        if (!$id || $status === '')             throw new RuntimeException('Missing id or status');
        if (!in_array($status, STATUSES, true)) throw new RuntimeException('Invalid status value');

        $conn->begin_transaction();

        /* fetch report ---------------------------------------------------------- */
        $stmt = $conn->prepare("
            SELECT id, user_id, message, location_text,
                department, status AS old_status,
                image_url, updated_image_url, created_at
            FROM   reports
            WHERE  id = ? LIMIT 1
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $rep = $stmt->get_result()->fetch_assoc() ?: throw new RuntimeException('Report not found');
        $oldStatus = $rep['old_status'];

        /* handle upload if status = เสร็จสิ้น ---------------------------------- */
        $hasNewImage = false;
        if ($status === 'เสร็จสิ้น') {
            if (empty($_FILES['final_image']['tmp_name'])) {
                throw new RuntimeException('final_image required for status "เสร็จสิ้น"');
            }
            $tmp  = $_FILES['final_image']['tmp_name'];
            $name = $_FILES['final_image']['name'];
            $size = $_FILES['final_image']['size'];

            if (!is_uploaded_file($tmp))                        throw new RuntimeException('Upload error');
            if ($size > MAX_FILE_MB * 1048576)                  throw new RuntimeException('File exceeds ' . MAX_FILE_MB . ' MB');
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','gif','webp'], true)) {
                throw new RuntimeException('Invalid file type');
            }

            $dir = dirname(__DIR__, 2) . '/uploads/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fname = uniqid('done_') . ".$ext";
            if (!move_uploaded_file($tmp, $dir . $fname)) {
                throw new RuntimeException('Failed to save uploaded image');
            }

            $rep['updated_image_url'] = BASE_URL . '/backend/uploads/' . $fname;
            $hasNewImage = true;
        }

        /* update reports table -------------------------------------------------- */
        if ($hasNewImage) {
            $stmt = $conn->prepare("
                UPDATE reports SET status = ?, updated_image_url = ? WHERE id = ?
            ");
            $stmt->bind_param('ssi', $status, $rep['updated_image_url'], $id);
        } else {
            $stmt = $conn->prepare("
                UPDATE reports SET status = ? WHERE id = ?
            ");
            $stmt->bind_param('si', $status, $id);
        }
        $stmt->execute();

        /* audit row ------------------------------------------------------------- */
        $stmt = $conn->prepare("
            INSERT INTO report_status_logs
                (report_id, admin_id, admin_name, old_status, new_status)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('iisss', $id, $adminId, $adminName, $oldStatus, $status);
        $stmt->execute();

        $conn->commit();

        /* push Flex to LINE ----------------------------------------------------- */
        $rep['status'] = $status;
        pushFlex(
            $rep['user_id'],
            buildFlexBubble($rep),
            $status === 'เสร็จสิ้น'
                ? 'งานของคุณเสร็จสมบูรณ์แล้ว'
                : "อัปเดตสถานะ: $status"
        );

        /* Google Chat notification --------------------------------------------- */
        if (GOOGLE_NOTIFY_URL) {
            $chatText = "*Status Updated* by **{$adminName}**\n"
                    . "Report: {$rep['message']}\n"
                    . "Location: {$rep['location_text']}\n"
                    . "Status: {$oldStatus} → {$status}\n"
                    . "Department: {$rep['department']}";
            if ($status === 'เสร็จสิ้น') $chatText .= "\nImage: {$rep['updated_image_url']}";

            $payload = json_encode(['text' => $chatText], JSON_UNESCAPED_UNICODE);
            $ch = curl_init(GOOGLE_NOTIFY_URL);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS     => $payload,
            ]);
            $chatRes = curl_exec($ch);
            curl_close($ch);

            logLine("GCHAT SEND ➜ $payload");
            logLine("GCHAT RECV ⇦ $chatRes");
        }

        logLine("SUCCESS id:$id admin:$adminName status:$status");
        echo json_encode(['success' => true]);

    } catch (Throwable $e) {
        if (isset($conn) && $conn->errno === 0) $conn->rollback();
        logLine('ERROR ✖ ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
?>