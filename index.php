<?php
// ==================== 配置 ====================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ==================== 数据库 ====================
$db_file = 'words.sqlite';

if (file_exists($db_file) && !is_writable($db_file)) {
    die("数据库文件 $db_file 不可写");
}
if (!is_writable(dirname($db_file))) {
    die("数据库目录不可写");
}

try {
    $db = new PDO("sqlite:$db_file");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 创建表
    $db->exec("
        CREATE TABLE IF NOT EXISTS words (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            word TEXT NOT NULL,
            meaning TEXT NOT NULL,
            last_studied_at INTEGER DEFAULT 0,
            next_review_at INTEGER DEFAULT 0,
            memory_level INTEGER DEFAULT 0
        )
    ");

    // 尝试添加唯一索引（忽略错误）
    try {
        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_word_unique ON words(word COLLATE NOCASE)");
    } catch (PDOException $e) {
        error_log("唯一索引失败: " . $e->getMessage());
    }

    // 清理重复单词：忽略大小写，保留 id 最小的一条
    try {
        $db->exec("
            DELETE FROM words
            WHERE id NOT IN (
                SELECT MIN(id)
                FROM words
                GROUP BY lower(word)
            )
        ");
    } catch (PDOException $e) {
        error_log("清理重复失败: " . $e->getMessage());
    }

} catch (PDOException $e) {
    die("数据库初始化失败: " . $e->getMessage());
}

// ==================== 艾宾浩斯间隔 ====================
$ebbinghaus_intervals = [
    0 => 86400,      // 1天
    1 => 172800,     // 2天
    2 => 345600,     // 4天
    3 => 604800,     // 7天
    4 => 1296000,    // 15天
    5 => 2592000,    // 30天
    6 => 5184000,    // 60天
    7 => 10368000,   // 120天
    8 => 20736000    // 240天
];

function calculate_next_review_time(int $level): int {
    global $ebbinghaus_intervals;
    $level = min($level, count($ebbinghaus_intervals) - 1);
    return time() + $ebbinghaus_intervals[$level];
}

// ==================== 消息 ====================
$message = '';

// ==================== 添加/更新单词 ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_word'])) {
    $word    = trim($_POST['new_word']);
    $meaning = trim($_POST['new_meaning']);

    if ($word === '' || $meaning === '') {
        $message = '<p style="color:red;">请填写完整！</p>';
    } else {
        try {
            // 查重（忽略大小写）
            $check = $db->prepare("SELECT id FROM words WHERE word COLLATE NOCASE = ?");
            $check->execute([$word]);
            $row = $check->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                // 更新
                $db->prepare("UPDATE words SET meaning = ? WHERE id = ?")
                   ->execute([$meaning, $row['id']]);
                $message = "<p style='color:orange'>单词 <strong>$word</strong> 已更新释义</p>";
            } else {
                // 插入
                $db->prepare("
                    INSERT INTO words (word, meaning, last_studied_at, next_review_at, memory_level)
                    VALUES (?, ?, 0, 0, 0)
                ")->execute([$word, $meaning]);
                $message = "<p style='color:green'>添加成功: <strong>$word</strong></p>";
            }
        } catch (PDOException $e) {
            // 唯一约束冲突 → 说明有重复
            if ($e->getCode() == 23000) {
                $message = "<p style='color:orange'>单词 <strong>$word</strong> 已存在，释义已覆盖</p>";
                $db->prepare("UPDATE words SET meaning = ? WHERE word COLLATE NOCASE = ?")
                   ->execute([$meaning, $word]);
            } else {
                $message = "<p style='color:red'>错误: " . htmlspecialchars($e->getMessage()) . "</p>";
            }
        }
    }
}

// ==================== 复习 ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_id'])) {
    $id = (int)$_POST['review_id'];
    $now = time();

    try {
        $stmt = $db->prepare("SELECT memory_level FROM words WHERE id = ?");
        $stmt->execute([$id]);
        $level = (int)$stmt->fetchColumn();

        if ($level === false) {
            $message = "<p style='color:red'>单词不存在</p>";
        } else {
            $new_level = min($level + 1, count($ebbinghaus_intervals) - 1);
            $next = calculate_next_review_time($new_level);

            $db->prepare("
                UPDATE words
                SET last_studied_at = ?, next_review_at = ?, memory_level = ?
                WHERE id = ?
            ")->execute([$now, $next, $new_level, $id]);

            $message = "<p style='color:green'>复习成功！下次：".date('Y-m-d', $next)."</p>";
        }
    } catch (PDOException $e) {
        $message = "<p style='color:red'>复习失败</p>";
    }
}

// ==================== 查询今日单词 ====================
$today_start = strtotime('today');
$today_end   = $today_start + 86399;

try {
    $stmt = $db->prepare("
        SELECT * FROM words
        WHERE next_review_at = 0
           OR next_review_at BETWEEN ? AND ?
        ORDER BY next_review_at ASC
        LIMIT 50
    ");
    $stmt->execute([$today_start, $today_end]);
    $words_to_review = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("查询失败: " . $e->getMessage());
}

function format_time(int $ts): string {
    return $ts == 0 ? "新词" : date('m-d H:i', $ts);
}
?>

<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <title>艾宾浩斯单词本</title>
    <style>
        body {font-family: system-ui, sans-serif; padding:20px; max-width:800px; margin:auto;}
        .msg {padding:10px; margin:10px 0; border-radius:4px; font-weight:bold;}
        .success {background:#d4edda; color:#155724;}
        .warning {background:#fff3cd; color:#856404;}
        .error   {background:#f8d7da; color:#721c24;}
        .card {border:1px solid #ddd; padding:15px; margin:10px 0; border-radius:6px;}
        .new {border-left:5px solid #007bff;}
        .due {border-left:5px solid #dc3545;}
        .ok  {border-left:5px solid #28a745;}
        textarea {width:100%; font-family:inherit;}
        button {padding:8px 16px; cursor:pointer;}
    </style>
</head>
<body>

<h1>艾宾浩斯单词复习</h1>

<?php if ($message) echo "<div class='msg'>$message</div>"; ?>

<form method="POST">
    <p><input type="text" name="new_word" placeholder="输入单词" required style="width:180px;"></p>
    <p><textarea name="new_meaning" placeholder="输入释义" rows="3" required></textarea></p>
    <p><button type="submit">添加/更新</button></p>
</form>

<hr>

<?php if (empty($words_to_review)): ?>
    <p>暂无待复习单词</p>
<?php else: foreach ($words_to_review as $w):
    $is_due = $w['next_review_at'] > 0 && $w['next_review_at'] <= time();
    $cls = $w['memory_level'] == 0 ? 'new' : ($is_due ? 'due' : 'ok');
?>
    <div class="card <?= $cls ?>">
        <h3><?= htmlspecialchars($w['word']) ?></h3>
        <p><strong>释义：</strong><?= nl2br(htmlspecialchars($w['meaning'])) ?></p>
        <p>
            上次：<?= format_time($w['last_studied_at']) ?> |
            下次：<?php
                if ($w['next_review_at']==0) echo "立即";
                elseif ($is_due) echo "<b style='color:red'>已到期</b>";
                else echo format_time($w['next_review_at']);
            ?> |
            等级：<?= $w['memory_level'] ?>/8
        </p>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="review_id" value="<?= $w['id'] ?>">
            <button type="submit">我记住了</button>
        </form>
    </div>
<?php endforeach; endif; ?>

</body>
</html>
