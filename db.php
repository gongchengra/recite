<?php
// database.php - 带登录、防暴力破解、分页、last_studied_at 可编辑 + is_mastered 支持（已修复）
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// === 配置 ===
$auth_file = 'auth.json';
$lockout_file = 'lockout.json';
$max_attempts = 5;
$lockout_duration = 15 * 60;
$per_page = 100;

// === 工具函数 ===
function get_client_ip() {
    $keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
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
        <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
        <?php if ($lock): ?>
            <p class="warning">登录失败过多，已被定！<br>请等待 <strong><?= $remaining ?></strong> 分钟。</p>
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
        <p class="info">默认用户名: <code>admin</code><br>首次使用请设置密码（≥6位）</p>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// === 处理登录 ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $ip = get_client_ip();
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if ($username !== 'gongchengra@gmail.com') {
        record_failed_login($ip, $username);
        show_login_form("用户名错误");
    }

    if ($lock = is_locked_out($ip, $username)) {
        show_login_form("账号被锁定，请稍后重试");
    }

    $auth_data = load_json($auth_file);

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

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    show_login_form();
}

// === 数据库逻辑 ===
$db_file = 'alan.sqlite';
if (!file_exists($db_file)) {
    die("数据库文件 $db_file 不存在，请先在 index.php 中添加单词。");
}

