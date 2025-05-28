<?php
/**
 * LINE Webhook – “CHANGE” แจ้งปัญหา
 * Flow   : เริ่มต้น ➜ ปัญหา ➜ อาคาร ➜ ชั้น ➜ ห้อง/พื้นที่ ➜ ฝ่าย ➜ รูปภาพ
 * Command: “ประวัติ” ➜ แสดงย้อนหลัง (Flex-carousel)
 *
 * Updated : 2025-05-27 – ปรับแก้ saveSession signature และ replyToken
 */

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php'; // provides $conn (mysqli) & $LINE_ACCESS_TOKEN
$accessToken = $LINE_ACCESS_TOKEN;

// Google Chat webhook URL
define('GOOGLE_NOTIFY_URL',
    'https://chat.googleapis.com/v1/spaces/AAQAFyUpo3I/messages'
  . '?key=AIzaSyDdI0hCZtE6vySjMm-WEfRq3CPzqKqqsHI'
  . '&token=Hdd7S6QQvcI1pvDfUiy-ocrlwhtpJCrgB7n6Dd1y0H0'
);

const DEPTS    = ['ฝ่ายอาคาร', 'สารสนเทศ (MIS)'];
const STATUSES = ['รอรับเรื่อง', 'กำลังดำเนินการ', 'เสร็จสิ้น'];
const COLORS   = ['#FF3B30', '#FFA500', '#00C853'];

/**
 * เติมโดเมนให้ URL ที่ยังเป็น path สั้น ๆ (ป้องกัน invalid uri scheme)
 */
function normalizeUrl(string $u): string
{
    return preg_match('#^https?://#', $u)
        ? $u
        : 'https://sjgrip.com/backend/' . ltrim($u, '/\\');
}

// LINE API helpers
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
    lineApi('https://api.line.me/v2/bot/message/reply', [
        'replyToken' => $token,
        'messages'   => $messages,
    ]);
}

