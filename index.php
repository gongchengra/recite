<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// 数据库连接
$db_file = 'words.sqlite';
if (!is_writable($db_file) && file_exists($db_file)) {
    die("数据库文件 $db_file 不可写，请检查权限。");
}
if (!is_writable(dirname($db_file))) {
    die("数据库目录 " . dirname($db_file) . " 不可写，请检查权限。");
}
try {
    $db = new PDO("sqlite:$db_file");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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
} catch (PDOException $e) {
    die("数据库连接或表创建失败: " . $e->getMessage());
}
$ebbinghaus_intervals = [
    0 => 1 * 86400,
    1 => 2 * 86400,
    2 => 4 * 86400,
    3 => 7 * 86400,
    4 => 15 * 86400,
    5 => 30 * 86400,
    6 => 60 * 86400,
    7 => 120 * 86400,
    8 => 240 * 86400
];
function calculate_next_review_time($current_level) {
    global $ebbinghaus_intervals;
    $level = min($current_level, count($ebbinghaus_intervals) - 1);
    return time() + $ebbinghaus_intervals[$level];
}
// 处理单词提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_word'])) {
	$word = trim($_POST['new_word']);
	$meaning = trim($_POST['new_meaning']);
	if (!empty($word) && !empty($meaning)) {
		try {
            // 1. 检查是否已存在
            $check = $db->prepare("SELECT id, meaning FROM words WHERE word = ?");
            $check->execute([$word]);
            $row = $check->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                // 2. 已存在 → 更新释义
                $upd = $db->prepare("UPDATE words SET meaning = ? WHERE id = ?");
                $upd->execute([$meaning, $row['id']]);
                $message = '<p style="color:orange;">单词 <strong>'
                    . htmlspecialchars($word)
                    . '</strong> 已存在，释义已更新为最新内容。</p>';
            } else {
                // 3. 不存在 → 插入新词
                $ins = $db->prepare(
                    "INSERT INTO words (word, meaning, last_studied_at, next_review_at, memory_level)
                     VALUES (?, ?, 0, 0, 0)"
                );
                $ins->execute([$word, $meaning]);
                $message = '<p style="color:green;">新单词 <strong>'
                    . htmlspecialchars($word)
                    . '</strong> 添加成功！</p>';
            }
        } catch (PDOException $e) {
            $message = '<p style="color:red;">操作失败: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
	}
}
// 处理复习操作
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_id'])) {
	$word_id = (int)$_POST['review_id'];
	$current_time = time();try {
	$stmt = $db->prepare("SELECT memory_level FROM words WHERE id = ?");
	$stmt->execute([$word_id]);
	$current_level = (int)$stmt->fetchColumn();

	if ($current_level === false) {
		die("未找到单词 ID: $word_id");
	}

	$new_level = min($current_level + 1, count($ebbinghaus_intervals) - 1);
	$next_review_at = calculate_next_review_time($new_level);

	$stmt = $db->prepare("UPDATE words SET last_studied_at = ?, next_review_at = ?, memory_level = ? WHERE id = ?");
	$stmt->execute([$current_time, $next_review_at, $new_level, $word_id]);

	header("Location: " . $_SERVER['PHP_SELF']);
	exit;
	} catch (PDOException $e) {
		die("更新复习记录失败: " . $e->getMessage() . " (SQLSTATE: " . $e->getCode() . ")");
	}
}
// 数据获取
$current_time = time();
$today_start = strtotime('today');
$today_end   = $today_start + 86399;try {
$stmt = $db->prepare("
		SELECT * FROM words
		WHERE next_review_at = 0
		   OR next_review_at BETWEEN :today_start AND :today_end
		ORDER BY next_review_at ASC
		LIMIT 50
	");
	$stmt->bindParam(':today_start', $today_start, PDO::PARAM_INT);
	$stmt->bindParam(':today_end',   $today_end,   PDO::PARAM_INT);
	$stmt->execute();
	$words_to_review = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
	die("查询单词失败: " . $e->getMessage() . " (SQLSTATE: " . $e->getCode() . ")");
}
function format_time($timestamp) {
	if ($timestamp === 0) {
		return "N/A (新词)";
	}
	return date('Y-m-d H:i:s', $timestamp);
}
?>
<!DOCTYPE html>
<html lang="zh">
<head>
	<meta charset="UTF-8">
	<title>艾宾浩斯单词记忆系统</title>
	<style>
		body { font-family: Arial, sans-serif; line-height: 1.6; padding: 20px; }
		.word-list { margin-top: 20px; }
		.word-item { border: 1px solid #ccc; padding: 15px; margin-bottom: 10px; }
		.review-status { font-size: 0.9em; color: #555; margin-top: 5px; }
		.needs-review { border-left: 5px solid red; }
		.well-known { border-left: 5px solid green; }
		.new-word { border-left: 5px solid blue; }
		form { display: inline; }
	</style>
</head>
<body>
<h1>单词记忆复习板 (10 个一组)</h1>
<h2>手动添加单词</h2>
<form method="POST">
	<label for="new_word">单词:</label>
	<input type="text" id="new_word" name="new_word" required>
<label for="new_meaning">释义:</label>
<textarea id="new_meaning" name="new_meaning" required rows="5" cols="50"></textarea>
<button type="submit">添加单词</button>
</form>
<hr>
<div class="word-list">
	<?php if (empty($words_to_review)): ?>
		<p>没有需要复习或新的单词了！请添加更多单词。</p>
	<?php endif; ?>

<?php foreach ($words_to_review as $word):
	$is_due = $word['next_review_at'] > 0 && $word['next_review_at'] <= $current_time;
	$class = $word['memory_level'] == 0 ? 'new-word' : ($is_due ? 'needs-review' : 'well-known');
?>
	<div class="word-item <?= $class ?>">
		<h2><?= htmlspecialchars($word['word']) ?></h2>
		<p><strong>释义:</strong> <?= nl2br(htmlspecialchars($word['meaning'])) ?></p>

		<div class="review-status">
			上次学习: <?= format_time($word['last_studied_at']) ?>

			<strong>建议复习:</strong>
<?php
	if ($word['next_review_at'] == 0) {
		echo "立即 (新词)";
	} elseif ($is_due) {
		echo "<strong>已过期，请立即复习！</strong>";
	} else {
		echo format_time($word['next_review_at']);
	}
?>
			记忆等级: <?= $word['memory_level'] ?> / <?= count($ebbinghaus_intervals) - 1 ?>
		</div>
		<form method="POST">
			<input type="hidden" name="review_id" value="<?= $word['id'] ?>">
			<button type="submit">我记住了 (进入下一轮)</button>
		</form>
	</div>
<?php endforeach; ?></div>

</body>
</html>
