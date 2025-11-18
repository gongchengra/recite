<?php
// alan.php
declare(strict_types=1);
require_once __DIR__ . '/alan.login.php';   // 登录校验
require_once __DIR__ . '/alan.func.php';    // 公共函数

$db = alan_db();                            // PDO 实例

// ==================== 艾宾浩斯间隔 ====================
$intervals = $GLOBALS['ebbinghaus_intervals'];

// ==================== 消息 ====================
$msg = '';

// ==================== AJAX 搜索 ====================
if ($_POST['action'] ?? '' === 'search') {
    header('Content-Type: application/json');
    $word = trim($_POST['word'] ?? '');
    $res  = ['success'=>false,'message'=>''];
    if ($word === '') {
        $res['message'] = '<p style="color:red;">请输入单词</p>';
        echo json_encode($res); exit;
    }
    $stmt = $db->prepare("SELECT meaning FROM words WHERE word COLLATE NOCASE = ?");
    $stmt->execute([$word]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $res = [
            'success'=>true,'found'=>true,'meaning'=>$row['meaning'],
            'message'=>"<p>已找到 <strong>$word</strong>，释义已填充</p>"
        ];
    } else {
        $res = [
            'success'=>true,'found'=>false,
            'message'=>"<p style='color:red;'>未找到 <strong>$word</strong>，可直接添加</p>"
        ];
    }
    echo json_encode($res); exit;
}

