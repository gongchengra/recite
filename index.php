<?php
// index.php - 艾宾浩斯单词复习主界面
declare(strict_types=1);

require_once __DIR__ . '/alan.login.php';   // 登录与权限校验
require_once __DIR__ . '/alan.func.php';    // 公共函数与业务逻辑

$db = alan_db();

// ==================== 请求处理 ====================
handle_ajax_search($db);
$msg = handle_word_update($db);
if (!$msg) {
    $msg = handle_review_action($db);
}

// ==================== 数据获取 ====================
$words_to_review = get_words_to_review($db);
$review_count = count($words_to_review);
$estimated_time = $review_count > 0 ? floor($review_count * 10 / 60) . " 分 " . ($review_count * 10 % 60) . " 秒" : "0 秒";

// 表单保留
$retain_word    = $_POST['new_word'] ?? '';
$retain_meaning = $_POST['new_meaning'] ?? '';
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <title>Alan - 艾宾浩斯单词本</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body{font-family:system-ui,-apple-system,sans-serif;max-width:800px;margin:auto;padding:20px;background:#f9f9f9;color:#333;}
        h1{text-align:center;color:#2c3e50;margin-top:10px;}
        .msg-container{margin: 10px 0;}
        .msg{padding:12px;border-radius:6px;font-weight:bold;box-shadow:0 2px 5px rgba(0,0,0,0.05);text-align:center;background:#fff;border:1px solid #eee;}
        .msg:empty{display:none;}
        .card{background:#fff;border:1px solid #eee;padding:20px;margin:15px 0;border-radius:8px;position:relative;transition:transform 0.2s;}
        .card:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,0.05);}
        .new{border-left:5px solid #007bff;}
        .due{border-left:5px solid #dc3545;}
        .ok{border-left:5px solid #28a745;}
        textarea{width:100%;font-family:inherit;padding:12px;border:1px solid #ddd;border-radius:8px;box-sizing:border-box;font-size:16px;}
        button{padding:10px 20px;cursor:pointer;margin:5px;border:none;border-radius:6px;font-size:14px;transition:opacity 0.2s;}
        button:hover{opacity:0.9;}
        .btn-remember{background:#28a745;color:#fff;}
        .btn-forgot{background:#dc3545;color:#fff;}
        .btn-mastered{background:#6f42c1;color:#fff;}
        .input-group{display:flex;gap:10px;margin-bottom:15px;}
        .input-group input{flex:1;padding:12px;border:1px solid #ddd;border-radius:8px;font-size:16px;box-sizing:border-box;}
        .search-btn{background:#007bff;color:#fff;white-space:nowrap;}
        .submit-btn{background:#333;color:#fff;width:100%;font-size:16px;margin:0;}
        .stats-bar{background:#e7f3ff;padding:15px;margin:20px 0;border-radius:8px;font-size:1.1em;font-weight:bold;text-align:center;color:#0056b3;}
        .card-menu-btn{position:absolute;top:10px;right:10px;background:none;border:none;font-size:20px;cursor:pointer;color:#999;padding:5px;}
        .card-menu{position:absolute;top:40px;right:10px;background:#fff;border:1px solid #ddd;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.1);z-index:10;display:none;overflow:hidden;}
        .card-menu.show{display:block;}
        .card-menu button{display:block;width:100%;text-align:left;margin:0;border-radius:0;padding:12px 20px;background:#fff;color:#333;border-bottom:1px solid #eee;font-size:14px;}
        .card-menu button:last-child{border-bottom:none;}
        .card-menu button:hover{background:#f5f5f5;}
        .nav-links{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;font-size:14px;}
        .nav-links a{color:#666;text-decoration:none;transition:color 0.2s;}
        .nav-links a:hover{color:#333;}
    </style>
</head>
<body>

<div class="nav-links">
    <div>
        <a href="database.php">后台管理</a> | 
        <a href="test.php">掌握测试</a>
    </div>
    <div style="color:#999;">
        <?php if (!empty($_SESSION['alan_auth'])): ?>
            用户: <span style="color:#333;font-weight:bold;"><?=h($_SESSION['alan_user'])?></span> | 
            <a href="?logout=1" style="color:#dc3545;">退出登录</a>
        <?php else: ?>
            <span style="color:#666;">游客模式 (words.sqlite)</span> | 
            <a href="alan.login.php" style="color:#007bff;font-weight:bold;">登录/切换用户</a>
        <?php endif; ?>
    </div>
</div>

<h1>Alan 单词复习</h1>

<div class="msg-container" id="msg-anchor">
    <div id="msg"><?= $msg ?></div>
</div>

<form method="POST" id="addForm" style="background:#fff;padding:25px;border-radius:12px;border:1px solid #eee;margin-bottom:30px;box-shadow:0 2px 10px rgba(0,0,0,0.05);">
    <div class="input-group">
        <input type="text" name="new_word" id="new_word" placeholder="输入单词" required value="<?=h($retain_word)?>" autocomplete="off">
        <button type="button" class="search-btn" id="searchBtn">搜索已存释义</button>
    </div>
    <p><textarea name="new_meaning" id="new_meaning" rows="4" placeholder="输入释义" required><?=h($retain_meaning)?></textarea></p>
    <p style="margin:0;"><button type="submit" class="submit-btn">添加 / 更新单词</button></p>
</form>

<hr style="border:0;border-top:1px solid #eee;margin:30px 0;">

<?php if(empty($words_to_review)): ?>
    <div style="background:#fff;padding:50px 20px;border-radius:12px;border:1px solid #eee;text-align:center;box-shadow:0 2px 10px rgba(0,0,0,0.05);">
        <p style="color:#999;font-size:1.1em;margin:0;">🎉 太棒了！今日待复习单词已全部清空。</p>
    </div>
<?php else: ?>
    <div class="stats-bar">
        今日待复习：<span style="color:#dc3545;"><?= $review_count ?></span> 个单词 |
        预计耗时：<span style="color:#007bff;"><?= $estimated_time ?></span>
    </div>

    <?php foreach ($words_to_review as $w):
        $due = $w['next_review_at'] > 0 && $w['next_review_at'] <= time();
        $cls = $w['memory_level'] == 0 ? 'new' : ($due ? 'due' : 'ok');
    ?>
    <div class="card <?= $cls ?>">
        <button type="button" class="card-menu-btn">⋮</button>
        <h3 style="margin-top:0;font-size:1.4em;"><?= h($w['word']) ?></h3>
        <p style="color:#666;line-height:1.6;font-size:1.05em;"><?= nl2br(h($w['meaning'])) ?></p>
        <div style="font-size:12px;color:#999;margin-top:15px;border-top:1px solid #f5f5f5;padding-top:10px;">
            上次：<?= format_time((int)$w['last_studied_at']) ?> |
            下次：<?= $w['next_review_at'] == 0 ? '立即' : ($due ? '<span style="color:#dc3545;font-weight:bold;">已到期</span>' : format_time((int)$w['next_review_at'])) ?> |
            等级：<?= $w['memory_level'] ?>/9
        </div>

        <div style="margin-top:15px;">
            <form method="POST" style="display:inline;">
                <input type="hidden" name="review_id" value="<?= $w['id'] ?>">
                <input type="hidden" name="review_action" value="remembered">
                <button type="submit" class="btn-remember">我记住了</button>
            </form>
        </div>

        <div class="card-menu">
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
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<script>
$(function(){
    const $msg = $('#msg'), $word = $('#new_word'), $meaning = $('#new_meaning'), $btn = $('#searchBtn');

    $btn.on('click', function(){
        const w = $word.val().trim();
        if(!w) return;
        $btn.prop('disabled',true).text('搜索中...');
        $.post('', {action:'search', word:w}, function(r){
            if(r.success){
                if(r.found) $meaning.val(r.meaning);
                showMsg(r.message);
            } else showMsg(r.message);
        },'json').always(()=>{$btn.prop('disabled',false).text('搜索已存释义');});
    });

    $word.on('keypress', e => { if(e.which === 13){ e.preventDefault(); $btn.click(); } });

    $('.card-menu-btn').on('click', function(e){
        e.stopPropagation();
        const $m = $(this).siblings('.card-menu');
        $('.card-menu.show').not($m).removeClass('show');
        $m.toggleClass('show');
    });

    $(document).on('click', () => $('.card-menu.show').removeClass('show'));

    function showMsg(html){
        $msg.html('<div class="msg">' + html + '</div>');
        $('html,body').animate({scrollTop:0}, 300);
    }
});
</script>
</body>
</html>
