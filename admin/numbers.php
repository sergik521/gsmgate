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
    $numbersAddressColumnExists = (bool) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'numbers' AND COLUMN_NAME = 'address_id'")->fetchColumn();
    if (!$numbersAddressColumnExists) {
        $pdo->exec("ALTER TABLE numbers ADD COLUMN address_id INT NULL AFTER active");
    }
    $numbersAddressIndexExists = (bool) $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'numbers' AND INDEX_NAME = 'idx_numbers_address_id'")->fetchColumn();
    if (!$numbersAddressIndexExists) {
        $pdo->exec("ALTER TABLE numbers ADD INDEX idx_numbers_address_id (address_id)");
    }
    $numbersAddressFkExists = (bool) $pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_numbers_address' AND TABLE_NAME = 'numbers'")->fetchColumn();
    if (!$numbersAddressFkExists) {
        $pdo->exec("ALTER TABLE numbers ADD CONSTRAINT fk_numbers_address FOREIGN KEY (address_id) REFERENCES address(id) ON DELETE SET NULL ON UPDATE CASCADE");
    }

    // Миграция legacy-таблицы number_locations -> address + numbers.address_id.
    $legacyExists = (bool) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'number_locations'")->fetchColumn();
    if ($legacyExists) {
        $pdo->exec("
            INSERT INTO address (address, plot_number)
            SELECT DISTINCT COALESCE(address, ''), COALESCE(plot_number, '')
            FROM number_locations
            WHERE COALESCE(address, '') <> '' OR COALESCE(plot_number, '') <> ''
            ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP
        ");
        $pdo->exec("
            UPDATE numbers n
            JOIN number_locations nl ON nl.number_id = n.id
            JOIN address a ON a.address = COALESCE(nl.address, '') AND a.plot_number = COALESCE(nl.plot_number, '')
            SET n.address_id = a.id
            WHERE (COALESCE(nl.address, '') <> '' OR COALESCE(nl.plot_number, '') <> '')
        ");
    }
} catch (Throwable $e) {
    // Если нет прав на DDL — предполагаем, что миграции применены вручную.
}

$normalizeAddressId = static function (PDO $pdo, array $src): ?int {
    $raw = trim((string) ($src['address_id'] ?? ''));
    if ($raw === '') {
        // fallback: если hidden не пришёл (старый кэш), возьмём один из select-полей.
        $raw = trim((string) ($src['address_id_address'] ?? ''));
        if ($raw === '') {
            $raw = trim((string) ($src['address_id_plot'] ?? ''));
        }
    }
    if ($raw === '' || !ctype_digit($raw)) {
        return null;
    }
    $id = (int) $raw;
    if ($id <= 0) {
        return null;
    }
    $q = $pdo->prepare('SELECT id FROM address WHERE id = ? LIMIT 1');
    $q->execute([$id]);
    return $q->fetchColumn() !== false ? $id : null;
};

$resolveAddressId = static function (PDO $pdo, string $address, string $plot): ?int {
    $address = trim($address);
    $plot = trim($plot);
    if ($address === '' && $plot === '') {
        return null;
    }
    $ins = $pdo->prepare('INSERT INTO address (address, plot_number) VALUES (?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), updated_at = CURRENT_TIMESTAMP');
    $ins->execute([$address, $plot]);
    $id = (int) $pdo->lastInsertId();
    return $id > 0 ? $id : null;
};

$buildNumbersFilter = static function (array $src): array {
    $nfId = trim((string) ($src['nf_id'] ?? ''));
    $nfPhone = trim((string) ($src['nf_phone'] ?? ''));
    $nfGroup = trim((string) ($src['nf_group'] ?? ''));
    $nfActive = trim((string) ($src['nf_active'] ?? ''));
    $nfAddress = trim((string) ($src['nf_address'] ?? ''));
    $nfPlot = trim((string) ($src['nf_plot'] ?? ''));

    $where = [];
    $params = [];
    if ($nfId !== '' && ctype_digit($nfId)) {
        $where[] = 'n.id = ?';
        $params[] = (int) $nfId;
    }
    if ($nfPhone !== '') {
        $needle = str_replace(['%', '_'], '', $nfPhone);
        if ($needle !== '') {
            $where[] = 'n.phone_number LIKE ?';
            $params[] = '%' . $needle . '%';
        }
    }
    if ($nfGroup !== '' && is_numeric($nfGroup)) {
        $where[] = 'n.group_id = ?';
        $params[] = (int) $nfGroup;
    }
    if ($nfActive !== '' && ($nfActive === '0' || $nfActive === '1')) {
        $where[] = 'n.active = ?';
        $params[] = (int) $nfActive;
    }
    if ($nfAddress !== '') {
        $needle = str_replace(['%', '_'], '', $nfAddress);
        if ($needle !== '') {
            $where[] = 'a.address LIKE ?';
            $params[] = '%' . $needle . '%';
        }
    }
    if ($nfPlot !== '') {
        $needle = str_replace(['%', '_'], '', $nfPlot);
        if ($needle !== '') {
            $where[] = 'a.plot_number LIKE ?';
            $params[] = '%' . $needle . '%';
        }
    }
    return [$nfId, $nfPhone, $nfGroup, $nfActive, $nfAddress, $nfPlot, $where, $params];
};

$retQ = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $retQ = trim((string) ($_POST['return_query'] ?? ''));
    if (strlen($retQ) > 512) {
        $retQ = '';
    }

    if (isset($_POST['add_number'])) {
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $group = (int) ($_POST['group'] ?? 0);
        $active = (int) ($_POST['active'] ?? 1);
        $addressId = $normalizeAddressId($pdo, $_POST);
        if ($group !== 0 && $group !== 1) {
            $group = 0;
        }
        $active = $active ? 1 : 0;
        if ($phone !== '') {
            $stmt = $pdo->prepare('INSERT INTO numbers (phone_number, group_id, active, address_id) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE group_id = VALUES(group_id), active = VALUES(active), address_id = VALUES(address_id)');
            $stmt->execute([$phone, $group, $active, $addressId]);
        }
    } elseif (isset($_POST['save_number_row'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $phone = trim((string) ($_POST['phone_number'] ?? ''));
        $group = (int) ($_POST['group_id'] ?? 0);
        $active = (int) ($_POST['active'] ?? 1);
        $addressId = $normalizeAddressId($pdo, $_POST);
        if ($group !== 0 && $group !== 1) {
            $group = 0;
        }
        $active = $active ? 1 : 0;
        if ($id > 0 && $phone !== '') {
            $stmt = $pdo->prepare('UPDATE numbers SET phone_number = ?, group_id = ?, active = ?, address_id = ? WHERE id = ?');
            $stmt->execute([$phone, $group, $active, $addressId, $id]);
        }
    } elseif (isset($_POST['deactivate_number'])) {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE numbers SET active = 0 WHERE id = ?');
            $stmt->execute([$id]);
        }
    } elseif (isset($_POST['activate_number'])) {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE numbers SET active = 1 WHERE id = ?');
            $stmt->execute([$id]);
        }
    } elseif (isset($_POST['purge_number'])) {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM numbers WHERE id = ?');
            $stmt->execute([$id]);
        }
    } elseif ($isAdminRole && isset($_POST['import_csv']) && isset($_FILES['csv_file']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        $tmp = $_FILES['csv_file']['tmp_name'];
        $size = (int) ($_FILES['csv_file']['size'] ?? 0);
        if ($size > 0 && $size <= 2 * 1024 * 1024) {
            $raw = file_get_contents($tmp);
            if ($raw !== false && $raw !== '') {
                if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
                    $raw = substr($raw, 3);
                }
                $lines = preg_split("/\r\n|\n|\r/", $raw);
                $lines = array_values(array_filter($lines, static function ($ln) {
                    return trim((string) $ln) !== '';
                }));
                if ($lines !== []) {
                    $delim = (strpos($lines[0], ';') !== false && strpos($lines[0], ',') === false) ? ';' : ',';
                    $row0 = str_getcsv($lines[0], $delim);
                    $headerKeywords = [
                        'id', 'ид', 'phone', 'phone_number', 'номер', 'tel',
                        'group', 'group_id', 'группа', 'active', 'активен',
                        'address_id', 'address', 'адрес', 'plot_number', 'участок', 'номер_участка',
                    ];
                    $isHeader = false;
                    foreach ($row0 as $cell) {
                        $c = strtolower(trim((string) $cell));
                        if (in_array($c, $headerKeywords, true)) {
                            $isHeader = true;
                            break;
                        }
                    }
                    $map = ['phone' => 0, 'group' => 1];
                    $start = 0;
                    if ($isHeader) {
                        foreach ($row0 as $i => $col) {
                            $k = strtolower(trim((string) $col));
                            if ($k === 'id' || $k === 'ид') $map['id'] = $i;
                            if ($k === 'phone' || $k === 'phone_number' || $k === 'номер' || $k === 'tel') $map['phone'] = $i;
                            if ($k === 'group' || $k === 'group_id' || $k === 'группа') $map['group'] = $i;
                            if ($k === 'active' || $k === 'активен') $map['active'] = $i;
                            if ($k === 'address_id') $map['address_id'] = $i;
                            if ($k === 'address' || $k === 'адрес') $map['address'] = $i;
                            if ($k === 'plot_number' || $k === 'участок' || $k === 'номер_участка') $map['plot_number'] = $i;
                        }
                        $start = 1;
                    }
                    if (!isset($map['group'])) $map['group'] = 1;

                    $insDup = $pdo->prepare('INSERT INTO numbers (phone_number, group_id, active, address_id) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE group_id = VALUES(group_id), active = VALUES(active), address_id = VALUES(address_id), phone_number = VALUES(phone_number)');
                    $updById = $pdo->prepare('UPDATE numbers SET phone_number = ?, group_id = ?, active = ?, address_id = ? WHERE id = ?');
                    $insById = $pdo->prepare('INSERT INTO numbers (id, phone_number, group_id, active, address_id) VALUES (?, ?, ?, ?, ?)');
                    $chkId = $pdo->prepare('SELECT id FROM numbers WHERE id = ? LIMIT 1');

                    $rows = 0;
                    $maxRows = 5000;
                    for ($li = $start; $li < count($lines) && $rows < $maxRows; $li++) {
                        $row = str_getcsv($lines[$li], $delim);
                        if (!isset($row[$map['phone']])) continue;
                        $phone = trim((string) $row[$map['phone']]);
                        if ($phone === '') continue;

                        $g = isset($row[$map['group']]) ? (int) trim((string) $row[$map['group']]) : 0;
                        if ($g !== 0 && $g !== 1) $g = 0;

                        $act = 1;
                        if (isset($map['active']) && isset($row[$map['active']])) {
                            $av = strtolower(trim((string) $row[$map['active']]));
                            if ($av === '0' || $av === 'no' || $av === 'нет' || $av === 'false') $act = 0;
                        }

                        $addressId = null;
                        if (isset($map['address_id']) && isset($row[$map['address_id']])) {
                            $aidRaw = trim((string) $row[$map['address_id']]);
                            if ($aidRaw !== '' && ctype_digit($aidRaw)) {
                                $aid = (int) $aidRaw;
                                if ($aid > 0) {
                                    $qAid = $pdo->prepare('SELECT id FROM address WHERE id = ? LIMIT 1');
                                    $qAid->execute([$aid]);
                                    if ($qAid->fetchColumn() !== false) $addressId = $aid;
                                }
                            }
                        }
                        if ($addressId === null) {
                            $adr = (isset($map['address']) && isset($row[$map['address']])) ? trim((string) $row[$map['address']]) : '';
                            $plt = (isset($map['plot_number']) && isset($row[$map['plot_number']])) ? trim((string) $row[$map['plot_number']]) : '';
                            $addressId = $resolveAddressId($pdo, $adr, $plt);
                        }

                        $rowId = 0;
                        if (isset($map['id']) && isset($row[$map['id']])) {
                            $idRaw = trim((string) $row[$map['id']]);
                            if ($idRaw !== '' && ctype_digit($idRaw)) $rowId = (int) $idRaw;
                        }

                        if ($rowId > 0) {
                            $chkId->execute([$rowId]);
                            if ($chkId->fetchColumn() !== false) {
                                $updById->execute([$phone, $g, $act, $addressId, $rowId]);
                            } else {
                                try {
                                    $insById->execute([$rowId, $phone, $g, $act, $addressId]);
                                } catch (PDOException $e) {
                                    // конфликт id/уникальности — пропуск.
                                }
                            }
                        } else {
                            $insDup->execute([$phone, $g, $act, $addressId]);
                        }
                        $rows++;
                    }
                }
            }
        }
    } elseif ($isAdminRole && isset($_POST['bulk_set_group'])) {
        [$pfId, $pfPhone, $pfGroup, $pfActive, $pfAddress, $pfPlot, $pWhere, $pParams] = $buildNumbersFilter($_POST);
        $newGroup = (int) ($_POST['bulk_group_value'] ?? 0);
        if ($newGroup !== 0 && $newGroup !== 1) {
            $newGroup = 0;
        }
        $sqlBulk = 'UPDATE numbers SET group_id = ?';
        $vals = [$newGroup];
        if ($pWhere) {
            $sqlBulk .= ' WHERE id IN (SELECT id FROM (SELECT n.id FROM numbers n LEFT JOIN address a ON a.id = n.address_id WHERE ' . implode(' AND ', $pWhere) . ') as filtered_ids)';
            $vals = array_merge($vals, $pParams);
        }
        $stmt = $pdo->prepare($sqlBulk);
        $stmt->execute($vals);
    } elseif ($isAdminRole && isset($_POST['bulk_delete_numbers'])) {
        [$pfId, $pfPhone, $pfGroup, $pfActive, $pfAddress, $pfPlot, $pWhere, $pParams] = $buildNumbersFilter($_POST);
        $mode = (string) ($_POST['bulk_delete_mode'] ?? 'filtered');
        if ($mode === 'all') {
            $pdo->exec('DELETE FROM numbers');
        } elseif ($pWhere) {
            $sqlBulk = 'DELETE FROM numbers WHERE id IN (SELECT id FROM (SELECT n.id FROM numbers n LEFT JOIN address a ON a.id = n.address_id WHERE ' . implode(' AND ', $pWhere) . ') as filtered_ids)';
            $stmt = $pdo->prepare($sqlBulk);
            $stmt->execute($pParams);
        }
    }
    header('Location: numbers.php' . ($retQ !== '' ? '?' . $retQ : ''));
    exit;
}

