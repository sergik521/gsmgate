<?php
require __DIR__ . '/include.php';

$buildLogsFilter = static function (array $src): array {
    $lfEvent = trim((string) ($src['lf_event'] ?? ''));
    $lfPhone = trim((string) ($src['lf_phone'] ?? ''));
    $lfGroup = trim((string) ($src['lf_group'] ?? ''));
    $lfStatus = trim((string) ($src['lf_status'] ?? ''));
    $lfDetails = trim((string) ($src['lf_details'] ?? ''));
    $lfPeriod = trim((string) ($src['lf_period'] ?? ''));

    $where = [];
    $params = [];
    if ($lfEvent !== '') {
        $where[] = 'event_type = ?';
        $params[] = $lfEvent;
    }
    if ($lfPhone !== '') {
        $where[] = 'phone_number = ?';
        $params[] = $lfPhone;
    }
    if ($lfGroup !== '') {
        if ($lfGroup === '__null__') {
            $where[] = 'group_id IS NULL';
        } else {
            $where[] = 'group_id = ?';
            $params[] = (int) $lfGroup;
        }
    }
    if ($lfStatus !== '') {
        $where[] = 'status = ?';
        $params[] = $lfStatus;
    }
    if ($lfDetails !== '') {
        $where[] = 'details = ?';
        $params[] = $lfDetails;
    }
    if ($lfPeriod === 'today') {
        $where[] = 'created_at >= CURDATE()';
    } elseif ($lfPeriod === '7d') {
        $where[] = 'created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
    } elseif ($lfPeriod === '30d') {
        $where[] = 'created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
    }
    return [$lfEvent, $lfPhone, $lfGroup, $lfStatus, $lfDetails, $lfPeriod, $where, $params];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requireAdmin();
    [$pfEvent, $pfPhone, $pfGroup, $pfStatus, $pfDetails, $pfPeriod, $pWhere, $pParams] = $buildLogsFilter($_POST);
    if (isset($_POST['clear_logs_filtered'])) {
        if ($pWhere) {
            $stmt = $pdo->prepare('DELETE FROM logs WHERE ' . implode(' AND ', $pWhere));
            $stmt->execute($pParams);
        }
    } elseif (isset($_POST['clear_logs_older'])) {
        $days = (int) ($_POST['older_days'] ?? 30);
        if ($days < 1) $days = 1;
        if ($days > 3650) $days = 3650;
        $stmt = $pdo->prepare('DELETE FROM logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)');
        $stmt->execute([$days]);
    } elseif (isset($_POST['clear_logs_all'])) {
        $pdo->exec('DELETE FROM logs');
    }
    $q = [];
    if ($pfEvent !== '') $q['lf_event'] = $pfEvent;
    if ($pfPhone !== '') $q['lf_phone'] = $pfPhone;
    if ($pfGroup !== '') $q['lf_group'] = $pfGroup;
    if ($pfStatus !== '') $q['lf_status'] = $pfStatus;
    if ($pfDetails !== '') $q['lf_details'] = $pfDetails;
    if ($pfPeriod !== '') $q['lf_period'] = $pfPeriod;
    $q['sort'] = trim((string)($_POST['sort'] ?? 'created_at'));
    $q['dir'] = trim((string)($_POST['dir'] ?? 'desc'));
    $q['log_limit'] = (int)($_POST['log_limit'] ?? 50);
    header('Location: logs.php?' . http_build_query($q));
    exit;
}

$lfEvent = trim($_GET['lf_event'] ?? '');
$lfPhone = trim($_GET['lf_phone'] ?? '');
$lfGroup = trim($_GET['lf_group'] ?? '');
$lfStatus = trim($_GET['lf_status'] ?? '');
$lfDetails = trim($_GET['lf_details'] ?? '');
$lfPeriod = trim($_GET['lf_period'] ?? '');
$logLimit = (int) ($_GET['log_limit'] ?? 50);
$sort = $_GET['sort'] ?? 'created_at';
$dir = strtolower($_GET['dir'] ?? 'desc');
if ($dir !== 'asc' && $dir !== 'desc') {
    $dir = 'desc';
}
$allowedSort = ['created_at', 'event_type', 'phone_number', 'group_id', 'status', 'details'];
if (!in_array($sort, $allowedSort, true)) {
    $sort = 'created_at';
}
if ($logLimit < 1 || $logLimit > 500) {
    $logLimit = 50;
}

