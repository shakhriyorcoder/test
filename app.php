<?php
/**
 * ╔══════════════════════════════════════════════════╗
 *  TEST TEKSHIRUVCHI BOT  |  app.php
 *  PHP 7.4+  |  SQLite3  |  Webhook
 * ╚══════════════════════════════════════════════════╝
 *
 *  O'rnatish:
 *  1) BOT_TOKEN va ADMIN_IDS ni to'ldiring
 *  2) Webhook:
 *     https://api.telegram.org/botTOKEN/setWebhook?url=https://sayt.uz/app.php
 */

// ═══════════════════════════════════════════════════
//   SOZLAMALAR  —  FAQAT SHU QISMNI TO'LDIRING
// ═══════════════════════════════════════════════════
define('BOT_TOKEN', '8299663329:AAEW0rSjkGZQvQ_s7DBC8KYPDuYucTS1Gws');  // BotFather tokeni
define('ADMIN_IDS', [8201674543]);             // Admin Telegram ID (massiv)
// ═══════════════════════════════════════════════════

define('TG_API', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('DB_FILE', __DIR__ . '/bot.sqlite');

ini_set('display_errors', '0');
ini_set('log_errors',     '1');
error_reporting(E_ALL);

// ═══════════════════════════════════════════════════
//   DATABASE
// ═══════════════════════════════════════════════════
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL;');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tests (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            test_num     TEXT UNIQUE NOT NULL,
            title        TEXT NOT NULL DEFAULT '',
            answers      TEXT NOT NULL DEFAULT '{}',
            dur_min      INTEGER NOT NULL DEFAULT 0,
            created_at   TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );
        CREATE TABLE IF NOT EXISTS users (
            id           INTEGER PRIMARY KEY,
            username     TEXT NOT NULL DEFAULT '',
            first_name   TEXT NOT NULL DEFAULT '',
            last_name    TEXT NOT NULL DEFAULT '',
            joined_at    TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );
        CREATE TABLE IF NOT EXISTS results (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id      INTEGER NOT NULL,
            test_num     TEXT NOT NULL,
            user_ans     TEXT NOT NULL DEFAULT '{}',
            correct      INTEGER NOT NULL DEFAULT 0,
            wrong        INTEGER NOT NULL DEFAULT 0,
            empty_q      INTEGER NOT NULL DEFAULT 0,
            total        INTEGER NOT NULL DEFAULT 0,
            score        REAL NOT NULL DEFAULT 0,
            elapsed      INTEGER NOT NULL DEFAULT 0,
            created_at   TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );
        CREATE TABLE IF NOT EXISTS channels (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            chan_id      TEXT UNIQUE NOT NULL,
            chan_user    TEXT NOT NULL DEFAULT '',
            chan_title   TEXT NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS sessions (
            user_id      INTEGER PRIMARY KEY,
            state        TEXT NOT NULL DEFAULT '',
            sdata        TEXT NOT NULL DEFAULT '{}',
            updated_at   TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );
    ");
    return $pdo;
}

// Qisqa query yordamchi
function q(string $sql, array $p = []): \PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st;
}

// ═══════════════════════════════════════════════════
//   TELEGRAM API
// ═══════════════════════════════════════════════════
function tg(string $method, array $params = []): array {
    $ch = curl_init(TG_API . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) { error_log("CURL[$method]: $err"); return ['ok' => false]; }
    $r = json_decode($raw, true);
    if (!is_array($r)) return ['ok' => false];
    if (!($r['ok'] ?? false)) error_log("TG[$method]: " . ($r['description'] ?? $raw));
    return $r;
}

