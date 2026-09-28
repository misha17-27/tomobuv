<?php
/**
 * Скопируйте файл в config/config.php и заполните под свой хостинг.
 * config/config.php в git не попадает.
 */
return [
    'env'      => 'production',          // production | dev
    'debug'    => false,                  // true — показывать ошибки (только на локальной машине!)
    'base_url' => 'https://tomobuv.com.ua', // без слэша в конце; используется в canonical, sitemap, письмах

    'db' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'name'     => 'tomobuv',
        'user'     => 'tomobuv',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],

    // База старого сайта Webasyst — нужна только для bin/import-webasyst.php
    'webasyst_db' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'name'     => 'tomobuv_old',
        'user'     => 'tomobuv',
        'password' => '',
    ],

    'cache' => [
        'pages'     => true,   // кэш готовых HTML-страниц витрины (главный ускоритель)
        'page_ttl'  => 3600,   // сек.
        'data_ttl'  => 3600,
    ],

    'images' => [
        // Папка wa-data со старого сайта (фото товаров). Путь относительно public/.
        'wa_data'     => 'wa-data',
        // Для локальной разработки без копии wa-data: брать фото с живого сайта.
        // На боевом сервере оставьте пустым.
        'remote_base' => '',
        'jpeg_quality'=> 85,
    ],

    'mail' => [
        'from'       => 'tomobuv@gmail.com',
        'from_name'  => 'Tomobuv',
        'admin_to'   => 'tomobuv@gmail.com', // куда слать уведомления о заказах и заявках
        // SMTP (необязательно). Пусто — используется mail() хостинга.
        'smtp_host'  => '',
        'smtp_port'  => 465,
        'smtp_user'  => '',
        'smtp_pass'  => '',
        'smtp_secure'=> 'ssl',
    ],

    // Секрет для подписи cookie и токенов. Сгенерируйте: php -r "echo bin2hex(random_bytes(32));"
    'app_key' => 'CHANGE_ME',

    // Ограничить доступ к /admin по IP (пусто — без ограничения)
    'admin_ips' => [],
];