try {
    $optEvents = $pdo->query('SELECT DISTINCT event_type FROM logs WHERE event_type IS NOT NULL AND event_type != "" ORDER BY event_type LIMIT 300')->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $optEvents = [];
}
try {
    $optPhones = $pdo->query('SELECT DISTINCT phone_number FROM logs WHERE phone_number IS NOT NULL AND phone_number != "" ORDER BY phone_number LIMIT 500')->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $optPhones = [];
}
try {
    $optGroups = $pdo->query('SELECT DISTINCT group_id FROM logs ORDER BY group_id IS NULL, group_id LIMIT 50')->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $optGroups = [];
}
try {
    $optStatuses = $pdo->query('SELECT DISTINCT status FROM logs WHERE status IS NOT NULL AND status != "" ORDER BY status LIMIT 200')->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $optStatuses = [];
}
try {
    $optDetails = $pdo->query('SELECT DISTINCT details FROM logs WHERE details IS NOT NULL AND details != "" ORDER BY details LIMIT 150')->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $optDetails = [];
}

[, , , , , , $where, $params] = $buildLogsFilter($_GET);

$sql = 'SELECT * FROM logs';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY ' . $sort . ' ' . strtoupper($dir) . ' LIMIT ' . (int) $logLimit;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Таблица logs</title>
    <link rel="stylesheet" href="style_admin.css">
