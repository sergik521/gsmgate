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

// Если уже авторизован, перенаправляем на главную админки
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: index.php');
    exit;
}

$error = '';
require '../config.php';
if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$fail = $_SESSION['login_fail'] ?? ['count' => 0, 'until' => 0];
if (!is_array($fail)) {
    $fail = ['count' => 0, 'until' => 0];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $sessCsrf = (string) ($_SESSION['csrf_token'] ?? '');
    if ($csrf === '' || $sessCsrf === '' || !hash_equals($sessCsrf, $csrf)) {
        $error = 'Сессия устарела, обновите страницу.';
    } elseif ((int) ($fail['until'] ?? 0) > time()) {
        $wait = max(1, (int) $fail['until'] - time());
        $error = 'Слишком много попыток. Подождите ' . $wait . ' сек.';
    } else {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (!empty($username) && !empty($password)) {
        // Подключение к БД
        //$pdo = new PDO("mysql:host=localhost;dbname=gate_controller;charset=utf8mb4", 'root', '');
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $role = (string) ($user['role'] ?? 'admin');
            if ($role !== 'admin' && $role !== 'user') {
                $role = 'admin';
            }
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = $user['id'];
            $_SESSION['admin_username'] = $user['username'];
            $_SESSION['admin_role'] = $role;
            $_SESSION['login_fail'] = ['count' => 0, 'until' => 0];
            header('Location: index.php');
            exit;
        } else {
            $cnt = (int) ($fail['count'] ?? 0) + 1;
            $lockSec = ($cnt >= 8) ? 300 : (($cnt >= 5) ? 60 : 0);
            $_SESSION['login_fail'] = ['count' => $cnt, 'until' => (time() + $lockSec)];
            $error = 'Неверное имя пользователя или пароль';
        }
    } else {
        $error = 'Заполните все поля';
    }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Вход в панель управления</title>
    <link rel="stylesheet" href="style_admin.css">
</head>
<body class="auth-page">
    <div class="login-form">
        <h2>Вход в панель управления</h2>
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
            <input type="text" name="username" placeholder="Имя пользователя" required>
            <input type="password" name="password" placeholder="Пароль" required>
            <button type="submit">Войти</button>
        </form>
    </div>
</body>
</html>