<?php
require __DIR__ . '/include.php';
if (!$isAdmin()) {
    header('Location: numbers.php');
    exit;
}

$allowed = ['all', 'sms', 'ussd', 'settings'];
$show = $_GET['show'] ?? 'all';
if (!in_array($show, $allowed, true)) {
    $show = 'all';
}
$smsSort = $_GET['sms_sort'] ?? 'id';
$smsDir = strtolower($_GET['sms_dir'] ?? 'desc');
$allowedSmsSort = ['id', 'phone_number', 'message', 'status', 'attempts', 'created_at', 'sent_at'];
if (!in_array($smsSort, $allowedSmsSort, true)) {
    $smsSort = 'id';
}
if ($smsDir !== 'asc' && $smsDir !== 'desc') {
    $smsDir = 'desc';
}
$ussdSort = $_GET['ussd_sort'] ?? 'id';
$ussdDir = strtolower($_GET['ussd_dir'] ?? 'desc');
$allowedUssdSort = ['id', 'code', 'status', 'attempts', 'result', 'created_at', 'sent_at'];
if (!in_array($ussdSort, $allowedUssdSort, true)) {
    $ussdSort = 'id';
}
if ($ussdDir !== 'asc' && $ussdDir !== 'desc') {
    $ussdDir = 'desc';
}

$cfgSet = static function (PDO $pdo, string $key, string $value): void {
    $stmt = $pdo->prepare('INSERT INTO gateway_config (config_key, config_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)');
    $stmt->execute([$key, $value]);
};
$isHexToken = static function (string $t): bool {
    return (bool) preg_match('/^[a-f0-9]{32}$/i', $t);
};