</head>
<body>
<?php
$navCurrent = 'logs';
require __DIR__ . '/admin_nav.php';
?>
<div class="admin-wrap">
    <header class="page-header">
        <h1>Таблица <code>logs</code></h1>
        <p class="page-lead">События шлюза: звонки, открытие, синхронизация. Фильтры используют выборки из базы.</p>
    </header>

    <div class="section">
        <h2>Фильтр по столбцам</h2>
        <p class="muted">Значения в списках — из базы (ограниченная выборка). Период — по полю <code>created_at</code>.</p>
        <form method="get" class="col-filters">
            <label>Период
                <select name="lf_period">
                    <option value="">Все даты</option>
                    <option value="today" <?php echo $lfPeriod === 'today' ? 'selected' : ''; ?>>Сегодня</option>
                    <option value="7d" <?php echo $lfPeriod === '7d' ? 'selected' : ''; ?>>7 дней</option>
                    <option value="30d" <?php echo $lfPeriod === '30d' ? 'selected' : ''; ?>>30 дней</option>
                </select>
            </label>
            <label>Тип события
                <select name="lf_event">
                    <option value="">Все</option>
                    <?php foreach ($optEvents as $v): ?>
                        <option value="<?php echo $e($v); ?>" <?php echo $lfEvent === $v ? 'selected' : ''; ?>><?php echo $e($v); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Номер
                <select name="lf_phone">
                    <option value="">Все</option>
                    <?php foreach ($optPhones as $v): ?>
                        <option value="<?php echo $e($v); ?>" <?php echo $lfPhone === $v ? 'selected' : ''; ?>><?php echo $e($v); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Группа
                <select name="lf_group">
                    <option value="">Все</option>
                    <option value="__null__" <?php echo $lfGroup === '__null__' ? 'selected' : ''; ?>>NULL</option>
                    <?php foreach ($optGroups as $v): ?>
                        <?php if ($v === null || $v === '') {
                            continue;
                        } ?>
                        <option value="<?php echo $e((string) $v); ?>" <?php echo $lfGroup === (string) $v ? 'selected' : ''; ?>><?php echo $e((string) $v); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Статус
                <select name="lf_status">
                    <option value="">Все</option>
                    <?php foreach ($optStatuses as $v): ?>
                        <option value="<?php echo $e($v); ?>" <?php echo $lfStatus === $v ? 'selected' : ''; ?>><?php echo $e($v); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Детали
                <select name="lf_details">
                    <option value="">Все</option>
                    <?php foreach ($optDetails as $v): ?>
                        <option value="<?php echo $e($v); ?>" <?php echo $lfDetails === $v ? 'selected' : ''; ?>><?php echo $e(strlen($v) > 90 ? substr($v, 0, 90) . '...' : $v); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Строк
                <select name="log_limit">
                    <?php foreach ([20, 50, 100, 200, 500] as $lim): ?>
                        <option value="<?php echo (int) $lim; ?>" <?php echo $logLimit === $lim ? 'selected' : ''; ?>><?php echo (int) $lim; ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <input type="hidden" name="sort" value="<?php echo $e($sort); ?>">
            <input type="hidden" name="dir" value="<?php echo $e($dir); ?>">
            <button type="submit">Применить</button>
            <a href="logs.php">Сбросить</a>
        </form>

        <?php if ($isAdmin()): ?>
            <div class="section" style="margin: 12px 0 16px; padding: 12px;">
                <h2>Очистка логов</h2>
                <form method="post" class="col-filters" onsubmit="return confirm('Удалить логи по текущему фильтру?');">
                    <?php echo $csrfField(); ?>
                    <input type="hidden" name="lf_event" value="<?php echo $e($lfEvent); ?>">
                    <input type="hidden" name="lf_phone" value="<?php echo $e($lfPhone); ?>">
                    <input type="hidden" name="lf_group" value="<?php echo $e($lfGroup); ?>">
                    <input type="hidden" name="lf_status" value="<?php echo $e($lfStatus); ?>">
                    <input type="hidden" name="lf_details" value="<?php echo $e($lfDetails); ?>">
                    <input type="hidden" name="lf_period" value="<?php echo $e($lfPeriod); ?>">
                    <input type="hidden" name="sort" value="<?php echo $e($sort); ?>">
                    <input type="hidden" name="dir" value="<?php echo $e($dir); ?>">
                    <input type="hidden" name="log_limit" value="<?php echo (int)$logLimit; ?>">
                    <button type="submit" name="clear_logs_filtered" value="1" class="btn-danger">Очистить по текущему фильтру</button>
                </form>
                <form method="post" class="col-filters" onsubmit="return confirm('Удалить старые логи?');">
                    <?php echo $csrfField(); ?>
                    <input type="hidden" name="sort" value="<?php echo $e($sort); ?>">
                    <input type="hidden" name="dir" value="<?php echo $e($dir); ?>">
                    <input type="hidden" name="log_limit" value="<?php echo (int)$logLimit; ?>">
                    <label>Старше (дней)
                        <input type="number" name="older_days" value="30" min="1" max="3650" required>
                    </label>
                    <button type="submit" name="clear_logs_older" value="1" class="btn-danger">Очистить старые</button>
                </form>
                <form method="post" class="col-filters" onsubmit="return confirm('Удалить ВСЕ логи? Операция необратима.');">
                    <?php echo $csrfField(); ?>
                    <input type="hidden" name="sort" value="<?php echo $e($sort); ?>">
                    <input type="hidden" name="dir" value="<?php echo $e($dir); ?>">
                    <input type="hidden" name="log_limit" value="<?php echo (int)$logLimit; ?>">
                    <button type="submit" name="clear_logs_all" value="1" class="btn-danger">Очистить все</button>
                </form>
            </div>
        <?php endif; ?>

        <table>
            <tr>
                <?php
                $sortUrl = static function ($col) use ($lfEvent, $lfPhone, $lfGroup, $lfStatus, $lfDetails, $lfPeriod, $logLimit, $sort, $dir) {
                    $q = [];
                    if ($lfEvent !== '') $q['lf_event'] = $lfEvent;
                    if ($lfPhone !== '') $q['lf_phone'] = $lfPhone;
                    if ($lfGroup !== '') $q['lf_group'] = $lfGroup;
                    if ($lfStatus !== '') $q['lf_status'] = $lfStatus;
                    if ($lfDetails !== '') $q['lf_details'] = $lfDetails;
                    if ($lfPeriod !== '') $q['lf_period'] = $lfPeriod;
                    $q['log_limit'] = $logLimit;
                    $q['sort'] = $col;
                    $q['dir'] = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
                    return 'logs.php?' . http_build_query($q);
                };
                ?>
                <th><a class="sort-link" href="<?php echo $e($sortUrl('created_at')); ?>">Время</a></th>
                <th><a class="sort-link" href="<?php echo $e($sortUrl('event_type')); ?>">Тип</a></th>
                <th><a class="sort-link" href="<?php echo $e($sortUrl('phone_number')); ?>">Номер</a></th>
                <th><a class="sort-link" href="<?php echo $e($sortUrl('group_id')); ?>">Группа</a></th>
                <th><a class="sort-link" href="<?php echo $e($sortUrl('status')); ?>">Статус</a></th>
                <th><a class="sort-link" href="<?php echo $e($sortUrl('details')); ?>">Детали</a></th>
            </tr>
            <?php
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                while ($row = $stmt->fetch()) {
                    echo '<tr>';
                    echo '<td>' . $e($row['created_at'] ?? '') . '</td>';
                    echo '<td>' . $e($row['event_type'] ?? '') . '</td>';
                    echo '<td>' . $e($row['phone_number'] ?? '') . '</td>';
                    echo '<td>' . $e(isset($row['group_id']) && $row['group_id'] !== null && $row['group_id'] !== '' ? (string) $row['group_id'] : 'NULL') . '</td>';
                    echo '<td>' . $e($row['status'] ?? '') . '</td>';
                    echo '<td>' . $e($row['details'] ?? '') . '</td>';
                    echo '</tr>';
                }
            } catch (PDOException $ex) {
                echo '<tr><td colspan="6">' . $e($ex->getMessage()) . '</td></tr>';
            }
            ?>
        </table>
    </div>
</div>
</body>
</html>
