<?php
/* cspell:disable */
session_start();

// Если уже вошли – показываем панель управления
if (isset($_SESSION['connected']) && $_SESSION['connected'] === true) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    function getDB() {
        $conn = new mysqli($_SESSION['db_host'], $_SESSION['db_user'], $_SESSION['db_pass'], $_SESSION['db_name']);
        if ($conn->connect_error) return null;
        $conn->set_charset("utf8");
        return $conn;
    }

    $conn = getDB();
    $tables = [];
    if ($conn) {
        $res = $conn->query("SHOW TABLES");
        if ($res) {
            while ($row = $res->fetch_array()) {
                $tables[] = $row[0];
            }
            $res->close();
        }
    }

    // Русские названия таблиц
    $tableNamesRu = [
        'users'          => 'Пользователи',
        'categories'     => 'Категории',
        'order_items'    => 'Состав заказа',
        'statuses'       => 'Статусы заказов',
        'order_statuses' => 'Статусы заказов',
        'payments'       => 'Платежи',
        'menu_items'     => 'Пункты меню',
        'orders'         => 'Заказы',
        'customers'      => 'Клиенты',
        'roles'          => 'Роли'
    ];

    $currentTable = isset($_GET['table']) ? $_GET['table'] : ($tables[0] ?? '');
    $message = '';
    $messageType = 'success';
    $editRow = null;
    $columns = [];
    $primaryKey = '';
    $foreignKeys = [];

    if ($currentTable && $conn) {
        // Получаем структуру таблицы
        $structRes = $conn->query("DESCRIBE `$currentTable`");
        if ($structRes) {
            while ($col = $structRes->fetch_assoc()) {
                $columns[] = $col;
                if ($col['Key'] === 'PRI') {
                    $primaryKey = $col['Field'];
                }
            }
            $structRes->close();
        }

        // Получаем внешние ключи
        $fkQuery = "
            SELECT
                kcu.COLUMN_NAME,
                kcu.REFERENCED_TABLE_NAME,
                kcu.REFERENCED_COLUMN_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
            WHERE kcu.CONSTRAINT_SCHEMA = DATABASE()
              AND kcu.TABLE_NAME = ?
              AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
        ";
        $fkStmt = $conn->prepare($fkQuery);
        $fkStmt->bind_param("s", $currentTable);
        $fkStmt->execute();
        $fkResult = $fkStmt->get_result();
        while ($rowFk = $fkResult->fetch_assoc()) {
            $foreignKeys[$rowFk['COLUMN_NAME']] = [
                'referenced_table' => $rowFk['REFERENCED_TABLE_NAME'],
                'referenced_column' => $rowFk['REFERENCED_COLUMN_NAME']
            ];
        }
        $fkStmt->close();

        // Функция преобразования даты/времени из input в формат MySQL
        function formatDateTimeValue($value, $columnType) {
            if ($value === null || $value === '') {
                return null;
            }

            // Для DATETIME и TIMESTAMP
            if (strpos($columnType, 'datetime') !== false || strpos($columnType, 'timestamp') !== false) {
                // Если значение содержит только время (HH:MM или HH:MM:SS) - добавляем текущую дату
                if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $value)) {
                    $value = date('Y-m-d') . ' ' . $value;
                }
                // Если значение содержит только дату (YYYY-MM-DD) - добавляем 00:00:00
                elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                    $value = $value . ' 00:00:00';
                }
                // Если значение пришло из datetime-local (YYYY-MM-DDTHH:MM)
                elseif (strpos($value, 'T') !== false) {
                    $value = str_replace('T', ' ', $value);
                }
                // Добавляем секунды, если их нет
                if (strlen($value) == 16) {
                    $value .= ':00';
                }
                return $value;
            }

            // Для DATE
            elseif (strpos($columnType, 'date') !== false) {
                // Если только время - добавляем текущую дату
                if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $value)) {
                    $value = date('Y-m-d');
                }
                return $value;
            }

            // Для TIME
            elseif (strpos($columnType, 'time') !== false) {
                if (strlen($value) == 5) {
                    $value .= ':00';
                }
                return $value;
            }

            return $value;
        }

        // Обработка POST (добавление, обновление, удаление)
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (isset($_POST['add']) && $primaryKey) {
                $fields = [];
                $values = [];
                $types = '';
                $params = [];
                foreach ($columns as $col) {
                    $field = $col['Field'];
                    if ($field === $primaryKey && strpos($col['Extra'], 'auto_increment') !== false) {
                        continue;
                    }
                    $value = isset($_POST[$field]) ? $_POST[$field] : null;
                    // Преобразуем пустую строку в NULL для внешних ключей
                    if ($value === '' && isset($foreignKeys[$field])) {
                        $value = null;
                    }
                    // Преобразуем дату/время
                    if ($value !== null && $value !== '') {
                        $value = formatDateTimeValue($value, $col['Type']);
                    }
                    // Пропускаем NULL поля, которые могут быть NULL
                    if ($value === null && $col['Null'] === 'YES') {
                        continue;
                    }
                    // Для datetime/timestamp, если поле пустое и не обязательное, пропускаем (БД поставит default)
                    if (($value === null || $value === '') && (strpos($col['Type'], 'datetime') !== false || strpos($col['Type'], 'timestamp') !== false) && $col['Null'] === 'YES') {
                        continue;
                    }
                    $fields[] = "`$field`";
                    $values[] = '?';
                    $params[] = $value;
                    if (strpos($col['Type'], 'int') !== false) {
                        $types .= 'i';
                    } else {
                        $types .= 's';
                    }
                }
                if (!empty($fields)) {
                    $sql = "INSERT INTO `$currentTable` (" . implode(',', $fields) . ") VALUES (" . implode(',', $values) . ")";
                    $stmt = $conn->prepare($sql);
                    if ($stmt) {
                        $stmt->bind_param($types, ...$params);
                        if ($stmt->execute()) {
                            $message = "Запись добавлена (ID: " . $conn->insert_id . ")";
                        } else {
                            $message = "Ошибка: " . $stmt->error;
                            $messageType = 'error';
                        }
                        $stmt->close();
                    } else {
                        $message = "Ошибка подготовки запроса: " . $conn->error;
                        $messageType = 'error';
                    }
                } else {
                    $message = "Нет данных для добавления (все поля опциональны?)";
                    $messageType = 'error';
                }
            } elseif (isset($_POST['update']) && isset($_POST['edit_id']) && $primaryKey) {
                $editId = $_POST['edit_id'];
                $fields = [];
                $params = [];
                $types = '';
                foreach ($columns as $col) {
                    $field = $col['Field'];
                    if ($field === $primaryKey) continue;
                    $value = isset($_POST[$field]) ? $_POST[$field] : null;
                    if ($value === '' && isset($foreignKeys[$field])) {
                        $value = null;
                    }
                    if ($value !== null && $value !== '') {
                        $value = formatDateTimeValue($value, $col['Type']);
                    }
                    if ($value === null && $col['Null'] === 'YES') {
                        continue;
                    }
                    $fields[] = "`$field` = ?";
                    $params[] = $value;
                    if (strpos($col['Type'], 'int') !== false) {
                        $types .= 'i';
                    } else {
                        $types .= 's';
                    }
                }
                if (empty($fields)) {
                    $message = "Нет полей для обновления";
                    $messageType = 'error';
                } else {
                    $params[] = $editId;
                    $types .= 'i';
                    $sql = "UPDATE `$currentTable` SET " . implode(',', $fields) . " WHERE `$primaryKey` = ?";
                    $stmt = $conn->prepare($sql);
                    if ($stmt) {
                        $stmt->bind_param($types, ...$params);
                        if ($stmt->execute()) {
                            $message = "Запись обновлена";
                        } else {
                            $message = "Ошибка обновления: " . $stmt->error;
                            $messageType = 'error';
                        }
                        $stmt->close();
                    }
                }
            } elseif (isset($_POST['delete']) && isset($_POST['delete_id']) && $primaryKey) {
                $deleteId = $_POST['delete_id'];
                $stmt = $conn->prepare("DELETE FROM `$currentTable` WHERE `$primaryKey` = ?");
                $stmt->bind_param("i", $deleteId);
                if ($stmt->execute()) {
                    $message = "Запись удалена";
                } else {
                    $message = "Ошибка удаления (возможно, есть связанные записи): " . $stmt->error;
                    $messageType = 'error';
                }
                $stmt->close();
                header("Location: ?table=" . urlencode($currentTable));
                exit;
            }
        }

        if (isset($_GET['edit']) && $primaryKey) {
            $editId = (int)$_GET['edit'];
            $stmt = $conn->prepare("SELECT * FROM `$currentTable` WHERE `$primaryKey` = ?");
            $stmt->bind_param("i", $editId);
            $stmt->execute();
            $editRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        $dataResult = $currentTable ? $conn->query("SELECT * FROM `$currentTable` ORDER BY `$primaryKey` DESC LIMIT 500") : null;
        $rows = [];
        if ($dataResult && $dataResult->num_rows > 0) {
            while ($r = $dataResult->fetch_assoc()) {
                $rows[] = $r;
            }
        }
    }