/** Прежний api_token в prev на N суток — шлюз со старым токеном во флеше не получает 403, пока не подтянет новый через get_hash */
$gracePrevFromOldCurrent = static function (PDO $pdo, string $oldCurrent, callable $cfgSet, callable $isHexToken): void {
    $old = strtolower(trim($oldCurrent));
    if ($isHexToken($old)) {
        $cfgSet($pdo, 'prev_api_token', $old);
        $cfgSet($pdo, 'prev_api_token_valid_until', (string) (time() + 86400 * 14));
    } else {
        $cfgSet($pdo, 'prev_api_token', '');
        $cfgSet($pdo, 'prev_api_token_valid_until', '0');
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['send_sms'])) {
        $phone = $_POST['phone'] ?? '';
        $message = $_POST['message'] ?? '';
        $tbl = SMS_COMMANDS_TABLE;
        $stmt = $pdo->prepare("INSERT INTO `{$tbl}` (phone_number, message, status) VALUES (?, ?, 'pending')");
        $stmt->execute([$phone, $message]);
    } elseif (isset($_POST['send_ussd'])) {
        $code = trim($_POST['ussd_code'] ?? '');
        if ($code !== '') {
            $tbl = USSD_COMMANDS_TABLE;
            $stmt = $pdo->prepare("INSERT INTO `{$tbl}` (code, status) VALUES (?, 'pending')");
            $stmt->execute([$code]);
        }
    } elseif (isset($_POST['save_serial_debug'])) {
        $v = (isset($_POST['serial_debug']) && $_POST['serial_debug'] === '1') ? '1' : '0';
        try {
            $stmt = $pdo->prepare('INSERT INTO gateway_config (config_key, config_value) VALUES (\'serial_debug\', ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)');
            $stmt->execute([$v]);
        } catch (PDOException $ex) {
            $u = $pdo->prepare("UPDATE gateway_config SET config_value = ? WHERE config_key = 'serial_debug'");
            $u->execute([$v]);
            if ($u->rowCount() === 0) {
                $pdo->prepare("INSERT INTO gateway_config (config_key, config_value) VALUES ('serial_debug', ?)")->execute([$v]);
            }
        }
    } elseif (isset($_POST['save_gateway_log_level'])) {
        $lvl = (int) ($_POST['gateway_log_level'] ?? 2);
        if ($lvl < 0) {
            $lvl = 0;
        }
        if ($lvl > 2) {
            $lvl = 2;
        }
        $lvStr = (string) $lvl;
        try {
            $stmt = $pdo->prepare('INSERT INTO gateway_config (config_key, config_value) VALUES (\'gateway_log_level\', ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)');
            $stmt->execute([$lvStr]);
        } catch (PDOException $ex) {
            $u = $pdo->prepare("UPDATE gateway_config SET config_value = ? WHERE config_key = 'gateway_log_level'");
            $u->execute([$lvStr]);
            if ($u->rowCount() === 0) {
                $pdo->prepare("INSERT INTO gateway_config (config_key, config_value) VALUES ('gateway_log_level', ?)")->execute([$lvStr]);
            }
        }
    } elseif (isset($_POST['save_relay_timing'])) {
        $sec = (int) ($_POST['relay_delay_group1_sec'] ?? 300);
        if ($sec < 10) {
            $sec = 10;
        }
        if ($sec > 86400) {
            $sec = 86400;
        }
        $delayMs = (string) ($sec * 1000);
        $pulse = (int) ($_POST['relay_pulse_ms'] ?? 1000);
        if ($pulse < 100) {
            $pulse = 100;
        }
        if ($pulse > 60000) {
            $pulse = 60000;
        }
        $pulseStr = (string) $pulse;
        $stmt = $pdo->prepare('INSERT INTO gateway_config (config_key, config_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)');
        $stmt->execute(['relay_delay_group1_ms', $delayMs]);
        $stmt->execute(['relay_pulse_ms', $pulseStr]);
    } elseif (isset($_POST['set_api_token_now'])) {
        $token = strtolower(trim((string) ($_POST['api_token_now'] ?? '')));
        if ($isHexToken($token)) {
            $st = $pdo->prepare("SELECT config_value FROM gateway_config WHERE config_key = 'api_token'");
            $st->execute();
            $oldCurrent = (string) ($st->fetchColumn() ?: '');
            if ($isHexToken($oldCurrent) && strtolower($oldCurrent) !== $token) {
                $gracePrevFromOldCurrent($pdo, $oldCurrent, $cfgSet, $isHexToken);
            } else {
                $cfgSet($pdo, 'prev_api_token', '');
                $cfgSet($pdo, 'prev_api_token_valid_until', '0');
            }
            $cfgSet($pdo, 'api_token', $token);
            $cfgSet($pdo, 'next_api_token', '');
            $cfgSet($pdo, 'token_switch_at', '0');
        }
    } elseif (isset($_POST['schedule_token_rotation'])) {
        $next = strtolower(trim((string) ($_POST['next_api_token'] ?? '')));
        $switchAtInput = trim((string) ($_POST['token_switch_at'] ?? ''));
        if ($isHexToken($next)) {
            $ts = strtotime($switchAtInput);
            if ($ts === false) {
                $ts = time() + 300;
            }
            if ($ts < time() + 60) {
                $ts = time() + 60;
            }
            $cfgSet($pdo, 'next_api_token', $next);
            $cfgSet($pdo, 'token_switch_at', (string) $ts);
        }
    } elseif (isset($_POST['cancel_token_rotation'])) {
        $cfgSet($pdo, 'next_api_token', '');
        $cfgSet($pdo, 'token_switch_at', '0');
    } elseif (isset($_POST['generate_api_token_now'])) {
        $st = $pdo->prepare("SELECT config_value FROM gateway_config WHERE config_key = 'api_token'");
        $st->execute();
        $oldCurrent = (string) ($st->fetchColumn() ?: '');
        try {
            $newToken = bin2hex(random_bytes(16));
        } catch (Throwable $e) {
            $newToken = md5(uniqid((string) mt_rand(), true));
        }
        $newToken = strtolower($newToken);
        if ($isHexToken($oldCurrent)) {
            $gracePrevFromOldCurrent($pdo, $oldCurrent, $cfgSet, $isHexToken);
        } else {
            $cfgSet($pdo, 'prev_api_token', '');
            $cfgSet($pdo, 'prev_api_token_valid_until', '0');
        }
        $cfgSet($pdo, 'api_token', $newToken);
        $cfgSet($pdo, 'next_api_token', '');
        $cfgSet($pdo, 'token_switch_at', '0');
    }
    $retShow = $_POST['ret_show'] ?? 'all';
    if (!in_array($retShow, $allowed, true)) {
        $retShow = 'all';
    }
    header('Location: index.php?show=' . urlencode($retShow));
    exit;
}

$smsTbl = SMS_COMMANDS_TABLE;
$ussdTbl = USSD_COMMANDS_TABLE;

