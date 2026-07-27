<?php
// done.php - 一键将当天待复习列表中等级大于 0 的单词标记为“我记住了”
declare(strict_types=1);

require_once __DIR__ . '/alan.login.php';
check_login();
require_once __DIR__ . '/alan.func.php';

$db = alan_db();

$review_count = get_review_total_count($db);
$words_to_review = $review_count > 0 ? get_words_to_review($db, $review_count) : [];

$processed = 0;
$skipped = 0;
$original_post = $_POST;

foreach ($words_to_review as $word) {
    if ((int)$word['memory_level'] <= 0) {
        $skipped++;
        continue;
    }

    $_POST = [
        'review_id' => (string)$word['id'],
        'review_action' => 'remembered',
    ];

    handle_review_action($db);
    $processed++;
}

$_POST = $original_post;

if ($processed > 0) {
    $_SESSION['done_msg'] = '<div class="msg" style="color:#166534;">已将今天列表中等级大于 0 的 ' . $processed . ' 个单词标记为“我记住了”。' . ($skipped > 0 ? ' 已跳过 ' . $skipped . ' 个新词。' : '') . '</div>';
} else {
    $_SESSION['done_msg'] = '<div class="msg" style="color:#92400e;">今天列表中没有等级大于 0 的单词需要一键处理。</div>';
}

header('Location: index.php#msg-anchor');
exit;