try {
    $db = new PDO("sqlite:$db_file");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 自动添加 is_mastered 字段（兼容旧数据库）
    try {
        $db->exec("ALTER TABLE words ADD COLUMN is_mastered INTEGER DEFAULT 0");
    } catch (PDOException $e) {
        if (!str_contains($e->getMessage(), 'duplicate column name')) {
            throw $e;
        }
    }

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

// === AJAX 更新（已修复 is_mastered）===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    header('Content-Type: application/json');
    $id = (int)$_POST['id'];
    $memory_level = (int)$_POST['memory_level'];
    $is_mastered = isset($_POST['is_mastered']) ? (int)$_POST['is_mastered'] : 0; // 明确接收 0 或 1
    $next_review_at = $_POST['next_review_at'] === 'custom' ? strtotime($_POST['custom_time']) : calculate_next_review_time($memory_level);
    $last_studied_at = $_POST['last_studied_at'] === 'custom' ? strtotime($_POST['custom_last_time']) : ($_POST['last_studied_at'] === 'now' ? time() : null);

    if ($_POST['next_review_at'] === 'custom' && $next_review_at === false) {
        echo json_encode(['success' => false, 'message' => '无效下次复习时间']);
        exit;
    }
    if ($_POST['last_studied_at'] === 'custom' && $last_studied_at === false) {
        echo json_encode(['success' => false, 'message' => '无效上次学习时间']);
        exit;
    }

    try {
        $sql = "UPDATE words SET memory_level = ?, next_review_at = ?, is_mastered = ?";
        $params = [$memory_level, $next_review_at, $is_mastered];

        if ($last_studied_at !== null) {
            $sql .= ", last_studied_at = ?";
            $params[] = $last_studied_at;
        }

        $sql .= " WHERE id = ?";
        $params[] = $id;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $stmt = $db->prepare("SELECT last_studied_at, next_review_at, memory_level, is_mastered FROM words WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'last_studied' => format_time($row['last_studied_at']),
            'next_review' => format_time($row['next_review_at']),
            'memory_level' => $row['memory_level'],
            'is_mastered' => $row['is_mastered']
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// === 分页逻辑 ===
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

// 获取总数
$total_stmt = $db->query("SELECT COUNT(*) FROM words");
$total_words = $total_stmt->fetchColumn();
$total_pages = max(1, ceil($total_words / $per_page));

// 获取当前页数据
try {
    $stmt = $db->prepare("SELECT * FROM words ORDER BY memory_level ASC, word ASC LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $words = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("查询失败: " . $e->getMessage());
}

function page_url($p) {
    return '?' . http_build_query(array_merge($_GET, ['page' => $p]));
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
        table { width: 100%; border-collapse: collapse; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #4CAF50; color: white; }
        tr:hover { background: #f1f1f1; }
        .edit-btn, .save-btn, .cancel-btn { padding: 5px 8px; margin: 0 3px; font-size: 0.8em; border: none; border-radius: 4px; color: white; cursor: pointer; }
        .edit-btn { background: #2196F3; }
        .save-btn { background: #4CAF50; }
        .cancel-btn { background: #f44336; }
        input, select { width: 100%; padding: 5px; box-sizing: border-box; }
        .editing { background: #fffde7; }
        .pagination { text-align: center; margin: 20px 0; }
        .pagination a, .pagination span {
            display: inline-block; padding: 8px 12px; margin: 0 4px;
            background: #fff; border: 1px solid #ddd; border-radius: 4px;
            text-decoration: none; color: #333; font-size: 0.9em;
        }
        .pagination a:hover { background: #f0f0f0; }
        .pagination .current { background: #4CAF50; color: white; border-color: #4CAF50; }
        .pagination .disabled { color: #aaa; cursor: not-allowed; }
        .count { color: #ddd; font-size: 0.9em; }
        .mastered { color: #28a745; font-weight: bold; }
        .not-mastered { color: #dc3545; }
    </style>
</head>
<body>

<div class="header">
    <h1>单词数据库管理</h1>
    <div>
        <span class="count">共 <?= $total_words ?> 个单词 | 第 <?= $page ?> / <?= $total_pages ?> 页</span> |
        <a href="?logout=1">登出</a>
    </div>
</div>

<table id="wordsTable">
    <thead>
        <tr>
            <th>单词</th>
            <th>释义</th>
            <th>记忆等级 (0-8)</th>
            <th>上次学习</th>
            <th>下次复习</th>
            <th>已掌握</th>
            <th>操作</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($words as $w): ?>
        <tr data-id="<?= $w['id'] ?>">
            <td><strong><?= htmlspecialchars($w['word']) ?></strong></td>
            <td style="max-width:300px; word-wrap:break-word;"><?= nl2br(htmlspecialchars($w['meaning'])) ?></td>
            <td>
                <span class="level-display"><?= $w['memory_level'] ?></span>
                <input type="number" class="level-input" min="0" max="8" value="<?= $w['memory_level'] ?>" style="display:none;">
            </td>
            <td>
                <span class="last-display" data-timestamp="<?= $w['last_studied_at'] ?>"><?= format_time($w['last_studied_at']) ?></span>
                <select class="last-preset" style="display:none;">
                    <option value="keep">保持不变</option>
                    <option value="now">设为现在</option>
                    <option value="custom">自定义时间</option>
                </select>
                <input type="datetime-local" class="last-input" style="display:none; margin-top:5px;">
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
                <span class="mastered-display <?= $w['is_mastered'] ? 'mastered' : 'not-mastered' ?>">
                    <?= $w['is_mastered'] ? '已掌握' : '未掌握' ?>
                </span>
                <label style="display:none; white-space:nowrap;">
                    <input type="checkbox" class="mastered-input" <?= $w['is_mastered'] ? 'checked' : '' ?>> 已完全记住
                </label>
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

<!-- 分页导航 -->
<div class="pagination">
    <?php if ($page > 1): ?>
        <a href="<?= page_url($page - 1) ?>">上一页</a>
    <?php else: ?>
        <span class="disabled">上一页</span>
    <?php endif; ?>

    <?php
    $start = max(1, $page - 2);
    $end = min($total_pages, $page + 2);
    if ($start > 1) echo '<span>...</span>';
    for ($i = $start; $i <= $end; $i++): ?>
        <?php if ($i == $page): ?>
            <span class="current"><?= $i ?></span>
        <?php else: ?>
            <a href="<?= page_url($i) ?>"><?= $i ?></a>
        <?php endif; ?>
    <?php endfor;
    if ($end < $total_pages) echo '<span>...</span>';
    ?>

    <?php if ($page < $total_pages): ?>
        <a href="<?= page_url($page + 1) ?>">下一页</a>
    <?php else: ?>
        <span class="disabled">下一页</span>
    <?php endif; ?>
</div>

<script>
$(document).ready(function() {
    let originalLevel, originalLast, originalNext, originalMastered;

    $(document).on('click', '.edit-btn', function() {
        const row = $(this).closest('tr');
        row.addClass('editing');

        originalLevel = row.find('.level-display').text();
        originalLast = row.find('.last-display').text();
        originalNext = row.find('.time-display').text();
        originalMastered = row.find('.mastered-input').is(':checked') ? 1 : 0;

        row.find('.level-display, .last-display, .time-display, .mastered-display, .edit-btn').hide();
        row.find('.level-input, .last-preset, .time-preset, .mastered-input, .save-btn, .cancel-btn').show();
        row.find('.mastered-input').closest('label').show();

        // 初始化时间
        const lastTs = row.find('.last-display').data('timestamp') || 0;
        if (lastTs > 0) {
            const d = new Date(lastTs * 1000);
            const local = new Date(d.getTime() - d.getTimezoneOffset() * 60000);
            row.find('.last-input').val(local.toISOString().slice(0, 16));
        }
        row.find('.last-preset').val('keep');
        row.find('.last-input').hide();

        const nextTs = row.find('.time-display').data('timestamp') || 0;
        if (nextTs > 0) {
            const d = new Date(nextTs * 1000);
            const local = new Date(d.getTime() - d.getTimezoneOffset() * 60000);
            row.find('.time-input').val(local.toISOString().slice(0, 16));
        }
        row.find('.time-preset').val('auto');
        row.find('.time-input').hide();
    });

    $(document).on('change', '.last-preset', function() {
        $(this).closest('td').find('.last-input').toggle($(this).val() === 'custom');
    });

    $(document).on('change', '.time-preset', function() {
        $(this).closest('td').find('.time-input').toggle($(this).val() === 'custom');
    });

    $(document).on('click', '.cancel-btn', function() {
        const row = $(this).closest('tr');
        row.removeClass('editing');
        row.find('.level-input').val(originalLevel);
        row.find('.level-display').text(originalLevel);
        row.find('.last-display').text(originalLast);
        row.find('.time-display').text(originalNext);
        row.find('.mastered-input').prop('checked', originalMastered);
        row.find('.mastered-display').text(originalMastered ? '已掌握' : '未掌握')
            .removeClass('mastered not-mastered')
            .addClass(originalMastered ? 'mastered' : 'not-mastered');

        row.find('.level-input, .last-preset, .last-input, .time-preset, .time-input, .mastered-input, .save-btn, .cancel-btn').hide();
        row.find('.level-display, .last-display, .time-display, .mastered-display, .edit-btn').show();
        row.find('.mastered-input').closest('label').hide();
    });

    $(document).on('click', '.save-btn', function() {
        const row = $(this).closest('tr');
        const id = row.data('id');
        const level = row.find('.level-input').val();
        const lastPreset = row.find('.last-preset').val();
        const nextPreset = row.find('.time-preset').val();
        const customLast = lastPreset === 'custom' ? row.find('.last-input').val() : '';
        const customNext = nextPreset === 'custom' ? row.find('.time-input').val() : '';
        const isMastered = row.find('.mastered-input').is(':checked') ? 1 : 0;

        if (lastPreset === 'custom' && !customLast) { alert('请选择上次学习时间'); return; }
        if (nextPreset === 'custom' && !customNext) { alert('请选择下次复习时间'); return; }

        const postData = {
            action: 'update',
            id: id,
            memory_level: level,
            is_mastered: isMastered,  // 明确发送 0 或 1
            last_studied_at: lastPreset,
            next_review_at: nextPreset
        };
        if (lastPreset === 'custom') postData.custom_last_time = customLast;
        if (nextPreset === 'custom') postData.custom_time = customNext;

        $.post('', postData, function(res) {
            if (res.success) {
                // 更新显示
                row.find('.level-display').text(res.memory_level);
                row.find('.last-display').text(res.last_studied);
                row.find('.time-display').text(res.next_review);
                row.find('.mastered-display')
                    .text(res.is_mastered ? '已掌握' : '未掌握')
                    .removeClass('mastered not-mastered')
                    .addClass(res.is_mastered ? 'mastered' : 'not-mastered');

                // 退出编辑模式
                row.removeClass('editing');
                row.find('.level-input, .last-preset, .last-input, .time-preset, .time-input, .mastered-input, .save-btn, .cancel-btn').hide();
                row.find('.level-display, .last-display, .time-display, .mastered-display, .edit-btn').show();
                row.find('.mastered-input').closest('label').hide();
            } else {
                alert('保存失败: ' + (res.message || '未知错误'));
            }
        }, 'json').fail(function() {
            alert('网络错误，请重试');
        });
    });
});
</script>

</body>
</html>
