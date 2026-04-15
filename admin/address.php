<?php
require __DIR__ . '/include.php';
$isAdminRole = $isAdmin();

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS address (
            id INT AUTO_INCREMENT PRIMARY KEY,
            address VARCHAR(255) NOT NULL DEFAULT '',
            plot_number VARCHAR(64) NOT NULL DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_address_plot (address(128), plot_number(63)),
            KEY idx_address (address(191)),
            KEY idx_plot_number (plot_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Throwable $e) {
    // Если нет прав на DDL/старый MySQL — страницу всё равно показываем.
}

$retQ = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdminRole) {
    $retQ = trim((string) ($_POST['return_query'] ?? ''));
    if (strlen($retQ) > 512) {
        $retQ = '';
    }

    if (isset($_POST['add_address'])) {
        $address = trim((string) ($_POST['address_name'] ?? ''));
        $plot = trim((string) ($_POST['plot_number'] ?? ''));
        if ($address !== '' || $plot !== '') {
            $stmt = $pdo->prepare('INSERT INTO address (address, plot_number) VALUES (?, ?) ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP');
            $stmt->execute([$address, $plot]);
        }
    } elseif (isset($_POST['save_address'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $address = trim((string) ($_POST['address_name'] ?? ''));
        $plot = trim((string) ($_POST['plot_number'] ?? ''));
        if ($id > 0) {
            if ($address === '' && $plot === '') {
                $stmt = $pdo->prepare('DELETE FROM address WHERE id = ?');
                $stmt->execute([$id]);
            } else {
                $stmt = $pdo->prepare('UPDATE address SET address = ?, plot_number = ? WHERE id = ?');
                $stmt->execute([$address, $plot, $id]);
            }
        }
    } elseif (isset($_POST['delete_address'])) {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM address WHERE id = ?');
            $stmt->execute([$id]);
        }
    }

    header('Location: address.php' . ($retQ !== '' ? '?' . $retQ : ''));
    exit;
}

$afAddress = trim((string) ($_GET['af_address'] ?? ''));
$afPlot = trim((string) ($_GET['af_plot'] ?? ''));
$where = [];
$params = [];
if ($afAddress !== '') {
    $needle = str_replace(['%', '_'], '', $afAddress);
    if ($needle !== '') {
        $where[] = 'address LIKE ?';
        $params[] = '%' . $needle . '%';
    }
}
if ($afPlot !== '') {
    $needle = str_replace(['%', '_'], '', $afPlot);
    if ($needle !== '') {
        $where[] = 'plot_number LIKE ?';
        $params[] = '%' . $needle . '%';
    }
}
$sql = 'SELECT id, address, plot_number, created_at, updated_at FROM address';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY address ASC, plot_number ASC, id ASC';

$retParts = [];
if ($afAddress !== '') {
    $retParts['af_address'] = $afAddress;
}
if ($afPlot !== '') {
    $retParts['af_plot'] = $afPlot;
}
$retQuery = http_build_query($retParts);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Справочник address</title>
    <link rel="stylesheet" href="style_admin.css">
</head>
<body>
<?php
$rqHidden = $e($retQuery);
$csrfHidden = $e((string) ($_SESSION['csrf_token'] ?? ''));
$navCurrent = 'address';
require __DIR__ . '/admin_nav.php';
?>
<div class="admin-wrap">
    <header class="page-header">
        <h1>Справочник <code>address</code></h1>
        <p class="page-lead">Уникальные адреса и участки для выбора в <code>numbers</code>.</p>
    </header>

    <div class="section">
        <h2>Фильтр</h2>
        <form method="get" class="col-filters">
            <label>Адрес
                <input type="text" name="af_address" value="<?php echo $e($afAddress); ?>" placeholder="Фрагмент адреса" autocomplete="off">
            </label>
            <label>Участок
                <input type="text" name="af_plot" value="<?php echo $e($afPlot); ?>" placeholder="Фрагмент участка" autocomplete="off">
            </label>
            <button type="submit">Применить</button>
            <a href="address.php">Сбросить</a>
        </form>
    </div>

    <?php if ($isAdminRole): ?>
    <div class="section">
        <h2>Добавить адрес</h2>
        <form method="post" class="numbers-add-form">
            <?php echo $csrfField(); ?>
            <input type="hidden" name="return_query" value="<?php echo $rqHidden; ?>">
            <input type="text" name="address_name" placeholder="Адрес" maxlength="255" autocomplete="off">
            <input type="text" name="plot_number" placeholder="Номер участка" maxlength="64" autocomplete="off">
            <button type="submit" name="add_address" value="1">Добавить</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="section">
        <h2>Список адресов</h2>
        <table>
            <tr>
                <th>ID</th>
                <th>Адрес</th>
                <th>Участок</th>
                <th>Создан</th>
                <th>Обновлён</th>
                <th>Действия</th>
            </tr>
            <?php
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                while ($row = $stmt->fetch()) {
                    $rid = (int) $row['id'];
                    $fid = 'addr-' . $rid;
                    echo '<tr>';
                    echo '<td>' . $e((string) $rid) . '</td>';
                    if ($isAdminRole) {
                        echo '<td><input form="' . $e($fid) . '" class="table-inline-input" type="text" name="address_name" value="' . $e($row['address']) . '" maxlength="255" autocomplete="off"></td>';
                        echo '<td><input form="' . $e($fid) . '" class="table-inline-input" type="text" name="plot_number" value="' . $e($row['plot_number']) . '" maxlength="64" autocomplete="off"></td>';
                    } else {
                        echo '<td>' . $e($row['address']) . '</td>';
                        echo '<td>' . $e($row['plot_number']) . '</td>';
                    }
                    echo '<td>' . $e((string) ($row['created_at'] ?? '')) . '</td>';
                    echo '<td>' . $e((string) ($row['updated_at'] ?? '')) . '</td>';
                    echo '<td class="actions-cell">';
                    if ($isAdminRole) {
                        echo '<form id="' . $e($fid) . '" method="post" class="inline-form"></form>';
                        echo '<input form="' . $e($fid) . '" type="hidden" name="csrf_token" value="' . $csrfHidden . '">';
                        echo '<input form="' . $e($fid) . '" type="hidden" name="return_query" value="' . $rqHidden . '">';
                        echo '<input form="' . $e($fid) . '" type="hidden" name="save_address" value="1">';
                        echo '<input form="' . $e($fid) . '" type="hidden" name="id" value="' . $e((string) $rid) . '">';
                        echo '<button form="' . $e($fid) . '" type="submit" class="btn-compact">Сохранить</button> ';

                        echo '<form method="post" class="inline-form" onsubmit="return confirm(\'Удалить адрес?\');">';
                        echo '<input type="hidden" name="csrf_token" value="' . $csrfHidden . '">';
                        echo '<input type="hidden" name="return_query" value="' . $rqHidden . '">';
                        echo '<input type="hidden" name="delete_address" value="1">';
                        echo '<input type="hidden" name="id" value="' . $e((string) $rid) . '">';
                        echo '<button type="submit" class="btn-danger">Удалить</button>';
                        echo '</form>';
                    }
                    echo '</td>';
                    echo '</tr>';
                }
            } catch (PDOException $ex) {
                echo '<tr><td colspan="6">Ошибка: ' . $e($ex->getMessage()) . '</td></tr>';
            }
            ?>
        </table>
    </div>
</div>
</body>
</html>
