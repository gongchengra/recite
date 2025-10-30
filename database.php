<?php
// database.php - 带登录验证 + 防暴力破解
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// === 配置 ===
$auth_file = 'auth.json';           // 管理员密码
$lockout_file = 'lockout.json';     // 锁定记录
$max_attempts = 5;                  // 最大失败次数
$lockout_duration = 15 * 60;        // 锁定15分钟（秒）

// === 工具函数 ===
function get_client_ip() {
    $keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = $_SERVER[$key];
            $ip = trim(explode(',', $ip)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return 'unknown';
}

function load_json($file, $default = []) {
    return file_exists($file) ? json_decode(file_get_contents($file), true) : $default;
}

function save_json($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

function is_locked_out($ip, $username) {
    global $lockout_file, $max_attempts, $lockout_duration;
    $lockouts = load_json($lockout_file, []);

    $key = "$ip|$username";
    if (isset($lockouts[$key])) {
        $record = $lockouts[$key];
        if ($record['attempts'] >= $max_attempts && (time() - $record['locked_at'] < $lockout_duration)) {
            return $record;
        }
        // 超时自动解锁
        unset($lockouts[$key]);
        save_json($lockout_file, $lockouts);
    }
    return false;
}

function record_failed_login($ip, $username) {
    global $lockout_file, $max_attempts;
    $lockouts = load_json($lockout_file, []);
    $key = "$ip|$username";

    if (!isset($lockouts[$key])) {
        $lockouts[$key] = ['attempts' => 0, 'locked_at' => 0];
    }

    $lockouts[$key]['attempts']++;
    if ($lockouts[$key]['attempts'] >= $max_attempts) {
        $lockouts[$key]['locked_at'] = time();
    }
    save_json($lockout_file, $lockouts);
}

function clear_login_attempts($ip, $username) {
    global $lockout_file;
    $lockouts = load_json($lockout_file, []);
    $key = "$ip|$username";
    unset($lockouts[$key]);
    save_json($lockout_file, $lockouts);
}

// === 登录界面 ===
function show_login_form($error = '') {
    global $max_attempts, $lockout_duration;
    $ip = get_client_ip();
    $username = $_POST['username'] ?? 'admin';
    $lock = is_locked_out($ip, $username);

    $remaining = $lock ? ceil(($lock['locked_at'] + $lockout_duration - time()) / 60) : 0;
    $attempts_left = $lock ? 0 : ($max_attempts - ($lock['attempts'] ?? 0));

    ?>
    <!DOCTYPE html>
    <html lang="zh">
    <head>
        <meta charset="UTF-8">
        <title>登录 - 单词数据库管理</title>
        <style>
            body { font-family: Arial, sans-serif; background: #f0f2f5; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
            .login-box { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); width: 320px; }
            h2 { text-align: center; color: #333; margin-bottom: 20px; }
            input[type="text"], input[type="password"] { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 5px; box-sizing: border-box; font-size: 16px; }
            button { width: 100%; padding: 12px; background: #4CAF50; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; margin-top: 10px; }
            button:hover { background: #45a049; }
            button:disabled { background: #ccc; cursor: not-allowed; }
            .error, .warning { text-align: center; margin: 10px 0; font-size: 0.9em; }
            .error { color: #d32f2f; }
            .warning { color: #f57c00; }
            .info { color: #666; font-size: 0.8em; text-align: center; margin-top: 15px; }
        </style>
    </head>
    <body>
    <div class="login-box">
        <h2>管理员登录</h2>

        <?php if ($error): ?>
            <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <?php if ($lock): ?>
            <p class="warning">
                登录失败过多，已被锁定！<br>
                请等待 <strong><?= $remaining ?></strong> 分钟后重试。
            </p>
        <?php else: ?>
            <form method="post">
                <input type="text" name="username" placeholder="用户名" value="admin" required autofocus>
                <input type="password" name="password" placeholder="密码" required>
                <button type="submit" name="login" <?= $attempts_left <= 0 ? 'disabled' : '' ?>>
                    登录 (剩余 <?= $attempts_left ?> 次机会)
                </button>
            </form>
            <?php if ($attempts_left <= 2 && $attempts_left > 0): ?>
                <p class="warning">注意：还剩 <?= $attempts_left ?> 次机会，失败将锁定15分钟！</p>
            <?php endif; ?>
        <?php endif; ?>

        <p class="info">
            默认用户名: <code>admin</code><br>
            首次使用请设置密码（≥6位）
        </p>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// === 处理登录请求 ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $ip = get_client_ip();
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if ($username !== 'gongchengra@gmail.com') {
        record_failed_login($ip, $username);
        show_login_form("用户名错误");
    }

    // 检查是否被锁定
    if ($lock = is_locked_out($ip, $username)) {
        show_login_form("账号被锁定，请稍后重试");
    }

    $auth_data = load_json($auth_file);

    // 首次设置密码
    if (empty($auth_data)) {
        if (strlen($password) < 6) {
            record_failed_login($ip, $username);
            show_login_form("密码至少6位");
        }
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        save_json($auth_file, ['username' => 'admin', 'password' => $hashed]);
        clear_login_attempts($ip, $username);
        $_SESSION['authenticated'] = true;
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    // 验证密码
    if (password_verify($password, $auth_data['password'])) {
        clear_login_attempts($ip, $username);
        $_SESSION['authenticated'] = true;
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } else {
        record_failed_login($ip, $username);
        show_login_form("密码错误");
    }
}

// 处理登出
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// === 登录成功后执行 ===
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    show_login_form();
}

// === 数据库管理界面（从这里开始与之前一致）===
$db_file = 'words.sqlite';
if (!file_exists($db_file)) {
    die("数据库文件 $db_file 不存在，请先在 index.php 中添加单词。");
}

try {
    $db = new PDO("sqlite:$db_file");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("数据库连接失败: " . $e->getMessage());
}

$ebbinghaus_intervals = [
    0 => 1 * 86400, 1 => 2 * 86400, 2 => 4 * 86400, 3 => 7 * 86400,
    4 => 15 * 86400, 5 => 30 * 86400, 6 => 60 * 86400,
    7 => 120 * 86400, 8 => 240 * 86400
];

function calculate_next_review_time($level) {
    global $ebbinghaus_intervals;
    $level = min($level, count($ebbinghaus_intervals) - 1);
    return time() + $ebbinghaus_intervals[$level];
}

function format_time($timestamp) {
    return $timestamp == 0 ? "N/A" : date('Y-m-d H:i', $timestamp);
}

// AJAX 更新
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    header('Content-Type: application/json');
    $id = (int)$_POST['id'];
    $memory_level = (int)$_POST['memory_level'];
    $next_review_at = $_POST['next_review_at'] === 'custom' ? strtotime($_POST['custom_time']) : calculate_next_review_time($memory_level);

    if ($_POST['next_review_at'] === 'custom' && $next_review_at === false) {
        echo json_encode(['success' => false, 'message' => '无效时间']);
        exit;
    }

    try {
        $stmt = $db->prepare("UPDATE words SET memory_level = ?, next_review_at = ? WHERE id = ?");
        $stmt->execute([$memory_level, $next_review_at, $id]);
        echo json_encode([
            'success' => true,
            'next_review' => format_time($next_review_at),
            'memory_level' => $memory_level
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// 获取所有单词
try {
    $stmt = $db->query("SELECT * FROM words ORDER BY memory_level ASC");
    $all_words = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("查询失败: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <title>单词数据库管理</title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f9f9f9; margin: 0; }
        .header { display: flex; justify-content: space-between; align-items: center; background: #4CAF50; color: white; padding: 10px 20px; border-radius: 5px; margin-bottom: 20px; }
        .header a { color: white; text-decoration: none; font-size: 0.9em; }
        h1 { margin: 0; font-size: 1.5em; }
        table { width: 100%; border-collapse: collapse; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #4CAF50; color: white; }
        tr:hover { background: #f1f1f1; }
        .edit-btn, .save-btn, .cancel-btn { padding: 5px 8px; margin: 0 3px; font-size: 0.8em; border: none; border-radius: 4px; color: white; cursor: pointer; }
        .edit-btn { background: #2196F3; }
        .save-btn { background: #4CAF50; }
        .cancel-btn { background: #f44336; }
        input, select { width: 100%; padding: 5px; box-sizing: border-box; }
        .editing { background: #fffde7; }
        .count { color: #ddd; font-size: 0.9em; }
    </style>
</head>
<body>

<div class="header">
    <h1>单词数据库管理</h1>
    <div>
        <span class="count">共 <?= count($all_words) ?> 个单词</span> |
        <a href="?logout=1">登出</a>
    </div>
</div>

<table id="wordsTable">
    <thead>
        <tr>
            <th>单词</th>
            <th>释义</th>
            <th>记忆等级 (0-8)</th>
            <th>下次复习时间</th>
            <th>操作</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($all_words as $w): ?>
        <tr data-id="<?= $w['id'] ?>">
            <td><strong><?= htmlspecialchars($w['word']) ?></strong></td>
            <td style="max-width:300px; word-wrap:break-word;"><?= nl2br(htmlspecialchars($w['meaning'])) ?></td>
            <td>
                <span class="level-display"><?= $w['memory_level'] ?></span>
                <input type="number" class="level-input" min="0" max="8" value="<?= $w['memory_level'] ?>" style="display:none;">
            </td>
            <td>
                <span class="time-display" data-timestamp="<?= $w['next_review_at'] ?>"><?= format_time($w['next_review_at']) ?></span>
                <select class="time-preset" style="display:none;">
                    <option value="auto">自动计算</option>
                    <option value="custom">自定义时间</option>
                </select>
                <input type="datetime-local" class="time-input" style="display:none; margin-top:5px;">
            </td>
            <td>
                <button class="edit-btn">修改</button>
                <button class="save-btn" style="display:none;">保存</button>
                <button class="cancel-btn" style="display:none;">取消</button>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<script>
$(document).ready(function() {
    let originalLevel, originalTime;

    $(document).on('click', '.edit-btn', function() {
        const row = $(this).closest('tr');
        row.addClass('editing');
        originalLevel = row.find('.level-display').text();
        originalTime = row.find('.time-display').text();

        row.find('.level-display, .time-display, .edit-btn').hide();
        row.find('.level-input, .time-preset, .save-btn, .cancel-btn').show();

        const ts = row.find('.time-display').data('timestamp') || 0;
        if (ts > 0) {
            const d = new Date(ts * 1000);
            const local = new Date(d.getTime() - d.getTimezoneOffset() * 60000);
            row.find('.time-input').val(local.toISOString().slice(0, 16));
        }
        row.find('.time-preset').val('auto');
        row.find('.time-input').hide();
    });

    $(document).on('change', '.time-preset', function() {
        $(this).closest('td').find('.time-input').toggle($(this).val() === 'custom');
    });

    $(document).on('click', '.cancel-btn', function() {
        const row = $(this).closest('tr');
        row.removeClass('editing');
        row.find('.level-input').val(originalLevel);
        row.find('.level-display').text(originalLevel);
        row.find('.time-display').text(originalTime);
        row.find('.level-input, .time-preset, .time-input, .save-btn, .cancel-btn').hide();
        row.find('.level-display, .time-display, .edit-btn').show();
    });

    $(document).on('click', '.save-btn', function() {
        const row = $(this).closest('tr');
        const id = row.data('id');
        const level = row.find('.level-input').val();
        const preset = row.find('.time-preset').val();
        let custom_time = preset === 'custom' ? row.find('.time-input').val() : '';

        if (preset === 'custom' && !custom_time) {
            alert('请选择自定义时间');
            return;
        }

        $.post('', {
            action: 'update',
            id: id,
            memory_level: level,
            next_review_at: preset,
            custom_time: custom_time
        }, function(res) {
            if (res.success) {
                row.find('.level-display').text(res.memory_level);
                row.find('.time-display').text(res.next_review);
                row.removeClass('editing');
                row.find('.level-input, .time-preset, .time-input, .save-btn, .cancel-btn').hide();
                row.find('.level-display, .time-display, .edit-btn').show();
            } else {
                alert('保存失败: ' + res.message);
            }
        }, 'json');
    });
});
</script>

</body>
</html>
