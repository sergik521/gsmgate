<?php
/** Ожидается: include.php уже подключён, переменная $e. */
$cur = $navCurrent ?? 'home';
$role = (string) ($_SESSION['admin_role'] ?? 'admin');
$isAdminNav = ($role === 'admin');
?>
<header class="admin-header">
    <nav class="admin-nav" aria-label="Основная навигация">
        <div class="admin-nav-brand">
            <span class="admin-nav-title">GSM шлюз</span>
        </div>
        <div class="admin-nav-links">
            <?php if ($isAdminNav): ?>
                <a href="index.php" class="<?php echo $cur === 'home' ? 'is-active' : ''; ?>">Главная</a>
            <?php endif; ?>
            <a href="numbers.php" class="<?php echo $cur === 'numbers' ? 'is-active' : ''; ?>">Numbers</a>
            <a href="address.php" class="<?php echo $cur === 'address' ? 'is-active' : ''; ?>">Address</a>
            <a href="logs.php" class="<?php echo $cur === 'logs' ? 'is-active' : ''; ?>">Logs</a>
            <?php if ($isAdminNav): ?>
                <a href="users.php" class="<?php echo $cur === 'users' ? 'is-active' : ''; ?>">Users</a>
                <a href="firmware.php" class="<?php echo $cur === 'firmware' ? 'is-active' : ''; ?>">Firmware</a>
            <?php endif; ?>
        </div>
        <div class="admin-nav-aside">
            <?php if (!empty($_SESSION['admin_username'])): ?>
                <span class="admin-user" title="Текущий пользователь"><?php echo $e((string) $_SESSION['admin_username']); ?> (<?php echo $e($role); ?>)</span>
            <?php endif; ?>
            <a href="logout.php" class="admin-logout">Выйти</a>
        </div>
    </nav>
</header>
