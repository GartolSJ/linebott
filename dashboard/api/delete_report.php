<?php
/*───────────────────────────────────────────────────────────────*/
/*  cancel_report.php – admin ยกเลิกงาน + Flex UI “Track status”  */
/*  location  : backend/dashboard/api/                            */
/*  requires  : backend/config.php  → $conn, $LINE_ACCESS_TOKEN   */
/*───────────────────────────────────────────────────────────────*/
declare(strict_types=1);

namespace App\Api;

use RuntimeException;
use Throwable;

require_once __DIR__ . '/../../config.php';   // defines $conn, $LINE_ACCESS_TOKEN

// Google Chat webhook URL
define('GOOGLE_NOTIFY_URL',
    'https://chat.googleapis.com/v1/spaces/AAQAFyUpo3I/messages'
  . '?key=AIzaSyDdI0hCZtE6vySjMm-WEfRq3CPzqKqqsHI'
  . '&token=Hdd7S6QQvcI1pvDfUiy-ocrlwhtpJCrgB7n6Dd1y0H0'
);

session_start();
header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// constants
const CANCEL_LOG   = __DIR__ . '/../../logs/cancel.log';
const LINE_API_URL = 'https://api.line.me/v2/bot/message/push';
const STATUSES     = ['รอรับเรื่อง', 'กำลังดำเนินการ', 'เสร็จสิ้น', 'ยกเลิก'];
const COLORS       = ['#9E9E9E',    '#1976D2',        '#388E3C',  '#D32F2F'];

try {
    // 1) validate input & admin
    [$reportId, $reason] = validateInput();
    $adminName = getAdminName();
    logLine("IN id={$reportId} reason=\"{$reason}\" by {$adminName}");

    $conn->begin_transaction();

    // 2) fetch report row
    $rep = fetchReport($reportId);

    // 3) update report: status, reason, deleted_by, deleted_at
    updateReport($reportId, $reason, $adminName);

    // 4) prepare updated data for Flex bubble
    $rep['status']         = 'ยกเลิก';
    $rep['deleted_reason'] = $reason;
    $rep['deleted_by']     = $adminName;

    // 5) build & push Flex
    $bubble = buildFlexBubble($rep);
    if (empty($rep['user_id'])) {
        throw new RuntimeException("user_id is empty for report {$reportId}");
    }
    pushFlex($rep['user_id'], $bubble, 'อัปเดตสถานะงานของคุณ');
    logLine("PUSH OK to {$rep['user_id']}");

    $conn->commit();

    // 6) Google Chat notification
    notifyGoogleChat([
        'text' =>
            "*❌ Remove Report*\n".
            "*What:* {$rep['message']}\n".
            "*Department:* {$rep['department']}\n".
            "*Why:* {$rep['deleted_reason']}\n".
            "*Who Removed:* {$rep['deleted_by']}"
    ]);

    respondJson(['success' => true], 200);
}
catch (Throwable $e) {
    if (isset($conn) && $conn->errno === 0) {
        $conn->rollback();
    }
    logLine('ERROR ' . $e->getMessage());
    respondJson(['error' => $e->getMessage()], 500);
}


/** ตรวจสอบ input */
function validateInput(): array
{
    $id     = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $reason = trim((string) filter_input(INPUT_POST, 'reason', FILTER_UNSAFE_RAW));

    if ($id === false || $id <= 0 || $reason === '') {
        throw new \InvalidArgumentException('Missing or invalid id/reason');
    }
    return [$id, $reason];
}

/** ดึงข้อมูล report */
function fetchReport(int $id): array
{
    global $conn;
    $stmt = $conn->prepare('SELECT * FROM reports WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new RuntimeException("Report {$id} not found");
    }
    return $row;
}

/** อัปเดตสถานะ report + reason + deleted_by */
function updateReport(int $id, string $reason, string $admin): void
{
    global $conn;
    $stmt = $conn->prepare(
        'UPDATE reports
           SET `status`       = "ยกเลิก",
               deleted_reason = ?,
               deleted_by     = ?,
               deleted_at     = NOW()
         WHERE id = ?'
    );
    $stmt->bind_param('ssi', $reason, $admin, $id);
    $stmt->execute();
    if ($stmt->affected_rows === 0) {
        throw new RuntimeException("Update failed: report {$id} unchanged");
    }
    $stmt->close();
    logLine("UPDATE OK id={$id} by {$admin}");
}