$serialDebug = '1';
try {
    $st = $pdo->prepare("SELECT config_value FROM gateway_config WHERE config_key = 'serial_debug'");
    $st->execute();
    $sdv = $st->fetchColumn();
    if ($sdv !== false && $sdv !== null && $sdv !== '') {
        $serialDebug = (string) $sdv;
    }
} catch (PDOException $ex) {
    $serialDebug = '1';
}

$gatewayLogLevel = 2;
try {
    $st = $pdo->prepare("SELECT config_value FROM gateway_config WHERE config_key = 'gateway_log_level'");
    $st->execute();
    $glv = $st->fetchColumn();
    if ($glv !== false && $glv !== null && $glv !== '') {
        $gatewayLogLevel = max(0, min(2, (int) $glv));
    }
} catch (PDOException $ex) {
    $gatewayLogLevel = 2;
}

$relayDelayGroup1Ms = 300000;
$relayPulseMs = 1000;
try {
    $st = $pdo->prepare("SELECT config_value FROM gateway_config WHERE config_key = 'relay_delay_group1_ms'");
    $st->execute();
    $rv = $st->fetchColumn();
    if ($rv !== false && $rv !== null && $rv !== '') {
        $relayDelayGroup1Ms = (int) $rv;
    }
    $st = $pdo->prepare("SELECT config_value FROM gateway_config WHERE config_key = 'relay_pulse_ms'");
    $st->execute();
    $rv = $st->fetchColumn();
    if ($rv !== false && $rv !== null && $rv !== '') {
        $relayPulseMs = (int) $rv;
    }
} catch (PDOException $ex) {
    // defaults
}
$relayDelayGroup1Sec = max(1, (int) round($relayDelayGroup1Ms / 1000));
$apiToken = '';
$nextApiToken = '';
$tokenSwitchAt = 0;
$prevApiToken = '';
$prevApiUntil = 0;
try {
    $st = $pdo->prepare("SELECT config_value FROM gateway_config WHERE config_key = 'api_token'");
    $st->execute();
    $apiToken = (string) ($st->fetchColumn() ?: '');
    $st = $pdo->prepare("SELECT config_value FROM gateway_config WHERE config_key = 'next_api_token'");
    $st->execute();
    $nextApiToken = (string) ($st->fetchColumn() ?: '');
    $st = $pdo->prepare("SELECT config_value FROM gateway_config WHERE config_key = 'token_switch_at'");
    $st->execute();
    $tokenSwitchAt = (int) ($st->fetchColumn() ?: 0);
    $st = $pdo->prepare("SELECT config_value FROM gateway_config WHERE config_key = 'prev_api_token'");
    $st->execute();
    $prevApiToken = strtolower(trim((string) ($st->fetchColumn() ?: '')));
    $st = $pdo->prepare("SELECT config_value FROM gateway_config WHERE config_key = 'prev_api_token_valid_until'");
    $st->execute();
    $prevApiUntil = (int) ($st->fetchColumn() ?: 0);
} catch (PDOException $ex) {
    $apiToken = '';
    $nextApiToken = '';
    $tokenSwitchAt = 0;
    $prevApiToken = '';
    $prevApiUntil = 0;
}
$tokenSwitchAtLocal = ($tokenSwitchAt > 0) ? date('Y-m-d H:i:s', $tokenSwitchAt) : '';
$prevApiUntilLocal = ($prevApiUntil > 0) ? date('Y-m-d H:i:s', $prevApiUntil) : '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Управление шлюзом ворот</title>
    <link rel="stylesheet" href="style_admin.css">
</head>
<body>
<?php
$navCurrent = 'home';
require __DIR__ . '/admin_nav.php';
?>
<div class="admin-wrap">
    <header class="page-header">
        <h1>Управление GSM шлюзом</h1>
        <p class="page-lead">Очереди команд к модему, настройки отладки и обзор состояния. Разделы ниже можно сузить через список «Разделы на этой странице».</p>
    </header>

    <div class="filter-bar">
        <label for="show">Разделы на этой странице:</label>
        <select id="show" name="show" onchange="location.href='index.php?show='+encodeURIComponent(this.value)">
            <option value="all" <?php echo $show === 'all' ? 'selected' : ''; ?>>Все</option>
            <option value="sms" <?php echo $show === 'sms' ? 'selected' : ''; ?>>Очередь SMS (<?php echo $e($smsTbl); ?>)</option>
            <option value="ussd" <?php echo $show === 'ussd' ? 'selected' : ''; ?>>USSD (<?php echo $e($ussdTbl); ?>)</option>
            <option value="settings" <?php echo $show === 'settings' ? 'selected' : ''; ?>>Настройки шлюза</option>
        </select>
    </div>

