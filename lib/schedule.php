<?php
declare(strict_types=1);

/**
 * Розклад зі спільної Google-таблиці. Читаємо експорт у xlsx, бо лише він зберігає ОБʼЄДНАНІ
 * КОМІРКИ — саме за ними видно, яка пара на обидва тижні, яка лише на один і які групи
 * об'єднані в одну пару. (CSV віддає значення лише в першій комірці обʼєднання.)
 *
 * Повертає ['ok'=>bool, 'error'=>string, 'items'=>[[день, пара, тиждень, текст], ...]]
 *   день: 1=Пн…7=Нд;  тиждень: 'both' | 'num' | 'den'
 */
/** Нижній регістр для кирилиці без залежності від mbstring (довжина в байтах не змінюється). */
function ulower(string $s): string
{
    static $map = null;
    $map ??= array_combine(
        array_merge(mb_str_split_safe('АБВГҐДЕЄЖЗИІЇЙКЛМНОПРСТУФХЦЧШЩЬЮЯ'), range('A', 'Z')),
        array_merge(mb_str_split_safe('абвгґдеєжзиіїйклмнопрстуфхцчшщьюя'), range('a', 'z'))
    );
    return strtr($s, $map);
}
function mb_str_split_safe(string $s): array { return preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY); }

function schedule_load(array $src, string $cacheDir): array
{
    $file = $cacheDir . '/schedule.xlsx';
    $ttl = (int)($src['ttl'] ?? 1800);
    $fresh = is_file($file) && filemtime($file) > time() - $ttl;
    if (!$fresh) {
        $data = schedule_download($src['url'] ?? '');
        if ($data !== null) {
            @file_put_contents($file, $data, LOCK_EX);
        } elseif (!is_file($file)) {
            return ['ok' => false, 'error' => 'Не вдалося завантажити таблицю', 'items' => []];
        } // інакше — лишаємо попередню копію з кешу
    }
    try {
        return ['ok' => true, 'error' => '', 'items' => schedule_parse($file, $src)];
    } catch (Throwable $e) {
        error_log('schedule: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage(), 'items' => []];
    }
}

function schedule_download(string $url): ?string
{
    if ($url === '') return null;
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'follow_location' => 1, 'user_agent' => 'nubip-schedule']]);
    $d = @file_get_contents($url, false, $ctx);
    if ($d === false || strncmp($d, "PK", 2) !== 0) { // xlsx — це zip
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 8]);
            $d = curl_exec($ch);
            curl_close($ch);
        }
    }
    return (is_string($d) && strncmp($d, "PK", 2) === 0) ? $d : null;
}

function schedule_col(string $letters): int
{
    $n = 0;
    foreach (str_split($letters) as $ch) $n = $n * 26 + ord($ch) - 64;
    return $n;
}

