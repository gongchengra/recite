<?php
// index.php - 访客/简易视图
declare(strict_types=1);

require_once __DIR__ . '/alan.func.php';

$db = alan_db();

// ==================== 请求处理 ====================
handle_ajax_search($db);
$msg = handle_word_update($db);
if (!$msg) {
    $msg = handle_review_action($db);
}

// ==================== 数据获取 ====================
$words_to_review = get_words_to_review($db);

// 表单保留
$retain_word    = $_POST['new_word'] ?? '';
$retain_meaning = $_POST['new_meaning'] ?? '';
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <title>单词复习</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background: #f4f7f6; color: #333; }
        .container { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        h1 { font-size: 24px; margin-bottom: 20px; color: #2c3e50; text-align: center; }
        .msg { padding: 10px; margin-bottom: 20px; border-radius: 6px; text-align: center; font-weight: bold; }
        .msg:empty { display: none; }
        .form-group { margin-bottom: 15px; }
        input[type="text"], textarea { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px; box-sizing: border-box; font-size: 16px; }
        button { background: #007bff; color: #fff; border: none; padding: 12px 20px; border-radius: 8px; cursor: pointer; font-size: 16px; width: 100%; transition: background 0.3s; }
        button:hover { background: #0056b3; }
        .card { background: #fff; border: 1px solid #eee; padding: 15px; margin-top: 15px; border-radius: 10px; }
        .card h3 { margin: 0 0 10px 0; color: #007bff; }
        .card p { margin: 0; color: #666; line-height: 1.5; }
        .stats { text-align: center; margin-top: 20px; color: #999; font-size: 14px; }
        .admin-link { display: block; text-align: center; margin-top: 30px; color: #999; text-decoration: none; font-size: 14px; }
    </style>
</head>
<body>

<div class="container">
    <h1>单词复习</h1>

    <div id="msg" class="msg"><?= $msg ?></div>

    <form method="POST">
        <div class="form-group">
            <input type="text" name="new_word" id="word" placeholder="输入新单词" required value="<?= h($retain_word) ?>">
        </div>
        <div class="form-group">
            <textarea name="new_meaning" id="meaning" rows="3" placeholder="输入释义" required><?= h($retain_meaning) ?></textarea>
        </div>
        <button type="submit">添加并开始复习</button>
    </form>

    <?php if (!empty($words_to_review)): ?>
        <div style="margin-top: 30px;">
            <h2 style="font-size: 18px; color: #666;">待复习 (<?= count($words_to_review) ?>)</h2>
            <?php foreach (array_slice($words_to_review, 0, 3) as $w): ?>
                <div class="card">
                    <h3><?= h($w['word']) ?></h3>
                    <p><?= nl2br(h($w['meaning'])) ?></p>
                    <form method="POST" style="margin-top: 10px;">
                        <input type="hidden" name="review_id" value="<?= $w['id'] ?>">
                        <input type="hidden" name="review_action" value="remembered">
                        <button type="submit" style="background: #28a745; padding: 8px 15px; font-size: 14px; width: auto;">记住了</button>
                    </form>
                </div>
            <?php endforeach; ?>
            <?php if (count($words_to_review) > 3): ?>
                <p class="stats">还有 <?= count($words_to_review) - 3 ?> 个单词待复习</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <p class="stats" style="margin-top: 40px;">目前没有需要复习的单词</p>
    <?php endif; ?>
</div>

<a href="alan.php" class="admin-link">管理后台</a>

</body>
</html>