<?php if ($show === 'settings'): ?>
    <div class="section">
        <h2>API токен и ротация</h2>
        <p class="muted">Формат токена: 32 hex-символа. При «Применить сразу» или «Сгенерировать» прежний текущий токен автоматически попадает в <code>prev_api_token</code> на 14 суток — шлюз со старым значением во флеше не получит 403 и подтянет новый через <code>get_hash</code>. Ротация по расписанию: задайте <code>next_api_token</code> и время переключения.</p>
        <p><strong>Текущий токен:</strong> <code><?php echo $e($apiToken !== '' ? $apiToken : '(не задан)'); ?></code></p>
        <p><strong>Предыдущий (grace, API всё ещё принимает):</strong> <code><?php echo $e($prevApiToken !== '' ? $prevApiToken : '(нет)'); ?></code><?php if ($prevApiUntil > 0) { ?> до <code><?php echo $e($prevApiUntilLocal); ?></code><?php } ?></p>
        <form method="post" style="margin-bottom:12px;">
            <?php echo $csrfField(); ?>
            <input type="hidden" name="ret_show" value="<?php echo $e($show); ?>">
            <input type="text" name="api_token_now" maxlength="32" pattern="[A-Fa-f0-9]{32}" placeholder="Новый текущий токен (32 hex)" style="width: 360px;" required>
            <button type="submit" name="set_api_token_now" value="1">Применить сразу</button>
            <button type="submit" name="generate_api_token_now" value="1">Сгенерировать и применить</button>
        </form>
        <p><strong>Ожидающий токен:</strong> <code><?php echo $e($nextApiToken !== '' ? $nextApiToken : '(нет)'); ?></code></p>
        <p><strong>Время переключения:</strong> <code><?php echo $e($tokenSwitchAtLocal !== '' ? $tokenSwitchAtLocal : '(не запланировано)'); ?></code></p>
        <form method="post" style="margin-bottom:12px;">
            <?php echo $csrfField(); ?>
            <input type="hidden" name="ret_show" value="<?php echo $e($show); ?>">
            <input type="text" name="next_api_token" maxlength="32" pattern="[A-Fa-f0-9]{32}" placeholder="Следующий токен (32 hex)" style="width: 300px;" required>
            <input type="datetime-local" name="token_switch_at" required>
            <button type="submit" name="schedule_token_rotation" value="1">Запланировать ротацию</button>
        </form>
        <form method="post">
            <?php echo $csrfField(); ?>
            <input type="hidden" name="ret_show" value="<?php echo $e($show); ?>">
            <button type="submit" name="cancel_token_rotation" value="1" class="btn-danger">Отменить ротацию</button>
        </form>
    </div>

    <div class="section">
        <h2>Отладка по Serial (шлюз)</h2>
        <p class="muted">Сохраняется в <code>gateway_config.serial_debug</code>. Шлюз читает флаг при каждом запросе <code>get_hash</code> (периодическая синхронизация). Выключено — меньше шума в UART.</p>
        <form method="post">
            <?php echo $csrfField(); ?>
            <input type="hidden" name="ret_show" value="<?php echo $e($show); ?>">
            <label><input type="checkbox" name="serial_debug" value="1" <?php echo $serialDebug === '1' ? 'checked' : ''; ?>> Включить подробный вывод в Serial</label>
            <button type="submit" name="save_serial_debug">Сохранить</button>
        </form>
    </div>

    <div class="section">
        <h2>Логирование работы шлюза в БД</h2>
        <p class="muted">Шлюз шлёт события на <code>api/?action=log</code>. Уровень приходит в ответе <code>get_hash</code> (как и отладка Serial). Уровень 0 — не слать события с устройства; 1 — без «шумных» записей <code>call/received</code> и <code>call/ignored</code>; 2 — все события. Сообщения о синхронизации со стороны PHP (get_hash, get_data) в таблице логов не зависят от этой настройки.</p>
        <form method="post">
            <?php echo $csrfField(); ?>
            <input type="hidden" name="ret_show" value="<?php echo $e($show); ?>">
            <label for="gateway_log_level">Уровень</label><br>
            <select id="gateway_log_level" name="gateway_log_level" required>
                <option value="0" <?php echo $gatewayLogLevel === 0 ? 'selected' : ''; ?>>0 — выкл. (только серверные записи)</option>
                <option value="1" <?php echo $gatewayLogLevel === 1 ? 'selected' : ''; ?>>1 — без лишних входящих звонков</option>
                <option value="2" <?php echo $gatewayLogLevel === 2 ? 'selected' : ''; ?>>2 — полный лог</option>
            </select>
            <button type="submit" name="save_gateway_log_level" value="1">Сохранить</button>
        </form>
    </div>

    <div class="section">
        <h2>Тайминги реле (шлюз)</h2>
        <p class="muted">Параметры уходят в ответ <code>get_hash</code> вместе с хешем; шлюз применяет их при синхронизации. Группа&nbsp;1 — задержка перед импульсом «открыть»; длительность импульса — время удержания линии реле при открытии и закрытии (во время импульса опрашивается UART модема).</p>
        <form method="post" class="relay-timing-form">
            <?php echo $csrfField(); ?>
            <input type="hidden" name="ret_show" value="<?php echo $e($show); ?>">
            <p>
                <label for="relay_delay_group1_sec">Задержка перед открытием для группы&nbsp;1 (секунды)</label><br>
                <input type="number" id="relay_delay_group1_sec" name="relay_delay_group1_sec" min="10" max="86400" step="1" value="<?php echo $e((string) $relayDelayGroup1Sec); ?>" required>
                <span class="muted">10 с … 24 ч (по умолчанию 300 с = 5 мин)</span>
            </p>
            <p>
                <label for="relay_pulse_ms">Длительность импульса реле (миллисекунды)</label><br>
                <input type="number" id="relay_pulse_ms" name="relay_pulse_ms" min="100" max="60000" step="50" value="<?php echo $e((string) $relayPulseMs); ?>" required>
                <span class="muted">100 … 60000 мс (по умолчанию 1000 мс)</span>
            </p>
            <button type="submit" name="save_relay_timing" value="1">Сохранить тайминги</button>
        </form>
    </div>
