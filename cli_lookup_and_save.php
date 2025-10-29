<?php
// cli_lookup_and_save.php

// 检查是否通过命令行提供了单词参数
if ($argc < 2) {
    die("用法: php cli_lookup_and_save.php <要查询的单词>\n");
}

$word_to_lookup = trim($argv[1]);

if (empty($word_to_lookup)) {
    die("错误: 单词不能为空。\n");
}

// 数据库文件路径
$memory_db_file = 'words.sqlite';
$dict_db_file = 'dict.sqlite';

// 初始化数据库连接
try {
    $memory_db = new PDO("sqlite:$memory_db_file");
    $memory_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $dict_db = new PDO("sqlite:$dict_db_file");
    $dict_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (PDOException $e) {
    die("数据库连接失败: " . $e->getMessage() . "\n");
}

$meaning = '';

// =========================================================================
// 阶段 1: 在 words.sqlite (记忆库) 中查询
// =========================================================================
echo "-> 阶段 1: 在记忆库 ('$memory_db_file') 中查询 '$word_to_lookup'...\n";

$stmt = $memory_db->prepare("SELECT meaning FROM words WHERE word = ?");
$stmt->execute([$word_to_lookup]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);

if ($result) {
    $meaning = $result['meaning'];
    echo "✅ 结果已在记忆库中找到。\n";
    echo "   单词: " . $word_to_lookup . "\n";
    echo "   释义: " . $meaning . "\n";
    exit(0); // 找到并输出，脚本结束
}

echo "❌ 单词不在记忆库中。\n";

// =========================================================================
// 阶段 2: 在 dict.db (字典库) 中查询
// =========================================================================
echo "-> 阶段 2: 在字典库 ('$dict_db_file') 中查询 '$word_to_lookup'...\n";

// 注意: dict.db 使用 'definition' 列名
$stmt = $dict_db->prepare("SELECT definition FROM words WHERE word = ?");
$stmt->execute([$word_to_lookup]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);

if ($result) {
    $meaning = $result['definition'];
    echo "✅ 结果已在字典库中找到。\n";
    echo "   释义: " . $meaning . "\n";

    // =====================================================================
    // 阶段 3: 将结果插入 words.sqlite
    // =====================================================================
    echo "-> 阶段 3: 将结果插入记忆库...\n";

    try {
        // word, meaning, last_studied_at, next_review_at, memory_level
        $stmt = $memory_db->prepare("
            INSERT INTO words (word, meaning, last_studied_at, next_review_at, memory_level)
            VALUES (?, ?, 0, 0, 0)
        ");

        // 插入时，将字典的 'definition' 存入记忆库的 'meaning'
        $stmt->execute([$word_to_lookup, $meaning]);

        echo "🎉 插入成功! 单词已添加到艾宾浩斯记忆列表。\n";
        exit(0);

    } catch (PDOException $e) {
        // 处理 UNIQUE 约束错误等
        echo "❌ 插入记忆库失败: " . $e->getMessage() . "\n";
        exit(1);
    }

} else {
    echo "❌ 错误: 单词 '$word_to_lookup' 在两个数据库中都未找到。\n";
    exit(1);
}
?>