?>
<!DOCTYPE html>
<html lang="ru" xml:lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Управление базой данных: <?= htmlspecialchars($_SESSION['db_name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .table-responsive { max-height: 500px; overflow-y: auto; }
        .nav-tabs .nav-link { cursor: pointer; }
        .form-card { background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
    </style>
</head>
<body>
<div class="container mt-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>📊 База данных: <?= htmlspecialchars($_SESSION['db_name']) ?></h2>
        <a href="?logout=1" class="btn btn-danger" aria-label="Выйти из системы">Выйти</a>
    </div>

    <ul class="nav nav-tabs mb-3">
        <?php foreach ($tables as $tbl): ?>
            <li class="nav-item">
                <a class="nav-link <?= ($currentTable === $tbl) ? 'active' : '' ?>" href="?table=<?= urlencode($tbl) ?>">
                    <?= htmlspecialchars($tableNamesRu[$tbl] ?? $tbl) ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Закрыть"></button>
        </div>
    <?php endif; ?>

    <?php if ($currentTable && $conn): ?>
        <!-- Форма добавления записи -->
        <div class="form-card">
            <h4>➕ Добавить запись в «<?= htmlspecialchars($tableNamesRu[$currentTable] ?? $currentTable) ?>»</h4>
            <form method="post">
                <div class="row g-2">
                    <?php
                    $fieldIndex = 0;
                    foreach ($columns as $col):
                        $field = $col['Field'];
                        if ($field === $primaryKey && strpos($col['Extra'], 'auto_increment') !== false) {
                            continue;
                        }
                        $type = $col['Type'];
                        $nullable = ($col['Null'] === 'YES');
                        $isForeignKey = isset($foreignKeys[$field]);
                        $fieldIndex++;
                        $uniqueId = "add_{$fieldIndex}_{$field}";
                    ?>
                        <div class="col-md-3">
                            <label for="<?= $uniqueId ?>" class="form-label">
                                <?= htmlspecialchars($field) ?>
                                <?php if (!$nullable && !$isForeignKey): ?><span class="text-danger">*</span><?php endif; ?>
                            </label>
                            <?php if ($isForeignKey):
                                $refTable = $foreignKeys[$field]['referenced_table'];
                                $refColumn = $foreignKeys[$field]['referenced_column'];
                                $displayField = null;
                                $checkFields = ['name', 'title', 'full_name', 'description', 'label', 'username', 'email'];
                                foreach ($checkFields as $cf) {
                                    $resCheck = $conn->query("SHOW COLUMNS FROM `$refTable` LIKE '$cf'");
                                    if ($resCheck && $resCheck->num_rows > 0) {
                                        $displayField = $cf;
                                        $resCheck->close();
                                        break;
                                    }
                                    if ($resCheck) $resCheck->close();
                                }
                                if (!$displayField) $displayField = $refColumn;
                                $selectQuery = "SELECT `$refColumn`, `$displayField` FROM `$refTable` ORDER BY `$displayField`";
                                $refRows = $conn->query($selectQuery);
                                $hasRows = ($refRows && $refRows->num_rows > 0);
                            ?>
                                <?php if ($hasRows): ?>
                                    <select name="<?= $field ?>" id="<?= $uniqueId ?>" class="form-select" <?= $nullable ? '' : 'required' ?>>
                                        <?php if ($nullable): ?>
                                            <option value="">— не выбрано —</option>
                                        <?php endif; ?>
                                        <?php while ($opt = $refRows->fetch_assoc()): ?>
                                            <option value="<?= $opt[$refColumn] ?>">
                                                <?= htmlspecialchars($opt[$displayField]) ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                <?php else: ?>
                                    <div class="alert alert-warning small p-1 mb-0">
                                        Нет данных в таблице «<?= htmlspecialchars($tableNamesRu[$refTable] ?? $refTable) ?>».
                                        <a href="?table=<?= urlencode($refTable) ?>" target="_blank">Добавить</a>
                                    </div>
                                <?php endif; ?>
                                <?php if ($refRows) $refRows->close(); ?>
                            <?php elseif (strpos($type, 'enum') === 0):
                                preg_match("/^enum\((.*)\)$/", $type, $matches);
                                $options = array_map(function($opt) { return trim($opt, "'"); }, explode(',', $matches[1]));
                            ?>
                                <select name="<?= $field ?>" id="<?= $uniqueId ?>" class="form-select" <?= $nullable ? '' : 'required' ?>>
                                    <?php if ($nullable): ?>
                                        <option value="">— не выбрано —</option>
                                    <?php endif; ?>
                                    <?php foreach ($options as $opt): ?>
                                        <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else:
                                $inputType = 'text';
                                if (strpos($type, 'int') !== false) $inputType = 'number';
                                if (strpos($type, 'decimal') !== false) $inputType = 'number';
                                if (strpos($type, 'date') !== false) $inputType = 'date';
                                if (strpos($type, 'datetime') !== false) $inputType = 'datetime-local';
                                if (strpos($type, 'time') !== false) $inputType = 'time';
                            ?>
                                <input type="<?= $inputType ?>" name="<?= $field ?>" id="<?= $uniqueId ?>" class="form-control"
                                       step="any" <?= $nullable ? '' : 'required' ?> value="<?= htmlspecialchars($editRow[$field] ?? '') ?>">
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="submit" name="add" class="btn btn-primary mt-3">Добавить</button>
            </form>
        </div>

        <!-- Форма редактирования -->
        <?php if ($editRow && $primaryKey): ?>
            <div class="form-card" style="background:#fff3cd;">
                <h4>✏️ Редактировать запись № <?= $editRow[$primaryKey] ?> в таблице «<?= htmlspecialchars($tableNamesRu[$currentTable] ?? $currentTable) ?>»</h4>
                <form method="post">
                    <input type="hidden" name="edit_id" value="<?= $editRow[$primaryKey] ?>">
                    <div class="row g-2">
                        <?php
                        $fieldIndex = 0;
                        foreach ($columns as $col):
                            $field = $col['Field'];
                            if ($field === $primaryKey) continue;
                            $value = $editRow[$field] ?? '';
                            $type = $col['Type'];
                            $nullable = ($col['Null'] === 'YES');
                            $isForeignKey = isset($foreignKeys[$field]);
                            $fieldIndex++;
                            $uniqueId = "edit_{$fieldIndex}_{$field}";
                        ?>
                            <div class="col-md-3">
                                <label for="<?= $uniqueId ?>" class="form-label"><?= htmlspecialchars($field) ?></label>
                                <?php if ($isForeignKey):
                                    $refTable = $foreignKeys[$field]['referenced_table'];
                                    $refColumn = $foreignKeys[$field]['referenced_column'];
                                    $displayField = null;
                                    $checkFields = ['name', 'title', 'full_name', 'description', 'label', 'username', 'email'];
                                    foreach ($checkFields as $cf) {
                                        $resCheck = $conn->query("SHOW COLUMNS FROM `$refTable` LIKE '$cf'");
                                        if ($resCheck && $resCheck->num_rows > 0) {
                                            $displayField = $cf;
                                            $resCheck->close();
                                            break;
                                        }
                                        if ($resCheck) $resCheck->close();
                                    }
                                    if (!$displayField) $displayField = $refColumn;
                                    $selectQuery = "SELECT `$refColumn`, `$displayField` FROM `$refTable` ORDER BY `$displayField`";
                                    $refRows = $conn->query($selectQuery);
                                    $hasRows = ($refRows && $refRows->num_rows > 0);
                                ?>
                                    <?php if ($hasRows): ?>
                                        <select name="<?= $field ?>" id="<?= $uniqueId ?>" class="form-select">
                                            <?php if ($nullable): ?>
                                                <option value="">— не выбрано —</option>
                                            <?php endif; ?>
                                            <?php while ($opt = $refRows->fetch_assoc()): ?>
                                                <option value="<?= $opt[$refColumn] ?>" <?= ($opt[$refColumn] == $value) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($opt[$displayField]) ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    <?php else: ?>
                                        <div class="alert alert-warning small p-1 mb-0">
                                            Нет данных в таблице «<?= htmlspecialchars($tableNamesRu[$refTable] ?? $refTable) ?>».
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($refRows) $refRows->close(); ?>
                                <?php elseif (strpos($type, 'enum') === 0):
                                    preg_match("/^enum\((.*)\)$/", $type, $matches);
                                    $options = array_map(function($opt) { return trim($opt, "'"); }, explode(',', $matches[1]));
                                ?>
                                    <select name="<?= $field ?>" id="<?= $uniqueId ?>" class="form-select">
                                        <?php if ($nullable): ?>
                                            <option value="">— не выбрано —</option>
                                        <?php endif; ?>
                                        <?php foreach ($options as $opt): ?>
                                            <option value="<?= htmlspecialchars($opt) ?>" <?= ($opt == $value) ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else:
                                    $inputType = 'text';
                                    if (strpos($type, 'int') !== false) $inputType = 'number';
                                    if (strpos($type, 'decimal') !== false) $inputType = 'number';
                                    if (strpos($type, 'date') !== false) $inputType = 'date';
                                    if (strpos($type, 'datetime') !== false) $inputType = 'datetime-local';
                                    if (strpos($type, 'time') !== false) $inputType = 'time';
                                    // Форматируем значение для datetime-local (MySQL -> input format)
                                    if ($inputType === 'datetime-local' && !empty($value) && $value !== '0000-00-00 00:00:00') {
                                        $value = str_replace(' ', 'T', $value);
                                        if (strlen($value) > 16) {
                                            $value = substr($value, 0, 16);
                                        }
                                    }
                                ?>
                                    <input type="<?= $inputType ?>" name="<?= $field ?>" id="<?= $uniqueId ?>" value="<?= htmlspecialchars($value) ?>" class="form-control" step="any">
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" name="update" class="btn btn-warning mt-3">Обновить</button>
                    <a href="?table=<?= urlencode($currentTable) ?>" class="btn btn-secondary mt-3">Отмена</a>
                </form>
            </div>
        <?php endif; ?>

        <!-- Таблица данных -->
        <h4>📋 Содержимое таблицы: <?= htmlspecialchars($tableNamesRu[$currentTable] ?? $currentTable) ?></h4>
        <div class="table-responsive">
            <?php if (!empty($rows)): ?>
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <?php foreach ($columns as $col): ?>
                            <th><?= htmlspecialchars($col['Field']) ?></th>
                        <?php endforeach; ?>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <?php foreach ($columns as $col):
                                $field = $col['Field'];
                            ?>
                                <td><?= htmlspecialchars($row[$field] ?? '') ?></td>
                            <?php endforeach; ?>
                            <td>
                                <a href="?table=<?= urlencode($currentTable) ?>&edit=<?= $row[$primaryKey] ?>" class="btn btn-sm btn-primary">Редактировать</a>
                                <form method="post" style="display:inline;" onsubmit="return confirm('Удалить запись?')">
                                    <input type="hidden" name="delete_id" value="<?= $row[$primaryKey] ?>">
                                    <button type="submit" name="delete" class="btn btn-sm btn-danger">Удалить</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p>Нет записей.</p>
            <?php endif; ?>
        </div>
    <?php elseif ($conn): ?>
        <p>Выберите таблицу на вкладках выше.</p>
    <?php else: ?>
        <div class="alert alert-danger">Не удалось подключиться к базе данных.</div>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
    if (isset($conn) && $conn) {
        $conn->close();
    }
    exit;
} // конец проверки сессии

// --- СТРАНИЦА ВХОДА ---
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim($_POST['host']);
    $user = trim($_POST['user']);
    $pass = $_POST['pass'];
    $dbname = trim($_POST['dbname']);
    $testConn = @new mysqli($host, $user, $pass, $dbname);
    if ($testConn->connect_error) {
        $loginError = "Ошибка подключения: " . $testConn->connect_error;
    } else {
        $_SESSION['db_host'] = $host;
        $_SESSION['db_user'] = $user;
        $_SESSION['db_pass'] = $pass;
        $_SESSION['db_name'] = $dbname;
        $_SESSION['connected'] = true;
        $testConn->close();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ru" xml:lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Вход в базу данных</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">🔐 Вход в MySQL</div>
                <div class="card-body">
                    <?php if ($loginError): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($loginError) ?></div>
                    <?php endif; ?>
                    <form method="post">
                        <div class="mb-3">
                            <label for="login_host" class="form-label">Хост</label>
                            <input type="text" name="host" id="login_host" class="form-control" value="localhost" required>
                        </div>
                        <div class="mb-3">
                            <label for="login_user" class="form-label">Пользователь</label>
                            <input type="text" name="user" id="login_user" class="form-control" value="admin" required>
                        </div>
                        <div class="mb-3">
                            <label for="login_pass" class="form-label">Пароль</label>
                            <input type="password" name="pass" id="login_pass" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label for="login_dbname" class="form-label">Имя базы данных</label>
                            <input type="text" name="dbname" id="login_dbname" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Подключиться</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
<?php
/* cspell:enable */
?>