[$nfId, $nfPhone, $nfGroup, $nfActive, $nfAddress, $nfPlot, $where, $params] = $buildNumbersFilter($_GET);
$sort = $_GET['sort'] ?? 'phone_number';
$dir = strtolower($_GET['dir'] ?? 'asc');
$allowedSort = ['id', 'phone_number', 'group_id', 'active', 'created_at', 'updated_at', 'address', 'plot_number'];
if (!in_array($sort, $allowedSort, true)) $sort = 'phone_number';
if ($dir !== 'asc' && $dir !== 'desc') $dir = 'asc';

$sql = 'SELECT n.*, a.address, a.plot_number FROM numbers n LEFT JOIN address a ON a.id = n.address_id';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
if ($sort === 'address') $orderBy = 'a.address';
elseif ($sort === 'plot_number') $orderBy = 'a.plot_number';
else $orderBy = 'n.' . $sort;
$sql .= ' ORDER BY ' . $orderBy . ' ' . strtoupper($dir);

$retParts = [];
if ($nfId !== '') $retParts['nf_id'] = $nfId;
if ($nfPhone !== '') $retParts['nf_phone'] = $nfPhone;
if ($nfGroup !== '') $retParts['nf_group'] = $nfGroup;
if ($nfActive !== '') $retParts['nf_active'] = $nfActive;
if ($nfAddress !== '') $retParts['nf_address'] = $nfAddress;
if ($nfPlot !== '') $retParts['nf_plot'] = $nfPlot;
$retParts['sort'] = $sort;
$retParts['dir'] = $dir;
$retQuery = http_build_query($retParts);

