# Магістри 26004М · Бджільництво та апітехнології

Закритий сайт групи: список групи, посилання, поточний тиждень (чисельник/знаменник).
PHP без бази даних. Вхід: номер телефону зі списку + код групи.

- Користувачі й телефони — `data/members.php` (НЕ в git, лежить лише на сервері й локально; зразок — `members.example.php`).
- Код групи, дата початку семестру, посилання — `config.php` (перекрити на сервері можна в `config.local.php`).
- Тиждень: `week_start` — понеділок першого тижня, `week_start_type` — `denominator`/`numerator`.
- Від пошуковиків закрито: `robots.txt`, `X-Robots-Tag` (.htaccess і PHP), `<meta robots>`.
- Розгортання: cPanel → Git Version Control → Deploy HEAD Commit (`.cpanel.yml`, тека `~/public_html/nubip`).
- Локально: `php -S localhost:8095`.
- Конфіденційне (телефони, код групи, посилання на таблиці й Viber) — `data/members.php` і `config.local.php`: **не в git**, їх кладуть на сервер вручну (cPanel File Manager).