// Google Chat notifier
function googleChat(string $text): void
{
    if (empty(GOOGLE_NOTIFY_URL)) {
        return;
    }
    $ch = curl_init(GOOGLE_NOTIFY_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode(['text' => $text], JSON_UNESCAPED_UNICODE),
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// Bad-word filter
function containsBadWord(mysqli $conn, string $text): bool
{
    $normalized = mb_strtolower($text, 'UTF-8');
    $sql        = "SELECT 1 FROM bad_words WHERE ? LIKE CONCAT('%', word, '%') LIMIT 1";
    $stmt       = $conn->prepare($sql);
    $stmt->bind_param('s', $normalized);
    $stmt->execute();
    $stmt->store_result();
    $found = $stmt->num_rows > 0;
    $stmt->close();
    return $found;
}

// Session helpers (table: user_sessions)
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

    $session = $q->fetch()
        ? ['step' => $step, 'msg' => $msg, 'loc' => $loc, 'dept' => $dept, 'upd' => $upd]
        : ['step' => 'none', 'msg' => null, 'loc' => null, 'dept' => null, 'upd' => null];

    $q->close();

    // หมดเวลา session หลัง 5 นาที
    if ($session['step'] !== 'none' && (time() - strtotime($session['upd'])) > 300) {
        clearSession($uid);
        $session = ['step' => 'none', 'msg' => null, 'loc' => null, 'dept' => null];
    }

    return $session;
}

/**
 * @param array{step:string, msg?:string, loc?:string, dept?:string} $data
 */
function saveSession(string $uid, array $data): void
{
    global $conn;
    $step = $data['step'] ?? '';
    $msg  = $data['msg']  ?? null;
    $loc  = $data['loc']  ?? null;
    $dept = $data['dept'] ?? null;

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

// Quick-reply builder
function deptQuickReply(): array
{
    return [
        'items' => array_map(
            static fn(string $d): array => [
                'type'   => 'action',
                'action' => ['type' => 'message', 'label' => $d, 'text' => $d],
            ],
            DEPTS
        ),
    ];
}

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

// Main webhook loop
$events = json_decode(file_get_contents('php://input'), true)['events'] ?? [];

foreach ($events as $ev) {
    $uid        = $ev['source']['userId'] ?? '';
    $replyToken = $ev['replyToken']   ?? '';
    $type       = $ev['type']         ?? '';
    $msgType    = $ev['message']['type'] ?? '';

    // — Text messages —
    if ($type === 'message' && $msgType === 'text') {
        $text = trim($ev['message']['text']);

        // 0. Bad-word filter
        if (containsBadWord($conn, $text)) {
            // สร้าง Flex bubble เตือนคำไม่สุภาพ (ใช้ image แทน icon)
            $bubble = [
                'type'   => 'bubble',
                'styles' => [
                    'header' => [
                        'backgroundColor' => '#FF3B30',
                        'separator'       => false,
                    ],
                    'body'   => [
                        'backgroundColor' => '#FFF5F5',
                    ],
                    'footer' => [
                        'separator' => true,
                    ],
                ],
                'header' => [
                    'type'       => 'box',
                    'layout'     => 'horizontal',
                    'contents'   => [
                        // โลโก้ SBAC-FIXIT
                        [
                            'type'        => 'image',
                            'url'         => 'https://sjgrip.com/backend/logo/warning.png',
                            'size'        => 'xxs',
                            'aspectMode'  => 'cover',
                            'aspectRatio' => '1:1',
                        ],
                        // ข้อความแจ้งเตือน
                        [
                            'type'   => 'text',
                            'text'   => 'ระบบตรวจพบคำไม่สุภาพ',
                            'flex'   => 3,
                            'weight' => 'bold',
                            'color'  => '#FFFFFF',
                            'size'   => 'md',
                            'wrap'   => true,
                            'margin' => 'md',
                            'align'  => 'start',
                        ],
                    ],
                    'paddingAll'   => 'lg',
                    'spacing'      => 'md',
                    'cornerRadius' => 'lg', // มุมโค้งด้านบน
                ],
                'body' => [
                    'type'     => 'box',
                    'layout'   => 'vertical',
                    'contents' => [
                        [
                            'type' => 'separator',
                            'margin' => 'none',
                        ],
                        [
                            'type'   => 'text',
                            'text'   => 'โปรดกดปุ่ม “เริ่มใหม่” ด้านล่าง เพื่อเริ่มต้นใหม่',
                            'size'   => 'sm',
                            'color'  => '#555555',
                            'wrap'   => true,
                            'align'  => 'center',
                            'margin' => 'md',
                        ],
                    ],
                    'paddingAll' => 'md',
                ],
                'footer' => [
                    'type'     => 'box',
                    'layout'   => 'vertical',
                    'spacing'  => 'sm',
                    'contents' => [[
                        'type'   => 'button',
                        'style'  => 'primary',
                        'color'  => '#FF3B30',
                        'action' => [
                            'type'  => 'message',
                            'label' => 'เริ่มใหม่',
                            'text'  => 'เริ่มต้น',
                        ],
                    ]],
                    'paddingAll' => 'md',
                ],
            ];

            reply($replyToken, [[
                'type'     => 'flex',
                'altText'  => 'คำไม่สุภาพ ถูกตรวจพบ',
                'contents' => $bubble,
            ]]);

            clearSession($uid);
            continue;
        }

        // 1. History
        if ($text === 'ประวัติ') {
            // ... (history logic unchanged)
            continue;
        }

        // 2. Load session
        $session = loadSession($uid);
        $step    = $session['step'];

        // 3. Start flow
        if ($text === 'เริ่มต้น') {
            saveSession($uid, ['step' => 'waiting_problem']);
            reply($replyToken, [[
                'type' => 'text',
                'text' => 'แจ้ง “ปัญหา” ได้เลยครับ'
            ]]);
            continue;
        }

        // 4. State machine
        switch ($step) {
            case 'waiting_problem':
                saveSession($uid, ['step' => 'waiting_building', 'msg' => $text]);
                reply($replyToken, [[
                    'type' => 'text',
                    'text' => 'กรุณาเลือกอาคารที่พบปัญหาครับ',
                    'quickReply' => [
                        'items' => array_map(
                            fn($lbl) => [
                                'type'   => 'action',
                                'action' => ['type' => 'message', 'label' => $lbl, 'text' => $lbl],
                            ],
                            ['ตึก 1','ตึก 2','Upperspace','Event Space','ลานเวทีคนเก่ง','อื่นๆ']
                        )
                    ],
                ]]);
                break;

            case 'waiting_building':
                // ตึก 1-2 => ชั้น, อื่นๆ => ขอรายละเอียด
                if (in_array($text, ['ตึก 1', 'ตึก 2'], true)) {
                    saveSession($uid, ['step' => 'waiting_floor', 'msg' => $session['msg'], 'loc' => $text]);
                    // สร้าง quickReply ชั้น
                    $labels = $text === 'ตึก 1' ? range(1, 4) : range(2, 7);
                    reply($replyToken, [[
                        'type' => 'text',
                        'text' => "เลือกชั้นของ {$text} ครับ",
                        'quickReply'=>[
                            'items'=>array_map(
                                fn($n)=>['type'=>'action','action'=>['type'=>'message','label'=>"$n",'text'=>"$n"]],
                                $labels
                            )
                        ],
                    ]]);
                } else {
                    // สถานที่อื่นๆ
                    saveSession($uid, ['step' => 'waiting_dept', 'msg' => $session['msg'], 'loc' => $text]);
                    reply($replyToken, [[
                        'type' => 'text',
                        'text' => 'เลือกฝ่ายที่รับผิดชอบครับ',
                        'quickReply'=> deptQuickReply(),
                    ]]);
                }
                break;

            case 'waiting_floor':
                // รวมอาคาร+ชั้น เป็น loc
                $newLoc = $session['loc'] . ' ชั้น ' . $text;
                saveSession($uid, ['step'=>'waiting_room','msg'=>$session['msg'],'loc'=>$newLoc]);
                // สร้าง quickReply ห้อง
                $max   = strpos($session['loc'], 'ตึก 1') !== false ? 10 : 12;
                $rooms = [];
                for ($i = 1; $i <= $max; $i++) {
                    $rooms[] = "{$text}" . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
                }
                if (strpos($session['loc'], 'ตึก 1') !== false) {
                    $rooms[] = 'ห้องสมุด';
                }
                reply($replyToken, [[
                    'type'=>'text',
                    'text'=> "เลือกเลขห้องของ {$session['loc']} ครับ",
                    'quickReply'=>[
                        'items'=>array_map(
                            fn($r)=>['type'=>'action','action'=>['type'=>'message','label'=>$r,'text'=>$r]],
                            $rooms
                        )
                    ],
                ]]);
                break;

            case 'waiting_room':
                // รวม loc + ห้อง
                $finalLoc = $session['loc'] . ' ห้อง ' . $text;
                saveSession($uid, ['step'=>'waiting_dept','msg'=>$session['msg'],'loc'=>$finalLoc]);
                reply($replyToken, [[
                    'type'=>'text',
                    'text'=>'เลือกฝ่ายที่รับผิดชอบครับ',
                    'quickReply'=> deptQuickReply(),
                ]]);
                break;

            case 'waiting_dept':
                if (!in_array($text, DEPTS, true)) {
                    reply($replyToken, [[
                        'type'=>'text',
                        'text'=>'กรุณากดปุ่มเพื่อเลือกฝ่ายเท่านั้นครับ',
                        'quickReply'=> deptQuickReply(),
                    ]]);
                } else {
                    saveSession($uid, ['step'=>'waiting_image','msg'=>$session['msg'],'loc'=>$session['loc'],'dept'=>$text]);
                    reply($replyToken, [[
                        'type'=>'text',
                        'text'=>'ส่งรูปประกอบปัญหามาได้เลยครับ'
                    ]]);
                }
                break;

            default:
                reply($replyToken, [[
                    'type'=>'text',
                    'text'=>'พิมพ์ “เริ่มต้น” เพื่อแจ้งปัญหาครับ'
                ]]);
                break;
        }

        continue;
    }

    // — Image messages —
    if ($type === 'message' && $msgType === 'image') {
        $session = loadSession($uid);

        if ($session['step'] !== 'waiting_image') {
            // ไม่ได้รอรูป
            reply($replyToken, [[
                'type'=>'text',
                'text'=>'กรุณาทำตามขั้นตอนโดยพิมพ์ “เริ่มต้น” ก่อนครับ'
            ]]);
            continue;
        }

        // ดาวน์โหลดภาพจาก LINE
        $mid = $ev['message']['id'];
        $ch  = curl_init("https://api-data.line.me/v2/bot/message/{$mid}/content");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$accessToken}"],
        ]);
        $bin = curl_exec($ch);
        curl_close($ch);

        // บันทึกไฟล์
        $uploadDir = __DIR__ . '/../uploads/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            reply($replyToken, [['type'=>'text','text'=>'ไม่สามารถสร้างโฟลเดอร์อัปโหลดได้']]);
            continue;
        }
        $file = uniqid('img_', true) . '.jpg';
        if (file_put_contents($uploadDir . $file, $bin) === false) {
            reply($replyToken, [['type'=>'text','text'=>'บันทึกไฟล์ไม่สำเร็จ']]);
            continue;
        }
        $url = normalizeUrl("uploads/{$file}");

        // บันทึกใน DB
        $ins    = $conn->prepare(
            'INSERT INTO reports
                (user_id, message, department, location_text, image_url, status)
             VALUES (?,?,?,?,?,?)'
        );
        $status = 'รอรับเรื่อง';
        $ins->bind_param(
            'ssssss',
            $uid,
            $session['msg'],
            $session['dept'],
            $session['loc'],
            $url,
            $status
        );
        $ins->execute();
        $ins->close();

        // แจ้ง Google Chat
        googleChat(
            "*🛠 New Problem!*  \n"
          . "นักศึกษา: {$uid}\n"
          . "ปัญหา: {$session['msg']}\n"
          . "แผนก: {$session['dept']}\n"
          . "สถานที่: {$session['loc']}\n"
          . "สถานะ: {$status}\n"
          . "URL: {$url}"
        );

        // ส่ง Flex receipt
        $bubble = buildFlexBubble([
            'message'    => $session['msg'],
            'location_text' => $session['loc'],
            'image_url'  => $url,
            'status'     => $status,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        reply($replyToken, [[
            'type'    => 'flex',
            'altText' => 'รับเรื่องแล้ว',
            'contents'=> $bubble,
        ]]);

        // จบ flow
        saveSession($uid, [
            'step'=>'completed',
            'msg'=>$session['msg'],
            'loc'=>$session['loc'],
            'dept'=>$session['dept'],
        ]);

        continue;
    }

    // — Fallback —
    if ($replyToken) {
    $bubble = [
            'type'   => 'bubble',
            'styles' => [
                'header' => [
                    'backgroundColor' => '#FFC107',  // สีพื้นหลังเหลือง
                    'separator'       => false,
                ],
            ],
            'header' => [
                'type'       => 'box',
                'layout'     => 'vertical',
                'contents'   => [
                    [
                        'type'        => 'image',
                        'url'         => 'https://sjgrip.com/backend/logo/warning.png',
                        'size'        => 'md',
                        'aspectMode'  => 'fit',
                        'align'       => 'center',
                    ],
                    [
                        'type'   => 'text',
                        'text'   => 'ขออภัยระบบยังไม่รองรับข้อความประเภทนี้ครับ',
                        'size'   => 'lg',
                        'weight' => 'bold',
                        'color'  => '#FFFFFF',
                        'align'  => 'center',
                        'wrap'   => true,
                        'margin' => 'md',
                    ],
                ],
                'paddingAll'   => 'lg',
                'spacing'      => 'md',
                'cornerRadius' => 'lg',
            ],
        ];

        reply($replyToken, [[
            'type'     => 'flex',
            'altText'  => 'เตือน: ขออภัยระบบยังไม่รองรับข้อความประเภทนี้ครับ',
            'contents' => $bubble,
        ]]);
    }
}