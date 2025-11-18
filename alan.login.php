<?php
// alan.login.php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/alan.func.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

// ==================== 配置 ====================
$auth_file        = __DIR__ . '/alan_auth.json';
$lockout_file     = __DIR__ . '/alan_lockout.json';
$max_attempts     = 5;
$lockout_duration = 15 * 60;   // 15 分钟

// ==================== 工具函数 ====================
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

// 防暴力破解
function is_locked_out(string $ip, string $user): array|false {
    global $lockout_file, $max_attempts, $lockout_duration;
    $data = load_json($lockout_file);
    $key  = "$ip|$user";
    if (isset($data[$key])) {
        $r = $data[$key];
        if ($r['attempts'] >= $max_attempts && (time() - $r['locked_at'] < $lockout_duration)) {
            return $r;
        }
        unset($data[$key]);
        save_json($lockout_file, $data);
    }
    return false;
}
function record_failed(string $ip, string $user): void {
    global $lockout_file, $max_attempts;
    $data = load_json($lockout_file);
    $key  = "$ip|$user";
    $data[$key]['attempts'] = ($data[$key]['attempts'] ?? 0) + 1;
    if ($data[$key]['attempts'] >= $max_attempts) $data[$key]['locked_at'] = time();
    save_json($lockout_file, $data);
}
function clear_attempts(string $ip, string $user): void {
    global $lockout_file;
    $data = load_json($lockout_file);
    unset($data["$ip|$user"]);
    save_json($lockout_file, $data);
}

// ==================== 登录表单 ====================
function show_login(string $error = ''): void {
    global $max_attempts, $lockout_duration;
    $ip   = get_client_ip();
    $user = $_POST['username'] ?? 'admin';
    $lock = is_locked_out($ip, $user);
    $remain = $lock ? ceil(($lock['locked_at'] + $lockout_duration - time()) / 60) : 0;
    $left   = $lock ? 0 : $max_attempts - ($lock['attempts'] ?? 0);
    ?>
    <!DOCTYPE html><html lang="zh"><head><meta charset="UTF-8"><title>Alan - 登录</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f0f2f5;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;}
        .box{background:#fff;padding:30px;border-radius:10px;box-shadow:0 4px 12px rgba(0,0,0,.1);width:320px;}
        h2{text-align:center;color:#333;margin-bottom:20px;}
        input{width:100%;padding:10px;margin:8px 0;border:1px solid #ddd;border-radius:5px;box-sizing:border-box;font-size:16px;}
        button{width:100%;padding:12px;background:#4CAF50;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:16px;margin-top:10px;}
        button:hover{background:#45a049;}
        button:disabled{background:#ccc;cursor:not-allowed;}
        .msg{text-align:center;margin:10px 0;font-size:.9em;}
        .error{color:#d32f2f;}.warning{color:#f57c00;}
        .info{color:#666;font-size:.8em;text-align:center;margin-top:15px;}
    </style></head><body>
    <div class="box"><h2>Alan 管理员登录</h2>
    <?php if($error):?><p class="msg error"><?=h($error)?></p><?php endif;?>
    <?php if($lock):?>
        <p class="msg warning">登录失败过多，已锁定！<br>请等待 <strong><?=$remain?></strong> 分钟。</p>
    <?php else:?>
        <form method="post">
            <input type="text" name="username" placeholder="用户名" value="admin" required autofocus>
            <input type="password" name="password" placeholder="密码" required>
            <button type="submit" name="login" <?= $left<=0?'disabled':'' ?>>
                登录 (剩余 <?=$left?> 次)
            </button>
        </form>
        <?php if($left<=2 && $left>0):?>
            <p class="msg warning">还剩 <?=$left?> 次机会，失败将锁定 15 分钟！</p>
        <?php endif;?>
    <?php endif;?>
    <p class="info">默认用户名: <code>admin</code><br>首次使用请设置密码（≥6位）</p>
    </div></body></html>
    <?php
    exit;
}

// ==================== 处理登录 ====================
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['login'])) {
    $ip   = get_client_ip();
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';

    // 固定管理员用户名（可自行修改）
    if ($user !== 'gongchengra@gmail.com') {
        record_failed($ip, $user);
        show_login('用户名错误');
    }

    if (is_locked_out($ip, $user)) {
        show_login('账号被锁定，请稍后重试');
    }

    $auth = load_json($auth_file);
    if (empty($auth)) {               // 首次设置密码
        if (strlen($pass) < 6) {
            record_failed($ip, $user);
            show_login('密码至少 6 位');
        }
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        save_json($auth_file, ['username'=>'admin','password'=>$hash]);
        clear_attempts($ip, $user);
        $_SESSION['alan_auth'] = true;
        header('Location: alan.php');
        exit;
    }

    if (password_verify($pass, $auth['password'])) {
        clear_attempts($ip, $user);
        $_SESSION['alan_auth'] = true;
        header('Location: alan.php');
        exit;
    } else {
        record_failed($ip, $user);
        show_login('密码错误');
    }
}

// 退出
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: alan.php');
    exit;
}

// 必须登录
if (empty($_SESSION['alan_auth'])) {
    show_login();
}
