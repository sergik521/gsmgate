<?php
// Настройки базы данных
require __DIR__ . '/../config.php';

header('Content-Type: application/json');

// Подключение к БД
//try {
//    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
//    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
//} catch (PDOException $e) {
//    die(json_encode(['error' => 'Database connection failed']));
//}

function getCfg(string $key, string $default = ''): string {
    global $pdo;
    try {
        $st = $pdo->prepare('SELECT config_value FROM gateway_config WHERE config_key = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return ($v !== false && $v !== null) ? (string) $v : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

function setCfg(string $key, string $value): void {
    global $pdo;
    $st = $pdo->prepare('INSERT INTO gateway_config (config_key, config_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)');
    $st->execute([$key, $value]);
}

function isHexToken32(string $s): bool {
    return (bool) preg_match('/^[a-f0-9]{32}$/i', $s);
}

function logSyncEvent(string $status, string $details): void {
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO logs (event_type, phone_number, group_id, status, details) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            'sync_api',
            null,
            null,
            $status,
            $details,
        ]);
    } catch (PDOException $e) {
        // Логирование не должно ломать API-ответ.
    }
}

/** UNIX-время с MySQL — для server_time в get_hash; иначе якорь дельты (lastServerSyncTs) от PHP time() расходится с updated_at в БД → 0 строк в дельте → full_sync_required */
function pdoUnixTimestamp(PDO $pdo): int {
    try {
        $v = $pdo->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();
        return ($v !== false && $v !== null) ? (int) $v : time();
    } catch (Throwable $e) {
        return time();
    }
}

function getTokenStateAndRotate(): array {
    $now = time();
    $state = [
        'current' => trim(getCfg('api_token', '')),
        'next' => trim(getCfg('next_api_token', '')),
        'switch_at' => (int) getCfg('token_switch_at', '0'),
        'prev' => trim(getCfg('prev_api_token', '')),
        'prev_until' => (int) getCfg('prev_api_token_valid_until', '0'),
    ];

    if (!isHexToken32($state['current'])) {
        $state['current'] = '';
    }
    if (!isHexToken32($state['next'])) {
        $state['next'] = '';
        $state['switch_at'] = 0;
    }
    if (!isHexToken32($state['prev'])) {
        $state['prev'] = '';
        $state['prev_until'] = 0;
    }

    if ($state['next'] !== '' && $state['switch_at'] > 0 && $now >= $state['switch_at']) {
        $old = $state['current'];
        $state['current'] = $state['next'];
        $state['next'] = '';
        $state['switch_at'] = 0;
        $state['prev'] = $old;
        $state['prev_until'] = $now + 86400; // 24ч grace после переключения

        setCfg('api_token', $state['current']);
        setCfg('next_api_token', '');
        setCfg('token_switch_at', '0');
        setCfg('prev_api_token', $state['prev']);
        setCfg('prev_api_token_valid_until', (string) $state['prev_until']);
    }

    return $state;
}

// Проверка токена авторизации (current + next + prev в grace)
function checkAuth() {
    $state = getTokenStateAndRotate();
    $auth = '';
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            $auth = $headers['Authorization'];
        } elseif (isset($headers['authorization'])) {
            $auth = $headers['authorization'];
        }
    }
    if ($auth === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'];
    }
    if ($auth === '') {
        http_response_code(401);
        die(json_encode(['error' => 'Missing Authorization header']));
    }
    $token = preg_replace('/^Bearer\s+/i', '', $auth);
    $now = time();
    $ok = false;
    if ($state['current'] !== '' && hash_equals($state['current'], $token)) $ok = true;
    if (!$ok && $state['next'] !== '' && hash_equals($state['next'], $token)) $ok = true;
    if (!$ok && $state['prev'] !== '' && $now <= $state['prev_until'] && hash_equals($state['prev'], $token)) $ok = true;
    if (!$ok) {
        http_response_code(403);
        die(json_encode([
            'error' => 'Invalid token',
            'hint' => 'Проверьте Bearer: текущий, next или prev (в срок). Шлюз: при смене токена в админке прежний автоматически в prev на 14 дней. Вручную: gateway_config prev_api_token = токен с флеша, prev_api_token_valid_until = UNIX+недели.',
        ]));
    }
}