function schedule_parse(string $xlsx, array $src): array
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('Немає розширення zip у PHP');
    $zip = new ZipArchive();
    if ($zip->open($xlsx) !== true) throw new RuntimeException('Файл таблиці пошкоджений');

    // Рядки зі спільного словника
    $strings = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false) {
        $x = simplexml_load_string($ss);
        foreach ($x->si as $si) {
            $t = '';
            foreach ($si->xpath('.//*[local-name()="t"]') as $n) $t .= (string)$n;
            $strings[] = $t;
        }
    }

    $sheet = $zip->getFromName($src['sheet_file'] ?? 'xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheet === false) throw new RuntimeException('Немає аркуша розкладу');
    $x = simplexml_load_string($sheet);

    // Комірки: [рядок][колонка] => текст
    $cells = [];
    foreach ($x->sheetData->row as $row) {
        foreach ($row->c as $c) {
            if (!preg_match('/^([A-Z]+)(\d+)$/', (string)$c['r'], $m)) continue;
            $v = isset($c->v) ? (string)$c->v : '';
            if ($v === '') continue;
            $cells[(int)$m[2]][schedule_col($m[1])] = ((string)$c['t'] === 's') ? ($strings[(int)$v] ?? '') : $v;
        }
    }

    // Обʼєднання: для кожної комірки — прямокутник, до якого вона належить
    $merges = [];
    if (isset($x->mergeCells)) {
        foreach ($x->mergeCells->mergeCell as $mc) {
            if (!preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', (string)$mc['ref'], $m)) continue;
            $merges[] = [schedule_col($m[1]), (int)$m[2], schedule_col($m[3]), (int)$m[4]];
        }
    }
    $mergeAt = function (int $col, int $row) use ($merges): ?array {
        foreach ($merges as $m) if ($col >= $m[0] && $col <= $m[2] && $row >= $m[1] && $row <= $m[3]) return $m;
        return null;
    };
    // Значення комірки з урахуванням обʼєднання (значення лежить у лівій верхній)
    $valueAt = function (int $col, int $row) use ($cells, $mergeAt): string {
        $m = $mergeAt($col, $row);
        $t = $m ? ($cells[$m[1]][$m[0]] ?? '') : ($cells[$row][$col] ?? '');
        return trim(preg_replace('/\s+/u', ' ', $t));
    };

    // Колонка групи — за назвою в шапці
    $needle = ulower((string)($src['group'] ?? ''));
    $gc = 0;
    foreach ($cells as $r => $line) {
        if ($r > 12) break;
        foreach ($line as $c => $t) if ($needle !== '' && strpos(ulower($t), $needle) !== false) { $gc = $c; break 2; }
    }
    if (!$gc) throw new RuntimeException('Колонку групи «' . ($src['group'] ?? '') . '» у таблиці не знайдено');

    $days = ['понеділок' => 1, 'вівторок' => 2, 'середа' => 3, 'четвер' => 4, 'пятниця' => 5, 'п’ятниця' => 5, 'пʼятниця' => 5, 'субота' => 6, 'неділя' => 7];
    $topWeek = ($src['top_week'] ?? 'num') === 'den' ? 'den' : 'num';
    $botWeek = $topWeek === 'num' ? 'den' : 'num';

    $maxRow = max(array_keys($cells));
    $items = [];
    $day = 0;
    for ($r = 1; $r <= $maxRow; $r++) {
        $d = ulower($valueAt(1, $r));
        if (isset($days[$d])) $day = $days[$d];
        if (!$day) continue;
        // Початок блоку пари: у колонці B є номер, і комірка B починається саме з цього рядка
        $b = $mergeAt(2, $r);
        $first = $b ? $b[1] : $r;
        if ($first !== $r || !is_numeric($valueAt(2, $r))) continue;
        // Блок пари — два рядки (чисельник/знаменник), навіть якщо номер не обʼєднано
        $last = $b ? $b[3] : (is_numeric($valueAt(2, $r + 1)) || $valueAt(2, $r + 1) !== '' ? $r : $r + 1);
        $pair = (int)(float)$valueAt(2, $r);

        // Комірка групи в цьому блоці: одна на обидва тижні чи по одній на кожен
        $seen = [];
        for ($rr = $first; $rr <= $last; $rr++) {
            $m = $mergeAt($gc, $rr);
            $top = $m ? $m[1] : $rr;
            $bottom = $m ? $m[3] : $rr;
            $text = $valueAt($gc, $rr);
            $key = $top . ':' . $bottom;
            if ($text === '' || isset($seen[$key])) continue;
            $seen[$key] = true;
            $coversAll = $top <= $first && $bottom >= $last;
            $week = $coversAll ? 'both' : ($rr === $first ? $topWeek : $botWeek);
            if (!$coversAll && $last - $first < 1) $week = 'both';
            $items[] = [$day, $pair, $week, $text];
        }
    }
    return $items;
}

/** Розбиває «Назва предмета 121 к.1» на предмет (за списком відомих) і решту (аудиторія). */
function schedule_split(string $text, array $subjects): array
{
    $best = null;
    foreach ($subjects as $key => $s) {
        $name = (string)($s['name'] ?? '');
        if ($name !== '' && strncmp(ulower($text), ulower($name), strlen($name)) === 0 && ($best === null || strlen($name) > strlen($subjects[$best]['name']))) $best = $key;
    }
    if ($best !== null) return [$best, trim(substr($text, strlen($subjects[$best]['name'])))];
    return [null, $text];
}

/** Додає до кожної пари назву предмета й аудиторію; прибирає зайвий припис «(по чисельнику)». */
function schedule_normalize(array $items, array $subjects): array
{
    $out = [];
    foreach ($items as [$day, $pair, $week, $text]) {
        [$key, $rest] = schedule_split($text, $subjects);
        $rest = trim(preg_replace('/\(\s*по\s+(чисельнику|знаменнику)\s*\)/ui', '', $rest));
        $out[] = ['text' => $text, 'day' => $day, 'pair' => $pair, 'week' => $week, 'key' => $key,
                  'join' => $key !== null ? ($subjects[$key]['join'] ?? '') : '',
                  'name' => $key !== null ? $subjects[$key]['name'] : $text, 'room' => $key !== null ? $rest : ''];
    }
    usort($out, fn($a, $b) => [$a['day'], $a['pair']] <=> [$b['day'], $b['pair']]);
    return $out;
}

/** Пари на день для потрібного тижня ('num' | 'den'): спільні + ті, що лише на цей тиждень. */
function schedule_for(array $norm, int $day, string $weekType): array
{
    return array_values(array_filter($norm, fn($p) => $p['day'] === $day && ($p['week'] === 'both' || $p['week'] === $weekType)));
}

/**
 * Посилання на підключення з таблиці «Посилання на онлайн заняття». У ній два блоки для магістрів:
 * «посилання на лекції» (вівторок) і «посилання на практичні заняття». Рядок: назва предмета
 * (інколи з кодом на кшталт (07.03)), викладач, посилання.
 * Повертає ['lecture' => [[назва, код, url], …], 'practice' => [...]].
 */
function joins_load(array $src, string $cacheDir): array
{
    $file = $cacheDir . '/joins.csv';
    $ttl = (int)($src['ttl'] ?? 1800);
    if (!is_file($file) || filemtime($file) < time() - $ttl) {
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'follow_location' => 1, 'user_agent' => 'nubip-schedule']]);
        $d = @file_get_contents($src['url'] ?? '', false, $ctx);
        if (is_string($d) && $d !== '' && $d[0] !== '<') @file_put_contents($file, $d, LOCK_EX);
    }
    $out = ['lecture' => [], 'practice' => []];
    if (!is_file($file)) return $out;
    $h = fopen($file, 'rb');
    $section = null;
    $started = !isset($src['after']);
    while (($row = fgetcsv($h, 0, ',', '"', '')) !== false) {
        $line = ulower(implode(' ', $row));
        if (!$started) { if (strpos($line, ulower($src['after'])) !== false) $started = true; continue; }
        if (strpos($line, 'посилання на лекції') !== false) { $section = 'lecture'; continue; }
        if (strpos($line, 'посилання на практичні') !== false) { $section = 'practice'; continue; }
        if ($section === null) continue;
        $a = trim(preg_replace('/\s+/u', ' ', (string)($row[0] ?? '')));
        if ($a === '') continue;
        if (!preg_match('~https?://[^\s,;]+~u', implode(' ', array_slice($row, 1)), $m)) continue;
        $code = preg_match('/\((\d\d\.\d\d)\)/', $a, $c) ? $c[1] : '';
        $name = trim(preg_replace('/\(\d\d\.\d\d\)|\//u', '', $a));
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        $out[$section][] = [$name, $code, $m[0]];
    }
    fclose($h);
    return $out;
}

/** Підставляє посилання в пари, де його ще немає. Вівторок — лекції, інші дні — практичні. */
function joins_apply(array $norm, array $joins): array
{
    foreach ($norm as &$p) {
        if (!empty($p['join'])) continue;
        $order = $p['day'] === 2 ? ['lecture', 'practice'] : ['practice', 'lecture'];
        foreach ($order as $sec) {
            foreach ($joins[$sec] as [$name, $code, $url]) {
                if ($name === '' || strncmp(ulower($p['name']), ulower($name), strlen($name)) !== 0) continue;
                if ($code !== '' && strpos($p['text'], '(' . $code . ')') === false) continue;
                $p['join'] = $url;
                break 2;
            }
        }
    }
    return $norm;
}
