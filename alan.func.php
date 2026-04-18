<?php
// alan.func.php - 中央工具库与配置
declare(strict_types=1);
ini_set('session.gc_maxlifetime', 86400 * 3);   // 服务器端 session 数据保留 3 天
ini_set('session.cookie_lifetime', 0);          // cookie 为会话级（浏览器关闭时依赖 Remember Me）
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('ALAN_ROOT')) define('ALAN_ROOT', __DIR__);

// ==================== 配置 ====================
$GLOBALS['config'] = [
    'db_file'          => ALAN_ROOT . '/words.sqlite',
    'auth_file'        => ALAN_ROOT . '/alan_auth.json',
    'lockout_file'     => ALAN_ROOT . '/alan_lockout.json',
    'remember_file'    => ALAN_ROOT . '/alan_remember.json',     // 新增：Remember Me 存储文件
    'max_attempts'     => 5,
    'lockout_duration' => 15 * 60,                                // 15 分钟
    'remember_duration'=> 365 * 24 * 60 * 60,                    // 1年（秒）
    'ebbinghaus_intervals' => [
        0 => 0,           // 0 (立即)
        1 => 86400,       // 1天
        2 => 86400,       // 1天
        3 => 172800,      // 2天
        4 => 259200,      // 3天
        5 => 691200,      // 8天
        6 => 1296000,     // 15天
        7 => 2592000,     // 30天
        8 => 5184000,     // 60天
        9 => 10368000,    // 120天
        10 => 20736000,   // 240天
        11 => 41472000,   // 480天
        12 => 82944000,   // 960天
        13 => 165888000,  // 1920天
        14 => 331776000,  // 3840天
        15 => 663552000   // 7680天
    ]
];

