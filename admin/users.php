<?php
require __DIR__ . '/include.php';
$requireAdmin();

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_user'])) {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if ($username === '' || $password === '') {
            $err = 'Заполните логин и пароль.';
        } elseif (strlen($username) < 3) {
            $err = 'Логин должен быть не короче 3 символов.';
        } elseif (strlen($password) < 6) {
            $err = 'Пароль должен быть не короче 6 символов.';
        } else {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $role = (string) ($_POST['role'] ?? 'user');
                if ($role !== 'admin' && $role !== 'user') {
                    $role = 'user';
                }
                $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)');
                $stmt->execute([$username, $hash, $role]);
                $msg = 'Пользователь создан.';
            } catch (PDOException $ex) {
                $err = 'Ошибка создания пользователя: ' . $ex->getMessage();
            }
        }
    } elseif (isset($_POST['rename_user'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $username = trim((string) ($_POST['username'] ?? ''));
        if ($id < 1 || $username === '') {
            $err = 'Некорректные данные для переименования.';
        } else {
            try {
                $stmt = $pdo->prepare('UPDATE users SET username = ? WHERE id = ?');
                $stmt->execute([$username, $id]);
                $msg = 'Логин обновлён.';
            } catch (PDOException $ex) {
                $err = 'Ошибка обновления логина: ' . $ex->getMessage();
            }
        }
    } elseif (isset($_POST['change_password'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $password = (string) ($_POST['new_password'] ?? '');
        if ($id < 1 || $password === '') {
            $err = 'Некорректные данные для смены пароля.';
        } elseif (strlen($password) < 6) {
            $err = 'Новый пароль должен быть не короче 6 символов.';
        } else {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                $stmt->execute([$hash, $id]);
                $msg = 'Пароль обновлён.';
            } catch (PDOException $ex) {
                $err = 'Ошибка обновления пароля: ' . $ex->getMessage();
            }
        }
    } elseif (isset($_POST['set_role'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $role = (string) ($_POST['role'] ?? 'user');
        if ($role !== 'admin' && $role !== 'user') {
            $role = 'user';
        }
        if ($id < 1) {
            $err = 'Некорректный ID пользователя.';
        } else {
            try {
                $stmt = $pdo->prepare('UPDATE users SET role = ? WHERE id = ?');
                $stmt->execute([$role, $id]);
                if ((int) ($_SESSION['admin_id'] ?? 0) === $id) {
                    $_SESSION['admin_role'] = $role;
                }
                $msg = 'Роль обновлена.';
            } catch (PDOException $ex) {
                $err = 'Ошибка обновления роли: ' . $ex->getMessage();
            }
        }
    } elseif (isset($_POST['delete_user'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $selfId = (int) ($_SESSION['admin_id'] ?? 0);
        if ($id < 1) {
            $err = 'Некорректный ID пользователя.';
        } elseif ($id === $selfId) {
            $err = 'Нельзя удалить текущего пользователя.';
        } else {
            try {
                $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
                $stmt->execute([$id]);
                $msg = 'Пользователь удалён.';
            } catch (PDOException $ex) {
                $err = 'Ошибка удаления пользователя: ' . $ex->getMessage();
            }
        }
    }
}

$sort = $_GET['sort'] ?? 'id';
$dir = strtolower($_GET['dir'] ?? 'asc');
$allowedSort = ['id', 'username', 'role', 'created_at'];
if (!in_array($sort, $allowedSort, true)) {
    $sort = 'id';
}
if ($dir !== 'asc' && $dir !== 'desc') {
    $dir = 'asc';
}
$nextDir = static function ($currentSort, $col, $currentDir) {
    if ($currentSort === $col && $currentDir === 'asc') {
        return 'desc';
    }
    return 'asc';
};

$columns = [];
try {
    $columns = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN, 0);
} catch (PDOException $ex) {
    $err = $err ?: 'Не удалось прочитать структуру таблицы users: ' . $ex->getMessage();
}
$hasCreatedAt = in_array('created_at', $columns, true);

if ($sort === 'created_at' && !$hasCreatedAt) {
    $sort = 'id';
}

$rows = [];
try {
    $select = $hasCreatedAt ? 'id, username, role, created_at' : 'id, username, role';
    $stmt = $pdo->query('SELECT ' . $select . ' FROM users ORDER BY ' . $sort . ' ' . strtoupper($dir));
    $rows = $stmt->fetchAll();
} catch (PDOException $ex) {
    $err = $err ?: 'Ошибка чтения пользователей: ' . $ex->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Пользователи админки</title>
    <link rel="stylesheet" href="style_admin.css">
</head>
<body>
<?php
$navCurrent = 'users';
require __DIR__ . '/admin_nav.php';
?>
<div class="admin-wrap">
    <header class="page-header">
        <h1>Пользователи админки</h1>
        <p class="page-lead">Создание, изменение логина, роли, пароля и удаление пользователей.</p>
    </header>

    <?php if ($msg): ?><p class="admin-flash admin-flash--ok"><?php echo $e($msg); ?></p><?php endif; ?>
    <?php if ($err): ?><p class="admin-flash admin-flash--err"><?php echo $e($err); ?></p><?php endif; ?>

    <div class="section">
        <h2>Создать пользователя</h2>
        <form method="post">
            <?php echo $csrfField(); ?>
            <input type="text" name="username" placeholder="Логин" required minlength="3" maxlength="64">
            <input type="password" name="password" placeholder="Пароль" required minlength="6" maxlength="128">
            <select name="role">
                <option value="user" selected>Пользователь</option>
                <option value="admin">Администратор</option>
            </select>
            <button type="submit" name="create_user" value="1">Создать</button>
        </form>
    </div>

    <div class="section">
        <h2>Список пользователей</h2>
        <table>
            <tr>
                <?php
                $idDir = $nextDir($sort, 'id', $dir);
                $nameDir = $nextDir($sort, 'username', $dir);
                $roleDir = $nextDir($sort, 'role', $dir);
                $createdDir = $nextDir($sort, 'created_at', $dir);
                ?>
                <th><a class="sort-link" href="?sort=id&amp;dir=<?php echo $e($idDir); ?>">ID</a></th>
                <th><a class="sort-link" href="?sort=username&amp;dir=<?php echo $e($nameDir); ?>">Логин</a></th>
                <th><a class="sort-link" href="?sort=role&amp;dir=<?php echo $e($roleDir); ?>">Роль</a></th>
                <?php if ($hasCreatedAt): ?>
                    <th><a class="sort-link" href="?sort=created_at&amp;dir=<?php echo $e($createdDir); ?>">Создан</a></th>
                <?php endif; ?>
                <th>Действия</th>
            </tr>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?php echo $e($row['id']); ?></td>
                    <td><?php echo $e($row['username']); ?></td>
                    <td><?php echo $e((string) ($row['role'] ?? 'admin')); ?></td>
                    <?php if ($hasCreatedAt): ?>
                        <td><?php echo $e($row['created_at'] ?? ''); ?></td>
                    <?php endif; ?>
                    <td class="actions-cell">
                        <form method="post" class="inline-form">
                            <?php echo $csrfField(); ?>
                            <input type="hidden" name="id" value="<?php echo $e($row['id']); ?>">
                            <input type="text" name="username" value="<?php echo $e($row['username']); ?>" required minlength="3" maxlength="64">
                            <button type="submit" name="rename_user" value="1">Переименовать</button>
                        </form>
                        <form method="post" class="inline-form">
                            <?php echo $csrfField(); ?>
                            <input type="hidden" name="id" value="<?php echo $e($row['id']); ?>">
                            <select name="role">
                                <option value="user" <?php echo (($row['role'] ?? '') === 'user') ? 'selected' : ''; ?>>Пользователь</option>
                                <option value="admin" <?php echo (($row['role'] ?? 'admin') === 'admin') ? 'selected' : ''; ?>>Администратор</option>
                            </select>
                            <button type="submit" name="set_role" value="1">Роль</button>
                        </form>
                        <form method="post" class="inline-form">
                            <?php echo $csrfField(); ?>
                            <input type="hidden" name="id" value="<?php echo $e($row['id']); ?>">
                            <input type="password" name="new_password" placeholder="Новый пароль" required minlength="6" maxlength="128">
                            <button type="submit" name="change_password" value="1">Сменить пароль</button>
                        </form>
                        <form method="post" class="inline-form" onsubmit="return confirm('Удалить пользователя?');">
                            <?php echo $csrfField(); ?>
                            <input type="hidden" name="id" value="<?php echo $e($row['id']); ?>">
                            <button type="submit" name="delete_user" value="1" class="btn-danger">Удалить</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="<?php echo $hasCreatedAt ? '5' : '4'; ?>">Пользователи не найдены.</td></tr>
            <?php endif; ?>
        </table>
    </div>
</div>
</body>
</html>