// Хеш списка номеров: всегда считается по таблице (phone + group), не из кэша gateway_config —
// иначе при смене только group_id кэш мог не совпадать с триггером (синтаксис GROUP_CONCAT и т.д.).
function getCurrentHash($pdo) {
    try {
        // Иначе GROUP_CONCAT обрезается на ~1 KiB (по умолчанию) — хеш не меняется при смене группы «в хвосте» списка
        try {
            $pdo->exec('SET SESSION group_concat_max_len = 16777216');
        } catch (PDOException $e) {
            // нет прав на SESSION — остаётся дефолт сервера
        }
        $stmt = $pdo->query(
            "SELECT MD5(GROUP_CONCAT(CONCAT(phone_number, '|', group_id) ORDER BY phone_number SEPARATOR ',')) AS h " .
            'FROM numbers WHERE active = 1'
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $h = $row['h'] ?? null;
        if ($h === null || $h === false) {
            return '';
        }
        return (string) $h;
    } catch (PDOException $e) {
        $st = $pdo->query("SELECT config_value FROM gateway_config WHERE config_key = 'numbers_hash'");
        $v = $st->fetchColumn();
        return ($v !== false && $v !== null) ? (string) $v : '';
    }
}

$action = $_GET['action'] ?? '';

// Скомпилированный .bin для кэша на SPI Flash шлюза (см. admin/firmware.php)
define('GATEWAY_FIRMWARE_FILE', dirname(__DIR__) . '/data/gateway_firmware.bin');
define('GATEWAY_FIRMWARE_MAX_BYTES', 524224); // 512 KiB − 64 байта служебного заголовка на флеше
define('FIRMWARE_DISTRIBUTION_ENABLED', false);

switch ($action) {
    case 'get_hash':
        // GET /api/?action=get_hash — serial_debug, gateway_log_level, relay_* (см. admin)
        checkAuth();
        $tokenState = getTokenStateAndRotate();
        $hash = getCurrentHash($pdo);
        $serialDebug = 1;
        try {
            $st = $pdo->prepare("SELECT config_value FROM gateway_config WHERE config_key = 'serial_debug'");
            $st->execute();
            $v = $st->fetchColumn();
            if ($v !== false && $v !== null && $v !== '') {
                $serialDebug = ((int) $v) ? 1 : 0;
            }
        } catch (PDOException $e) {
            $serialDebug = 1;
        }
        $relayDelayGroup1Ms = 300000;
        $relayPulseMs = 1000;
        try {
            $st = $pdo->prepare("SELECT config_value FROM gateway_config WHERE config_key = 'relay_delay_group1_ms'");
            $st->execute();
            $v = $st->fetchColumn();
            if ($v !== false && $v !== null && $v !== '') {
                $relayDelayGroup1Ms = max(10000, min(86400000, (int) $v));
            }
            $st = $pdo->prepare("SELECT config_value FROM gateway_config WHERE config_key = 'relay_pulse_ms'");
            $st->execute();
            $v = $st->fetchColumn();
            if ($v !== false && $v !== null && $v !== '') {
                $relayPulseMs = max(100, min(60000, (int) $v));
            }
        } catch (PDOException $e) {
            // defaults
        }
        $gatewayLogLevel = 2;
        try {
            $st = $pdo->prepare("SELECT config_value FROM gateway_config WHERE config_key = 'gateway_log_level'");
            $st->execute();
            $v = $st->fetchColumn();
            if ($v !== false && $v !== null && $v !== '') {
                $gatewayLogLevel = max(0, min(2, (int) $v));
            }
        } catch (PDOException $e) {
            $gatewayLogLevel = 2;
        }
        echo json_encode([
            'hash' => $hash,
            'api_token' => $tokenState['current'],
            'next_api_token' => $tokenState['next'],
            'token_switch_at' => (int) $tokenState['switch_at'],
            'server_time' => pdoUnixTimestamp($pdo),
            'serial_debug' => $serialDebug,
            'gateway_log_level' => $gatewayLogLevel,
            'relay_delay_group1_ms' => $relayDelayGroup1Ms,
            'relay_pulse_ms' => $relayPulseMs,
        ]);
        $remoteIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (strlen($ua) > 80) {
            $ua = substr($ua, 0, 80) . '...';
        }
        logSyncEvent(
            'get_hash',
            'ok ip=' . $remoteIp . ' serial_debug=' . (int) $serialDebug . ' ua=' . $ua
        );
        break;

    case 'get_data':
        // GET /api/?action=get_data&hash=...&page=1&limit=1 — полный список постранично
        // GET ...&delta=1&since_ts=UNIX&batch=removed|upserts — только изменения с last sync (шлюз экономит HTTP)
        checkAuth();
        $clientHash = $_GET['hash'] ?? '';
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = (int) ($_GET['limit'] ?? 1);
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 50) {
            $limit = 50;
        }

        $currentHash = getCurrentHash($pdo);

        if ($clientHash === $currentHash) {
            echo json_encode(['changed' => false]);
            logSyncEvent(
                'get_data',
                'changed=0 page=' . (int) $page . ' limit=' . (int) $limit
            );
            break;
        }

        $delta = isset($_GET['delta']) && (string) $_GET['delta'] === '1';
        $sinceTs = (int) ($_GET['since_ts'] ?? 0);
        $batch = (string) ($_GET['batch'] ?? 'upserts');
        if ($batch !== 'removed' && $batch !== 'upserts') {
            $batch = 'upserts';
        }

        if ($delta && $sinceTs > 0) {
            $countDelta = static function (PDO $pdo, int $since): array {
                $nDel = 0;
                try {
                    $st = $pdo->prepare('SELECT COUNT(*) FROM numbers_deleted_phones WHERE deleted_at > FROM_UNIXTIME(?)');
                    $st->execute([$since]);
                    $nDel = (int) $st->fetchColumn();
                } catch (PDOException $e) {
                    $nDel = 0;
                }
                $st = $pdo->prepare('SELECT COUNT(*) FROM numbers WHERE active = 1 AND updated_at > FROM_UNIXTIME(?)');
                $st->execute([$since]);
                $nUp = (int) $st->fetchColumn();

                return [$nUp, $nDel];
            };

            $sinceEff = $sinceTs;
            [$nUp, $nDel] = $countDelta($pdo, $sinceEff);

            // Перекос web/БД или граница «ровно на секунде» — без строк дельты шлюз получает full_sync_required
            if ($nUp + $nDel === 0) {
                $sinceLo = max(0, $sinceTs - 900);
                if ($sinceLo < $sinceTs) {
                    [$nUpLo, $nDelLo] = $countDelta($pdo, $sinceLo);
                    if ($nUpLo + $nDelLo > 0) {
                        $sinceEff = $sinceLo;
                        $nUp = $nUpLo;
                        $nDel = $nDelLo;
                    }
                }
            }

            if ($nUp + $nDel === 0) {
                echo json_encode([
                    'changed' => true,
                    'full_sync_required' => true,
                    'hash' => $currentHash,
                ]);
                logSyncEvent(
                    'get_data',
                    'delta=1 full_sync_required=1 reason=no_rows since=' . $sinceTs . ' since_eff_tried=' . $sinceEff
                );
                break;
            }

            $deltaFullThreshold = 100;
            if ($nUp + $nDel > $deltaFullThreshold) {
                echo json_encode([
                    'changed' => true,
                    'full_sync_required' => true,
                    'hash' => $currentHash,
                ]);
                logSyncEvent(
                    'get_data',
                    'delta=1 full_sync_required=1 upsert=' . $nUp . ' del=' . $nDel . ' since_eff=' . $sinceEff
                );
                break;
            }

            if ($batch === 'removed') {
                $limitRm = max(1, min(8, $limit));
                $offsetRm = ($page - 1) * $limitRm;
                try {
                    $stCnt = $pdo->prepare('SELECT COUNT(*) FROM numbers_deleted_phones WHERE deleted_at > FROM_UNIXTIME(?)');
                    $stCnt->execute([$sinceEff]);
                    $totalRm = (int) $stCnt->fetchColumn();
                } catch (PDOException $e) {
                    $totalRm = 0;
                }
                $totalPagesRm = $totalRm > 0 ? (int) ceil($totalRm / $limitRm) : 1;
                $chunk = [];
                try {
                    $stmt = $pdo->prepare(
                        'SELECT phone_number FROM numbers_deleted_phones WHERE deleted_at > FROM_UNIXTIME(?) ORDER BY deleted_at ASC, id ASC LIMIT ' . (int) $limitRm . ' OFFSET ' . (int) $offsetRm
                    );
                    $stmt->execute([$sinceEff]);
                    $chunk = $stmt->fetchAll(PDO::FETCH_COLUMN);
                } catch (PDOException $e) {
                    $chunk = [];
                }
                echo json_encode([
                    'changed' => true,
                    'delta' => true,
                    'batch' => 'removed',
                    'hash' => $currentHash,
                    'total_pages' => $totalPagesRm,
                    'page' => $page,
                    'removed' => $chunk,
                ]);
                logSyncEvent(
                    'get_data',
                    'delta=1 batch=removed page=' . (int) $page . ' total_pages=' . (int) $totalPagesRm . ' rows=' . (int) count($chunk)
                );
                break;
            }

            $limitUp = max(1, min($limit, 50));
            $offsetUp = ($page - 1) * $limitUp;
            $stCnt = $pdo->prepare('SELECT COUNT(*) FROM numbers WHERE active = 1 AND updated_at > FROM_UNIXTIME(?)');
            $stCnt->execute([$sinceEff]);
            $totalUp = (int) $stCnt->fetchColumn();
            $totalPagesUp = $totalUp > 0 ? (int) ceil($totalUp / $limitUp) : 1;

            $stmt = $pdo->prepare(
                'SELECT phone_number, group_id FROM numbers WHERE active = 1 AND updated_at > FROM_UNIXTIME(?) ORDER BY phone_number LIMIT ' . (int) $limitUp . ' OFFSET ' . (int) $offsetUp
            );
            $stmt->execute([$sinceEff]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $formatted = [];
            foreach ($data as $row) {
                $formatted[] = [
                    'phone' => $row['phone_number'],
                    'group' => (int) $row['group_id'],
                ];
            }
            echo json_encode([
                'changed' => true,
                'delta' => true,
                'batch' => 'upserts',
                'hash' => $currentHash,
                'total_pages' => $totalPagesUp,
                'page' => $page,
                'data' => $formatted,
            ]);
            logSyncEvent(
                'get_data',
                'delta=1 batch=upserts page=' . (int) $page . ' total_pages=' . (int) $totalPagesUp . ' rows=' . (int) count($formatted)
            );
            break;
        }

        $totalRecords = (int) $pdo->query('SELECT COUNT(*) FROM numbers WHERE active = 1')->fetchColumn();
        $totalPages = $totalRecords > 0 ? (int) ceil($totalRecords / $limit) : 1;
        $offset = ($page - 1) * $limit;

        $lim = (int) $limit;
        $off = (int) $offset;
        $stmt = $pdo->query("SELECT phone_number, group_id FROM numbers WHERE active = 1 ORDER BY phone_number LIMIT {$lim} OFFSET {$off}");
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $formatted = [];
        foreach ($data as $row) {
            $formatted[] = [
                'phone' => $row['phone_number'],
                'group' => (int) $row['group_id'],
            ];
        }
        echo json_encode([
            'changed' => true,
            'hash' => $currentHash,
            'total_pages' => $totalPages,
            'data' => $formatted,
        ]);
        logSyncEvent(
            'get_data',
            'changed=1 page=' . (int) $page . ' limit=' . (int) $limit .
            ' total_pages=' . (int) $totalPages . ' rows=' . (int) count($formatted)
        );
        break;

    case 'log':
        // POST /api/?action=log — полные ключи или компактные t,p,g,s,d (шлюз, экономия SRAM/Flash)
        checkAuth();
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            http_response_code(400);
            die(json_encode(['error' => 'Invalid JSON']));
        }

        $normalizeLogRow = static function (array $log): array {
            $gid = null;
            if (array_key_exists('group_id', $log)) {
                $gid = $log['group_id'];
            } elseif (array_key_exists('g', $log)) {
                $gid = $log['g'];
            }
            return [
                'event_type' => $log['event_type'] ?? $log['t'] ?? '',
                'phone_number' => $log['phone_number'] ?? $log['p'] ?? null,
                'group_id' => $gid,
                'status' => $log['status'] ?? $log['s'] ?? null,
                'details' => $log['details'] ?? $log['d'] ?? null,
            ];
        };

        $stmt = $pdo->prepare("INSERT INTO logs (event_type, phone_number, group_id, status, details) VALUES (?, ?, ?, ?, ?)");
        foreach ($input as $log) {
            if (!is_array($log)) {
                continue;
            }
            $row = $normalizeLogRow($log);
            $stmt->execute([
                $row['event_type'],
                $row['phone_number'],
                $row['group_id'],
                $row['status'],
                $row['details'],
            ]);
        }
        echo json_encode(['status' => 'ok']);
        break;

    case 'commands':
        // GET /api/?action=commands
        checkAuth();
        $commands = [];
        try {
            $tbl = SMS_COMMANDS_TABLE;
            $stmt = $pdo->query("SELECT id, phone_number, message FROM `{$tbl}` WHERE status = 'pending' ORDER BY id ASC LIMIT 10");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $commands[] = [
                    'id' => (int) $row['id'],
                    'type' => 'send_sms',
                    'phone' => $row['phone_number'],
                    'message' => $row['message'],
                ];
            }
            $tblU = USSD_COMMANDS_TABLE;
            $stmt = $pdo->query("SELECT id, code FROM `{$tblU}` WHERE status = 'pending' ORDER BY id ASC LIMIT 10");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $commands[] = [
                    'id' => (int) $row['id'],
                    'type' => 'send_ussd',
                    'code' => $row['code'],
                ];
            }
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['error' => 'commands query failed', 'detail' => $e->getMessage()]));
        }
        echo json_encode($commands);
        break;

    case 'command_ack':
        // POST /api/?action=command_ack  body: { "id", "status", "kind": "sms"|"ussd", "result": "..." }
        checkAuth();
        $input = json_decode(file_get_contents('php://input'), true);
        if (!isset($input['id'], $input['status'])) {
            http_response_code(400);
            die(json_encode(['error' => 'Missing id or status']));
        }
        $kind = $input['kind'] ?? 'sms';
        if ($kind === 'ussd') {
            $tbl = USSD_COMMANDS_TABLE;
            $result = $input['result'] ?? null;
            $stmt = $pdo->prepare("UPDATE `{$tbl}` SET status = ?, sent_at = NOW(), attempts = IFNULL(attempts, 0) + 1, result = ? WHERE id = ?");
            $stmt->execute([$input['status'], $result, $input['id']]);
        } else {
            $tbl = SMS_COMMANDS_TABLE;
            $stmt = $pdo->prepare("UPDATE `{$tbl}` SET status = ?, sent_at = NOW(), attempts = IFNULL(attempts, 0) + 1 WHERE id = ?");
            $stmt->execute([$input['status'], $input['id']]);
        }
        echo json_encode(['status' => 'ok']);
        break;

    case 'firmware_meta':
        checkAuth();
        if (!FIRMWARE_DISTRIBUTION_ENABLED) {
            echo json_encode(['available' => false, 'disabled' => true]);
            break;
        }
        if (!is_file(GATEWAY_FIRMWARE_FILE) || !is_readable(GATEWAY_FIRMWARE_FILE)) {
            echo json_encode(['available' => false]);
            break;
        }
        $sz = filesize(GATEWAY_FIRMWARE_FILE);
        if ($sz === false || $sz < 1 || $sz > GATEWAY_FIRMWARE_MAX_BYTES) {
            echo json_encode(['available' => false, 'error' => 'invalid size']);
            break;
        }
        $raw = file_get_contents(GATEWAY_FIRMWARE_FILE) ?: '';
        $crc = crc32($raw);
        $crcHex = sprintf('%08x', $crc);
        $ver = '';
        try {
            $st = $pdo->prepare("SELECT config_value FROM gateway_config WHERE config_key = 'fw_version'");
            $st->execute();
            $ver = (string) $st->fetchColumn();
        } catch (PDOException $e) {
            $ver = '';
        }
        echo json_encode([
            'available' => true,
            'size' => $sz,
            'crc32' => $crcHex,
            'version' => $ver,
            'chunk_max' => 256,
        ]);
        break;

    case 'firmware_chunk':
        checkAuth();
        if (!FIRMWARE_DISTRIBUTION_ENABLED) {
            http_response_code(410);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'firmware distribution disabled']);
            break;
        }
        $offset = (int) ($_GET['offset'] ?? 0);
        $len = (int) ($_GET['len'] ?? 256);
        if ($len < 1 || $len > 256) {
            $len = 256;
        }
        if (!is_file(GATEWAY_FIRMWARE_FILE) || !is_readable(GATEWAY_FIRMWARE_FILE)) {
            http_response_code(404);
            header('Content-Type: application/json');
            die(json_encode(['error' => 'no firmware file']));
        }
        $sz = filesize(GATEWAY_FIRMWARE_FILE);
        if ($sz === false || $offset < 0 || $offset >= $sz) {
            http_response_code(416);
            header('Content-Type: application/json');
            die(json_encode(['error' => 'range']));
        }
        $len = min($len, $sz - $offset);
        $fh = fopen(GATEWAY_FIRMWARE_FILE, 'rb');
        if (!$fh) {
            http_response_code(500);
            die(json_encode(['error' => 'read']));
        }
        fseek($fh, $offset);
        $chunk = fread($fh, $len);
        fclose($fh);
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . strlen($chunk));
        if (function_exists('header_remove')) {
            header_remove('X-Powered-By');
        }
        echo $chunk;
        exit;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Unknown action']);
}