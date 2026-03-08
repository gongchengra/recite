<?php
// cli_lookup_and_save.php - 命令行单词查询与自动添加工具
declare(strict_types=1);

require_once __DIR__ . '/alan.func.php';

if ($argc < 2) {
    die("用法: php cli_lookup_and_save.php <要查询的单词>\n");
}

$word = trim($argv[1]);
if (empty($word)) {
    die("错误: 单词不能为空。\n");
}

$db = alan_db();
$dict_db_file = __DIR__ . '/dict.sqlite';

try {
    $dict_db = new PDO("sqlite:$dict_db_file");
    $dict_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("字典数据库连接失败: " . $e->getMessage() . "\n");
}

// 阶段 1: 记忆库查询
echo "-> 阶段 1: 在记忆库中查询 '$word'...\n";
$stmt = $db->prepare("SELECT meaning FROM words WHERE word COLLATE NOCASE = ?");
$stmt->execute([$word]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row) {
    echo "✅ 记忆库已存在：\n   单词: $word\n   释义: " . $row['meaning'] . "\n";
    exit(0);
}

// 阶段 2: 字典库查询
echo "-> 阶段 2: 在字典库中查询 '$word'...\n";
$stmt = $dict_db->prepare("SELECT definition FROM words WHERE word = ?");
$stmt->execute([$word]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row) {
    $meaning = $row['definition'];
    echo "✅ 字典库找到释义。\n";

    // 阶段 3: 插入记忆库
    echo "-> 阶段 3: 插入记忆库...\n";
    try {
        $db->prepare("
            INSERT INTO words (word, meaning, last_studied_at, next_review_at, memory_level)
            VALUES (?, ?, 0, 0, 0)
        ")->execute([$word, $meaning]);
        echo "🎉 成功添加到艾宾浩斯复习列表！\n";
    } catch (PDOException $e) {
        echo "❌ 插入失败: " . $e->getMessage() . "\n";
    }
} else {
    echo "❌ 两个数据库均未找到该词。\n";
}
