<?php
// database.php - 单词数据库高级管理工具
declare(strict_types=1);

require_once __DIR__ . '/alan.login.php';   // 登录校验
check_login();                             // 强制管理后台登录
require_once __DIR__ . '/alan.func.php';    // 公共函数

$db = alan_db();
$config = $GLOBALS['config'];
$per_page = 100;

// ==================== AJAX 更新 ====================
if (($_POST['action'] ?? '') === 'update') {
    header('Content-Type: application/json');
    $id = (int)$_POST['id'];
    $memory_level = (int)$_POST['memory_level'];
    $is_mastered = isset($_POST['is_mastered']) ? (int)$_POST['is_mastered'] : 0;

    $next_review_at = $_POST['next_review_at'] === 'custom'
        ? strtotime($_POST['custom_time'])
        : calculate_next_review_time($memory_level);

    $last_studied_at = $_POST['last_studied_at'] === 'custom'
        ? strtotime($_POST['custom_last_time'])
        : ($_POST['last_studied_at'] === 'now' ? time() : null);

    try {
        $sql = "UPDATE words SET memory_level = ?, next_review_at = ?, is_mastered = ?";
        $params = [$memory_level, $next_review_at, $is_mastered];
        if ($last_studied_at !== null) {
            $sql .= ", last_studied_at = ?";
            $params[] = $last_studied_at;
        }
        $sql .= " WHERE id = ?";
        $params[] = $id;

        $db->prepare($sql)->execute($params);

        $stmt = $db->prepare("SELECT last_studied_at, next_review_at, memory_level, is_mastered FROM words WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'last_studied' => format_time((int)$row['last_studied_at']),
            'next_review' => format_time((int)$row['next_review_at']),
            'memory_level' => $row['memory_level'],
            'is_mastered' => $row['is_mastered']
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ==================== AJAX 搜索 ====================
if (($_GET['action'] ?? '') === 'search') {
    header('Content-Type: application/json');
    $word = trim($_GET['word'] ?? '');
    if (!$word) {
        echo json_encode(['success' => false, 'message' => '未输入单词']); exit;
    }

    $stmt = $db->prepare("SELECT * FROM words WHERE word = ? COLLATE NOCASE LIMIT 1");
    $stmt->execute([$word]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'message' => '未找到单词']); exit;
    }

    ob_start();
    ?>
    <tr data-id="<?= $row['id'] ?>">
        <td><strong><?= h($row['word']) ?></strong></td>
        <td style="max-width:300px; word-wrap:break-word;"><?= nl2br(h($row['meaning'])) ?></td>
        <td>
            <span class="level-display"><?= $row['memory_level'] ?></span>
            <input type="number" class="level-input" min="0" max="9" value="<?= $row['memory_level'] ?>" style="display:none;">
        </td>
        <td>
            <span class="last-display" data-timestamp="<?= $row['last_studied_at'] ?>"><?= format_time((int)$row['last_studied_at']) ?></span>
            <select class="last-preset" style="display:none;">
                <option value="keep">保持不变</option>
                <option value="now">设为现在</option>
                <option value="custom">自定义时间</option>
            </select>
            <input type="datetime-local" class="last-input" style="display:none; margin-top:5px;">
        </td>
        <td>
            <span class="time-display" data-timestamp="<?= $row['next_review_at'] ?>"><?= format_time((int)$row['next_review_at']) ?></span>
            <select class="time-preset" style="display:none;">
                <option value="auto">自动计算</option>
                <option value="custom">自定义时间</option>
            </select>
            <input type="datetime-local" class="time-input" style="display:none; margin-top:5px;">
        </td>
        <td>
            <span class="mastered-display <?= $row['is_mastered'] ? 'mastered' : 'not-mastered' ?>">
                <?= $row['is_mastered'] ? '已掌握' : '未掌握' ?>
            </span>
            <label style="display:none; white-space:nowrap;">
                <input type="checkbox" class="mastered-input" <?= $row['is_mastered'] ? 'checked' : '' ?>> 已完全记住
            </label>
        </td>
        <td>
            <button class="edit-btn">修改</button>
            <button class="save-btn" style="display:none;">保存</button>
            <button class="cancel-btn" style="display:none;">取消</button>
        </td>
    </tr>
    <?php
    $html = ob_get_clean();
    echo json_encode(['success' => true, 'id' => $row['id'], 'html' => $html]);
    exit;
}

// ==================== 分页与列表 ====================
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

$total_words = (int)$db->query("SELECT COUNT(*) FROM words")->fetchColumn();
$total_pages = max(1, ceil($total_words / $per_page));

$stmt = $db->prepare("SELECT * FROM words ORDER BY memory_level ASC, word ASC LIMIT :limit OFFSET :offset");
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$words = $stmt->fetchAll(PDO::FETCH_ASSOC);

function page_url($p) {
    return '?' . http_build_query(array_merge($_GET, ['page' => $p]));
}
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <title>单词管理 - Alan</title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body { font-family: system-ui, sans-serif; padding: 20px; background: #f4f7f6; color: #333; margin: 0; }
        .header { display: flex; justify-content: space-between; align-items: center; background: #333; color: white; padding: 15px 25px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .header h1 { margin: 0; font-size: 1.2em; }
        .header a { color: #aaa; text-decoration: none; font-size: 0.9em; transition: color 0.2s; }
        .header a:hover { color: #fff; }
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; font-weight: 600; color: #666; }
        tr:hover { background: #fafafa; }
        .edit-btn, .save-btn, .cancel-btn { padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; font-size: 0.85em; transition: opacity 0.2s; }
        .edit-btn { background: #007bff; color: #fff; }
        .save-btn { background: #28a745; color: #fff; }
        .cancel-btn { background: #6c757d; color: #fff; }
        .mastered { color: #28a745; font-weight: bold; }
        .not-mastered { color: #dc3545; }
        .pagination { text-align: center; margin: 30px 0; }
        .pagination a, .pagination span { display: inline-block; padding: 8px 16px; margin: 0 4px; background: #fff; border: 1px solid #ddd; border-radius: 6px; text-decoration: none; color: #333; }
        .pagination .current { background: #007bff; color: #fff; border-color: #007bff; }
        .pagination .disabled { color: #ccc; cursor: not-allowed; }
    </style>
</head>
<body>

<div class="header">
    <h1>单词库管理</h1>
    <div style="display:flex; align-items:center; gap:15px;">
        <input type="text" id="searchWord" placeholder="搜索单词..." style="padding:8px 12px; border-radius:4px; border:none; outline:none; width:200px;">
        <span style="font-size:0.9em; color:#999;"><?= $total_words ?> 词 | <?= $page ?>/<?= $total_pages ?> 页</span>
        <a href="alan.php">返回复习</a>
        <a href="?logout=1">退出</a>
    </div>
</div>

<table id="wordsTable">
    <thead>
        <tr>
            <th>单词</th>
            <th>释义</th>
            <th>等级</th>
            <th>上次学习</th>
            <th>下次复习</th>
            <th>掌握</th>
            <th>操作</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($words as $w): ?>
        <tr data-id="<?= $w['id'] ?>">
            <td><strong><?= h($w['word']) ?></strong></td>
            <td style="max-width:300px; word-wrap:break-word;"><?= nl2br(h($w['meaning'])) ?></td>
            <td>
                <span class="level-display"><?= $w['memory_level'] ?></span>
                <input type="number" class="level-input" min="0" max="9" value="<?= $w['memory_level'] ?>" style="display:none;">
            </td>
            <td>
                <span class="last-display" data-timestamp="<?= $w['last_studied_at'] ?>"><?= format_time((int)$w['last_studied_at']) ?></span>
                <select class="last-preset" style="display:none;">
                    <option value="keep">保持不变</option>
                    <option value="now">设为现在</option>
                    <option value="custom">自定义时间</option>
                </select>
                <input type="datetime-local" class="last-input" style="display:none; margin-top:5px;">
            </td>
            <td>
                <span class="time-display" data-timestamp="<?= $w['next_review_at'] ?>"><?= format_time((int)$w['next_review_at']) ?></span>
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
                    <input type="checkbox" class="mastered-input" <?= $w['is_mastered'] ? 'checked' : '' ?>> 已掌握
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

<div class="pagination">
    <?php if ($page > 1): ?><a href="<?= page_url($page - 1) ?>">上一页</a><?php endif; ?>
    <?php
    $start = max(1, $page - 2);
    $end = min($total_pages, $page + 2);
    for ($i = $start; $i <= $end; $i++): ?>
        <a href="<?= page_url($i) ?>" class="<?= $i == $page ? 'current' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <?php if ($page < $total_pages): ?><a href="<?= page_url($page + 1) ?>">下一页</a><?php endif; ?>
</div>

<script>
$(function(){
    // 复用 database.php 中的编辑逻辑，但进行了简化
    $(document).on('click', '.edit-btn', function(){
        const row = $(this).closest('tr');
        row.find('.level-display, .last-display, .time-display, .mastered-display, .edit-btn').hide();
        row.find('.level-input, .last-preset, .time-preset, .save-btn, .cancel-btn').show();
        row.find('.mastered-input').closest('label').show();
    });

    $(document).on('change', '.last-preset, .time-preset', function(){
        $(this).next('input').toggle($(this).val() === 'custom');
    });

    $(document).on('click', '.cancel-btn', function(){
        location.reload();
    });

    $(document).on('click', '.save-btn', function(){
        const row = $(this).closest('tr');
        const data = {
            action: 'update',
            id: row.data('id'),
            memory_level: row.find('.level-input').val(),
            is_mastered: row.find('.mastered-input').is(':checked') ? 1 : 0,
            last_studied_at: row.find('.last-preset').val(),
            next_review_at: row.find('.time-preset').val(),
            custom_last_time: row.find('.last-input').val(),
            custom_time: row.find('.time-input').val()
        };
        $.post('', data, function(res){
            if(res.success) location.reload();
            else alert(res.message);
        }, 'json');
    });

    $('#searchWord').on('keypress', function(e){
        if(e.which === 13){
            const w = $(this).val().trim();
            if(!w) return;
            $.get('', {action:'search', word:w}, function(res){
                if(res.success){
                    $('tr[data-id="'+res.id+'"]').remove();
                    $('#wordsTable tbody').prepend(res.html);
                } else alert(res.message);
            }, 'json');
        }
    });
});
</script>
</body>
</html>
