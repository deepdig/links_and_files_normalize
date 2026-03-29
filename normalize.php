<?php

error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(300);

while (ob_get_level()) ob_end_clean();
ob_start();

define('ROOT', str_replace('\\', '/', __DIR__));
define('SELF', basename(__FILE__));

$log_file = ROOT . '/normalizer_log.txt';
file_put_contents($log_file, '');

function log_msg($msg) {
    global $log_file;
    $msg = mb_convert_encoding($msg, 'UTF-8', 'auto');
    file_put_contents($log_file, $msg . "\n", FILE_APPEND | LOCK_EX);
}

function translit($str) {
    $map = [
        'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e',
        'ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m',
        'н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u',
        'ф'=>'f','х'=>'h','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'',
        'ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
        'А'=>'A','Б'=>'B','В'=>'V','Г'=>'G','Д'=>'D','Е'=>'E','Ё'=>'E',
        'Ж'=>'Zh','З'=>'Z','И'=>'I','Й'=>'Y','К'=>'K','Л'=>'L','М'=>'M',
        'Н'=>'N','О'=>'O','П'=>'P','Р'=>'R','С'=>'S','Т'=>'T','У'=>'U',
        'Ф'=>'F','Х'=>'H','Ц'=>'Ts','Ч'=>'Ch','Ш'=>'Sh','Щ'=>'Sch','Ъ'=>'',
        'Ы'=>'Y','Ь'=>'','Э'=>'E','Ю'=>'Yu','Я'=>'Ya',
    ];
    return strtr($str, $map);
}