// ==================== 数据库 ====================
function alan_db(): PDO {
    static $db = null;
    if ($db === null) {
        $file = $_SESSION['alan_db'] ?? $GLOBALS['config']['db_file'];

        if (file_exists($file) && !is_writable($file)) {
            die("数据库文件 $file 不可写");
        }
        if (!is_writable(dirname($file))) {
            die("数据库目录不可写");
        }

        try {
            $db = new PDO("sqlite:$file");
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $db->exec("
                CREATE TABLE IF NOT EXISTS words (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    word TEXT NOT NULL,
                    meaning TEXT NOT NULL,
                    last_studied_at INTEGER DEFAULT 0,
                    next_review_at INTEGER DEFAULT 0,
                    memory_level INTEGER DEFAULT 0,
                    is_mastered INTEGER DEFAULT 0
                )
            ");

            try {
                $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_word_unique ON words(word COLLATE NOCASE)");
            } catch (PDOException $e) {
                error_log("唯一索引创建失败: " . $e->getMessage());
            }

            try {
                $db->exec("ALTER TABLE words ADD COLUMN is_mastered INTEGER DEFAULT 0");
            } catch (PDOException $e) {
                // 忽略字段已存在的错误
            }
        } catch (PDOException $e) {
            die("数据库连接或初始化失败: " . $e->getMessage());
        }
    }
    return $db;
}

// ==================== 核心业务逻辑 ====================
function calculate_next_review_time(int $level): int {
    $intervals = $GLOBALS['config']['ebbinghaus_intervals'];
    if ($level <= 0) return time();
    $level = min($level, count($intervals) - 1);
    return time() + $intervals[$level];
}

function handle_ajax_search(PDO $db): void {
    if (($_POST['action'] ?? '') === 'search') {
        header('Content-Type: application/json');
        $word = trim($_POST['word'] ?? '');
        $res  = ['success' => false, 'message' => ''];
        if ($word === '') {
            $res['message'] = '<p style="color:red;">请输入单词</p>';
            echo json_encode($res); exit;
        }
        $stmt = $db->prepare("SELECT meaning FROM words WHERE word COLLATE NOCASE = ?");
        $stmt->execute([$word]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $res = [
                'success' => true, 'found' => true, 'meaning' => $row['meaning'],
                'message' => "<p>已找到 <strong>" . h($word) . "</strong>，释义已填充</p>"
            ];
        } else {
            $res = [
                'success' => true, 'found' => false,
                'message' => "<p style='color:red;'>未找到 <strong>" . h($word) . "</strong>，可直接添加</p>"
            ];
        }
        echo json_encode($res); exit;
    }
}

function handle_word_update(PDO $db): string {
    if (($_POST['new_word'] ?? '') !== '') {
        $word    = trim($_POST['new_word']);
        $meaning = trim($_POST['new_meaning']);
        if ($word === '' || $meaning === '') {
            return '<p style="color:red;">请填写完整！</p>';
        } else {
            $check = $db->prepare("SELECT id FROM words WHERE word COLLATE NOCASE = ?");
            $check->execute([$word]);
            $row = $check->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $db->prepare("UPDATE words SET meaning = ? WHERE id = ?")->execute([$meaning, $row['id']]);
                return "<p style='color:orange'>单词 <strong>" . h($word) . "</strong> 更新释义</p>";
            } else {
                $db->prepare("
                    INSERT INTO words (word, meaning, last_studied_at, next_review_at, memory_level, is_mastered)
                    VALUES (?, ?, 0, 0, 0, 0)
                ")->execute([$word, $meaning]);
                return "<p style='color:green'>添加成功: <strong>" . h($word) . "</strong></p>";
            }
        }
    }
    return '';
}

function handle_review_action(PDO $db): string {
    if (isset($_POST['review_id'])) {
        $id     = (int)$_POST['review_id'];
        $action = $_POST['review_action'] ?? 'remembered';
        $now    = time();
        $intervals = $GLOBALS['config']['ebbinghaus_intervals'];

        $stmt = $db->prepare("SELECT word, memory_level, is_mastered FROM words WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return "<p style='color:red'>单词不存在</p>";
        } else {
            $word = $row['word'];
            $lvl = (int)$row['memory_level'];
            $mastered = (int)$row['is_mastered'];

            if ($action === 'mastered') {
                $db->prepare("UPDATE words SET is_mastered = 1 WHERE id = ?")->execute([$id]);
                return "<p style='color:green'>恭喜！<strong>永久记住</strong> 单词 <strong>" . h($word) . "</strong></p>";
            } elseif ($action === 'forgotten') {
                $next = calculate_next_review_time(0);
                $db->prepare("
                    UPDATE words SET memory_level = 0, last_studied_at = ?, next_review_at = ?, is_mastered = 0
                    WHERE id = ?
                ")->execute([$now, $next, $id]);
                return "<p style='color:orange'>单词 <strong>" . h($word) . "</strong> 已重置，下次明天复习</p>";
            } else {
                if ($mastered) {
                    return "<p style='color:gray'>该词已完全记住</p>";
                } else {
                    $new_lvl = min($lvl + 1, count($intervals) - 1);
                    $next    = calculate_next_review_time($new_lvl);
                    $db->prepare("
                        UPDATE words SET last_studied_at = ?, next_review_at = ?, memory_level = ?
                        WHERE id = ?
                    ")->execute([$now, $next, $new_lvl, $id]);
                    return "<p style='color:green'>复习成功！单词 <strong>" . h($word) . "</strong> 下次复习时间：" . date('Y-m-d', $next) . "</p>";
                }
            }
        }
    }
    return '';
}

function get_words_to_review(PDO $db, int $limit = 100): array {
    $today_end = strtotime('today') + 86399;
    $stmt = $db->prepare("
        SELECT * FROM words
        WHERE (next_review_at = 0 OR next_review_at <= ?)
          AND (is_mastered IS NULL OR is_mastered = 0)
        ORDER BY next_review_at ASC
        LIMIT ?
    ");
    $stmt->execute([$today_end, $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ==================== 通用工具函数 ====================
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function format_time(int $ts): string {
    return $ts == 0 ? "新词" : date('m-d H:i', $ts);
}

function get_client_ip(): string {
    $keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return 'unknown';
}

function load_json(string $file, array $def = []): array {
    return file_exists($file) ? json_decode(file_get_contents($file), true) ?: $def : $def;
}

function save_json(string $file, array $data): void {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

/**
 * 处理 Remember Me 自动登录
 */
function try_remember_me_login(): bool {
    if (!empty($_SESSION['alan_auth'])) {
        return true;
    }

    if (empty($_COOKIE['alan_remember'])) {
        return false;
    }

    $config = $GLOBALS['config'];
    $token  = $_COOKIE['alan_remember'];
    $remember_data = load_json($config['remember_file']);

    if (isset($remember_data[$token]) && time() < $remember_data[$token]['expires']) {
        $user = $remember_data[$token]['user'];
        $all_auth = load_json($config['auth_file']);

        if (isset($all_auth[$user])) {
            $_SESSION['alan_auth'] = true;
            $_SESSION['alan_user'] = $user;
            $_SESSION['alan_db']   = $all_auth[$user]['db_file'] ?? $config['db_file'];
            return true;
        }
    }

    // token 无效，清理 cookie
    setcookie('alan_remember', '', time() - 3600, '/', '', false, true);
    return false;
}

/**
 * 设置 Remember Me Cookie（登录成功时调用）
 */
function set_remember_me(string $user): void {
    $config = $GLOBALS['config'];
    $token  = bin2hex(random_bytes(32));
    $expires = time() + $config['remember_duration'];

    $remember_data = load_json($config['remember_file']);
    $remember_data[$token] = [
        'user'    => $user,
        'expires' => $expires,
        'created' => time()
    ];
    save_json($config['remember_file'], $remember_data);

    setcookie(
        'alan_remember',
        $token,
        [
            'expires'  => $expires,
            'path'     => '/',
            'domain'   => '',
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax'
        ]
    );
}