// ==================== 添加/更新 ====================
if ($_POST['new_word'] ?? '' !== '') {
    $word    = trim($_POST['new_word']);
    $meaning = trim($_POST['new_meaning']);
    if ($word === '' || $meaning === '') {
        $msg = '<p style="color:red;">请填写完整！</p>';
    } else {
        $check = $db->prepare("SELECT id FROM words WHERE word COLLATE NOCASE = ?");
        $check->execute([$word]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $db->prepare("UPDATE words SET meaning = ? WHERE id = ?")
               ->execute([$meaning, $row['id']]);
            $msg = "<p style='color:orange'>单词 <strong>$word</strong> 更新释义</p>";
        } else {
            $db->prepare("
                INSERT INTO words (word, meaning, last_studied_at, next_review_at, memory_level, is_mastered)
                VALUES (?, ?, 0, 0, 0, 0)
            ")->execute([$word, $meaning]);
            $msg = "<p style='color:green'>添加成功: <strong>$word</strong></p>";
        }
    }
}

// ==================== 复习处理 ====================
if (isset($_POST['review_id'])) {
    $id     = (int)$_POST['review_id'];
    $action = $_POST['review_action'] ?? 'remembered';
    $now    = time();

    $stmt = $db->prepare("SELECT memory_level, is_mastered FROM words WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $msg = "<p style='color:red'>单词不存在</p>";
    } else {
        $lvl = (int)$row['memory_level'];
        $mastered = (int)$row['is_mastered'];

        if ($action === 'mastered') {
            $db->prepare("UPDATE words SET is_mastered = 1 WHERE id = ?")->execute([$id]);
            $msg = "<p style='color:green'>恭喜！<strong>永久记住</strong>该词</p>";
        } elseif ($action === 'forgotten') {
            $next = calculate_next_review_time(0);
            $db->prepare("
                UPDATE words SET memory_level = 0, last_studied_at = ?, next_review_at = ?, is_mastered = 0
                WHERE id = ?
            ")->execute([$now, $next, $id]);
            $msg = "<p style='color:orange'>已重置，下次明天复习</p>";
        } else { // remembered
            if ($mastered) {
                $msg = "<p style='color:gray'>该词已完全记住</p>";
            } else {
                $new_lvl = min($lvl + 1, count($intervals) - 1);
                $next    = calculate_next_review_time($new_lvl);
                $db->prepare("
                    UPDATE words SET last_studied_at = ?, next_review_at = ?, memory_level = ?
                    WHERE id = ?
                ")->execute([$now, $next, $new_lvl, $id]);
                $msg = "<p style='color:green'>复习成功！下次：".date('Y-m-d',$next)."</p>";
            }
        }
    }
}

// ==================== 今日待复习（排除已掌握） ====================
$today_end = strtotime('today') + 86399;
$stmt = $db->prepare("
    SELECT * FROM words
    WHERE (next_review_at = 0 OR next_review_at <= ?)
      AND (is_mastered IS NULL OR is_mastered = 0)
    ORDER BY next_review_at ASC
    LIMIT 100
");
$stmt->execute([$today_end]);
$words_to_review = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 表单保留
$retain_word    = $_POST['new_word'] ?? '';
$retain_meaning = $_POST['new_meaning'] ?? '';
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <title>Alan - 艾宾浩斯单词本</title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body{font-family:system-ui,sans-serif;max-width:800px;margin:auto;padding:20px;}
        .msg{padding:10px;margin:10px 0;border-radius:4px;font-weight:bold;}
        .success{background:#d4edda;color:#155724;}
        .warning{background:#fff3cd;color:#856404;}
        .error{background:#f8d7da;color:#721c24;}
        .card{border:1px solid #ddd;padding:15px;margin:10px 0;border-radius:6px;position:relative;}
        .new{border-left:5px solid #007bff;}
        .due{border-left:5px solid #dc3545;}
        .ok{border-left:5px solid #28a745;}
        textarea{width:100%;font-family:inherit;}
        button{padding:8px 16px;cursor:pointer;margin:0 5px;border:none;border-radius:4px;font-size:.9em;}
        .btn-remember{background:#28a745;color:#fff;}
        .btn-forgot{background:#dc3545;color:#fff;}
        .btn-mastered{background:#6f42c1;color:#fff;}
        .input-group{display:flex;gap:5px;align-items:center;}
        .input-group input{flex:1;}
        .search-btn{background:#007bff;color:#fff;padding:8px 12px;border-radius:4px;font-size:.9em;}
        .search-btn:hover{background:#0056b3;}
        .search-btn:disabled{background:#ccc;cursor:not-allowed;}
        .mastered-tag{color:#28a745;font-weight:bold;}
        .card-menu-btn{position:absolute;top:8px;right:8px;background:none;border:none;font-size:1.4em;cursor:pointer;color:#666;}
        .card-menu-btn:hover{color:#000;}
        .card-menu{position:absolute;top:32px;right:0;background:#fff;border:1px solid #ddd;border-radius:4px;
                   box-shadow:0 2px 8px rgba(0,0,0,.15);min-width:140px;z-index:10;display:none;}
        .card-menu.show{display:block;}
        .card-menu button{display:block;width:100%;text-align:left;margin:0;border-radius:0;font-size:.85em;}
    </style>
</head>
<body>

<h1>Alan - 艾宾浩斯单词复习</h1>
<a href="alan.php?logout=1" style="float:right;font-size:.9em;">退出登录</a>

<div id="msg"><?= $msg ?></div>

<form method="POST" id="addForm">
    <div class="input-group">
        <input type="text" name="new_word" id="new_word" placeholder="输入单词" required value="<?=h($retain_word)?>">
        <button type="button" class="search-btn" id="searchBtn">搜索</button>
    </div>
    <p><textarea name="new_meaning" id="new_meaning" rows="6" placeholder="输入释义" required><?=h($retain_meaning)?></textarea></p>
    <p><button type="submit">添加/更新</button></p>
</form>

<hr>

<?php if(empty($words_to_review)): ?>
    <p>暂无待复习单词</p>
<?php else: foreach($words_to_review as $w):
    $due = $w['next_review_at']>0 && $w['next_review_at']<=time();
    $cls = $w['memory_level']==0 ? 'new' : ($due ? 'due' : 'ok');
?>
    <div class="card <?= $cls ?>">
        <button type="button" class="card-menu-btn" aria-label="更多">…</button>

        <h3><?= h($w['word']) ?></h3>
        <p><strong>释义：</strong><?= nl2br(h($w['meaning'])) ?></p>
        <p>
            上次：<?= format_time((int)$w['last_studied_at']) ?> |
            下次：<?php
                if($w['next_review_at']==0) echo "立即";
                elseif($due) echo "<b style='color:red'>已到期</b>";
                else echo format_time((int)$w['next_review_at']);
            ?> |
            等级：<?= $w['memory_level'] ?>/8
            <?= $w['is_mastered'] ? ' <span class="mastered-tag">[已掌握]</span>' : '' ?>
        </p>

        <?php if(!$w['is_mastered']): ?>
        <div style="margin-top:10px;">
            <form method="POST" style="display:inline;">
                <input type="hidden" name="review_id" value="<?= $w['id'] ?>">
                <input type="hidden" name="review_action" value="remembered">
                <button type="submit" class="btn-remember">我记住了</button>
            </form>
        </div>

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
            <p style="color:#28a745;font-weight:bold;">已永久记住，无需复习</p>
        <?php endif; ?>
    </div>
<?php endforeach; endif; ?>

<script>
$(function(){
    const $msg = $('#msg'), $word = $('#new_word'), $meaning = $('#new_meaning'), $btn = $('#searchBtn');

    $btn.on('click', function(){
        const w = $word.val().trim();
        if(!w){ show('<p style="color:red;">请输入单词</p>'); return; }
        $btn.prop('disabled',true).text('搜索中...');
        $.post('', {action:'search', word:w}, function(r){
            if(r.success){
                if(r.found){ $meaning.val(r.meaning); }
                else{ $meaning.val('').focus(); }
                show(r.message);
            }else show(r.message||'<p style="color:red;">搜索失败</p>');
        },'json').always(()=>{$btn.prop('disabled',false).text('搜索');});
    });
    $word.on('keypress', e=>{ if(e.which===13){e.preventDefault();$btn.click();} });

    $('.card-menu-btn').on('click', function(e){
        e.stopPropagation();
        const $m = $(this).nextAll('.card-menu').first();
        $('.card-menu.show').not($m).removeClass('show');
        $m.toggleClass('show');
    });
    $(document).on('click', e=>{
        if(!$(e.target).closest('.card').length) $('.card-menu.show').removeClass('show');
    });

    function show(html){ $msg.html('<div class="msg">'+html+'</div>'); $('html,body').animate({scrollTop:0},300); }
});
</script>

</body>
</html>
