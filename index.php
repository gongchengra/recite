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
		error_log("唯一索引失败: " . $e->getMessage());
	}

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

	// 安全添加 is_mastered 字段（兼容旧数据库）
	try {
		$db->exec("ALTER TABLE words ADD COLUMN is_mastered INTEGER DEFAULT 0");
	} catch (PDOException $e) {
		if (!str_contains($e->getMessage(), 'duplicate column name')) {
			throw $e;
		}
	}

} catch (PDOException $e) {
	die("数据库初始化失败: " . $e->getMessage());
}

// ==================== 艾宾浩斯间隔 ====================
$ebbinghaus_intervals = [
	1 => 86400,      // 1天
	2 => 86400,     // 2天
	3 => 172800,     // 4
	4 => 259200,     // 7
	5 => 691200,    // 15
	6 => 1296000,    // 30
	7 => 2592000,    // 60
	8 => 5184000,   // 120
	9 => 10368000    // 240
];

function calculate_next_review_time(int $level): int {
	global $ebbinghaus_intervals;
	if ($level == 0) {
		return strtotime('tomorrow');
	}
	$level = min($level, count($ebbinghaus_intervals) - 1);
	return time() + $ebbinghaus_intervals[$level];
}

// ==================== 消息变量 ====================
$message = '';

// ==================== AJAX 搜索处理 ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'search') {
	header('Content-Type: application/json');
	$word = trim($_POST['word'] ?? '');

	if ($word === '') {
		echo json_encode(['success' => false, 'message' => '<p style="color:red;">请输入单词</p>']);
		exit;
	}

	try {
		$stmt = $db->prepare("SELECT meaning FROM words WHERE word COLLATE NOCASE = ?");
		$stmt->execute([$word]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		if ($row) {
			echo json_encode([
				'success' => true,
				'found' => true,
				'meaning' => $row['meaning'],
				'message' => "<p style>已找到单词 <strong>$word</strong>，释义已自动填充</p>"
			]);
		} else {
			echo json_encode([
				'success' => true,
				'found' => false,
				'message' => "<p style='color:red;'>未找到单词：<strong>$word</strong>，可直接添加</p>"
			]);
		}
	} catch (PDOException $e) {
		echo json_encode(['success' => false, 'message' => '<p style="color:red;">搜索失败，请重试</p>']);
	}
	exit;
}