<?php endif; ?>

<?php if ($show === 'all' || $show === 'sms'): ?>
    <div class="section">
        <h2>Отправить SMS (очередь → таблица <code><?php echo $e($smsTbl); ?></code>)</h2>
        <form method="post">
            <?php echo $csrfField(); ?>
            <input type="hidden" name="ret_show" value="<?php echo $e($show); ?>">
            <input type="text" name="phone" placeholder="Номер получателя" required>
            <input type="text" name="message" placeholder="Текст сообщения" required style="width: 320px;">
            <button type="submit" name="send_sms">Поставить в очередь</button>
        </form>
    </div>

    <div class="section">
        <h2>Очередь исходящих SMS</h2>
        <table>
            <tr>
                <?php
                $smsSortUrl = static function ($col) use ($show, $smsSort, $smsDir, $ussdSort, $ussdDir) {
                    $q = [
                        'show' => $show,
                        'sms_sort' => $col,
                        'sms_dir' => ($smsSort === $col && $smsDir === 'asc') ? 'desc' : 'asc',
                        'ussd_sort' => $ussdSort,
                        'ussd_dir' => $ussdDir,
                    ];
                    return 'index.php?' . http_build_query($q);
                };
                ?>
                <th><a class="sort-link" href="<?php echo $e($smsSortUrl('id')); ?>">ID</a></th>
                <th><a class="sort-link" href="<?php echo $e($smsSortUrl('phone_number')); ?>">Номер</a></th>
                <th><a class="sort-link" href="<?php echo $e($smsSortUrl('message')); ?>">Сообщение</a></th>
                <th><a class="sort-link" href="<?php echo $e($smsSortUrl('status')); ?>">Статус</a></th>
                <th><a class="sort-link" href="<?php echo $e($smsSortUrl('attempts')); ?>">Попытки</a></th>
                <th><a class="sort-link" href="<?php echo $e($smsSortUrl('created_at')); ?>">Создано</a></th>
                <th><a class="sort-link" href="<?php echo $e($smsSortUrl('sent_at')); ?>">Отправлено</a></th>
            </tr>
            <?php
            try {
                $stmt = $pdo->query("SELECT id, phone_number, message, status, attempts, created_at, sent_at FROM `{$smsTbl}` ORDER BY {$smsSort} " . strtoupper($smsDir) . " LIMIT 100");
                while ($row = $stmt->fetch()) {
                    echo '<tr>';
                    echo '<td>' . $e($row['id']) . '</td>';
                    echo '<td>' . $e($row['phone_number']) . '</td>';
                    echo '<td>' . $e($row['message']) . '</td>';
                    echo '<td>' . $e($row['status']) . '</td>';
                    echo '<td>' . $e($row['attempts'] ?? '') . '</td>';
                    echo '<td>' . $e($row['created_at'] ?? '') . '</td>';
                    echo '<td>' . $e($row['sent_at'] ?? '') . '</td>';
                    echo '</tr>';
                }
            } catch (PDOException $ex) {
                echo '<tr><td colspan="7">Ошибка: ' . $e($ex->getMessage()) . '</td></tr>';
            }
            ?>
        </table>
    </div>
