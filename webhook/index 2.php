<?php
/**
 * LINE Webhook – “CHANGE” แจ้งปัญหา
 * Flow   : เริ่มต้น ➜ ปัญหา ➜ สถานที่ ➜ ฝ่าย (Quick-Reply) ➜ รูปภาพ
 * Command: “ประวัติ” ➜ แสดงย้อนหลัง (Flex-carousel ดีไซน์เดียวกับที่กำหนด)
 *
 * Updated : 2025-05-21 – fix “invalid uri scheme” on history + harden upload
 * Style   : PSR-12 (4-space indent, strict_types, long-array syntax)
 */

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

/*───────────────────────────────────────────────────────────────────────────────
| 0. Config / constants
└──────────────────────────────────────────────────────────────────────────────*/
require_once __DIR__ . '/../config.php';          // $conn, $LINE_ACCESS_TOKEN
$accessToken = $LINE_ACCESS_TOKEN;

const DEPTS    = ['ฝ่ายอาคาร', 'สารสนเทศ (MIS)'];
const STATUSES = ['รอรับเรื่อง', 'กำลังดำเนินการ', 'เสร็จสิ้น'];
const COLORS   = ['#FF3B30', '#FFA500', '#00C853'];

/**
 * เติมโดเมนให้ url ที่ยังเป็น path สั้น ๆ (ป้องกัน invalid uri scheme)
 */
function normalizeUrl(string $u): string
{
    return preg_match('#^https?://#', $u)
        ? $u
        : 'https://sjgrip.com/backend/' . ltrim($u, '/\\');
}

/*───────────────────────────────────────────────────────────────────────────────
| 1. LINE API helpers
└──────────────────────────────────────────────────────────────────────────────*/
function lineApi(string $url, array $body): void
{
    global $accessToken;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Bearer {$accessToken}",
        ],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function reply(string $token, array $messages): void
{
    lineApi(
        'https://api.line.me/v2/bot/message/reply',
        ['replyToken' => $token, 'messages' => $messages],
    );
}

/*───────────────────────────────────────────────────────────────────────────────
| 2. Session helpers (table: user_sessions)
└──────────────────────────────────────────────────────────────────────────────*/
function loadSession(string $uid): array
{
    global $conn;

    $q = $conn->prepare(
        'SELECT step, message, location_text, department, updated_at
         FROM user_sessions
         WHERE user_id = ?'
    );
    $q->bind_param('s', $uid);
    $q->execute();
    $q->bind_result($step, $msg, $loc, $dept, $upd);

    $s = $q->fetch()
        ? ['step' => $step, 'msg' => $msg, 'loc' => $loc, 'dept' => $dept, 'upd' => $upd]
        : ['step' => 'none', 'msg' => null, 'loc' => null, 'dept' => null, 'upd' => null];

    $q->close();

    /* expire after 5 min inactivity */
    if ($s['step'] !== 'none' && (time() - strtotime($s['upd'])) > 300) {
        clearSession($uid);
        $s = ['step' => 'none', 'msg' => null, 'loc' => null, 'dept' => null];
    }

    return $s;
}

function saveSession(
    string  $uid,
    string  $step,
    ?string $msg  = null,
    ?string $loc  = null,
    ?string $dept = null,
): void {
    global $conn;

    $q = $conn->prepare(
        'REPLACE INTO user_sessions
            (user_id, step, message, location_text, department, updated_at)
         VALUES (?,?,?,?,?,NOW())'
    );
    $q->bind_param('sssss', $uid, $step, $msg, $loc, $dept);
    $q->execute();
    $q->close();
}

function clearSession(string $uid): void
{
    global $conn;

    $c = $conn->prepare('DELETE FROM user_sessions WHERE user_id = ?');
    $c->bind_param('s', $uid);
    $c->execute();
    $c->close();
}

/*───────────────────────────────────────────────────────────────────────────────
| 3. Quick-reply builder
└──────────────────────────────────────────────────────────────────────────────*/
function deptQuickReply(): array
{
    return [
        'items' => array_map(
            static fn(string $d): array => [
                'type'   => 'action',
                'action' => ['type' => 'message', 'label' => $d, 'text' => $d],
            ],
            DEPTS,
        ),
    ];
}

