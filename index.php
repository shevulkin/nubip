<?php
declare(strict_types=1);

// Помилки — у файл storage/php-error.log (закритий від веба), а не відвідувачу
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/storage/php-error.log');

// Підключення файлів з даними. Помилка синтаксису в них (зазвичай зіпсовані лапки) — зрозуміле
// повідомлення й запис у лог, а не порожня 500.
function load_data(string $file, bool $required = true): array {
    $path = __DIR__ . '/' . $file;
    if (!is_file($path)) {
        if (!$required) return [];
        http_response_code(503);
        exit('Не налаштовано: немає файлу ' . $file . ' (його кладуть на сервер вручну, див. README).');
    }
    try {
        $r = require $path;
    } catch (Throwable $e) {
        error_log($file . ': ' . $e->getMessage() . ' (рядок ' . $e->getLine() . ')');
        http_response_code(503);
        exit('Помилка у файлі ' . $file . ', рядок ' . $e->getLine() . ': ' . $e->getMessage());
    }
    return is_array($r) ? $r : [];
}

$config  = load_data('config.php');
$members = load_data('data/members.php');
$content = load_data('data/content.php', false)
    + ['times' => [], 'links' => [], 'chats' => [], 'subjects' => [], 'schedule' => []];
date_default_timezone_set($config['timezone']);

// ── Закрито від пошуковиків на кожній відповіді ──
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Content-Security-Policy: default-src \'self\'; img-src \'self\' data:; style-src \'self\' \'unsafe-inline\'; form-action \'self\'; frame-ancestors \'none\'');

// ── Сесія ──
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$sessDir = __DIR__ . '/storage/sessions';
if (is_dir($sessDir) && is_writable($sessDir)) session_save_path($sessDir);
session_name('nubip_s');
session_set_cookie_params([
    'lifetime' => $config['session_lifetime'], 'path' => '/',
    'secure' => $https, 'httponly' => true, 'samesite' => 'Lax',
]);
ini_set('session.gc_maxlifetime', (string)$config['session_lifetime']);
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function short_url(string $u): string {
    return preg_replace('~^https?://~', '', $u);
}
function key9(string $phone): string {
    $d = preg_replace('/\D+/', '', $phone);
    return strlen($d) >= 9 ? substr($d, -9) : '';
}

// ── Обмеження спроб за IP (файли в storage/cache) ──
function attempts_file(): string { return __DIR__ . '/storage/cache/att_' . md5($_SERVER['REMOTE_ADDR'] ?? '?') . '.json'; }
function attempts_get(array $c): array {
    $f = attempts_file();
    $a = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    return array_values(array_filter($a, fn($t) => $t > time() - $c['attempt_window']));
}

// ── Тиждень: чисельник / знаменник ──
function week_info(array $c): array {
    $tz = new DateTimeZone($c['timezone']);
    $start = new DateTimeImmutable($c['week_start'], $tz);
    $start = $start->modify('monday this week')->setTime(0, 0);
    $now = new DateTimeImmutable('now', $tz);
    $days = (int)floor(($now->getTimestamp() - $start->getTimestamp()) / 86400);
    $n = intdiv($days, 7) + ($days < 0 && $days % 7 !== 0 ? -1 : 0); // номер тижня від старту (0-based)
    $startDen = $c['week_start_type'] === 'denominator';
    $isDen = (($n % 2 + 2) % 2 === 0) ? $startDen : !$startDen;
    $mon = $start->modify(($n * 7) . ' days');
    $fmt = fn(DateTimeImmutable $d) => $d->format('d.m');
    return [
        'now' => $isDen ? 'Знаменник' : 'Чисельник',
        'next' => $isDen ? 'Чисельник' : 'Знаменник',
        'den' => $isDen,
        'num' => $n + 1,
        'range' => $fmt($mon) . ' – ' . $fmt($mon->modify('+6 days')),
        'next_range' => $fmt($mon->modify('+7 days')) . ' – ' . $fmt($mon->modify('+13 days')),
        'before_start' => $n < 0,
    ];
}

$action = $_GET['a'] ?? '';
$error = '';

