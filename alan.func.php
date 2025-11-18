<?php
// alan.func.php
declare(strict_types=1);

if (!defined('ALAN_ROOT')) define('ALAN_ROOT', __DIR__);

// ---------- 艾宾浩斯间隔 ----------
$GLOBALS['ebbinghaus_intervals'] = [
    1 => 86400,      // 1天
    2 => 86400,      // 2天
    3 => 172800,     // 4
    4 => 259200,     // 7
    5 => 691200,     // 15
    6 => 1296000,    // 30
    7 => 2592000,    // 60
    8 => 5184000,    // 120
    9 => 10368000    // 240
];

function alan_db(): PDO {
    static $db = null;
    if ($db === null) {
        $file = ALAN_ROOT . '/alan.sqlite';
        $db = new PDO("sqlite:$file");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    return $db;
}

function calculate_next_review_time(int $level): int {
    $intervals = $GLOBALS['ebbinghaus_intervals'];
    if ($level == 0) return strtotime('tomorrow');
    $level = min($level, count($intervals) - 1);
    return time() + $intervals[$level];
}

function format_time(int $ts): string {
    return $ts == 0 ? "新词" : date('m-d H:i', $ts);
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