<?php endif; ?>

<?php if ($show === 'all' || $show === 'ussd'): ?>
    <div class="section">
        <h2>Отправить USSD (очередь → таблица <code><?php echo $e($ussdTbl); ?></code>)</h2>
        <p class="muted">Пример: <code>*100#</code>, <code>*111#</code>. Шлюз заберёт команду через API <code>action=commands</code> (тип <code>send_ussd</code>).</p>
        <form method="post">
            <?php echo $csrfField(); ?>
            <input type="hidden" name="ret_show" value="<?php echo $e($show); ?>">
            <input type="text" name="ussd_code" placeholder="*100#" maxlength="32" required pattern="[\*#0-9]+" title="Цифры, * и #">
            <button type="submit" name="send_ussd">В очередь</button>
        </form>
    </div>

    <div class="section">
        <h2>Очередь USSD</h2>
        <table>
            <tr>
                <?php
                $ussdSortUrl = static function ($col) use ($show, $smsSort, $smsDir, $ussdSort, $ussdDir) {
                    $q = [
                        'show' => $show,
                        'sms_sort' => $smsSort,
                        'sms_dir' => $smsDir,
                        'ussd_sort' => $col,
                        'ussd_dir' => ($ussdSort === $col && $ussdDir === 'asc') ? 'desc' : 'asc',
                    ];
                    return 'index.php?' . http_build_query($q);
                };
                ?>
                <th><a class="sort-link" href="<?php echo $e($ussdSortUrl('id')); ?>">ID</a></th>
                <th><a class="sort-link" href="<?php echo $e($ussdSortUrl('code')); ?>">Код</a></th>
                <th><a class="sort-link" href="<?php echo $e($ussdSortUrl('status')); ?>">Статус</a></th>
                <th><a class="sort-link" href="<?php echo $e($ussdSortUrl('attempts')); ?>">Попытки</a></th>
                <th><a class="sort-link" href="<?php echo $e($ussdSortUrl('result')); ?>">Ответ модема</a></th>
                <th><a class="sort-link" href="<?php echo $e($ussdSortUrl('created_at')); ?>">Создано</a></th>
                <th><a class="sort-link" href="<?php echo $e($ussdSortUrl('sent_at')); ?>">Выполнено</a></th>
            </tr>
            <?php
            try {
                $stmt = $pdo->query("SELECT id, code, status, attempts, result, created_at, sent_at FROM `{$ussdTbl}` ORDER BY {$ussdSort} " . strtoupper($ussdDir) . " LIMIT 100");
                while ($row = $stmt->fetch()) {
                    echo '<tr>';
                    echo '<td>' . $e($row['id']) . '</td>';
                    echo '<td>' . $e($row['code']) . '</td>';
                    echo '<td>' . $e($row['status']) . '</td>';
                    echo '<td>' . $e($row['attempts'] ?? '') . '</td>';
                    echo '<td>' . $e($row['result'] ?? '') . '</td>';
                    echo '<td>' . $e($row['created_at'] ?? '') . '</td>';
                    echo '<td>' . $e($row['sent_at'] ?? '') . '</td>';
                    echo '</tr>';
                }
            } catch (PDOException $ex) {
                echo '<tr><td colspan="7">Таблица не создана или ошибка: ' . $e($ex->getMessage()) . '</td></tr>';
            }
            ?>
        </table>
    </div>
<?php endif; ?>

</div>
</body>
</html>