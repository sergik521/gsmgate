<?php
/**
 * Конфиг БД из .env в корне проекта.
 * Формат строк: KEY=value (без export), комментарии: #.
 */
$envPath = __DIR__ . '/.env';
if (is_file($envPath) && is_readable($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines)) {
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $eqPos = strpos($line, '=');
            if ($eqPos === false) {
                continue;
            }
            $k = trim(substr($line, 0, $eqPos));
            $v = trim(substr($line, $eqPos + 1));
            if ($k === '') {
                continue;
            }
            if ((strlen($v) >= 2 && $v[0] === '"' && substr($v, -1) === '"')
                || (strlen($v) >= 2 && $v[0] === "'" && substr($v, -1) === "'")) {
                $v = substr($v, 1, -1);
            }
            $_ENV[$k] = $v;
            putenv($k . '=' . $v);
        }
    }
}

$DB_HOST = (string) (getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? 'localhost'));
$DB_NAME = (string) (getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? ''));
$DB_USER = (string) (getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? ''));
$DB_PASS = (string) (getenv('DB_PASS') ?: ($_ENV['DB_PASS'] ?? ''));
$charset = (string) (getenv('DB_CHARSET') ?: ($_ENV['DB_CHARSET'] ?? 'utf8mb4'));
if ($DB_NAME === '' || $DB_USER === '') {
    http_response_code(500);
    die('Database is not configured');
}

// Таблица очереди SMS (если у вас другое имя — задайте здесь)
if (!defined('SMS_COMMANDS_TABLE')) {
    define('SMS_COMMANDS_TABLE', 'sms_commands');
    }
if (!defined('USSD_COMMANDS_TABLE')) {
    define('USSD_COMMANDS_TABLE', 'ussd_commands');
    }

$dsn = "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
// Иначе GROUP_CONCAT по умолчанию ~1024 байта: MD5 в PHP ≠ MD5 в триггерах → get_hash «старый», numbers_hash в БД новый
if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
    $options[PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET SESSION group_concat_max_len = 16777216';
}
try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (PDOException $e) {
    http_response_code(500);
    die('Database connection failed');
}

?>