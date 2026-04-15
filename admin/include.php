<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}
require dirname(__DIR__) . '/config.php';

try {
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        $pdo->exec("ALTER TABLE users ADD COLUMN role ENUM('admin','user') NOT NULL DEFAULT 'user' AFTER password_hash");
        $pdo->exec("UPDATE users SET role = 'admin' WHERE role IS NULL OR role = ''");
    }
} catch (Throwable $e) {
    // Таблица users может отсутствовать в ранних установках; не ломаем вход в админку.
}

if (!isset($_SESSION['admin_role']) || !in_array((string) $_SESSION['admin_role'], ['admin', 'user'], true)) {
    $_SESSION['admin_role'] = 'admin';
}
if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$e = static function ($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$csrfField = static function () use ($e): string {
    return '<input type="hidden" name="csrf_token" value="' . $e((string) ($_SESSION['csrf_token'] ?? '')) . '">';
};
$verifyCsrfPost = static function (): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    $token = (string) ($_POST['csrf_token'] ?? '');
    $sess = (string) ($_SESSION['csrf_token'] ?? '');
    if ($token === '' || $sess === '' || !hash_equals($sess, $token)) {
        http_response_code(419);
        die('CSRF token mismatch');
    }
};
$verifyCsrfPost();

$isAdmin = static function (): bool {
    return (string) ($_SESSION['admin_role'] ?? 'admin') === 'admin';
};

$requireAdmin = static function () use ($isAdmin): void {
    if (!$isAdmin()) {
        http_response_code(403);
        header('Location: numbers.php');
        exit;
    }
};