/** ดึงชื่อ admin จาก session หรือ DB */
function getAdminName(): string
{
    global $conn;
    if (!empty($_SESSION['admin_name'])) {
        return $_SESSION['admin_name'];
    }
    if (!empty($_SESSION['user_id'])) {
        $stmt = $conn->prepare('SELECT name FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $_SESSION['user_id']);
        $stmt->execute();
        $stmt->bind_result($name);
        $stmt->fetch();
        $stmt->close();
        return $name ?: 'Unknown';
    }
    return 'Unknown';
}

/** ยิง Flex ไปยัง LINE */
function pushFlex(string $uid, array $bubble, string $alt): void
{
    global $LINE_ACCESS_TOKEN;
    $payload = json_encode([
        'to'       => $uid,
        'messages' => [[
            'type'     => 'flex',
            'altText'  => $alt,
            'contents' => $bubble,
        ]],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init(LINE_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Bearer {$LINE_ACCESS_TOKEN}",
        ],
        CURLOPT_POSTFIELDS     => $payload,
    ]);
    $body  = curl_exec($ch);
    $errNo = curl_errno($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    logLine("LINE HTTP {$code} errno {$errNo} rsp {$body}");
    if ($errNo !== 0 || $code < 200 || $code >= 300) {
        throw new RuntimeException("LINE API error {$code}: {$body}");
    }
}

/** ส่งข้อความไปยัง Google Chat webhook */
function notifyGoogleChat(array $payload): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    logLine('GCHAT SEND ➜ ' . $json);

    $ch = curl_init(GOOGLE_NOTIFY_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_POSTFIELDS     => $json,
    ]);
    $res    = curl_exec($ch);
    $errNo  = curl_errno($ch);
    $errStr = curl_error($ch);
    curl_close($ch);
    if ($errNo) {
        logLine("GCHAT ERROR ➜ cURL error ({$errNo}): {$errStr}");
    }
}