$addressOptions = [];
try {
    $stmtAddressList = $pdo->query('SELECT id, address, plot_number FROM address ORDER BY address ASC, plot_number ASC, id ASC');
    while ($a = $stmtAddressList->fetch(PDO::FETCH_ASSOC)) {
        $addressOptions[] = $a;
    }
} catch (Throwable $e) {
    $addressOptions = [];
}

$renderAddressPair = static function (string $formId, int $selectedId, array $addressOptions, callable $e, string $suffix): string {
    $html = '<div class="address-pair" data-address-pair>';
    $html .= '<select form="' . $e($formId) . '" name="address_id_address' . $suffix . '" data-role="address">';
    $html .= '<option value="">— адрес —</option>';
    foreach ($addressOptions as $a) {
        $id = (int) ($a['id'] ?? 0);
        if ($id <= 0) continue;
        $label = trim((string) ($a['address'] ?? ''));
        if ($label === '') $label = '(пустой адрес)';
        $html .= '<option value="' . $e((string) $id) . '"' . ($id === $selectedId ? ' selected' : '') . '>' . $e($label) . '</option>';
    }
    $html .= '</select>';

    $html .= '<select form="' . $e($formId) . '" name="address_id_plot' . $suffix . '" data-role="plot">';
    $html .= '<option value="">— участок —</option>';
    foreach ($addressOptions as $a) {
        $id = (int) ($a['id'] ?? 0);
        if ($id <= 0) continue;
        $plot = trim((string) ($a['plot_number'] ?? ''));
        if ($plot === '') $plot = '(без участка)';
        $html .= '<option value="' . $e((string) $id) . '"' . ($id === $selectedId ? ' selected' : '') . '>' . $e($plot) . '</option>';
    }
    $html .= '</select>';
    $html .= '<input form="' . $e($formId) . '" type="hidden" name="address_id" value="' . ($selectedId > 0 ? $e((string) $selectedId) : '') . '" data-role="id">';
    $html .= '</div>';
    return $html;
};

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportSql = 'SELECT n.id, n.phone_number, n.group_id, n.active, n.address_id, a.address, a.plot_number, n.created_at, n.updated_at FROM numbers n LEFT JOIN address a ON a.id = n.address_id';
    $exportParams = [];
    if ($where) {
        $exportSql .= ' WHERE ' . implode(' AND ', $where);
        $exportParams = $params;
    }
    $exportSql .= ' ORDER BY ' . $orderBy . ' ' . strtoupper($dir);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="numbers_' . date('Y-m-d_His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'phone_number', 'group_id', 'active', 'address_id', 'address', 'plot_number', 'created_at', 'updated_at'], ';');
    $stmt = $pdo->prepare($exportSql);
    $stmt->execute($exportParams);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $r['id'],
            $r['phone_number'],
            $r['group_id'],
            $r['active'] ? '1' : '0',
            $r['address_id'] ?? '',
            $r['address'] ?? '',
            $r['plot_number'] ?? '',
            $r['created_at'] ?? '',
            $r['updated_at'] ?? '',
        ], ';');
    }
    fclose($out);
    exit;
}