function clean($name, $is_dir = false) {
    // Декодируем URL (на случай если имя содержит %20, %2C и т.д.)
    $name = urldecode($name);

    if ($is_dir) {
        $name = translit($name);
        $name = preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
        $name = preg_replace('/_+/', '_', $name);
        $name = substr($name, 0, 14);
        $result = trim($name, '_') ?: 'folder';
        log_msg("  [DIR] '$name' -> '$result'");
        return $result;
    } else {
        $path = pathinfo($name);
        $ext = isset($path['extension']) ? '.' . $path['extension'] : '';
        $orig_name = $path['filename'];

        $name = translit($orig_name);
        $name = preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
        $name = preg_replace('/_+/', '_', $name);
        $name = substr($name, 0, 14);

        $result = (trim($name, '_') ?: 'file') . $ext;
        log_msg("  [FILE] '$orig_name' -> '$result'");
        return $result;
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'start') {
    $result = ['ok' => true, 'msgs' => []];

    try {
        log_msg("=== " . date('Y-m-d H:i:s') . " ===");
        log_msg("ШАГ 1: Сбор всех путей");

        // Собираем все пути
        $all_paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(ROOT, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $all_paths[] = str_replace('\\', '/', $file->getPathname());
        }

        log_msg("Найдено путей: " . count($all_paths));

        // Сортируем: сначала папки, потом файлы; от глубоких к поверхностным
        usort($all_paths, function($a, $b) {
            $a_is_dir = is_dir($a) ? 1 : 0;
            $b_is_dir = is_dir($b) ? 1 : 0;
            if ($a_is_dir != $b_is_dir) {
                return $b_is_dir - $a_is_dir;
            }
            return substr_count($b, '/') - substr_count($a, '/');
        });

        log_msg("ШАГ 2: Сбор информации для переименования");

        // Сначала собираем все элементы с их текущими путями
        $all_items = [];

        foreach ($all_paths as $path) {
            $name = basename($path);
            if ($name == SELF) continue;
            if (strpos($name, 'session_') === 0) continue;

            $is_dir = is_dir($path);
            $new_name = clean($name, $is_dir);

            $all_items[] = [
                'old_path' => $path,
                'old_name' => $name,
                'new_name' => $new_name,
                'is_dir' => $is_dir,
                'dir' => dirname($path)
            ];
        }

        log_msg("ШАГ 3: Переименование папок");

        // Переименовываем папки
        $renamed_dirs = [];
        $dirs = array_filter($all_items, function($item) { return $item['is_dir']; });

        usort($dirs, function($a, $b) {
            return substr_count($b['old_path'], '/') - substr_count($a['old_path'], '/');
        });

        foreach ($dirs as $item) {
            $new_path = $item['dir'] . '/' . $item['new_name'];

            // Если имя не изменилось - пропускаем
            if ($item['old_name'] === $item['new_name']) {
                log_msg("  ⊘ Папка пропущена: {$item['old_name']}");
                continue;
            }

            // Если папка с таким именем уже существует - пропускаем (избегаем дубликатов)
            if (file_exists($new_path)) {
                log_msg("  ⊘ Папка пропущена (дубликат): {$item['old_name']}");
                continue;
            }

            log_msg("Переименование папки: {$item['old_name']} -> {$item['new_name']}");

            if (file_exists($item['old_path'])) {
                if (@rename($item['old_path'], $new_path)) {
                    $renamed_dirs[] = [
                        'old_path' => $item['old_path'],
                        'new_path' => $new_path,
                        'old_name' => $item['old_name'],
                        'new_name' => $item['new_name']
                    ];
                    log_msg("  ✓ Папка переименована");

                    // Обновляем пути во всех элементах
                    foreach ($all_items as &$sub_item) {
                        if (strpos($sub_item['old_path'], $item['old_path'] . '/') === 0) {
                            $sub_item['old_path'] = str_replace($item['old_path'], $new_path, $sub_item['old_path']);
                            $sub_item['dir'] = str_replace($item['old_path'], $new_path, $sub_item['dir']);
                        }
                    }
                } else {
                    log_msg("  ✗ Ошибка переименования папки");
                }
            }
        }

        log_msg("ШАГ 4: Переименование файлов");

        // Переименовываем файлы
        $renamed_files = [];
        $files = array_filter($all_items, function($item) { return !$item['is_dir']; });

        foreach ($files as $item) {
            if (!file_exists($item['old_path'])) {
                log_msg("  ✗ Файл не найден: {$item['old_name']}");
                continue;
            }

            $new_path = $item['dir'] . '/' . $item['new_name'];

            // Если имя не изменилось - пропускаем
            if ($item['old_name'] === $item['new_name']) {
                log_msg("  ⊘ Файл пропущен: {$item['old_name']}");
                continue;
            }

            // Если файл с таким именем уже существует - пропускаем (избегаем дубликатов)
            if (file_exists($new_path)) {
                log_msg("  ⊘ Файл пропущен (дубликат): {$item['old_name']}");
                continue;
            }

            log_msg("Переименование файла: {$item['old_name']} -> {$item['new_name']}");

            if (@rename($item['old_path'], $new_path)) {
                $renamed_files[] = [
                    'old_path' => $item['old_path'],
                    'new_path' => $new_path,
                    'old_name' => $item['old_name'],
                    'new_name' => $item['new_name']
                ];
                log_msg("  ✓ Файл переименован");
            } else {
                $error = error_get_last();
                log_msg("  ✗ Ошибка: " . ($error['message'] ?? 'неизвестная ошибка'));
            }
        }

        $renamed_count = count($renamed_dirs) + count($renamed_files);
        log_msg("Всего переименовано: $renamed_count");

        // === ШАГ 5: СОЗДАЕМ МАППИНГ С ВСЕМИ ВАРИАНТАМИ ===
        log_msg("ШАГ 5: Создание маппинга");

        $mapping = [];
        $all_renamed = array_merge($renamed_dirs, $renamed_files);

        foreach ($all_renamed as $item) {
            $old_rel = str_replace(ROOT . '/', '', $item['old_path']);
            $new_rel = str_replace(ROOT . '/', '', $item['new_path']);

            log_msg("Маппинг: $old_rel -> $new_rel");

            // 1. Полный относительный путь
            $mapping[$old_rel] = $new_rel;

            // 2. Только имя файла/папки
            $mapping[$item['old_name']] = $item['new_name'];

            // 3. URL-encoded версия старого пути -> новый путь
            $old_encoded_full = rawurlencode($old_rel);
            $old_encoded_full = str_replace('%2F', '/', $old_encoded_full);
            $mapping[$old_encoded_full] = $new_rel;

            // 4. Все варианты URL encoding для полного пути
            $old_encoded = rawurlencode($old_rel);
            $new_encoded = rawurlencode($new_rel);
            $old_encoded = str_replace('%2F', '/', $old_encoded);
            $new_encoded = str_replace('%2F', '/', $new_encoded);
            $mapping[$old_encoded] = $new_encoded;

            // 5. С %20 вместо пробелов
            $mapping[str_replace(' ', '%20', $old_rel)] = str_replace(' ', '%20', $new_rel);

            // 6. Путь с URL-encoded именем файла -> новый путь
            // (для ссылок вида "../../assets/img/folder/%D0%91%D1%83%D0%BA%D0%BB.jpg")
            $old_dir = dirname($old_rel);
            $old_encoded_name = rawurlencode($item['old_name']);
            if ($old_dir === '.') {
                $mapping[$old_encoded_name] = $new_rel;
            } else {
                $mapping[$old_dir . '/' . $old_encoded_name] = $new_rel;
            }

            // 6a. Варианты с ../ для относительных ссылок
            $prefixes = ['../', '../../', '../../../', '../../../../'];
            foreach ($prefixes as $prefix) {
                $mapping[$prefix . $old_rel] = $prefix . $new_rel;
                $mapping[$prefix . $old_dir . '/' . $old_encoded_name] = $prefix . $new_rel;
            }

            // 7. С закодированным именем
            $mapping[rawurlencode($item['old_name'])] = rawurlencode($item['new_name']);
            $mapping[str_replace(' ', '%20', $item['old_name'])] = $item['new_name'];

            // 8. URL-encoded имя файла -> новое имя (для ссылок в HTML)
            $mapping[rawurlencode($item['old_name'])] = $item['new_name'];

            // 9. Все сегменты пути
            $old_parts = explode('/', $old_rel);
            $new_parts = explode('/', $new_rel);

            for ($i = 0; $i < count($old_parts); $i++) {
                $old_seg_path = implode('/', array_slice($old_parts, 0, $i + 1));
                $new_seg_path = implode('/', array_slice($new_parts, 0, $i + 1));
                $mapping[$old_seg_path] = $new_seg_path;

                // URL-encoded версия сегмента пути
                $old_seg_path_encoded = rawurlencode($old_seg_path);
                $old_seg_path_encoded = str_replace('%2F', '/', $old_seg_path_encoded);
                $mapping[$old_seg_path_encoded] = $new_seg_path;

                // Отдельно каждый сегмент
                $old_seg = $old_parts[$i];
                $new_seg = $new_parts[$i];

                if ($old_seg != $new_seg) {
                    $mapping[$old_seg] = $new_seg;
                    $mapping[str_replace(' ', '%20', $old_seg)] = $new_seg;
                    $mapping[rawurlencode($old_seg)] = $new_seg;
                    $mapping[str_replace(' ', '_', $old_seg)] = $new_seg;
                    $old_with_comma = str_replace(',', '%2C', $old_seg);
                    $mapping[$old_with_comma] = $new_seg;
                    $mapping[str_replace(',', '_', $old_seg)] = $new_seg;
                    $mapping[str_replace([',', ' '], '_', $old_seg)] = $new_seg;
                }
            }
        }

        // Дополнительно: создаём маппинг для всех возможных комбинаций спецсимволов
        foreach ($all_renamed as $item) {
            $old_name = $item['old_name'];
            $new_name = $item['new_name'];

            // Все варианты написания старого имени
            $variants = [
                $old_name,
                str_replace(' ', '%20', $old_name),
                str_replace(' ', '_', $old_name),
                rawurlencode($old_name),
                str_replace(',', '%2C', $old_name),
                str_replace(',', '_', $old_name),
                str_replace([',', ' '], '_', $old_name),
                str_replace([' ', '-', '.'], '_', $old_name),
                str_replace([' ', '-', '.', ','], '_', $old_name),
            ];

            foreach ($variants as $var) {
                if ($var && $var != $new_name) {
                    $mapping[$var] = $new_name;
                }
            }
        }

        // Создаём маппинг для полных путей с разными вариантами кодирования
        foreach ($all_renamed as $item) {
            $old_rel = str_replace(ROOT . '/', '', $item['old_path']);
            $new_rel = str_replace(ROOT . '/', '', $item['new_path']);

            // Заменяем все возможные спецсимволы в старом пути
            $old_variants = [
                $old_rel,
                str_replace(' ', '%20', $old_rel),
                str_replace(' ', '_', $old_rel),
                str_replace('-', '_', $old_rel),
                str_replace(',', '%2C', $old_rel),
                str_replace(',', '_', $old_rel),
                str_replace([' ', '-', ','], '_', $old_rel),
                str_replace([' ', '-', '.', ','], '_', $old_rel),
                rawurlencode(str_replace('%2F', '/', rawurlencode($old_rel))),
            ];

            foreach ($old_variants as $var) {
                if ($var && $var != $new_rel) {
                    $mapping[$var] = $new_rel;
                }
            }
        }

        log_msg("Всего записей в маппинге: " . count($mapping));

        // === ШАГ 6: Поиск текстовых файлов ===
        log_msg("ШАГ 6: Поиск текстовых файлов");

        $text_files = [];
        $exts = ['php', 'html', 'htm', 'js', 'css', 'txt', 'xml', 'json'];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(ROOT, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $ext = strtolower($file->getExtension());
                if (in_array($ext, $exts)) {
                    $text_files[] = str_replace('\\', '/', $file->getPathname());
                }
            }
        }

        log_msg("Найдено текстовых файлов: " . count($text_files));

        // === ШАГ 7: Обновление ссылок ===
        log_msg("ШАГ 7: Обновление ссылок");

        // Сортируем ключи по длине (самые длинные первыми)
        $keys = array_keys($mapping);
        usort($keys, function($a, $b) {
            return strlen($b) - strlen($a);
        });

        $updated_count = 0;
        $total_replacements = 0;

        foreach ($text_files as $file) {
            if (!is_readable($file)) continue;

            $content = file_get_contents($file);
            if (!$content) continue;

            $original = $content;
            $file_replacements = 0;

            // Заменяем все найденные соответствия
            foreach ($keys as $search) {
                $replace = $mapping[$search];
                if ($search === $replace) continue;

                // Простая замена - ищем все вхождения
                $count = 0;
                $content = str_replace($search, $replace, $content, $count);

                if ($count > 0) {
                    $file_replacements += $count;
                    log_msg("  В " . basename($file) . ": '$search' -> '$replace' ($count раз)");
                }
            }

            if ($file_replacements > 0) {
                if (file_put_contents($file, $content)) {
                    $updated_count++;
                    $total_replacements += $file_replacements;
                    log_msg("✅ Обновлен файл: " . basename($file) . " (замен: $file_replacements)");
                } else {
                    log_msg("⚠️ Не удалось записать файл: " . basename($file));
                }
            }
        }

        $result['msgs'][] = "✅ Переименовано: $renamed_count";
        $result['msgs'][] = "✅ Обновлено файлов: $updated_count";
        $result['msgs'][] = "✅ Всего замен: $total_replacements";
        log_msg("=== Готово ===");
        log_msg("Всего обновлено файлов: $updated_count");
        log_msg("Всего замен: $total_replacements");

    } catch (Exception $e) {
        $result['ok'] = false;
        $result['error'] = $e->getMessage();
        log_msg("❌ ОШИБКА: " . $e->getMessage());
    }

    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Нормализатор</title>
    <style>
        body { font-family: Arial; max-width: 900px; margin: 30px auto; padding: 20px; background: #f5f5f5; }
        .container { background: white; padding: 30px; border-radius: 10px; }
        .log { background: #1e1e1e; color: #fff; padding: 15px; height: 400px; overflow-y: auto; font-family: monospace; font-size: 12px; }
        .log-entry { margin: 2px 0; }
        button { padding: 10px 20px; margin: 10px 0; cursor: pointer; }
        .primary { background: #4caf50; color: white; border: none; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔄 Нормализатор</h1>
        <div class="log" id="log"></div>
        <button class="primary" onclick="start()">▶️ Начать</button>
        <button onclick="clearLog()">🗑️ Очистить</button>
        <p><a href="normalizer_log.txt" target="_blank">📄 Открыть лог файл</a></p>
    </div>

    <script>
    const log = document.getElementById('log');

    function add(msg) {
        const div = document.createElement('div');
        div.className = 'log-entry';
        if (msg.includes('✅')) div.style.color = '#6bff6b';
        else if (msg.includes('⚠️')) div.style.color = '#ffd700';
        else if (msg.includes('✗')) div.style.color = '#ff6b6b';
        div.textContent = `[${new Date().toLocaleTimeString()}] ${msg}`;
        log.appendChild(div);
        log.scrollTop = log.scrollHeight;
    }

    function clearLog() {
        log.innerHTML = '';
    }

    async function start() {
        if (!confirm('Начать обработку? Это может занять некоторое время.')) return;

        clearLog();
        add('🚀 Старт...');
        add('📝 Подробности в normalizer_log.txt');

        try {
            const fd = new FormData();
            fd.append('action', 'start');

            const response = await fetch('', { method: 'POST', body: fd });
            const data = await response.json();

            if (data.msgs) {
                data.msgs.forEach(msg => add(msg));
            }

            if (data.error) {
                add('❌ ' + data.error);
            }

        } catch (e) {
            add('❌ ' + e.message);
        }
    }
    </script>
</body>
</html>
