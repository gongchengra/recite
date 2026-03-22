<?php
// test.php - 单词掌握测试页面
declare(strict_types=1);

require_once __DIR__ . '/alan.login.php';   // 登录与权限校验
check_login();                             // 强制测试页面登录
require_once __DIR__ . '/alan.func.php';    // 公共函数与业务逻辑

$db = alan_db();
$msg = '';

// ==================== AJAX 处理测试动作 ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'test_word') {
    header('Content-Type: application/json');
    $id = (int)$_POST['id'];
    $choice = $_POST['choice'] ?? '';
    $now = time();
    $tomorrow = strtotime('tomorrow');

    try {
        switch ($choice) {
            case 'none': // 完全不记得：等级 1，复习时间明天
                $db->prepare("UPDATE words SET memory_level = 1, next_review_at = ?, last_studied_at = ?, is_mastered = 0 WHERE id = ?")
                   ->execute([$tomorrow, $now, $id]);
                echo json_encode(['success' => true, 'message' => '已重置为等级 1，明天复习']);
                break;
            case 'vague': // 有印象：等级 3，复习时间明天
                $db->prepare("UPDATE words SET memory_level = 3, next_review_at = ?, last_studied_at = ?, is_mastered = 0 WHERE id = ?")
                   ->execute([$tomorrow, $now, $id]);
                echo json_encode(['success' => true, 'message' => '已调整为等级 3，明天复习']);
                break;
            case 'remember': // 记得：等级和时间不变
                echo json_encode(['success' => true, 'message' => '继续保持！']);
                break;
            case 'mastered': // 掌握了：标记为已掌握
                $db->prepare("UPDATE words SET is_mastered = 1, last_studied_at = ? WHERE id = ?")
                   ->execute([$now, $id]);
                echo json_encode(['success' => true, 'message' => '恭喜！已标记为永久掌握']);
                break;
            default:
                echo json_encode(['success' => false, 'message' => '无效选项']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => '数据库错: ' . $e->getMessage()]);
    }
    exit;
}

// ==================== 获取随机 10 个 7 级及以上单词 ====================
$stmt = $db->query("
    SELECT id, word FROM words
    WHERE memory_level >= 7
      AND (is_mastered = 0 OR is_mastered IS NULL)
    ORDER BY RANDOM()
    LIMIT 10
");
$test_words = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <title>单词掌握测试 - Alan</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background: #f0f4f8; color: #333; }
        .container { background: #fff; padding: 30px; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        h1 { text-align: center; color: #2c3e50; font-size: 24px; margin-bottom: 30px; }
        .word-item { padding: 20px; border-bottom: 1px solid #edf2f7; margin-bottom: 10px; transition: opacity 0.3s; }
        .word-item:last-child { border-bottom: none; }
        .word-text { font-size: 22px; font-weight: bold; color: #1a202c; margin-bottom: 15px; display: block; }
        .options { display: flex; flex-wrap: wrap; gap: 10px; }
        .option-btn { flex: 1; min-width: 120px; padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; cursor: pointer; font-size: 14px; transition: all 0.2s; }
        .option-btn:hover { background: #f7fafc; border-color: #cbd5e0; }

        .btn-none { color: #e53e3e; }
        .btn-none:hover { background: #fff5f5; border-color: #feb2b2; }

        .btn-vague { color: #d69e2e; }
        .btn-vague:hover { background: #fffaf0; border-color: #fbd38d; }

        .btn-remember { color: #3182ce; }
        .btn-remember:hover { background: #ebf8ff; border-color: #90cdf4; }

        .btn-mastered { color: #38a169; font-weight: bold; }
        .btn-mastered:hover { background: #f0fff4; border-color: #9ae6b4; }

        .finished { opacity: 0.5; pointer-events: none; background: #f8fafc; }
        .stats { text-align: center; margin-top: 20px; color: #718096; font-size: 14px; }
        .back-link { display: block; text-align: center; margin-top: 20px; color: #a0aec0; text-decoration: none; }
        .back-link:hover { color: #718096; }
    </style>
</head>
<body>

<div class="container">
    <h1>掌握程度测试 (Level 7+)</h1>

    <?php if (empty($test_words)): ?>
        <p style="text-align:center; padding:40px; color:#a0aec0;">目前没有等级在 7 级以上的待掌握单词。</p>
    <?php else: ?>
        <div id="test-list">
            <?php foreach ($test_words as $w): ?>
                <div class="word-item" id="word-<?= $w['id'] ?>">
                    <span class="word-text">
                        <a href="<?= h($w['word']) ?>.html" target="_blank" style="color:inherit;text-decoration:none;"><?= h($w['word']) ?></a>
                    </span>
                    <div class="options">
                        <button class="option-btn btn-none" data-id="<?= $w['id'] ?>" data-choice="none">完全不记得</button>
                        <button class="option-btn btn-vague" data-id="<?= $w['id'] ?>" data-choice="vague">有印象</button>
                        <button class="option-btn btn-remember" data-id="<?= $w['id'] ?>" data-choice="remember">记得</button>
                        <button class="option-btn btn-mastered" data-id="<?= $w['id'] ?>" data-choice="mastered">掌握了</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="stats">随机选取的 10 个高等级单词</div>
    <?php endif; ?>

    <a href="index.php" class="back-link">返回首页</a>
</div>

<script>
$(function() {
    $('.option-btn').on('click', function() {
        const $btn = $(this);
        const id = $btn.data('id');
        const choice = $btn.data('choice');
        const $item = $('#word-' + id);

        // 立即禁用按钮防止重复点击
        $item.find('.option-btn').prop('disabled', true);

        $.post('test.php', {
            action: 'test_word',
            id: id,
            choice: choice
        }, function(res) {
            if (res.success) {
                $item.addClass('finished');
                // 可选：在这里显示一个小反馈
                console.log(res.message);
            } else {
                alert('操作失败: ' + res.message);
                $item.find('.option-btn').prop('disabled', false);
            }
        }, 'json');
    });
});
</script>

</body>
</html>
