<?php
// Налаштування сайту. Секрети сервера можна перекрити у config.local.php (не потрапляє в git).
$config = [
    'title'      => 'Магістри · Бджільництво та апітехнології',
    // Код групи, посилання й чати — у config.local.php (не в git). Порожній код = вхід вимкнено.
    'group_code' => '',
    // Перший тиждень семестру (понеділок) і його тип
    'week_start' => '2026-09-07',
    'week_start_type' => 'denominator', // denominator = знаменник, numerator = чисельник
    'timezone'   => 'Europe/Kyiv',
    // Скільки невдалих спроб входу з однієї адреси за вікно
    'max_attempts' => 6,
    'attempt_window' => 900,
    'session_lifetime' => 60 * 60 * 24 * 30,
    'links' => [],
    'chats' => [],
];
if (is_file(__DIR__ . '/config.local.php')) {
    $config = array_replace($config, require __DIR__ . '/config.local.php');
}
return $config;