// reply_markup ni array qilib qabul qiladi, json_encode qilib yuboradi
function sendMsg(int $chat, string $text, array $markup = []): array {
    $p = ['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($markup) $p['reply_markup'] = json_encode($markup, JSON_UNESCAPED_UNICODE);
    return tg('sendMessage', $p);
}

function editMsg(int $chat, int $mid, string $text, array $markup = []): array {
    $p = ['chat_id' => $chat, 'message_id' => $mid, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($markup) $p['reply_markup'] = json_encode($markup, JSON_UNESCAPED_UNICODE);
    return tg('editMessageText', $p);
}

function editMarkup(int $chat, int $mid, array $markup): array {
    return tg('editMessageReplyMarkup', [
        'chat_id'      => $chat,
        'message_id'   => $mid,
        'reply_markup' => json_encode($markup, JSON_UNESCAPED_UNICODE),
    ]);
}

function ackCb(string $cbId, string $text = '', bool $alert = false): void {
    tg('answerCallbackQuery', [
        'callback_query_id' => $cbId,
        'text'              => $text,
        'show_alert'        => $alert,
    ]);
}

function sendDoc(int $chat, string $path, string $caption = ''): array {
    $ch = curl_init(TG_API . 'sendDocument');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'chat_id'    => $chat,
            'document'   => new CURLFile($path),
            'caption'    => $caption,
            'parse_mode' => 'HTML',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $r = json_decode($raw, true);
    if (!($r['ok'] ?? false)) error_log('sendDoc ERR: ' . ($r['description'] ?? ''));
    return is_array($r) ? $r : ['ok' => false];
}

// ═══════════════════════════════════════════════════
//   SESSION
// ═══════════════════════════════════════════════════
function getSess(int $uid): array {
    $row = q("SELECT state, sdata FROM sessions WHERE user_id=?", [$uid])->fetch();
    if (!$row) return ['state' => '', 'data' => []];
    return [
        'state' => (string)$row['state'],
        'data'  => json_decode($row['sdata'] ?? '{}', true) ?: [],
    ];
}

function setSess(int $uid, string $state, array $data = []): void {
    q("INSERT OR REPLACE INTO sessions (user_id,state,sdata,updated_at) VALUES (?,?,?,datetime('now','localtime'))",
      [$uid, $state, json_encode($data, JSON_UNESCAPED_UNICODE)]);
}

function delSess(int $uid): void {
    q("DELETE FROM sessions WHERE user_id=?", [$uid]);
}

// ═══════════════════════════════════════════════════
//   YORDAMCHI FUNKSIYALAR
// ═══════════════════════════════════════════════════
function isAdmin(int $uid): bool {
    return in_array($uid, ADMIN_IDS, true);
}

function saveUser(array $from): void {
    q("INSERT OR REPLACE INTO users (id,username,first_name,last_name) VALUES (?,?,?,?)", [
        (int)$from['id'],
        $from['username']   ?? '',
        $from['first_name'] ?? '',
        $from['last_name']  ?? '',
    ]);
}

function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// Kanalga obuna bormi?
function notSubscribed(int $uid): array {
    $all = q("SELECT chan_id, chan_user, chan_title FROM channels")->fetchAll();
    $out = [];
    foreach ($all as $ch) {
        $r = tg('getChatMember', ['chat_id' => $ch['chan_id'], 'user_id' => $uid]);
        $s = $r['result']['status'] ?? 'left';
        if (in_array($s, ['left', 'kicked'], true)) $out[] = $ch;
    }
    return $out;
}

// Test javoblarini parse qilish
function parseAnswers(string $raw): array {
    $raw = mb_strtolower(trim($raw));
    $ans = [];

    // "1a 2b 3c" yoki "1-a" yoki "1.a" yoki "1) a"
    if (preg_match_all('/(\d+)\s*[-\.\)]\s*([a-e])/u', $raw, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) $ans[(int)$x[1]] = $x[2];
        if ($ans) return $ans;
    }
    // "1 a 2 b 3 c" — raqam bo'shliq harf
    if (preg_match_all('/(\d+)\s+([a-e])(?:\s|$)/u', $raw, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) $ans[(int)$x[1]] = $x[2];
        if ($ans) return $ans;
    }
    // "1a 2b 3c" — raqam harf yopishib
    if (preg_match_all('/(\d+)([a-e])/u', $raw, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) $ans[(int)$x[1]] = $x[2];
        if ($ans) return $ans;
    }
    // "abcde..." — ketma-ket faqat harflar
    $clean = preg_replace('/[^a-e]/u', '', $raw);
    if ($clean !== '') {
        foreach (str_split($clean) as $i => $c) $ans[$i + 1] = $c;
    }
    return $ans;
}

function gradeAnswers(array $correct, array $user): array {
    $total = count($correct);
    $ok = $wr = $em = 0;
    $det = [];
    for ($i = 1; $i <= $total; $i++) {
        $c = $correct[$i] ?? '?';
        $u = $user[$i] ?? '';
        if ($u === '') {
            $em++;
            $det[$i] = ['s' => 'e', 'c' => $c, 'u' => '-'];
        } elseif ($u === $c) {
            $ok++;
            $det[$i] = ['s' => 'ok', 'c' => $c, 'u' => $u];
        } else {
            $wr++;
            $det[$i] = ['s' => 'wr', 'c' => $c, 'u' => $u];
        }
    }
    $score = $total > 0 ? round($ok / $total * 100, 1) : 0.0;
    return ['correct' => $ok, 'wrong' => $wr, 'empty' => $em,
            'total' => $total, 'score' => $score, 'details' => $det];
}

function pBar(float $s): string {
    $f = (int)round($s / 10);
    return str_repeat('▓', $f) . str_repeat('░', 10 - $f) . "  {$s}%";
}

function resultTxt(array $r, string $title, string $num, int $sec = 0): string {
    $e = $r['score'] >= 85 ? '🏆' : ($r['score'] >= 70 ? '🎯' : ($r['score'] >= 50 ? '📊' : '😔'));
    $t = $title ? "\n📌 " . esc($title) : '';
    $el = $sec > 0 ? "\n⏱ Vaqt: <b>" . gmdate('i:s', $sec) . "</b>" : '';
    $msg = "╔══════════════════════╗\n"
         . "   {$e} <b>TEST NATIJASI</b>\n"
         . "╚══════════════════════╝\n\n"
         . "📋 Test: <b>#" . esc($num) . "</b>{$t}{$el}\n\n"
         . "✅ To'g'ri:    <b>{$r['correct']} ta</b>\n"
         . "❌ Noto'g'ri:  <b>{$r['wrong']} ta</b>\n"
         . "⬜ Bo'sh:      <b>{$r['empty']} ta</b>\n"
         . "📝 Jami:       <b>{$r['total']} ta</b>\n\n"
         . pBar($r['score']) . "\n\n";
    if ($r['score'] >= 85)     $msg .= "🌟 <i>Ajoyib natija! Zo'rsiz!</i>";
    elseif ($r['score'] >= 70) $msg .= "👍 <i>Yaxshi natija!</i>";
    elseif ($r['score'] >= 50) $msg .= "📚 <i>Ko'proq o'qing!</i>";
    else                        $msg .= "💪 <i>Harakat qiling, uddalaysiz!</i>";
    return $msg;
}

function topTxt(int $lim): string {
    $rows = q("
        SELECT u.first_name, u.last_name, u.username,
               COUNT(r.id)           AS cnt,
               ROUND(AVG(r.score),1) AS avg_s,
               MAX(r.score)          AS max_s
        FROM results r JOIN users u ON r.user_id=u.id
        GROUP BY r.user_id
        ORDER BY avg_s DESC, cnt DESC
        LIMIT ?
    ", [$lim])->fetchAll();

    if (!$rows) return "📭 Hali hech kim test topshirmagan.";
    $medals = ['🥇','🥈','🥉'];
    $t = "🏆 <b>TOP {$lim} FOYDALANUVCHI</b>\n━━━━━━━━━━━━━━━━━━━━\n\n";
    foreach ($rows as $i => $u) {
        $m    = $medals[$i] ?? '🎖️';
        $name = esc(trim($u['first_name'] . ' ' . $u['last_name'])) ?: "Noma'lum";
        $un   = $u['username'] ? " <i>(@{$u['username']})</i>" : '';
        $t   .= "{$m} <b>{$name}</b>{$un}\n";
        $t   .= "    📊 O'rtacha: <b>{$u['avg_s']}%</b>  |  Max: <b>{$u['max_s']}%</b>  |  📝 <b>{$u['cnt']}</b> ta\n\n";
    }
    return $t;
}

function makeCSV(string $tn): string {
    $rows = q("
        SELECT u.first_name, u.last_name, u.username,
               r.correct, r.wrong, r.empty_q, r.total, r.score, r.elapsed, r.created_at
        FROM results r LEFT JOIN users u ON r.user_id=u.id
        WHERE r.test_num=?
        ORDER BY r.score DESC, r.created_at ASC
    ", [$tn])->fetchAll();

    $file = sys_get_temp_dir() . '/testbot_' . preg_replace('/\W+/', '_', $tn) . '_' . time() . '.csv';
    $fp   = fopen($file, 'w');
    fputs($fp, "\xEF\xBB\xBF"); // UTF-8 BOM (Excel uchun)
    fputcsv($fp, ['#', 'Ism', 'Familiya', 'Username', "To'g'ri", "Noto'g'ri", "Bo'sh", 'Jami', 'Ball(%)', 'Vaqt(s)', 'Sana'], ';');
    foreach ($rows as $i => $r) {
        fputcsv($fp, [
            $i + 1,
            $r['first_name'], $r['last_name'],
            $r['username'] ? '@' . $r['username'] : '-',
            $r['correct'], $r['wrong'], $r['empty_q'],
            $r['total'], $r['score'], $r['elapsed'], $r['created_at'],
        ], ';');
    }
    fclose($fp);
    return $file;
}

function doBroadcast(array $fromMsg, bool $fwd): array {
    $uids = q("SELECT id FROM users")->fetchAll(\PDO::FETCH_COLUMN);
    $ok = $fail = 0;
    foreach ($uids as $uid) {
        $params = ['chat_id' => $uid, 'from_chat_id' => $fromMsg['chat']['id'], 'message_id' => $fromMsg['message_id']];
        $r = $fwd ? tg('forwardMessage', $params) : tg('copyMessage', $params);
        ($r['ok'] ?? false) ? $ok++ : $fail++;
        usleep(35000);
    }
    return ['ok' => $ok, 'fail' => $fail];
}

// Test info matni
function testInfoTxt(string $tn): string {
    $test = q("SELECT * FROM tests WHERE test_num=?", [$tn])->fetch();
    if (!$test) return "❌ Test topilmadi.";
    $total = count(json_decode($test['answers'], true) ?: []);
    $cnt   = q("SELECT COUNT(*) FROM results WHERE test_num=?", [$tn])->fetchColumn();
    $dur   = $test['dur_min'] > 0 ? "{$test['dur_min']} daqiqa" : "Cheklovsiz";
    return "📋 <b>Test #" . esc($tn) . "</b>\n\n"
         . "📌 Sarlavha: <b>" . (esc($test['title']) ?: '<i>Yo\'q</i>') . "</b>\n"
         . "📊 Savollar: <b>{$total} ta</b>\n"
         . "⏱ Vaqt chegarasi: <b>{$dur}</b>\n"
         . "👥 Topshirganlar: <b>{$cnt} kishi</b>";
}

// ═══════════════════════════════════════════════════
//   KEYBOARD BUILDERS
// ═══════════════════════════════════════════════════

// Reply keyboard (pastki menyu)
function mainKb(bool $admin = false): array {
    $rows = [
        [['text' => '📝 Test topshirish'], ['text' => '📊 Natijalarim']],
        [['text' => "🏆 Top o'yinchilar"], ['text' => 'ℹ️ Yordam']],
    ];
    if ($admin) $rows[] = [['text' => '⚙️ Admin panel']];
    return ['keyboard' => $rows, 'resize_keyboard' => true];
}

function cancelKb(): array {
    return ['keyboard' => [[['text' => '❌ Bekor qilish']]], 'resize_keyboard' => true];
}

// Inline keyboard (tugmali menyu)
function subKb(array $channels): array {
    $rows = [];
    foreach ($channels as $ch) {
        $label = $ch['chan_title'] ?: $ch['chan_user'] ?: 'Kanal';
        $uname = ltrim($ch['chan_user'] ?? '', '@');
        if ($uname) {
            $rows[] = [['text' => "📢 {$label}", 'url' => "https://t.me/{$uname}"]];
        }
    }
    $rows[] = [['text' => "✅ Obuna bo'ldim — tekshir", 'callback_data' => 'sub:check']];
    return ['inline_keyboard' => $rows];
}

function adminKb(): array {
    return ['inline_keyboard' => [
        [
            ['text' => "➕ Test qo'shish",    'callback_data' => 'adm|add_test'],
            ['text' => '📋 Testlar ro\'yxati', 'callback_data' => 'adm|tests'],
        ],
        [
            ['text' => '📢 Reklama yuborish',  'callback_data' => 'adm|broadcast'],
            ['text' => '🔄 Forward xabar',     'callback_data' => 'adm|forward'],
        ],
        [
            ['text' => '📡 Obuna kanallari',   'callback_data' => 'adm|channels'],
            ['text' => '👥 Foydalanuvchilar',  'callback_data' => 'adm|users'],
        ],
        [
            ['text' => '🏆 Top 10',            'callback_data' => 'adm|top10'],
            ['text' => '🥇 Top 20',            'callback_data' => 'adm|top20'],
        ],
        [
            ['text' => '📊 Statistika',        'callback_data' => 'adm|stats'],
        ],
    ]];
}

function backToAdminKb(): array {
    return ['inline_keyboard' => [[['text' => '🔙 Admin panel', 'callback_data' => 'adm|back']]]];
}

function testMenuKb(string $tn): array {
    // test_num ni base64 encode qilamiz — `:` va boshqa belgilar xato chiqarmasligi uchun
    $enc = base64_encode($tn);
    return ['inline_keyboard' => [
        [['text' => '✏️ Javoblarni tahrirlash',  'callback_data' => "tm|edit_ans|{$enc}"]],
        [['text' => "📊 Natijalar ro'yxati",     'callback_data' => "tm|results|{$enc}"]],
        [['text' => '📥 Excel yuklab olish',     'callback_data' => "tm|excel|{$enc}"]],
        [['text' => "⏱ Vaqtni o'zgartirish",    'callback_data' => "tm|edit_dur|{$enc}"]],
        [['text' => "📌 Sarlavhani o'zgartirish",'callback_data' => "tm|edit_title|{$enc}"]],
        [['text' => "🗑 Testni o'chirish",       'callback_data' => "tm|del_ask|{$enc}"]],
        [['text' => '🔙 Testlar ro\'yxati',      'callback_data' => 'adm|tests']],
    ]];
}

// ═══════════════════════════════════════════════════
//   MESSAGE HANDLER
// ═══════════════════════════════════════════════════
function onMessage(array $msg): void {
    if (empty($msg['from'])) return;

    $uid   = (int)$msg['from']['id'];
    $chat  = (int)$msg['chat']['id'];
    $text  = trim($msg['text'] ?? '');
    $admin = isAdmin($uid);

    saveUser($msg['from']);

    // Obuna tekshirish — bu komandalar uchun o'tkazib yuboramiz
    if (!in_array($text, ['/start', '/admin', '⚙️ Admin panel'], true)) {
        $missing = notSubscribed($uid);
        if ($missing) {
            sendMsg($chat, "⚠️ <b>Botdan foydalanish uchun quyidagi kanallarga obuna bo'ling:</b>", subKb($missing));
            return;
        }
    }

    $sess  = getSess($uid);
    $state = $sess['state'];
    $sdata = $sess['data'];

    // ─── KOMANDALAR ──────────────────────────────────

    if ($text === '/start') {
        delSess($uid);
        $missing = notSubscribed($uid);
        if ($missing) {
            sendMsg($chat, "👋 <b>Salom!</b>\n\nBotdan foydalanish uchun quyidagi kanallarga obuna bo'ling:", subKb($missing));
            return;
        }
        $name = esc($msg['from']['first_name'] ?? 'Foydalanuvchi');
        sendMsg($chat,
            "👋 <b>Salom, {$name}!</b>\n\n"
            . "🤖 <b>Test Tekshiruvchi Bot</b>ga xush kelibsiz!\n\n"
            . "📝 <b>Qanday foydalanish:</b>\n"
            . "  1️⃣ «📝 Test topshirish» tugmasini bosing\n"
            . "  2️⃣ Test raqamini yuboring\n"
            . "  3️⃣ Javoblarni yuboring\n\n"
            . "⚡ <b>Tezkor usul:</b> <code>1 abcdabcd</code>\n"
            . "  (test raqami va javob birgalikda)\n\n"
            . "Bot avtomatik tekshirib natija beradi! 🎯",
            mainKb($admin)
        );
        return;
    }

    if (($text === '/admin' || $text === '⚙️ Admin panel') && $admin) {
        delSess($uid);
        sendMsg($chat, "⚙️ <b>Admin Panel</b>\n\nBo'limni tanlang:", adminKb());
        return;
    }

    if ($text === '❌ Bekor qilish') {
        delSess($uid);
        sendMsg($chat, "✅ Bekor qilindi.", mainKb($admin));
        return;
    }

    // ─── FOYDALANUVCHI MENYU ─────────────────────────

    if ($text === '📝 Test topshirish') {
        delSess($uid);
        setSess($uid, 'u:test_num');
        sendMsg($chat, "📝 <b>Test raqamini kiriting:</b>\n\nMisol: <code>1</code>, <code>25</code>, <code>2024-bio</code>", cancelKb());
        return;
    }

    if ($text === '📊 Natijalarim') {
        showMyResults($chat, $uid);
        return;
    }

    if ($text === "🏆 Top o'yinchilar") {
        sendMsg($chat, topTxt(10), ['inline_keyboard' => [
            [['text' => '🔄 Top 20 ko\'rish', 'callback_data' => 'top|20']],
        ]]);
        return;
    }

    if ($text === 'ℹ️ Yordam') {
        sendMsg($chat,
            "📖 <b>YORDAM</b>\n\n"
            . "<b>📝 Test topshirish:</b>\n"
            . "«Test topshirish» → test raqami → javoblar\n\n"
            . "<b>✍️ Qabul qilinadigan formatlar:</b>\n"
            . "• <code>abcdabcd</code> — ketma-ket\n"
            . "• <code>1a 2b 3c 4d</code> — raqam+harf\n"
            . "• <code>1-a 2-b 3-c</code> — tire bilan\n"
            . "• <code>1.a 2.b 3.c</code> — nuqta bilan\n\n"
            . "⚡ <b>Tezkor usul:</b>\n"
            . "<code>25 abcdabcd</code>\n"
            . "(raqam + bo'shliq + javoblar)\n\n"
            . "✅ Barcha formatni bot o'zi tushunadi!"
        );
        return;
    }

    // ─── FOYDALANUVCHI HOLATLARI ─────────────────────

    if ($state === 'u:test_num') {
        $tn   = trim($text);
        $test = q("SELECT * FROM tests WHERE test_num=?", [$tn])->fetch();
        if (!$test) {
            sendMsg($chat, "❌ <b>«" . esc($tn) . "»</b> raqamli test topilmadi.\n\nBoshqa raqam kiriting:");
            return;
        }
        $total  = count(json_decode($test['answers'], true) ?: []);
        $durTxt = $test['dur_min'] > 0 ? "\n⏱ Vaqt: <b>{$test['dur_min']} daqiqa</b>" : '';
        $titTxt = $test['title'] ? "\n📌 <b>" . esc($test['title']) . "</b>" : '';
        setSess($uid, 'u:answers', ['tn' => $tn, 'start' => time(), 'dur' => (int)$test['dur_min']]);
        sendMsg($chat,
            "✅ Test #" . esc($tn) . " topildi!{$titTxt}\n\n"
            . "📊 Savollar: <b>{$total} ta</b>{$durTxt}\n\n"
            . "📤 <b>Javoblaringizni yuboring:</b>\n"
            . "• <code>abcdabcd</code> — ketma-ket\n"
            . "• <code>1a 2b 3c 4d</code> — raqamli"
        );
        return;
    }

    if ($state === 'u:answers') {
        $tn      = $sdata['tn'] ?? '';
        $start   = (int)($sdata['start'] ?? time());
        $dur     = (int)($sdata['dur'] ?? 0);
        $elapsed = time() - $start;

        if ($dur > 0 && $elapsed > $dur * 60) {
            delSess($uid);
            sendMsg($chat, "⏰ <b>Vaqt tugadi!</b>\n\nTest #" . esc($tn) . " uchun {$dur} daqiqa ajratilgan edi.", mainKb($admin));
            return;
        }

        $test = q("SELECT * FROM tests WHERE test_num=?", [$tn])->fetch();
        if (!$test) {
            delSess($uid);
            sendMsg($chat, "❌ Test topilmadi. /start bosing.", mainKb($admin));
            return;
        }

        $correct = json_decode($test['answers'], true) ?: [];
        $user    = parseAnswers($text);
        if (!$user) {
            sendMsg($chat, "❌ <b>Format noto'g'ri!</b>\n\nMisol: <code>abcdabcd</code> yoki <code>1a 2b 3c</code>\n\nQaytadan kiriting:");
            return;
        }

        $res = gradeAnswers($correct, $user);
        q("INSERT INTO results (user_id,test_num,user_ans,correct,wrong,empty_q,total,score,elapsed) VALUES (?,?,?,?,?,?,?,?,?)",
          [$uid, $tn, json_encode($user), $res['correct'], $res['wrong'], $res['empty'], $res['total'], $res['score'], $elapsed]);
        $rid = (int)db()->lastInsertId();
        delSess($uid);

        $inlineKb = ['inline_keyboard' => [
            [['text' => "🔍 Batafsil ko'rish", 'callback_data' => "det|{$rid}"]],
            [['text' => '📝 Yana test topshirish', 'callback_data' => 'newtest']],
        ]];
        sendMsg($chat, resultTxt($res, $test['title'], $tn, $elapsed), $inlineKb);
        sendMsg($chat, "Asosiy menyu 👇", mainKb($admin));
        return;
    }

    // ─── ADMIN HOLATLARI ─────────────────────────────

    if ($admin) {

        // 1) Test raqami
        if ($state === 'a:add:num') {
            $tn = trim($text);
            if (!$tn) { sendMsg($chat, "❌ Bo'sh bo'lishi mumkin emas. Qayta kiriting:"); return; }
            if (q("SELECT id FROM tests WHERE test_num=?", [$tn])->fetch()) {
                sendMsg($chat, "❌ <b>#" . esc($tn) . "</b> raqamli test allaqachon mavjud!\n\nBoshqa raqam kiriting:");
                return;
            }
            setSess($uid, 'a:add:title', ['tn' => $tn]);
            sendMsg($chat, "✏️ Test <b>sarlavhasini</b> kiriting:\n(Sarlavha kerak bo'lmasa <code>-</code> yuboring)");
            return;
        }

        // 2) Test sarlavhasi
        if ($state === 'a:add:title') {
            $title = ($text === '-') ? '' : $text;
            setSess($uid, 'a:add:dur', array_merge($sdata, ['title' => $title]));
            sendMsg($chat, "⏱ <b>Vaqt chegarasini</b> kiriting (daqiqada):\n(Vaqt chegarasi bo'lmasa <code>0</code> yuboring)");
            return;
        }

        // 3) Test vaqti
        if ($state === 'a:add:dur') {
            if (!is_numeric($text) || (int)$text < 0) {
                sendMsg($chat, "❌ Faqat musbat raqam kiriting (cheklovsiz uchun 0):");
                return;
            }
            setSess($uid, 'a:add:ans', array_merge($sdata, ['dur' => (int)$text]));
            sendMsg($chat,
                "📋 <b>Test javoblarini kiriting:</b>\n\n"
                . "• <code>abcdabcd</code> — ketma-ket harflar\n"
                . "• <code>1a 2b 3c 4d</code> — raqamli\n\n"
                . "Faqat <b>a, b, c, d, e</b> harflar qabul qilinadi."
            );
            return;
        }

        // 4) Test javoblari — saqlash
        if ($state === 'a:add:ans') {
            $answers = parseAnswers($text);
            if (!$answers) {
                sendMsg($chat, "❌ Javoblar noto'g'ri!\n\nMisol: <code>abcdabcd</code> yoki <code>1a 2b 3c</code>\n\nQaytadan kiriting:");
                return;
            }
            q("INSERT INTO tests (test_num, title, answers, dur_min) VALUES (?,?,?,?)",
              [$sdata['tn'], $sdata['title'] ?? '', json_encode($answers, JSON_UNESCAPED_UNICODE), $sdata['dur'] ?? 0]);
            delSess($uid);
            $durTxt = ($sdata['dur'] ?? 0) > 0 ? "{$sdata['dur']} daqiqa" : "Cheklovsiz";
            sendMsg($chat,
                "✅ <b>Test muvaffaqiyatli qo'shildi!</b>\n\n"
                . "🔢 Raqam: <b>#" . esc($sdata['tn']) . "</b>\n"
                . "📌 Sarlavha: <b>" . esc($sdata['title'] ?? '') . (($sdata['title'] ?? '') ? '' : '<i>Yo\'q</i>') . "</b>\n"
                . "📊 Savollar soni: <b>" . count($answers) . " ta</b>\n"
                . "⏱ Vaqt: <b>{$durTxt}</b>",
                adminKb()
            );
            return;
        }

        // Javoblarni tahrirlash
        if ($state === 'a:edit:ans') {
            $answers = parseAnswers($text);
            if (!$answers) { sendMsg($chat, "❌ Format noto'g'ri!\n\nMisol: <code>abcdabcd</code>\n\nQaytadan:"); return; }
            q("UPDATE tests SET answers=? WHERE test_num=?", [json_encode($answers, JSON_UNESCAPED_UNICODE), $sdata['tn']]);
            delSess($uid);
            sendMsg($chat, "✅ Test #" . esc($sdata['tn']) . " javoblari yangilandi! (<b>" . count($answers) . " ta</b> savol)", adminKb());
            return;
        }

        // Vaqtni tahrirlash
        if ($state === 'a:edit:dur') {
            if (!is_numeric($text) || (int)$text < 0) { sendMsg($chat, "❌ Faqat musbat raqam (cheklovsiz = 0):"); return; }
            $dur = (int)$text;
            q("UPDATE tests SET dur_min=? WHERE test_num=?", [$dur, $sdata['tn']]);
            delSess($uid);
            $durTxt = $dur > 0 ? "{$dur} daqiqa" : "Cheklovsiz";
            sendMsg($chat, "✅ Test #" . esc($sdata['tn']) . " vaqti yangilandi: <b>{$durTxt}</b>", adminKb());
            return;
        }

        // Sarlavhani tahrirlash
        if ($state === 'a:edit:title') {
            $title = ($text === '-') ? '' : $text;
            q("UPDATE tests SET title=? WHERE test_num=?", [$title, $sdata['tn']]);
            delSess($uid);
            sendMsg($chat, "✅ Test #" . esc($sdata['tn']) . " sarlavhasi yangilandi: <b>" . esc($title ?: "Yo'q") . "</b>", adminKb());
            return;
        }

        // Reklama
        if ($state === 'a:broadcast') {
            delSess($uid);
            $wait = sendMsg($chat, "⏳ Xabar yuborilmoqda...");
            $r    = doBroadcast($msg, false);
            $wid  = $wait['result']['message_id'] ?? 0;
            if ($wid) editMsg($chat, $wid,
                "✅ <b>Reklama yuborildi!</b>\n\n✔️ Muvaffaqiyatli: <b>{$r['ok']}</b>\n❌ Xato: <b>{$r['fail']}</b>",
                adminKb()
            );
            return;
        }

        // Forward
        if ($state === 'a:forward') {
            delSess($uid);
            $wait = sendMsg($chat, "⏳ Forward qilinmoqda...");
            $r    = doBroadcast($msg, true);
            $wid  = $wait['result']['message_id'] ?? 0;
            if ($wid) editMsg($chat, $wid,
                "✅ <b>Forward qilindi!</b>\n\n✔️ Muvaffaqiyatli: <b>{$r['ok']}</b>\n❌ Xato: <b>{$r['fail']}</b>",
                adminKb()
            );
            return;
        }

        // Kanal qo'shish
        if ($state === 'a:add:channel') {
            $inp    = trim($text);
            $chanId = (str_starts_with($inp, '-') || ctype_digit($inp)) ? $inp : '@' . ltrim($inp, '@');
            $info   = tg('getChat', ['chat_id' => $chanId]);
            if (!($info['ok'] ?? false)) {
                sendMsg($chat, "❌ Kanal topilmadi yoki bot admin emas!\n\nBotni kanalga admin qiling va qayta yuboring:");
                return;
            }
            $ct = $info['result']['title']    ?? '';
            $cu = $info['result']['username'] ?? '';
            $ci = (string)($info['result']['id'] ?? $chanId);
            q("INSERT OR REPLACE INTO channels (chan_id, chan_user, chan_title) VALUES (?,?,?)", [$ci, $cu, $ct]);
            delSess($uid);
            sendMsg($chat, "✅ Kanal qo'shildi!\n\n📢 <b>" . esc($ct) . "</b>" . ($cu ? "\n🔗 @{$cu}" : ''), adminKb());
            return;
        }
    }

    // ─── TEZKOR FORMAT: "25 abcdabcd" ────────────────

    if ($state === '' && preg_match('/^(\S+)\s+(\S[\s\S]*)$/', $text, $m)) {
        $tn   = $m[1];
        $test = q("SELECT * FROM tests WHERE test_num=?", [$tn])->fetch();
        if ($test) {
            $user = parseAnswers($m[2]);
            if ($user) {
                $correct = json_decode($test['answers'], true) ?: [];
                $res     = gradeAnswers($correct, $user);
                q("INSERT INTO results (user_id,test_num,user_ans,correct,wrong,empty_q,total,score) VALUES (?,?,?,?,?,?,?,?)",
                  [$uid, $tn, json_encode($user), $res['correct'], $res['wrong'], $res['empty'], $res['total'], $res['score']]);
                $rid = (int)db()->lastInsertId();
                sendMsg($chat, resultTxt($res, $test['title'], $tn), ['inline_keyboard' => [
                    [['text' => "🔍 Batafsil ko'rish", 'callback_data' => "det|{$rid}"]],
                    [['text' => '📝 Yana test', 'callback_data' => 'newtest']],
                ]]);
                return;
            }
        }
    }

    sendMsg($chat, "❓ Tushunmadim.\n\nPastdagi menyu orqali tanlang 👇", mainKb($admin));
}

function showMyResults(int $chat, int $uid): void {
    $rows = q("
        SELECT r.test_num, r.correct, r.wrong, r.empty_q, r.total, r.score, r.created_at, t.title
        FROM results r LEFT JOIN tests t ON r.test_num=t.test_num
        WHERE r.user_id=?
        ORDER BY r.created_at DESC LIMIT 10
    ", [$uid])->fetchAll();

    if (!$rows) {
        sendMsg($chat, "📭 Siz hali hech qanday test topshirmagansiz.\n\n«📝 Test topshirish» tugmasini bosing!");
        return;
    }
    $t = "📊 <b>MENING NATIJALARIM</b> (so'nggi 10 ta)\n━━━━━━━━━━━━━━━━━━━━\n\n";
    foreach ($rows as $i => $r) {
        $e  = $r['score'] >= 85 ? '🏆' : ($r['score'] >= 70 ? '🎯' : ($r['score'] >= 50 ? '📊' : '😔'));
        $ti = $r['title'] ? " <i>(" . esc($r['title']) . ")</i>" : '';
        $t .= ($i + 1) . ". {$e} <b>Test #" . esc($r['test_num']) . "</b>{$ti}\n";
        $t .= "   ✅{$r['correct']} ❌{$r['wrong']} ⬜{$r['empty_q']} | <b>{$r['score']}%</b>\n";
        $t .= "   🕐 " . substr($r['created_at'], 0, 16) . "\n\n";
    }
    sendMsg($chat, $t);
}

// ═══════════════════════════════════════════════════
//   CALLBACK HANDLER
// ═══════════════════════════════════════════════════
function onCallback(array $cb): void {
    if (empty($cb['from'])) return;

    $uid   = (int)$cb['from']['id'];
    $chat  = (int)$cb['message']['chat']['id'];
    $mid   = (int)$cb['message']['message_id'];
    $d     = $cb['data'] ?? '';
    $admin = isAdmin($uid);

    saveUser($cb['from']);
    ackCb($cb['id']); // Har doim callback ga javob beramiz

    // Callback data ni parse qilamiz — separator "|"
    $parts = explode('|', $d, 3);
    $cmd   = $parts[0] ?? '';
    $arg1  = $parts[1] ?? '';
    $arg2  = isset($parts[2]) ? base64_decode($parts[2]) : ''; // test_num uchun base64

    // ─── UMUMIY ──────────────────────────────────────

    if ($d === 'sub:check') {
        $missing = notSubscribed($uid);
        if ($missing) {
            ackCb($cb['id'], "⚠️ Hali barcha kanallarga obuna bo'lmadingiz!", true);
            editMarkup($chat, $mid, subKb($missing));
        } else {
            editMsg($chat, $mid, "✅ <b>Obuna tasdiqlandi!</b>\n\nEndi botdan foydalanishingiz mumkin.\n/start bosing.");
        }
        return;
    }

    if ($d === 'newtest') {
        delSess($uid);
        setSess($uid, 'u:test_num');
        sendMsg($chat, "📝 <b>Test raqamini kiriting:</b>", cancelKb());
        return;
    }

    if ($cmd === 'top') {
        $lim   = (int)$arg1;
        $other = $lim === 10 ? 20 : 10;
        editMsg($chat, $mid, topTxt($lim), ['inline_keyboard' => [
            [['text' => "🔄 Top {$other} ko'rish", 'callback_data' => "top|{$other}"]],
        ]]);
        return;
    }

    // Batafsil natija
    if ($cmd === 'det') {
        $rid = (int)$arg1;
        $res = q("SELECT r.*, t.answers, t.title FROM results r LEFT JOIN tests t ON r.test_num=t.test_num WHERE r.id=?", [$rid])->fetch();
        if (!$res) { sendMsg($chat, "❌ Natija topilmadi."); return; }

        $correct = json_decode($res['answers'] ?? '{}', true) ?: [];
        $user    = json_decode($res['user_ans'] ?? '{}', true) ?: [];
        $det     = gradeAnswers($correct, $user)['details'];

        $t    = "🔍 <b>BATAFSIL NATIJA — Test #" . esc($res['test_num']) . "</b>\n━━━━━━━━━━━━━━━━━━━━\n\n";
        $line = '';
        $cnt  = 0;
        foreach ($det as $q_n => $di) {
            $ic = $di['s'] === 'ok' ? '✅' : ($di['s'] === 'wr' ? '❌' : '⬜');
            $line .= $di['s'] === 'wr'
                ? "{$q_n}:{$ic}<s>{$di['u']}</s>→{$di['c']}  "
                : "{$q_n}:{$ic}  ";
            $cnt++;
            if ($cnt % 4 === 0) { $t .= $line . "\n"; $line = ''; }
        }
        if ($line) $t .= $line . "\n";
        sendMsg($chat, $t);
        return;
    }

    // ─── ADMIN CALLBACKLAR ────────────────────────────

    if (!$admin) return;

    // Admin panel
    if ($d === 'adm|back') {
        delSess($uid);
        editMsg($chat, $mid, "⚙️ <b>Admin Panel</b>\n\nBo'limni tanlang:", adminKb());
        return;
    }

    // ─── Test qo'shish ────────────────────────────────
    if ($d === 'adm|add_test') {
        delSess($uid);
        setSess($uid, 'a:add:num');
        editMsg($chat, $mid,
            "➕ <b>Yangi test qo'shish</b>\n\n"
            . "1-qadam: Test <b>raqamini</b> kiriting:\n\n"
            . "Misol: <code>1</code>, <code>25</code>, <code>2024-bio</code>",
            backToAdminKb()
        );
        return;
    }

    // ─── Testlar ro'yxati ─────────────────────────────
    if ($d === 'adm|tests') {
        $tests = q("SELECT test_num, title, dur_min FROM tests ORDER BY created_at DESC")->fetchAll();
        if (!$tests) {
            editMsg($chat, $mid, "📭 Hali birorta test qo'shilmagan.", adminKb());
            return;
        }
        $rows = [];
        foreach ($tests as $t) {
            $cnt  = q("SELECT COUNT(*) FROM results WHERE test_num=?", [$t['test_num']])->fetchColumn();
            $dur  = $t['dur_min'] > 0 ? " ⏱{$t['dur_min']}m" : '';
            $lbl  = '#' . $t['test_num'] . ($t['title'] ? ' — ' . $t['title'] : '') . $dur . " ({$cnt} ta)";
            $enc  = base64_encode($t['test_num']);
            $rows[] = [['text' => $lbl, 'callback_data' => "adm|tmenu|{$enc}"]];
        }
        $rows[] = [['text' => '🔙 Orqaga', 'callback_data' => 'adm|back']];
        editMsg($chat, $mid,
            "📋 <b>Testlar ro'yxati</b> (" . count($tests) . " ta)\n\nBoshqarish uchun testni tanlang:",
            ['inline_keyboard' => $rows]
        );
        return;
    }

    // ─── Test menyusi ─────────────────────────────────
    if ($cmd === 'adm' && $arg1 === 'tmenu') {
        $tn   = $arg2; // base64_decode qilingan
        $test = q("SELECT * FROM tests WHERE test_num=?", [$tn])->fetch();
        if (!$test) { sendMsg($chat, "❌ Test topilmadi."); return; }
        editMsg($chat, $mid, testInfoTxt($tn), testMenuKb($tn));
        return;
    }

    // ─── Test menyusi action lar ─────────────────────
    if ($cmd === 'tm') {
        $action = $arg1;
        $tn     = $arg2; // base64_decode qilingan

        if ($action === 'edit_ans') {
            setSess($uid, 'a:edit:ans', ['tn' => $tn]);
            editMsg($chat, $mid,
                "✏️ <b>Test #" . esc($tn) . "</b> — Yangi javoblarni kiriting:\n\n"
                . "• <code>abcdabcd</code> — ketma-ket\n"
                . "• <code>1a 2b 3c 4d</code> — raqamli",
                ['inline_keyboard' => [[['text' => '🔙 Orqaga', 'callback_data' => 'adm|tmenu|' . base64_encode($tn)]]]]
            );
            return;
        }

        if ($action === 'edit_dur') {
            $test = q("SELECT dur_min FROM tests WHERE test_num=?", [$tn])->fetch();
            $cur  = $test['dur_min'] ?? 0;
            setSess($uid, 'a:edit:dur', ['tn' => $tn]);
            editMsg($chat, $mid,
                "⏱ <b>Test #" . esc($tn) . "</b> — Yangi vaqtni kiriting (daqiqada):\n\n"
                . "Hozirgi: <b>" . ($cur > 0 ? "{$cur} daqiqa" : "Cheklovsiz") . "</b>\n"
                . "(Cheklovsiz uchun <code>0</code>)",
                ['inline_keyboard' => [[['text' => '🔙 Orqaga', 'callback_data' => 'adm|tmenu|' . base64_encode($tn)]]]]
            );
            return;
        }

        if ($action === 'edit_title') {
            $test  = q("SELECT title FROM tests WHERE test_num=?", [$tn])->fetch();
            $curT  = $test['title'] ?? '';
            setSess($uid, 'a:edit:title', ['tn' => $tn]);
            editMsg($chat, $mid,
                "📌 <b>Test #" . esc($tn) . "</b> — Yangi sarlavhani kiriting:\n\n"
                . "Hozirgi: <b>" . esc($curT ?: "Yo'q") . "</b>\n"
                . "(O'chirish uchun <code>-</code>)",
                ['inline_keyboard' => [[['text' => '🔙 Orqaga', 'callback_data' => 'adm|tmenu|' . base64_encode($tn)]]]]
            );
            return;
        }

        if ($action === 'results') {
            $rows = q("
                SELECT r.correct, r.wrong, r.empty_q, r.total, r.score, r.created_at,
                       u.first_name, u.last_name, u.username
                FROM results r LEFT JOIN users u ON r.user_id=u.id
                WHERE r.test_num=?
                ORDER BY r.score DESC, r.created_at ASC
                LIMIT 20
            ", [$tn])->fetchAll();
            if (!$rows) {
                sendMsg($chat, "📭 Test #" . esc($tn) . " uchun hali natija yo'q.");
                return;
            }
            $t = "📊 <b>Test #" . esc($tn) . " — Natijalar (Top 20)</b>\n━━━━━━━━━━━━━━━━━━━━\n\n";
            foreach ($rows as $i => $r) {
                $name = esc(trim($r['first_name'] . ' ' . $r['last_name'])) ?: "Noma'lum";
                $un   = $r['username'] ? " @{$r['username']}" : '';
                $e    = $r['score'] >= 85 ? '🏆' : ($r['score'] >= 70 ? '🎯' : '📊');
                $t   .= ($i + 1) . ". {$e} <b>{$name}</b>{$un} — <b>{$r['score']}%</b>\n";
                $t   .= "   ✅{$r['correct']} ❌{$r['wrong']} ⬜{$r['empty_q']}\n\n";
            }
            sendMsg($chat, $t, ['inline_keyboard' => [[['text' => '🔙 Orqaga', 'callback_data' => 'adm|tmenu|' . base64_encode($tn)]]]]);
            return;
        }

        if ($action === 'excel') {
            $cnt = q("SELECT COUNT(*) FROM results WHERE test_num=?", [$tn])->fetchColumn();
            if (!$cnt) { sendMsg($chat, "📭 Test #" . esc($tn) . " uchun hali natija yo'q."); return; }
            $file = makeCSV($tn);
            sendDoc($chat, $file, "📊 <b>Test #" . esc($tn) . "</b> — {$cnt} ta natija");
            @unlink($file);
            return;
        }

        if ($action === 'del_ask') {
            $cnt = q("SELECT COUNT(*) FROM results WHERE test_num=?", [$tn])->fetchColumn();
            $enc = base64_encode($tn);
            editMsg($chat, $mid,
                "🗑 <b>O'chirishni tasdiqlaysizmi?</b>\n\n"
                . "Test: <b>#" . esc($tn) . "</b>\n"
                . "Natijalar: <b>{$cnt} ta</b>\n\n"
                . "⚠️ Bu amal <b>qaytarib bo'lmaydi!</b>",
                ['inline_keyboard' => [[
                    ['text' => "✅ Ha, o'chir",  'callback_data' => "tm|del_ok|{$enc}"],
                    ['text' => '❌ Bekor qilish', 'callback_data' => "adm|tmenu|{$enc}"],
                ]]]
            );
            return;
        }

        if ($action === 'del_ok') {
            $resCnt = (int)q("SELECT COUNT(*) FROM results WHERE test_num=?", [$tn])->fetchColumn();
            q("DELETE FROM results WHERE test_num=?", [$tn]);
            q("DELETE FROM tests   WHERE test_num=?", [$tn]);
            editMsg($chat, $mid, "✅ Test #" . esc($tn) . " va <b>{$resCnt}</b> ta natija o\'chirildi.", adminKb());
            return;
        }
    }

    // ─── Reklama ──────────────────────────────────────
    if ($d === 'adm|broadcast') {
        delSess($uid);
        setSess($uid, 'a:broadcast');
        editMsg($chat, $mid,
            "📢 <b>Reklama yuborish</b>\n\n"
            . "Barcha foydalanuvchilarga yuboriladigan xabarni yuboring:\n\n"
            . "<i>Matn, rasm, video, audio, hujjat — barchasi qabul qilinadi</i>",
            backToAdminKb()
        );
        return;
    }

    // ─── Forward ──────────────────────────────────────
    if ($d === 'adm|forward') {
        delSess($uid);
        setSess($uid, 'a:forward');
        editMsg($chat, $mid,
            "🔄 <b>Forward xabar</b>\n\n"
            . "Forward qilinadigan xabarni shu chatga yuboring:\n\n"
            . "<i>Bot o'sha xabarni barcha foydalanuvchilarga forward qiladi</i>",
            backToAdminKb()
        );
        return;
    }

    // ─── Kanallar ─────────────────────────────────────
    if ($d === 'adm|channels') {
        showChannelsAdmin($chat, $mid);
        return;
    }

    if ($d === 'adm|add_channel') {
        delSess($uid);
        setSess($uid, 'a:add:channel');
        editMsg($chat, $mid,
            "📡 <b>Kanal qo'shish</b>\n\n"
            . "Kanal username yoki ID ni kiriting:\n\n"
            . "• <code>@kanal_nomi</code>\n"
            . "• <code>-1001234567890</code>\n\n"
            . "⚠️ Bot kanalda <b>Administrator</b> bo'lishi shart!",
            ['inline_keyboard' => [[['text' => '🔙 Orqaga', 'callback_data' => 'adm|channels']]]]
        );
        return;
    }

    if ($cmd === 'adm' && $arg1 === 'del_ch') {
        $ci = base64_decode($arg2);
        q("DELETE FROM channels WHERE chan_id=?", [$ci]);
        showChannelsAdmin($chat, $mid);
        return;
    }

    // ─── Foydalanuvchilar ─────────────────────────────
    if ($d === 'adm|users') {
        $total  = q("SELECT COUNT(*) FROM users")->fetchColumn();
        $today  = q("SELECT COUNT(*) FROM users WHERE DATE(joined_at)=DATE('now','localtime')")->fetchColumn();
        $week   = q("SELECT COUNT(*) FROM users WHERE joined_at >= datetime('now','-7 days','localtime')")->fetchColumn();
        $recent = q("SELECT first_name, last_name, username, joined_at FROM users ORDER BY joined_at DESC LIMIT 5")->fetchAll();
        $t = "👥 <b>FOYDALANUVCHILAR</b>\n━━━━━━━━━━━━━━━━━━━━\n\n"
           . "📊 Jami: <b>{$total} ta</b>\n"
           . "🆕 Bugun qo'shilgan: <b>{$today} ta</b>\n"
           . "📅 Hafta ichida: <b>{$week} ta</b>\n\n"
           . "━━ So'nggi 5 ta ━━\n";
        foreach ($recent as $u) {
            $name = esc(trim($u['first_name'] . ' ' . $u['last_name'])) ?: "Noma'lum";
            $un   = $u['username'] ? " (@{$u['username']})" : '';
            $t   .= "• {$name}{$un}\n";
        }
        editMsg($chat, $mid, $t, backToAdminKb());
        return;
    }

    // ─── Top ──────────────────────────────────────────
    if ($d === 'adm|top10') { sendMsg($chat, topTxt(10)); return; }
    if ($d === 'adm|top20') { sendMsg($chat, topTxt(20)); return; }

    // ─── Statistika ───────────────────────────────────
    if ($d === 'adm|stats') {
        $uTotal = q("SELECT COUNT(*) FROM users")->fetchColumn();
        $tTotal = q("SELECT COUNT(*) FROM tests")->fetchColumn();
        $rTotal = q("SELECT COUNT(*) FROM results")->fetchColumn();
        $avg    = q("SELECT ROUND(AVG(score),1) FROM results")->fetchColumn() ?: '0';
        $maxS   = q("SELECT MAX(score) FROM results")->fetchColumn() ?: '0';
        $todayU = q("SELECT COUNT(*) FROM users   WHERE DATE(joined_at)=DATE('now','localtime')")->fetchColumn();
        $todayR = q("SELECT COUNT(*) FROM results WHERE DATE(created_at)=DATE('now','localtime')")->fetchColumn();
        $weekR  = q("SELECT COUNT(*) FROM results WHERE created_at >= datetime('now','-7 days','localtime')")->fetchColumn();
        editMsg($chat, $mid,
            "📊 <b>BOT STATISTIKASI</b>\n━━━━━━━━━━━━━━━━━━━━\n\n"
            . "👥 Jami foydalanuvchilar: <b>{$uTotal}</b>\n"
            . "🆕 Bugun qo'shilgan:      <b>{$todayU}</b>\n\n"
            . "📋 Jami testlar:           <b>{$tTotal}</b>\n"
            . "📝 Jami topshirishlar:     <b>{$rTotal}</b>\n"
            . "📅 Bugungi topshirishlar:  <b>{$todayR}</b>\n"
            . "🗓 Haftalik topshirishlar: <b>{$weekR}</b>\n\n"
            . "🎯 O'rtacha ball:          <b>{$avg}%</b>\n"
            . "🏆 Rekord ball:            <b>{$maxS}%</b>",
            backToAdminKb()
        );
        return;
    }
}

// Kanallar admin menyusi
function showChannelsAdmin(int $chat, int $mid): void {
    $channels = q("SELECT * FROM channels")->fetchAll();
    $rows     = [];
    foreach ($channels as $ch) {
        $label = esc($ch['chan_title'] ?: $ch['chan_user'] ?: $ch['chan_id']);
        $uname = ltrim($ch['chan_user'] ?? '', '@');
        $enc   = base64_encode($ch['chan_id']);
        $row   = [];
        if ($uname) $row[] = ['text' => "📢 {$label}", 'url' => "https://t.me/{$uname}"];
        else          $row[] = ['text' => "📢 {$label}", 'callback_data' => 'noop'];
        $row[]  = ['text' => '🗑 O\'chirish', 'callback_data' => "adm|del_ch|{$enc}"];
        $rows[] = $row;
    }
    $rows[] = [['text' => "➕ Kanal qo'shish", 'callback_data' => 'adm|add_channel']];
    $rows[] = [['text' => '🔙 Orqaga',          'callback_data' => 'adm|back']];
    $cnt    = count($channels);
    editMsg($chat, $mid,
        "📡 <b>Majburiy obuna kanallari</b>\n━━━━━━━━━━━━━━━━━━━━\n\n"
        . ($cnt > 0
            ? "Jami: <b>{$cnt} ta kanal</b>\n\nO'chirish uchun tugmani bosing."
            : "Hali birorta kanal qo'shilmagan."),
        ['inline_keyboard' => $rows]
    );
}

// ═══════════════════════════════════════════════════
//   ENTRY POINT
// ═══════════════════════════════════════════════════
$raw = file_get_contents('php://input');
if (!$raw || trim($raw) === '') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'running', 'bot' => 'Test Bot ishlayapti ✅']);
    exit;
}

$upd = json_decode($raw, true);
if (!is_array($upd)) exit;

try {
    if (!empty($upd['message']))         onMessage($upd['message']);
    elseif (!empty($upd['callback_query'])) onCallback($upd['callback_query']);
} catch (Throwable $e) {
    error_log('BOT CRASH: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}