$exportQuery = $retParts;
$exportQuery['export'] = 'csv';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Таблица numbers</title>
    <link rel="stylesheet" href="style_admin.css">
    <style>
        .address-pair { display: flex; gap: 6px; flex-wrap: wrap; }
        .address-pair select { min-width: 160px; }
    </style>
</head>
<body>
<?php
$rqHidden = $e($retQuery);
$csrfHidden = $e((string) ($_SESSION['csrf_token'] ?? ''));
$navCurrent = 'numbers';
require __DIR__ . '/admin_nav.php';
?>
<div class="admin-wrap">
    <header class="page-header">
        <h1>Таблица <code>numbers</code></h1>
        <p class="page-lead">Номера доступа и группы. Адреса берутся из справочника <code>address</code>; редактирование справочника на странице <a href="address.php">Address</a>.</p>
    </header>

    <div class="section">
        <h2>Импорт / экспорт CSV</h2>
        <p class="muted">Поддерживаются поля <code>address_id</code>, <code>address</code>, <code>plot_number</code>. При импорте, если указан адрес/участок, запись справочника создаётся автоматически.</p>
        <p>
            <a class="btn-link" href="numbers.php?<?php echo $e(http_build_query($exportQuery)); ?>">Скачать CSV</a>
            <span class="muted">(учитывается текущий фильтр ниже)</span>
        </p>
        <?php if ($isAdminRole): ?>
            <form method="post" enctype="multipart/form-data">
                <?php echo $csrfField(); ?>
                <input type="hidden" name="return_query" value="<?php echo $rqHidden; ?>">
                <input type="file" name="csv_file" accept=".csv,text/csv" required>
                <button type="submit" name="import_csv" value="1">Загрузить CSV</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>Фильтр</h2>
        <form method="get" class="col-filters">
            <label>ID
                <input type="text" name="nf_id" value="<?php echo $e($nfId); ?>" inputmode="numeric" pattern="[0-9]*" placeholder="Точное совпадение" autocomplete="off">
            </label>
            <label>Номер (поиск)
                <input type="text" name="nf_phone" value="<?php echo $e($nfPhone); ?>" placeholder="Фрагмент номера" autocomplete="off">
            </label>
            <label>Группа
                <select name="nf_group">
                    <option value="">Все</option>
                    <option value="0" <?php echo $nfGroup === '0' ? 'selected' : ''; ?>>0 — без задержки</option>
                    <option value="1" <?php echo $nfGroup === '1' ? 'selected' : ''; ?>>1 — с задержкой</option>
                </select>
            </label>
            <label>Активен
                <select name="nf_active">
                    <option value="">Все</option>
                    <option value="1" <?php echo $nfActive === '1' ? 'selected' : ''; ?>>Да</option>
                    <option value="0" <?php echo $nfActive === '0' ? 'selected' : ''; ?>>Нет</option>
                </select>
            </label>
            <label>Адрес
                <input type="text" name="nf_address" value="<?php echo $e($nfAddress); ?>" placeholder="Фрагмент адреса" autocomplete="off">
            </label>
            <label>Участок
                <input type="text" name="nf_plot" value="<?php echo $e($nfPlot); ?>" placeholder="Фрагмент участка" autocomplete="off">
            </label>
            <button type="submit">Применить</button>
            <a href="numbers.php">Сбросить</a>
        </form>

        <?php if ($isAdminRole): ?>
            <div class="section" style="margin: 12px 0 16px; padding: 12px;">
                <h2>Массовые операции по текущему фильтру</h2>
                <form method="post" class="col-filters">
                    <?php echo $csrfField(); ?>
                    <input type="hidden" name="return_query" value="<?php echo $rqHidden; ?>">
                    <input type="hidden" name="nf_id" value="<?php echo $e($nfId); ?>">
                    <input type="hidden" name="nf_phone" value="<?php echo $e($nfPhone); ?>">
                    <input type="hidden" name="nf_group" value="<?php echo $e($nfGroup); ?>">
                    <input type="hidden" name="nf_active" value="<?php echo $e($nfActive); ?>">
                    <input type="hidden" name="nf_address" value="<?php echo $e($nfAddress); ?>">
                    <input type="hidden" name="nf_plot" value="<?php echo $e($nfPlot); ?>">
                    <label>Новая группа
                        <select name="bulk_group_value">
                            <option value="0">0 — без задержки</option>
                            <option value="1">1 — с задержкой</option>
                        </select>
                    </label>
                    <button type="submit" name="bulk_set_group" value="1">Применить группу к отфильтрованным</button>
                </form>

                <form method="post" class="col-filters" onsubmit="return confirm('Удалить номера по выбранному режиму? Операция необратима.');">
                    <?php echo $csrfField(); ?>
                    <input type="hidden" name="return_query" value="<?php echo $rqHidden; ?>">
                    <input type="hidden" name="nf_id" value="<?php echo $e($nfId); ?>">
                    <input type="hidden" name="nf_phone" value="<?php echo $e($nfPhone); ?>">
                    <input type="hidden" name="nf_group" value="<?php echo $e($nfGroup); ?>">
                    <input type="hidden" name="nf_active" value="<?php echo $e($nfActive); ?>">
                    <input type="hidden" name="nf_address" value="<?php echo $e($nfAddress); ?>">
                    <input type="hidden" name="nf_plot" value="<?php echo $e($nfPlot); ?>">
                    <label>Режим очистки
                        <select name="bulk_delete_mode">
                            <option value="filtered">Только по текущему фильтру</option>
                            <option value="all">Все номера</option>
                        </select>
                    </label>
                    <button type="submit" name="bulk_delete_numbers" value="1" class="btn-danger">Очистить</button>
                </form>
            </div>
        <?php endif; ?>

        <table>
            <tr>
                <?php
                $sortUrl = static function ($col) use ($retParts, $sort, $dir) {
                    $q = $retParts;
                    $q['sort'] = $col;
                    $q['dir'] = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
                    return 'numbers.php?' . http_build_query($q);
                };
                ?>
                <th><a class="sort-link" href="<?php echo $e($sortUrl('id')); ?>">ID</a></th>
                <th><a class="sort-link" href="<?php echo $e($sortUrl('phone_number')); ?>">Номер</a></th>
                <th><a class="sort-link" href="<?php echo $e($sortUrl('group_id')); ?>">Группа</a></th>
                <th><a class="sort-link" href="<?php echo $e($sortUrl('active')); ?>">Активен</a></th>
                <th><a class="sort-link" href="<?php echo $e($sortUrl('address')); ?>">Адрес / участок</a></th>
                <th>Действия</th>
            </tr>
            <?php
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                while ($row = $stmt->fetch()) {
                    $rid = (int) $row['id'];
                    $fid = 'save-num-' . $rid;
                    $g0 = ((int) $row['group_id'] === 0);
                    $isActive = !empty($row['active']);
                    $selectedAddressId = (int) ($row['address_id'] ?? 0);
                    echo '<tr>';
                    echo '<td>';
                    echo '<form id="' . $e($fid) . '" method="post" class="inline-form"></form>';
                    echo '<input form="' . $e($fid) . '" type="hidden" name="csrf_token" value="' . $csrfHidden . '">';
                    echo '<input form="' . $e($fid) . '" type="hidden" name="return_query" value="' . $rqHidden . '">';
                    echo '<input form="' . $e($fid) . '" type="hidden" name="save_number_row" value="1">';
                    echo '<input form="' . $e($fid) . '" type="hidden" name="id" value="' . $rid . '">';
                    echo $e((string) $rid);
                    echo '</td>';
                    echo '<td><input form="' . $e($fid) . '" class="table-inline-input" type="text" name="phone_number" value="' . $e($row['phone_number']) . '" required maxlength="20" autocomplete="off"></td>';
                    echo '<td><select form="' . $e($fid) . '" name="group_id">';
                    echo '<option value="0"' . ($g0 ? ' selected' : '') . '>0 — без задержки</option>';
                    echo '<option value="1"' . (!$g0 ? ' selected' : '') . '>1 — с задержкой</option>';
                    echo '</select></td>';
                    echo '<td><select form="' . $e($fid) . '" name="active">';
                    echo '<option value="1"' . ($isActive ? ' selected' : '') . '>Да</option>';
                    echo '<option value="0"' . (!$isActive ? ' selected' : '') . '>Нет</option>';
                    echo '</select></td>';
                    echo '<td>' . $renderAddressPair($fid, $selectedAddressId, $addressOptions, $e, '') . '</td>';
                    echo '<td class="actions-cell">';
                    echo '<button form="' . $e($fid) . '" type="submit" class="btn-compact">Сохранить</button> ';
                    if ($isActive) {
                        echo '<form method="post" class="inline-form">';
                        echo '<input type="hidden" name="csrf_token" value="' . $csrfHidden . '">';
                        echo '<input type="hidden" name="return_query" value="' . $rqHidden . '">';
                        echo '<input type="hidden" name="id" value="' . $e((string) $rid) . '">';
                        echo '<button type="submit" name="deactivate_number" value="1">Деактивировать</button></form> ';
                    } else {
                        echo '<form method="post" class="inline-form">';
                        echo '<input type="hidden" name="csrf_token" value="' . $csrfHidden . '">';
                        echo '<input type="hidden" name="return_query" value="' . $rqHidden . '">';
                        echo '<input type="hidden" name="id" value="' . $e((string) $rid) . '">';
                        echo '<button type="submit" name="activate_number" value="1">Активировать</button></form> ';
                    }
                    echo '<form method="post" class="inline-form" onsubmit="return confirm(\'Удалить номер из базы безвозвратно?\');">';
                    echo '<input type="hidden" name="csrf_token" value="' . $csrfHidden . '">';
                    echo '<input type="hidden" name="return_query" value="' . $rqHidden . '">';
                    echo '<input type="hidden" name="id" value="' . $e((string) $rid) . '">';
                    echo '<button type="submit" name="purge_number" value="1" class="btn-danger">Удалить</button></form>';
                    echo '</td>';
                    echo '</tr>';
                }
            } catch (PDOException $ex) {
                echo '<tr><td colspan="6">' . $e($ex->getMessage()) . '</td></tr>';
            }
            ?>
            <tr class="numbers-add-row">
                <td>—</td>
                <td colspan="5">
                    <form id="add-number-form" method="post" class="numbers-add-form">
                        <?php echo $csrfField(); ?>
                        <input type="hidden" name="return_query" value="<?php echo $rqHidden; ?>">
                        <span class="muted">Новый номер:</span>
                        <input type="text" name="phone" placeholder="Номер" required maxlength="20" autocomplete="off">
                        <select name="group">
                            <option value="0">Группа 0</option>
                            <option value="1">Группа 1</option>
                        </select>
                        <select name="active">
                            <option value="1" selected>Активен</option>
                            <option value="0">Неактивен</option>
                        </select>
                        <?php echo $renderAddressPair('add-number-form', 0, $addressOptions, $e, '_new'); ?>
                        <button type="submit" name="add_number" value="1">Добавить</button>
                    </form>
                </td>
            </tr>
        </table>
    </div>
</div>
<script>
(function () {
    function syncPair(root, value) {
        var addressSel = root.querySelector('select[data-role="address"]');
        var plotSel = root.querySelector('select[data-role="plot"]');
        var hidden = root.querySelector('input[data-role="id"]');
        if (!addressSel || !plotSel || !hidden) return;
        if (!value) {
            addressSel.value = '';
            plotSel.value = '';
            hidden.value = '';
            return;
        }
        addressSel.value = value;
        plotSel.value = value;
        hidden.value = value;
    }

    document.querySelectorAll('[data-address-pair]').forEach(function (root) {
        var addressSel = root.querySelector('select[data-role="address"]');
        var plotSel = root.querySelector('select[data-role="plot"]');
        var hidden = root.querySelector('input[data-role="id"]');
        if (!addressSel || !plotSel || !hidden) return;

        var initial = hidden.value || addressSel.value || plotSel.value || '';
        syncPair(root, initial);

        addressSel.addEventListener('change', function () {
            syncPair(root, addressSel.value);
        });
        plotSel.addEventListener('change', function () {
            syncPair(root, plotSel.value);
        });
    });
})();
</script>
</body>
</html>