if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST'
    && hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
    $_SESSION = [];
    session_destroy();
    header('Location: ./');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    $att = attempts_get($config);
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $error = 'Сесія застаріла. Спробуйте ще раз.';
    } elseif (count($att) >= $config['max_attempts']) {
        $error = 'Забагато спроб. Зачекайте 15 хвилин.';
    } else {
        $k = key9((string)($_POST['phone'] ?? ''));
        $code = strtoupper(preg_replace('/\s+/', '', (string)($_POST['code'] ?? '')));
        $found = null;
        if ($k !== '') {
            foreach ($members as $i => [$name, $phones]) {
                foreach ($phones as $p) if (key9($p) === $k) $found = $i;
            }
        }
        // Обидві перевірки виконуються завжди — відповідь не видає, що саме не збіглося
        $codeOk = hash_equals(strtoupper($config['group_code']), $code);
        if ($found !== null && $codeOk && $config['group_code'] !== '') {
            session_regenerate_id(true);
            $_SESSION['member'] = $found;
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
            @unlink(attempts_file());
            header('Location: ./');
            exit;
        }
        $att[] = time();
        @file_put_contents(attempts_file(), json_encode($att), LOCK_EX);
        usleep(400000);
        $error = 'Номер або код групи не підійшли.';
    }
}

$me = isset($_SESSION['member'], $members[$_SESSION['member']]) ? $members[$_SESSION['member']][0] : null;
$csrf = $_SESSION['csrf'];
$mtime = fn(string $f) => (string)@filemtime(__DIR__ . '/assets/' . $f);
?><!doctype html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
<meta name="googlebot" content="noindex, nofollow">
<title><?= h($config['title']) ?></title>
<link rel="icon" href="assets/logo.jpg">
<link rel="stylesheet" href="assets/style.css?v=<?= $mtime('style.css') ?>">
</head>
<body>
<?php if (!$me): ?>
<main class="login">
  <img class="logo" src="assets/logo.jpg" alt="Магістри — Бджільництво та апітехнології">
  <h1>Вхід для групи</h1>
  <p class="muted">Введіть свій номер телефону зі списку групи та код групи.</p>
  <?php if ($error): ?><p class="err" role="alert"><?= h($error) ?></p><?php endif; ?>
  <form method="post" action="?a=login" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <label>Номер телефону
      <input name="phone" type="tel" inputmode="tel" placeholder="0XX XXX XX XX" required autofocus>
    </label>
    <label>Код групи
      <input name="code" type="text" autocapitalize="characters" placeholder="Код з чату групи" required>
    </label>
    <button type="submit">Увійти</button>
  </form>
</main>
<?php else:
  $w = week_info($config);
  $parts = explode(' ', $me);
  $greet = $parts[1] ?? $parts[0];
?>
<header class="top">
  <img class="logo sm" src="assets/logo.jpg" alt="">
  <div class="grow"><strong>Магістри 26004М</strong><span class="muted"> · Бджільництво та апітехнології</span></div>
  <div class="who" title="Ви увійшли як"><?= h($me) ?></div>
  <form method="post" action="?a=logout"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><button class="ghost" type="submit">Вийти</button></form>