/*───────────────────────────────────────────────────────────────────────────────
| 4. Flex bubble builder
└──────────────────────────────────────────────────────────────────────────────*/
/**
 * @param array{message:string,location_text:string,image_url:string,
 *              status:string,created_at:string} $rep
 */
function buildFlexBubble(array $rep): array
{
    /* labels with colored current status */
    $labels = array_map(
        static fn(string $lbl, int $i): array => [
            'type'   => 'text',
            'text'   => $lbl,
            'flex'   => 1,
            'align'  => 'center',
            'weight' => 'bold',
            'size'   => 'sm',
            'color'  => $lbl === $rep['status'] ? COLORS[$i] : '#AAAAAA',
        ],
        STATUSES,
        array_keys(STATUSES),
    );

    return [
        'type'   => 'bubble',
        'styles' => ['body' => ['backgroundColor' => '#FFFFFF']],
        /* header */
        'header' => [
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
        /* hero */
        'hero' => [
            'type'     => 'box',
            'layout'   => 'vertical',
            'contents' => [[
                'type'   => 'box',
                'layout' => 'horizontal',
                'spacing'=> 'md',
                'contents' => [
                    [
                        'type'            => 'box',
                        'layout'          => 'horizontal',
                        'backgroundColor' => '#FFFFFF',
                        'borderWidth'     => '2px',
                        'borderColor'     => '#ECECEC',
                        'cornerRadius'    => 'md',
                        'paddingAll'      => '8px',
                        'flex'            => 5,
                        'contents'        => [[
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
        /* body */
        'body' => [
            'type'     => 'box',
            'layout'   => 'vertical',
            'spacing'  => 'md',
            'contents' => [
                /* status labels */
                [
                    'type'     => 'box',
                    'layout'   => 'horizontal',
                    'contents' => $labels,
                ],
                ['type' => 'separator', 'margin' => 'md'],
                /* info card */
                [
                    'type'     => 'box',
                    'layout'   => 'horizontal',
                    'spacing'  => 'md',
                    'contents' => [
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
                            ],
                        ],
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
                    'uri'   => 'https://sjgrip.com/backend',
                ],
            ]],
        ],
    ];
}

/*───────────────────────────────────────────────────────────────────────────────
| 5. Main event loop
└──────────────────────────────────────────────────────────────────────────────*/
$events = json_decode(file_get_contents('php://input'), true)['events'] ?? [];

foreach ($events as $ev) {

    $uid        = $ev['source']['userId'] ?? '';
    $replyToken = $ev['replyToken']       ?? '';

    $s    = loadSession($uid);
    $step = $s['step'];
    $msg  = $s['msg'];
    $loc  = $s['loc'];
    $dept = $s['dept'];

    /*━━━━━━━━━━ TEXT ━━━━━━━━━━*/
    if ($ev['type'] === 'message' && ($ev['message']['type'] ?? '') === 'text') {

        $text = trim($ev['message']['text']);

        /* history */
        if ($text === 'ประวัติ') {

            $q = $conn->prepare(
                'SELECT message, location_text, image_url, status, created_at
                 FROM reports
                 WHERE user_id = ?
                 ORDER BY created_at DESC
                 LIMIT 10'
            );
            $q->bind_param('s', $uid);
            $q->execute();
            $rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
            $q->close();

            if (!$rows) {
                reply($replyToken, [['type' => 'text', 'text' => 'ยังไม่มีประวัติการแจ้งปัญหาครับ']]);
                continue;
            }

            /* เติม scheme ให้รูปเก่า ๆ ที่ยังเป็น path สั้น */
            foreach ($rows as &$r) {
                $r['image_url'] = normalizeUrl($r['image_url']);
            }

            reply($replyToken, [[
                'type'     => 'flex',
                'altText'  => 'ประวัติการแจ้งปัญหา',
                'contents' => [
                    'type'     => 'carousel',
                    'contents' => array_map('buildFlexBubble', $rows),
                ],
            ]]);
            continue;
        }

        /* flow start */
        if ($text === 'เริ่มต้น') {
            saveSession($uid, 'waiting_problem');
            reply($replyToken, [['type' => 'text', 'text' => 'แจ้ง “ปัญหา” ได้เลยครับ']]);
            continue;
        }

        switch ($step) {
            case 'waiting_problem':
                saveSession($uid, 'waiting_place', $text);
                reply($replyToken, [['type' => 'text', 'text' => 'ที่ไหนครับ (เช่น ห้องประชุม, อาคาร A)']]);
                break;

            case 'waiting_place':
                saveSession($uid, 'waiting_dept', $msg, $text);
                reply($replyToken, [[
                    'type'       => 'text',
                    'text'       => 'เลือกฝ่ายที่รับผิดชอบครับ',
                    'quickReply' => deptQuickReply(),
                ]]);
                break;

            case 'waiting_dept':
                if (!in_array($text, DEPTS, true)) {
                    reply($replyToken, [[
                        'type'       => 'text',
                        'text'       => 'กรุณากดปุ่มเพื่อเลือกฝ่ายเท่านั้นครับ',
                        'quickReply' => deptQuickReply(),
                    ]]);
                    break;
                }
                saveSession($uid, 'waiting_image', $msg, $loc, $text);
                reply($replyToken, [['type' => 'text', 'text' => 'ส่งรูปประกอบมาได้เลยครับ']]);
                break;

            case 'waiting_image':
                reply($replyToken, [['type' => 'text', 'text' => 'กรุณาส่ง “รูปภาพ” เท่านั้นครับ']]);
                break;

            default:
                reply($replyToken, [['type' => 'text', 'text' => 'พิมพ์ “เริ่มต้น” เพื่อแจ้งปัญหาครับ']]);
        }
        continue;
    }

    /*━━━━━━━━━━ IMAGE ━━━━━━━━━━*/
    if ($ev['type'] === 'message' && ($ev['message']['type'] ?? '') === 'image') {

        if ($step !== 'waiting_image') {
            reply($replyToken, [['type' => 'text', 'text' => 'ขณะนี้ต้องการข้อความ ไม่ใช่รูปภาพครับ']]);
            continue;
        }

        /* download image from LINE */
        $mid = $ev['message']['id'];
        $ch  = curl_init("https://api-data.line.me/v2/bot/message/{$mid}/content");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$accessToken}"],
        ]);
        $bin = curl_exec($ch);
        curl_close($ch);

        /* ensure uploads dir */
        $uploadDir = __DIR__ . '/../uploads/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            reply($replyToken, [['type' => 'text', 'text' => 'ไม่สามารถสร้างโฟลเดอร์อัปโหลดได้']]);
            continue;
        }

        /* save file */
        $file = uniqid('img_', true) . '.jpg';
        if (file_put_contents($uploadDir . $file, $bin) === false) {
            reply($replyToken, [['type' => 'text', 'text' => 'บันทึกไฟล์ไม่สำเร็จ']]);
            continue;
        }
        $url = "https://sjgrip.com/backend/uploads/{$file}";

        /* insert report */
        $ins = $conn->prepare(
            'INSERT INTO reports
                (user_id, message, department, location_text, image_url, status)
             VALUES (?,?,?,?,?,?)'
        );
        $status = 'รอรับเรื่อง';
        $ins->bind_param('ssssss', $uid, $msg, $dept, $loc, $url, $status);
        $ins->execute();
        $ins->close();

        /* send receipt */
        $bubble = buildFlexBubble([
            'message'       => $msg,
            'location_text' => $loc,
            'image_url'     => $url,
            'status'        => $status,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        reply($replyToken, [[
            'type'     => 'flex',
            'altText'  => 'รับเรื่องแล้ว',
            'contents' => $bubble,
        ]]);
        clearSession($uid);
        continue;
    }

    /*━━━━━━━━━━ fallback ━━━━━━━━━━*/
    if ($replyToken !== '') {
        reply($replyToken, [['type' => 'text', 'text' => 'ขออภัย ยังไม่รองรับข้อความประเภทนี้ครับ']]);
    }
}
?>