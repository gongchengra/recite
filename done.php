<?php
// done.php - 一键将当天待复习列表中等级大于 0 的单词标记为“我记住了”，并支持撤销
declare(strict_types=1);

require_once __DIR__ . '/alan.login.php';
check_login();
require_once __DIR__ . '/alan.func.php';

$db = alan_db();

function done_render_page(string $title, string $message, bool $canUndo = false): void
{
    ?>
    <!DOCTYPE html>
    <html lang="zh">
    <head>
        <meta charset="UTF-8">
        <title><?= h($title) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <style>
            body{
                font-family:system-ui,-apple-system,sans-serif;
                max-width:720px;
                margin:60px auto;
                padding:20px;
                background:#f9f9f9;
                color:#333;
            }
            .box{
                background:#fff;
                border:1px solid #eee;
                border-radius:12px;
                padding:28px;
                text-align:center;
                box-shadow:0 2px 10px rgba(0,0,0,0.05);
            }
            h1{
                margin-top:0;
                color:#2c3e50;
                font-size:24px;
            }
            .msg{
                margin:18px 0 24px;
                padding:14px;
                border-radius:8px;
                background:#f8fafc;
                border:1px solid #e2e8f0;
                line-height:1.7;
                font-weight:bold;
            }
            .actions{
                display:flex;
                gap:12px;
                justify-content:center;
                flex-wrap:wrap;
            }
            a.button{
                display:inline-block;
                padding:10px 18px;
                border-radius:8px;
                text-decoration:none;
                color:#fff;
                font-weight:bold;
            }
            .back{
                background:#2563eb;
            }
            .undo{
                background:#dc2626;
            }
            .run{
                background:#16a34a;
            }
            .hint{
                margin-top:18px;
                color:#64748b;
                font-size:13px;
                line-height:1.6;
            }
        </style>
    </head>
    <body>
        <div class="box">
            <h1><?= h($title) ?></h1>
            <div class="msg"><?= $message ?></div>
            <div class="actions">
                <a class="button back" href="index.php">返回首页</a>
                <?php if ($canUndo): ?>
                    <a class="button undo" href="done.php?undo=1" onclick="return confirm('确定要撤销刚才的一键记住操作吗？');">撤销本次操作</a>
                <?php else: ?>
                    <a class="button run" href="done.php" onclick="return confirm('确定要再次执行一键记住吗？');">再次执行</a>
                <?php endif; ?>
            </div>
            <div class="hint">
                一键记住只处理当天待复习列表中等级大于 0 的单词；等级为 0 的新词会被跳过。
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

function done_restore_last_batch(PDO $db): void
{
    $undo = $_SESSION['done_undo'] ?? null;

    if (
        !is_array($undo) ||
        empty($undo['words']) ||
        !is_array($undo['words'])
    ) {
        done_render_page(
            '没有可撤销的操作',
            '<span style="color:#92400e;">没有找到上一次一键记住的撤销记录，可能已经撤销过，或者会话已过期。</span>',
            false
        );
    }

    $restored = 0;

    try {
        $db->beginTransaction();

        $stmt = $db->prepare("
            UPDATE words
            SET memory_level = ?,
                next_review_at = ?,
                last_studied_at = ?,
                is_mastered = ?
            WHERE id = ?
        ");

        foreach ($undo['words'] as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }

            $stmt->bindValue(1, (int)$row['memory_level'], PDO::PARAM_INT);
            $stmt->bindValue(2, (int)$row['next_review_at'], PDO::PARAM_INT);
            $stmt->bindValue(3, (int)$row['last_studied_at'], PDO::PARAM_INT);

            if ($row['is_mastered'] === null) {
                $stmt->bindValue(4, null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(4, (int)$row['is_mastered'], PDO::PARAM_INT);
            }

            $stmt->bindValue(5, (int)$row['id'], PDO::PARAM_INT);
            $stmt->execute();
            $restored++;
        }

        $db->commit();
        unset($_SESSION['done_undo']);

        done_render_page(
            '撤销完成',
            '<span style="color:#166534;">已恢复 ' . $restored . ' 个单词到一键记住之前的状态。</span>',
            false
        );
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        done_render_page(
            '撤销失败',
            '<span style="color:#b91c1c;">撤销失败：' . h($e->getMessage()) . '</span>',
            false
        );
    }
}

function done_run_batch(PDO $db): void
{
    $review_count = get_review_total_count($db);
    $words_to_review = $review_count > 0 ? get_words_to_review($db, $review_count) : [];

    $processed = 0;
    $skipped = 0;
    $undo_words = [];
    $original_post = $_POST;

    foreach ($words_to_review as $word) {
        if ((int)$word['memory_level'] <= 0) {
            $skipped++;
            continue;
        }

        $undo_words[] = [
            'id' => (int)$word['id'],
            'word' => (string)$word['word'],
            'memory_level' => (int)$word['memory_level'],
            'next_review_at' => (int)$word['next_review_at'],
            'last_studied_at' => (int)$word['last_studied_at'],
            'is_mastered' => $word['is_mastered'] === null ? null : (int)$word['is_mastered'],
        ];

        $_POST = [
            'review_id' => (string)$word['id'],
            'review_action' => 'remembered',
        ];

        handle_review_action($db);
        $processed++;
    }

    $_POST = $original_post;

    if ($processed > 0) {
        $_SESSION['done_undo'] = [
            'created_at' => time(),
            'words' => $undo_words,
        ];

        done_render_page(
            '一键记住完成',
            '<span style="color:#166534;">已将今天列表中等级大于 0 的 ' . $processed . ' 个单词标记为“我记住了”。</span>' .
            ($skipped > 0 ? '<br><span style="color:#64748b;">已跳过 ' . $skipped . ' 个等级为 0 的新词。</span>' : ''),
            true
        );
    }

    unset($_SESSION['done_undo']);

    done_render_page(
        '没有需要处理的单词',
        '<span style="color:#92400e;">今天列表中没有等级大于 0 的单词需要一键处理。</span>' .
        ($skipped > 0 ? '<br><span style="color:#64748b;">当前列表中有 ' . $skipped . ' 个等级为 0 的新词，已跳过。</span>' : ''),
        false
    );
}

if (isset($_GET['undo']) && $_GET['undo'] === '1') {
    done_restore_last_batch($db);
}

done_run_batch($db);