/** JSON response helper */
function respondJson(array $data, int $code): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Log helper */
function logLine(string $msg): void
{
    if (!is_dir(dirname(CANCEL_LOG))) {
        mkdir(dirname(CANCEL_LOG), 0755, true);
    }
    file_put_contents(
        CANCEL_LOG,
        date('[Y-m-d H:i:s] ') . $msg . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}


/*──────────────── UI: Flex Bubble “Track status” ─────────────*/

/**
 * สร้าง Flex Bubble พร้อมแถบสถานะ
 * @param array $rep row จากตาราง reports (ต้องมี
 *        - message            (ปัญหา)
 *        - location_text      (สถานที่)
 *        - department         (ฝ่ายที่รับผิดชอบ)
 *        - deleted_reason     (สาเหตุยกเลิก)
 *        - status             (สถานะปัจจุบัน)
 *        - created_at
 *        - image_url          (URL รูปหลัก)
 * )
 */
function buildFlexBubble(array $rep): array
{
    /* สร้าง labels ของสถานะ */
    $labels = array_map(
        static function (string $lbl, int $i) use ($rep): array {
            $isCurrent = ($lbl === $rep['status']);
            return [
                'type'   => 'text',
                'text'   => $lbl,
                'flex'   => 1,
                'align'  => 'center',
                'weight' => 'bold',
                'size'   => 'sm',
                'color'  => $isCurrent ? COLORS[$i] : '#AAAAAA',
            ];
        },
        STATUSES,
        array_keys(STATUSES),
    );

    return [
        'type'     => 'bubble',
        'styles'   => ['body' => ['backgroundColor' => '#FFFFFF']],
        /*────────── HEADER ──────────*/
        'header'   => [
            'type'     => 'box',
            'layout'   => 'vertical',
            'contents' => [[
                'type'            => 'box',
                'layout'          => 'vertical',
                'backgroundColor' => '#2A2558',
                'paddingAll'      => '4px',
                'cornerRadius'    => 'sm',
                'contents'        => [[
                    'type'  => 'text',
                    'text'  => 'เปิดรับสมัครแล้ว DEKSBAC68 คลิกที่นี่!!',
                    'size'  => 'xs',
                    'color' => '#FFFFFF',
                    'align' => 'center',
                ]],
            ]],
        ],
        'hero' => [
            'type'     => 'box',
            'layout'   => 'vertical',
            'spacing'  => 'none',
            'margin'   => 'none',
            'contents' => [[
                'type'     => 'box',
                'layout'   => 'horizontal',
                'spacing'  => 'md',
                'contents' => [
                    [
                        'type'         => 'box',
                        'layout'       => 'horizontal',
                        'backgroundColor' => '#FFFFFF',
                        'borderWidth'  => '2px',
                        'borderColor'  => '#ECECEC',
                        'cornerRadius' => 'md',
                        'paddingAll'   => '8px',
                        'flex'         => 5,
                        'contents'     => [[
                            'type'        => 'image',
                            'url'         => 'https://sjgrip.com/backend/logo/bg.png',
                            'size'        => 'full',
                            'aspectMode'  => 'cover',
                            'aspectRatio' => '16:9',
                        ]],
                    ],
                    [
                        'type'            => 'box',
                        'layout'          => 'vertical',
                        'backgroundColor' => '#1976D2',
                        'cornerRadius'    => 'md',
                        'paddingAll'      => '12px',
                        'flex'            => 4,
                        'contents'        => [
                            [
                                'type'   => 'text',
                                'text'   => 'CHANGE',
                                'weight' => 'bold',
                                'size'   => 'xxl',
                                'color'  => '#FFFFFF',
                                'align'  => 'center',
                            ],
                            [
                                'type'   => 'text',
                                'text'   => 'Track your status',
                                'size'   => 'xs',
                                'color'  => '#BBDEFB',
                                'align'  => 'center',
                                'margin' => 'md',
                            ],
                        ],
                    ],
                ],
            ]],
        ],
        /*────────── BODY ──────────*/
        'body' => [
            'type'     => 'box',
            'layout'   => 'vertical',
            'spacing'  => 'md',
            'contents' => [
                /* แถบสถานะ (labels) */
                [
                    'type'     => 'box',
                    'layout'   => 'horizontal',
                    'contents' => $labels,
                ],
                ['type' => 'separator', 'margin' => 'md'],

                /* การ์ดข้อมูลหลัก + รูป */
                [
                    'type'     => 'box',
                    'layout'   => 'horizontal',
                    'spacing'  => 'md',
                    'contents' => [
                        /* ► คอลัมน์ซ้าย (ข้อความ) */
                        [
                            'type'            => 'box',
                            'layout'          => 'vertical',
                            'spacing'         => 'sm',
                            'backgroundColor' => '#F7F7F7',
                            'cornerRadius'    => 'sm',
                            'paddingAll'      => '10px',
                            'flex'            => 4,
                            'contents'        => [
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
                                [
                                    'type'  => 'text',
                                    'text'  => "👤 ฝ่ายที่รับผิดชอบ: {$rep['department']}",
                                    'size'  => 'sm',
                                    'wrap'  => true,
                                ],
                                [
                                    'type'  => 'text',
                                    'text'  => "❌ สาเหตุที่ยกเลิก: {$rep['deleted_reason']}",
                                    'size'  => 'sm',
                                    'color' => '#D32F2F',
                                    'wrap'  => true,
                                ],
                            ],
                        ],
                        /* ► คอลัมน์ขวา (รูป) */
                        [
                            'type'        => 'image',
                            'url'         => $rep['image_url'],
                            'size'        => 'lg',
                            'aspectRatio' => '3:4',
                            'aspectMode'  => 'cover',
                            'flex'        => 2,
                        ],
                    ],
                ],
            ],
        ],
        /*────────── FOOTER ──────────*/
        'footer' => [
            'type'     => 'box',
            'layout'   => 'vertical',
            'spacing'  => 'sm',
            'contents' => [[
                'type'   => 'button',
                'style'  => 'primary',
                'height' => 'sm',
                'action' => [
                    'type'  => 'uri',
                    'label' => 'แจ้งเรื่องใหม่',
                    'uri'   => 'https://sjgrip.com/backend',
                ],
            ]],
        ],
    ];
}

class NotFoundException extends RuntimeException {}