// ==================== 添加/更新单词 ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_word']) && !isset($_POST['action'])) {
	$word    = trim($_POST['new_word']);
	$meaning = trim($_POST['new_meaning']);

	if ($word === '' || $meaning === '') {
		$message = '<p style="color:red;">请填写完整！</p>';
	} else {
		try {
			$check = $db->prepare("SELECT id FROM words WHERE word COLLATE NOCASE = ?");
			$check->execute([$word]);
			$row = $check->fetch(PDO::FETCH_ASSOC);

			if ($row) {
				$db->prepare("UPDATE words SET meaning = ? WHERE id = ?")
				   ->execute([$meaning, $row['id']]);
				$message = "<p style='color:orange'>单词 <strong>$word</strong> 已更新释义</p>";
			} else {
				$db->prepare("
					INSERT INTO words (word, meaning, last_studied_at, next_review_at, memory_level, is_mastered)
					VALUES (?, ?, 0, 0, 0, 0)
				")->execute([$word, $meaning]);
				$message = "<p style='color:green'>添加成功: <strong>$word</strong></p>";
			}
		} catch (PDOException $e) {
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

// ==================== 复习处理（增强版）===================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_id'])) {
	$id = (int)$_POST['review_id'];
	$action = $_POST['review_action'] ?? 'remembered'; // remembered | forgotten | mastered
	$now = time();

	try {
		$stmt = $db->prepare("SELECT memory_level, is_mastered FROM words WHERE id = ?");
		$stmt->execute([$id]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		if (!$row) {
			$message = "<p style='color:red'>单词不存在</p>";
		} else {
			$current_level = (int)$row['memory_level'];
			$is_mastered = (int)$row['is_mastered'];

			if ($action === 'mastered') {
				$db->prepare("UPDATE words SET is_mastered = 1 WHERE id = ?")
				   ->execute([$id]);
				$message = "<p style='color:green'>恭喜！<strong>永久记住</strong>该词</p>";
			}
			elseif ($action === 'forgotten') {
				$next = calculate_next_review_time(0);
				$db->prepare("
					UPDATE words
					SET memory_level = 0,
						last_studied_at = ?,
						next_review_at = ?,
						is_mastered = 0
					WHERE id = ?
				")->execute([$now, $next, $id]);
				$message = "<p style='color:orange'>已重置，下次明天复习</p>";
			}
			else { // remembered
				if ($is_mastered) {
					$message = "<p style='color:gray'>该词已完全记住</p>";
				} else {
					$new_level = min($current_level + 1, count($ebbinghaus_intervals) - 1);
					$next = calculate_next_review_time($new_level);

					$db->prepare("
						UPDATE words
						SET last_studied_at = ?, next_review_at = ?, memory_level = ?
						WHERE id = ?
					")->execute([$now, $next, $new_level, $id]);

					$message = "<p style='color:green'>复习成功！下次：".date('Y-m-d', $next)."</p>";
				}
			}
		}
	} catch (PDOException $e) {
		$message = "<p style='color:red'>操作失败</p>";
	}
}

// ==================== 查询今日单词（排除已掌握）===================
$today_start = strtotime('today');
$today_end   = $today_start + 86399;

try {
	$stmt = $db->prepare("
		SELECT * FROM words
		WHERE (next_review_at = 0 OR next_review_at < ?)
		  AND (is_mastered IS NULL OR is_mastered = 0)
		ORDER BY next_review_at ASC
		LIMIT 100
	");
	$stmt->execute([$today_end]);
	$words_to_review = $stmt->fetchAll(PDO::FETCH_ASSOC);
	$review_count = count($words_to_review);
	$seconds_total = $review_count * 5;                // 每词 10 秒
	$minutes = floor($seconds_total / 60);
	$seconds = $seconds_total % 60;
	$estimated_time = $minutes > 0
		? "{$minutes} 分 {$seconds} 秒"
		: "{$seconds} 秒";
} catch (PDOException $e) {
	die("查询失败: " . $e->getMessage());
}

function format_time(int $ts): string {
	return $ts == 0 ? "新词" : date('m-d H:i', $ts);
}

// 获取表单保留值
$retain_word = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['new_word'] ?? '') : '';
$retain_meaning = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['new_meaning'] ?? '') : '';
?>

<!DOCTYPE html>
<html lang="zh">
<head>
	<meta charset="UTF-8">
	<title>艾宾浩斯单词本</title>
	<link rel="icon" type="image/x-icon" href="/favicon.ico">
	<link rel="icon" type="image/x-icon" href="/16x16.ico">
	<link rel="icon" type="image/x-icon" href="/32x32.ico">
	<link rel="icon" type="image/x-icon" href="/48x48.ico">
	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
	<style>
		body {font-family: system-ui, sans-serif; padding:20px; max-width:800px; margin:auto;}
		.msg {padding:10px; margin:10px 0; border-radius:4px; font-weight:bold;}
		.success {background:#d4edda; color:#155724;}
		.warning {background:#fff3cd; color:#856404;}
		.error   {background:#f8d7da; color:#721c24;}
		.card {border:1px solid #ddd; padding:15px; margin:10px 0; border-radius:6px; position:relative;}
		.new {border-left:5px solid #007bff;}
		.due {border-left:5px solid #dc3545;}
		.ok  {border-left:5px solid #28a745;}
		textarea {width:100%; font-family:inherit;}
		button {padding:8px 16px; cursor:pointer; margin:0 5px; border:none; border-radius:4px; font-size:0.9em;}
		.btn-remember {background:#28a745; color:white;}
		.btn-forgot   {background:#dc3545; color:white;}
		.btn-mastered {background:#6f42c1; color:white;}
		.input-group {display:flex; gap:5px; align-items:center;}
		.input-group input {flex:1;}
		.search-btn {background:#007bff; color:white; border:none; border-radius:4px; padding:8px 12px; font-size:0.9em;}
		.search-btn:hover {background:#0056b3;}
		.search-btn:disabled {background:#ccc; cursor:not-allowed;}
		.mastered-tag {color:#28a745; font-weight:bold;}

		/* ==== 新增：三个点按钮 & 下拉菜单 ==== */
		.card-menu-btn {
			position: absolute; top:8px; right:8px;
			background:none; border:none; font-size:1.4em; cursor:pointer;
			color:#666;
		}
		.card-menu-btn:hover {color:#000;}
		.card-menu {
			position: absolute; top:32px; right:0;
			background:#fff; border:1px solid #ddd; border-radius:4px;
			box-shadow:0 2px 8px rgba(0,0,0,.15);
			min-width:140px; z-index:10;
			display:none;
		}
		.card-menu.show {display:block;}
		.card-menu button {
			display:block; width:100%; text-align:left;
			margin:0; border-radius:0; font-size:0.85em;
		}
		.card-menu button:first-child {border-top-left-radius:4px; border-top-right-radius:4px;}
		.card-menu button:last-child {border-bottom-left-radius:4px; border-bottom-right-radius:4px;}
	</style>
</head>
<body>

<h1>艾宾浩斯单词复习</h1>

<!-- 消息显示区 -->
<div id="messageContainer">
	<?php if ($message): ?>
		<div class="msg"><?= $message ?></div>
	<?php endif; ?>
</div>

<form method="POST" id="addForm">
	<div class="input-group">
		<input type="text" name="new_word" id="new_word" placeholder="输入单词" required value="<?= htmlspecialchars($retain_word) ?>">
		<button type="button" class="search-btn" id="searchBtn">搜索</button>
	</div>
	<p><textarea name="new_meaning" id="new_meaning" placeholder="输入释义" rows="6" required><?= htmlspecialchars($retain_meaning) ?></textarea></p>
	<p><button type="submit">添加/更新</button></p>
</form>

<hr>

<?php if (empty($words_to_review)): ?>
	<p>暂无待复习单词</p>
<?php else: ?>
	<!-- 新增的统计行 -->
	<div style="background:#e7f3ff; padding:15px; margin:20px 0; border-radius:8px; font-size:1.1em; font-weight:bold; text-align:center;">
		今日待复习：<span style="color:#d63939;"><?= $review_count ?></span> 个单词　　
		预计用时约：<span style="color:#0066cc;"><?= $estimated_time ?></span>（每词 5 秒）
	</div>
	<?php foreach ($words_to_review as $w):
	$is_due = $w['next_review_at'] > 0 && $w['next_review_at'] <= time();
	$cls = $w['memory_level'] == 0 ? 'new' : ($is_due ? 'due' : 'ok');
	?>
	<div class="card <?= $cls ?>">
		<button type="button" class="card-menu-btn" aria-label="更多操作">...</button>

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
			<?= $w['is_mastered'] ? ' <span class="mastered-tag">[已掌握]</span>' : '' ?>
		</p>

		<?php if (!$w['is_mastered']): ?>
		<div style="margin-top:10px;">
			<!-- 记住按钮始终可见 -->
			<form method="POST" style="display:inline;">
				<input type="hidden" name="review_id" value="<?= $w['id'] ?>">
				<input type="hidden" name="review_action" value="remembered">
				<button type="submit" class="btn-remember">我记住了</button>
			</form>

			<!-- 其它两个按钮放入下拉菜单 -->
		</div>

		<!-- 下拉菜单 -->
		<div class="card-menu" id="menu-<?= $w['id'] ?>">
			<form method="POST" style="margin:0;">
				<input type="hidden" name="review_id" value="<?= $w['id'] ?>">
				<input type="hidden" name="review_action" value="forgotten">
				<button type="submit" class="btn-forgot">我忘记了</button>
			</form>
			<form method="POST" style="margin:0;">
				<input type="hidden" name="review_id" value="<?= $w['id'] ?>">
				<input type="hidden" name="review_action" value="mastered">
				<button type="submit" class="btn-mastered">已完全记住</button>
			</form>
		</div>
		<?php else: ?>
			<p style="color:#28a745; font-weight:bold;">已永久记住，无需复习</p>
		<?php endif; ?>
	</div>
<?php endforeach; endif; ?>

<script>
$(document).ready(function() {
	const $msgContainer = $('#messageContainer');
	const $wordInput = $('#new_word');
	const $meaningInput = $('#new_meaning');
	const $searchBtn = $('#searchBtn');

	/* ---------- 搜索功能 ---------- */
	$searchBtn.on('click', function() {
		const word = $wordInput.val().trim();
		if (!word) {
			showMessage('<p style="color:red;">请输入单词</p>');
			return;
		}
		$searchBtn.prop('disabled', true).text('搜索中...');
		$.post('', {action: 'search', word: word}, function(res) {
			if (res.success) {
				if (res.found) {
					$meaningInput.val(res.meaning);
					showMessage(res.message);
				} else {
					$meaningInput.val('').focus();
					showMessage(res.message);
				}
			} else {
				showMessage(res.message || '<p style="color:red;">搜索失败</p>');
			}
		}, 'json').fail(function() {
			showMessage('<p style="color:red;">网络错误，请重试</p>');
		}).always(function() {
			$searchBtn.prop('disabled', false).text('搜索');
		});
	});
	$wordInput.on('keypress', function(e) {
		if (e.which === 13) { e.preventDefault(); $searchBtn.click(); }
	});

	/* ---------- 三个点菜单 ---------- */
	$('.card-menu-btn').on('click', function(e) {
		e.stopPropagation();
		const $menu = $(this).nextAll('.card-menu').first();
		// 关闭其它打开的菜单
		$('.card-menu.show').not($menu).removeClass('show');
		$menu.toggleClass('show');
	});

	// 点击页面其它地方关闭所有菜单
	$(document).on('click', function(e) {
		if (!$(e.target).closest('.card').length) {
			$('.card-menu.show').removeClass('show');
		}
	});

	function showMessage(html) {
		$msgContainer.html('<div class="msg">' + html + '</div>');
		$('html, body').animate({ scrollTop: 0 }, 300);
	}
});
</script>

</body>
</html>