</header>
<main class="wrap">
  <section class="week <?= $w['den'] ? 'den' : 'num' ?>">
    <?php if ($w['before_start']): ?>
      <div class="big">Семестр ще не розпочався</div>
      <div class="sub">Перший тиждень — за знаменником, з <?= h((new DateTime($config['week_start']))->format('d.m.Y')) ?></div>
    <?php else: ?>
      <div class="lbl">Поточний тиждень · <?= (int)$w['num'] ?>-й · <?= h($w['range']) ?></div>
      <div class="big"><?= h($w['now']) ?></div>
      <div class="sub">Наступний тиждень (<?= h($w['next_range']) ?>) — <?= h($w['den'] ? 'чисельник' : 'знаменник') ?></div>
    <?php endif; ?>
  </section>

  <?php
    $dayNames = [1 => 'Понеділок', 2 => 'Вівторок', 3 => 'Середа', 4 => 'Четвер', 5 => 'Пʼятниця', 6 => 'Субота', 7 => 'Неділя'];
    $today = (int)date('N');
    $curWeek = $w['den'] ? 'den' : 'num';
    $nextWeek = $w['den'] ? 'num' : 'den';

    // Розклад: автоматично з Google-таблиці (за обʼєднаними комірками), інакше — ручний із content.php
    $norm = []; $schedNote = '';
    if (!empty($content['schedule_source'])) {
        require_once __DIR__ . '/lib/schedule.php';
        $res = schedule_load($content['schedule_source'], __DIR__ . '/storage/cache');
        if ($res['ok']) $norm = schedule_normalize($res['items'], $content['subjects']);
        else $schedNote = 'Розклад із таблиці тимчасово недоступний.';
    }
    if (!$norm && $content['schedule']) {
        $items = [];
        foreach ($content['schedule'] as $d => $pp) foreach ($pp as [$n, $k, $room]) $items[] = [$d, $n, 'both', ($content['subjects'][$k]['name'] ?? $k) . ' ' . $room];
        $norm = schedule_normalize($items, $content['subjects']);
    }
    $pairsToday = schedule_for($norm, $today, $curWeek);
    $weekTag = ['both' => '', 'num' => 'чисельник', 'den' => 'знаменник'];
    $renderPairs = function (array $pairs) use ($content, $weekTag) {
        foreach ($pairs as $p) {
            echo '<div class="card"><b>', (int)$p['pair'], ' пара · ', h($content['times'][$p['pair']] ?? ''), '</b>', h($p['name']);
            if ($p['room'] !== '') echo '<small>', h($p['room']), '</small>';
            echo '</div>';
        }
    };
  ?>
  <?php if ($norm || $schedNote): ?>
  <section>
    <h2>Пари сьогодні · <?= h($dayNames[$today]) ?> · <?= h($w['den'] ? 'знаменник' : 'чисельник') ?></h2>
    <?php if ($schedNote): ?><p class="muted"><?= h($schedNote) ?></p><?php endif; ?>
    <?php if ($norm && !$pairsToday): ?><p class="muted">Сьогодні пар немає.</p><?php endif; ?>
    <?php $renderPairs($pairsToday); ?>
    <?php foreach ([[$curWeek, 'Поточний тиждень · ' . $w['now']], [$nextWeek, 'Наступний тиждень · ' . $w['next']]] as $wi => [$wt, $wl]): ?>
      <details class="wk"<?= $wi === 0 ? ' open' : '' ?>><summary><?= h($wl) ?></summary>
        <?php for ($d = 1; $d <= 6; $d++): $dp = schedule_for($norm, $d, $wt); if (!$dp) continue; ?>
          <h3><?= h($dayNames[$d]) ?></h3>
          <?php $renderPairs($dp); ?>
        <?php endfor; ?>
      </details>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

  <div class="cols">
    <section>
      <h2>Посилання</h2>
      <?php foreach ($content['links'] as [$t, $u, $d, $showUrl]): ?>
        <a class="card" href="<?= h($u) ?>" target="_blank" rel="noopener noreferrer"><b><?= h($t) ?></b><small><?= h($d) ?></small><small class="url"><?= h(short_url($u)) ?></small></a>
      <?php endforeach; ?>
      <?php if ($content['chats']): ?><h2>Чати групи</h2>
        <?php foreach ($content['chats'] as [$t, $u, $d]): ?>
          <a class="card" href="<?= h($u) ?>" target="_blank" rel="noopener noreferrer"><b><?= h($t) ?></b><small><?= h($d) ?></small></a>
        <?php endforeach; ?>
      <?php endif; ?>
      <h2>Група · <?= count($members) ?> студентів</h2>
      <ol class="list">
        <?php foreach ($members as $i => [$name]): ?>
          <li<?= $name === $me ? ' class="me"' : '' ?>><?= h($name) ?></li>
        <?php endforeach; ?>
      </ol>
    </section>
    <section>
      <h2>Предмети</h2>
      <?php foreach ($content['subjects'] as $key => $s):
        ?>
        <div class="card">
          <b><?= h($s['name']) ?></b>
          <?php if (!empty($s['teacher'])): ?><small><?= h($s['teacher']) ?></small><?php endif; ?>
          <?php foreach ($s['playlists'] ?? [] as [$pl, $pu]): ?>
            <a class="pl" href="<?= h($pu) ?>" target="_blank" rel="noopener noreferrer">▶ <?= h($pl) ?></a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </section>
  </div>
</main>
<?php endif; ?>
</body>
</html>
