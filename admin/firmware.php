<?php
require __DIR__ . '/include.php';
$requireAdmin();

define('FW_FILE', dirname(__DIR__) . '/data/gateway_firmware.bin');
define('FW_MAX_BYTES', 524224);
define('FW_UPLOAD_DISABLED', true);

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_fw'])) {
    if (is_file(FW_FILE)) {
        if (@unlink(FW_FILE)) {
            $msg = 'Файл прошивки удалён.';
        } else {
            $err = 'Не удалось удалить файл прошивки.';
        }
    } else {
        $msg = 'Файл прошивки уже отсутствует.';
    }
}

$fwVer = '';
$fwSize = '';
if (is_file(FW_FILE)) {
    $fwSize = (string) filesize(FW_FILE);
    try {
        $st = $pdo->prepare("SELECT config_value FROM gateway_config WHERE config_key = 'fw_version'");
        $st->execute();
        $fwVer = (string) $st->fetchColumn();
    } catch (PDOException $e) {
        $fwVer = '';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Прошивка шлюза</title>
    <link rel="stylesheet" href="style_admin.css">
</head>
<body>
<?php
$navCurrent = 'firmware';
require __DIR__ . '/admin_nav.php';
?>
<div class="admin-wrap">
    <header class="page-header">
        <h1>Прошивка Arduino</h1>
        <p class="page-lead">Загрузка и выдача прошивок для Arduino отключены. Раздел оставлен только для контроля и удаления ранее загруженного файла.</p>
    </header>

    <?php if ($msg): ?><p class="admin-flash admin-flash--ok"><?php echo $e($msg); ?></p><?php endif; ?>
    <?php if ($err): ?><p class="admin-flash admin-flash--err"><?php echo $e($err); ?></p><?php endif; ?>

    <div class="section">
        <h2>Загрузка отключена</h2>
        <p class="muted">Политика проекта: обновление прошивки через админку не используется.</p>
    </div>

    <div class="section">
        <h2>Текущий файл на сервере</h2>
        <?php if (is_file(FW_FILE)): ?>
            <p>Размер: <code><?php echo $e($fwSize); ?></code> байт<br>
            Версия (из БД): <code><?php echo $e($fwVer); ?></code><br>
            Путь: <code><?php echo $e(FW_FILE); ?></code></p>
            <form method="post" onsubmit="return confirm('Удалить файл прошивки с сервера?');">
                <?php echo $csrfField(); ?>
                <button type="submit" name="delete_fw" value="1" class="btn-danger">Удалить файл</button>
            </form>
        <?php else: ?>
            <p class="muted">Файл ещё не загружен.</p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
