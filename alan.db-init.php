<?php
// alan.db-init.php
declare(strict_types=1);

$db_file = __DIR__ . '/alan.sqlite';

if (!is_writable(__DIR__)) {
    die("目录不可写，请检查权限");
}

try {
    $db = new PDO("sqlite:$db_file");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 单词表
    $db->exec("
        CREATE TABLE IF NOT EXISTS words (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            word             TEXT    NOT NULL,
            meaning          TEXT    NOT NULL,
            last_studied_at  INTEGER DEFAULT 0,
            next_review_at   INTEGER DEFAULT 0,
            memory_level     INTEGER DEFAULT 0,
            is_mastered      INTEGER DEFAULT 0
        )
    ");

    // 唯一索引（忽略大小写）
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_word_unique ON words(word COLLATE NOCASE)");

    // 兼容旧库：安全添加 is_mastered
    $db->exec("ALTER TABLE words ADD COLUMN is_mastered INTEGER DEFAULT 0");
} catch (PDOException $e) {
    if (!str_contains($e->getMessage(), 'duplicate column name')) {
        die("数据库初始化失败: " . $e->getMessage());
    }
}

// 仅在文件被直接访问时执行（防止重复初始化）
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    echo "alan.sqlite 初始化完成！<br>";
    echo "<a href='alan.php'>进入 Alan 单词本</a>";
    exit;
}
