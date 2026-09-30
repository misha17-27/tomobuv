<?php
declare(strict_types=1);

namespace App\Services\Import;

use App\Core\App;
use App\Core\Cache;
use App\Core\Image;
use App\Core\Log;
use App\Core\Str;
use App\Services\AdminCatalog;
use App\Services\CatalogIndexer;
use App\Services\HtmlSanitizer;
use App\Services\ProductName;

/**
 * Импорт товаров от поставщиков.
 *
 * Жизненный цикл задания (import_jobs.status):
 *   parsing  → файл читается потоком и складывается в import_rows пачками (с продолжением);
 *   new      → ждёт настройки: сопоставление колонок, ключ поиска, наценка…;
 *   running  → обработка шагами по 100–1000 строк (один шаг = одна транзакция, пакетные запросы);
 *   finishing→ «скрыть отсутствующие», переиндексация каталога, сброс кэша;
 *   images   → докачка фото по ссылкам (≤ 20 за шаг);
 *   done / error.
 * Каждый шаг укладывается в ~15 с, поэтому работает и на слабом хостинге; после обрыва
 * задание продолжается с last_n (номер последней обработанной строки).
 */
final class Importer
{
    /** Поля товара для сопоставления с колонками файла */
    public const FIELDS = [
        'id'             => 'ID товара на сайте',
        'sku'            => 'Артикул',
        'supplier_code'  => 'Код поставщика',
        'name'           => 'Название',
        'price'          => 'Цена за пару',
        'price_box'      => 'Цена за ящик',
        'compare_price'  => 'Старая цена (за пару)',
        'purchase_price' => 'Закупочная цена',
        'box_qty'        => 'Пар в ящике',
        'min_qty'        => 'Минимальный заказ, пар',
        'size'           => 'Размерный ряд',
        'brand'          => 'Бренд',
        'category'       => 'Категория',
        'stock'          => 'Остаток',
        'in_stock'       => 'Наличие (да/нет)',
        'status'         => 'Показывать на сайте (да/нет)',
        'description'    => 'Описание',
        'summary'        => 'Краткое описание',
        'images'         => 'Фото (ссылки)',
        'url'            => 'Адрес страницы (url)',
        'supplier'       => 'Поставщик',
        'meta_title'          => 'SEO: заголовок (title)',
        'meta_description'    => 'SEO: описание (description)',
        'meta_keywords'       => 'SEO: ключевые слова',
        'name_uk'             => 'Название (укр.)',
        'description_uk'      => 'Описание (укр.)',
        'summary_uk'          => 'Краткое описание (укр.)',
        'meta_title_uk'       => 'SEO укр.: заголовок (title)',
        'meta_description_uk' => 'SEO укр.: описание (description)',
        'meta_keywords_uk'    => 'SEO укр.: ключевые слова',
        'updated_at'          => 'Изменён на сайте (из выгрузки)',
    ];

    /** Текстовые колонки products (SEO и украинская версия): поле => максимальная длина */
    private const TEXT_COLS = ['name_uk' => 255, 'meta_title' => 500, 'meta_description' => 5000, 'meta_keywords' => 5000,
        'meta_title_uk' => 500, 'meta_description_uk' => 5000, 'meta_keywords_uk' => 5000];

    /** Поля product_texts */
    private const TEXT_FIELDS = ['description', 'summary', 'description_uk', 'summary_uk'];

    public const KEYS = [
        'sku'           => 'Артикул',
        'supplier_code' => 'Поставщик + код поставщика',
        'id'            => 'ID товара на сайте',
        'url'           => 'Адрес страницы (url)',
        'name'          => 'Название',
    ];

    public const MODES = [
        'both'   => 'Создавать новые и обновлять найденные',
        'update' => 'Только обновлять существующие',
        'create' => 'Только создавать новые',
    ];

    public const ROUNDS = [0 => 'Не округлять', 1 => 'До 1 грн (вверх)', 5 => 'До 5 грн (вверх)', 10 => 'До 10 грн (вверх)', 50 => 'До 50 грн (вверх)'];

    public const STATUSES = [
        'parsing' => 'Разбор файла', 'new' => 'Ожидает запуска', 'running' => 'Импорт идёт', 'finishing' => 'Завершение',
        'images' => 'Загрузка фото', 'done' => 'Готово', 'error' => 'Ошибка',
    ];

    /** Поля, которые меняет режим «обновлять только цену и наличие» */
    private const PRICE_ONLY = ['price', 'compare_price', 'purchase_price', 'stock', 'in_stock'];

    /** Колонки products, которые импорт может обновлять (белый список для UPDATE) */
    private const UPDATABLE = ['name', 'sku', 'url', 'supplier', 'supplier_code', 'category_id', 'brand_id', 'price', 'compare_price',
        'purchase_price', 'box_qty', 'min_qty', 'size', 'stock', 'in_stock', 'status', 'updated_at',
        'name_uk', 'meta_title', 'meta_description', 'meta_keywords', 'meta_title_uk', 'meta_description_uk', 'meta_keywords_uk'];

    private const EX_COLS = 'id, url, name, sku, supplier, supplier_code, category_id, brand_id, price, compare_price, purchase_price,
        box_qty, min_qty, size, stock, in_stock, status, image_id, updated_at,
        name_uk, meta_title, meta_description, meta_keywords, meta_title_uk, meta_description_uk, meta_keywords_uk';

    /** Подписи изменённых колонок в предпросмотре («Что изменится») */
    private const CHANGE_NAMES = ['price' => 'цена', 'compare_price' => 'старая цена', 'purchase_price' => 'закупочная', 'stock' => 'остаток',
        'in_stock' => 'наличие', 'status' => 'на сайте', 'name' => 'название', 'name_uk' => 'название (укр.)', 'sku' => 'артикул',
        'supplier_code' => 'код поставщика', 'size' => 'размеры', 'box_qty' => 'пар в ящике', 'min_qty' => 'мин. заказ', 'url' => 'адрес',
        'supplier' => 'поставщик', 'meta_title' => 'SEO title', 'meta_description' => 'SEO description', 'meta_keywords' => 'SEO keywords',
        'meta_title_uk' => 'SEO title (укр.)', 'meta_description_uk' => 'SEO description (укр.)', 'meta_keywords_uk' => 'SEO keywords (укр.)'];

    /** Характеристики, которые заполняются из полей товара (в сопоставлении их нет) */
    private const SYSTEM_FEATURES = ['brand', 'size', 'kol_vo_par'];

    private const MAX_ROWS = 500000;
    private const IMG_PER_STEP = 20;
    /** Больше стольких товаров с одним значением ключа — их строки не загружаются: в каталоге «Артикул» — это размерный ряд
     *  (36-41 — у 36 000 товаров), и пачка из 400 строк иначе тянула бы в память весь каталог */
    private const KEY_MAX_MATCHES = 20;
    private const IMG_MAX_BYTES = 10485760;     // 10 МБ
    private const IMG_TIMEOUT = 10;
    private const IMG_PER_PRODUCT = 10;
    private const FULL_REINDEX_FROM = 1500;     // больше изменённых товаров — перестраиваются все категории
    private const REINDEX_BUDGET = 8.0;         // секунд на перестройку категорий за один шаг
    private const LOCK_RETRIES = 3;             // повторов шага после взаимной блокировки InnoDB (1213) или ожидания блокировки (1205)

    /** Синонимы заголовков для автосопоставления (сравниваются без регистра, пробелов и знаков) */
    private const SYNONYMS = [
        'id'             => ['id', 'ид', 'idтовара', 'productid'],
        'sku'            => ['sku', 'артикул', 'артикултовара', 'art', 'article', 'vendorcode', 'модель', 'model'],
        'supplier_code'  => ['suppliercode', 'кодпоставщика', '@id', 'offerid', 'idпоставщика', 'кодтовара', 'code', '@code', 'productcode', 'itemcode'],
        'name'           => ['name', 'название', 'наименование', 'товар', 'nameru', 'названиетовара', 'наименованиетовара', 'title'],
        'price'          => ['price', 'цена', 'ценазапару', 'ценаопт', 'оптоваяцена', 'ценаоптовая', 'стоимость', 'ценазаединицу', 'cost', 'wholesaleprice', 'priceopt'],
        'price_box'      => ['pricebox', 'ценазаящик', 'ценаящика', 'ценазаупаковку', 'ценаупаковки'],
        'compare_price'  => ['compareprice', 'oldprice', 'стараяцена', 'ценадоскидки', 'ценабезскидки'],
        'purchase_price' => ['purchaseprice', 'закупочнаяцена', 'закупка', 'ценазакупки', 'закупочная'],
        'box_qty'        => ['boxqty', 'pairs', 'pairsinbox', 'парвящике', 'количествопар', 'колвопар', 'вящике', 'кратность', 'количествовящике', 'парвупаковке', 'paramколвопар', 'paramколичествопар', 'paramпарвящике'],
        'min_qty'        => ['minqty', 'минимальныйзаказ', 'минзаказ'],
        'size'           => ['size', 'размер', 'размеры', 'размерныйряд', 'ростовка', 'paramразмер', 'paramразмерныйряд', 'paramразмеры'],
        'brand'          => ['brand', 'бренд', 'vendor', 'производитель', 'торговаямарка', 'марка', 'тм', 'parambrand', 'paramбренд'],
        'category'       => ['category', 'категория', 'раздел', 'группа', 'categoryname', 'категориятовара', 'group', 'section'],
        'stock'          => ['stock', 'остаток', 'остатки', 'количество', 'quantity', 'quantityinstock', 'stockquantity', 'наскладе', 'колво'],
        'in_stock'       => ['instock', 'наличие', 'available', '@available', 'вналичии', 'availability'],
        'status'         => ['status', 'статус', 'видимость', 'опубликован', 'показывать'],
        'description'    => ['description', 'описание', 'descriptionru', 'полноеописание', 'описаниетовара'],
        'summary'        => ['summary', 'краткоеописание'],
        'images'         => ['images', 'image', 'фото', 'фотографии', 'изображения', 'изображение', 'картинка', 'картинки', 'picture', 'pictures', 'photo', 'photos', 'imageurl', 'ссылканафото', 'img', 'imagesimage'],
        'url'            => ['url', 'адресстраницы'],
        'supplier'       => ['supplier', 'поставщик'],
        'meta_title'          => ['metatitle', 'seotitle', 'заголовокtitle', 'seoзаголовок'],
        'meta_description'    => ['metadescription', 'seodescription', 'seoописание', 'метаописание'],
        'meta_keywords'       => ['metakeywords', 'keywords', 'ключевыеслова', 'seoключевыеслова'],
        'name_uk'             => ['nameuk', 'nameua', 'названиеукр', 'назва', 'названиеua', 'назватовару'],
        'description_uk'      => ['descriptionuk', 'descriptionua', 'описаниеукр', 'опис', 'опистовару'],
        'summary_uk'          => ['summaryuk', 'summaryua', 'краткоеописаниеукр', 'короткийопис'],
        'meta_title_uk'       => ['metatitleuk', 'metatitleua', 'seotitleuk'],
        'meta_description_uk' => ['metadescriptionuk', 'metadescriptionua', 'seodescriptionuk'],
        'meta_keywords_uk'    => ['metakeywordsuk', 'metakeywordsua', 'keywordsuk'],
        // только заголовок нашей выгрузки: «Дата изменения» / modified в файле поставщика — его собственная дата,
        // по ней свежий прайс ложно считался бы «изменён на сайте после выгрузки» и не записывался
        'updated_at'          => ['updatedat', 'изменённасайтеизвыгрузки', 'измененнасайтеизвыгрузки'],
    ];

    private static bool $schemaOk = false;

    // ============================================================ служебное

    /** Папка файлов импорта (вне public) */
    public static function dir(): string
    {
        $d = STORAGE . '/import';
        if (!is_dir($d)) @mkdir($d, 0775, true);
        if (!is_dir($d . '/tmp')) @mkdir($d . '/tmp', 0775, true);
        return $d;
    }

    /**
     * Проверить, что миграции раздела применены (иначе выполнить database/migrations/admin-import.sql
     * и import-conflicts.sql — колонка import_seen.conflict)
     */
    public static function ensureSchema(): void
    {
        if (self::$schemaOk) return;
        Cache::remember('import.schema.3', 86400, static function (): int {
            $db = App::db();
            $ok = (int) $db->value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'import_jobs' AND COLUMN_NAME = 'last_n'")
                && (int) $db->value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'import_errors'")
                && (int) $db->value("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'products' AND COLUMN_NAME = 'supplier_code' AND SEQ_IN_INDEX = 1");
            $run = static function (string $file) use ($db): void {
                $sql = (string) @file_get_contents(ROOT . '/database/migrations/' . $file);
                $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);
                foreach (preg_split('/;\s*\n/', $sql) ?: [] as $stmt) {
                    if (trim($stmt) !== '') $db->pdo()->exec($stmt);
                }
            };
            if (!$ok) $run('admin-import.sql');
            if (!(int) $db->value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'import_seen' AND COLUMN_NAME = 'conflict'")) $run('import-conflicts.sql');
            return 1;
        });
        self::$schemaOk = true;
    }

    public static function defaults(): array
    {
        return [
            'key' => 'sku', 'mode' => 'both', 'supplier' => '', 'markup' => 0, 'round' => 0,
            'price_only' => 0, 'hide_missing' => 0, 'unhide' => 0, 'purchase_from_price' => 0, 'images' => 1,
            'default_category' => 0, 'category_map' => '', 'default_box_qty' => 0, 'new_status' => 1, 'batch' => 400,
            'delimiter' => '', 'header' => 1, 'item_path' => '', 'yml' => 0, 'source_url' => '',
            'overwrite' => 0,   // перезаписывать товары, изменённые на сайте после выгрузки (колонка updated_at)
        ];
    }

    /**
     * Поставщик из формы: обычные символы названий («Forsage (Одесса)», «Obuv & Co», «ТМ "Лидер"») — как есть,
     * убираются только управляющие символы и лишние пробелы. В SQL — только через плейсхолдеры, в HTML — через e().
     */
    public static function cleanSupplier(string $v): string
    {
        $v = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', mb_scrub($v, 'UTF-8'));
        return trim(mb_substr(trim((string) preg_replace('/\s+/u', ' ', $v)), 0, 64));
    }

    /** Ссылка на файл поставщика: только http(s), без пробелов, до 1000 символов ('' — неверная) */
    public static function cleanSourceUrl(string $url): string
    {
        $url = trim($url);
        return $url !== '' && strlen($url) <= 1000 && preg_match('#^https?://[^\s<>"]+$#i', $url) && filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }

    // ============================================================ профили

    public static function profiles(): array
    {
        self::ensureSchema();
        $rows = App::db()->all('SELECT p.*, (SELECT MAX(j.created_at) FROM import_jobs j WHERE j.profile_id = p.id) last_run
            FROM import_profiles p ORDER BY p.name, p.id');
        foreach ($rows as &$r) {
            $o = json_decode((string) $r['options'], true);
            $r['options'] = (is_array($o) ? $o : []) + self::defaults();
            $m = json_decode((string) $r['mapping'], true);
            $r['mapping'] = is_array($m) ? $m : [];
        }
        unset($r);
        return $rows;
    }

    public static function profile(int $id): ?array
    {
        return $id > 0 ? App::db()->row('SELECT * FROM import_profiles WHERE id = ?', [$id]) : null;
    }

    /**
     * Сохранить настройки задания как профиль поставщика ($profileId = 0 — новый).
     * В профиль попадают сопоставление колонок и все настройки, кроме служебных.
     */
    public static function saveProfile(int $profileId, string $name, array $job): int
    {
        $db = App::db();
        $opt = array_intersect_key($job['options'], self::defaults());
        unset($opt['overwrite']);                   // перезапись — решение для одного запуска, не для профиля
        $data = [
            'name' => mb_substr(trim($name), 0, 190), 'format' => (string) $job['format'],
            'mapping' => self::json(array_filter($job['mapping'], static fn($v) => $v !== '')), 'options' => self::json($opt),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($profileId > 0 && $db->value('SELECT id FROM import_profiles WHERE id = ?', [$profileId])) {
            $db->update('import_profiles', $data, 'id = ?', [$profileId]);
        } else {
            $profileId = $db->insert('import_profiles', $data);
        }
        $db->update('import_jobs', ['profile_id' => $profileId], 'id = ?', [(int) $job['id']]);
        return $profileId;
    }

    /** Поставщики, которые уже есть у товаров (для подсказки в поле «Поставщик») */
    public static function suppliers(): array
    {
        return Cache::remember('import.suppliers', 600, static fn() =>
            App::db()->col("SELECT supplier FROM products WHERE supplier IS NOT NULL AND supplier <> '' GROUP BY supplier ORDER BY supplier LIMIT 200"));
    }

    /** Сохранить сопоставление и настройки задания */
    public static function saveSettings(int $jobId, array $map, array $opt): void
    {
        App::db()->update('import_jobs', ['mapping' => self::json($map), 'options' => self::json($opt), 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$jobId]);
    }

    /**
     * Проверка перед запуском: сопоставлено поле-ключ, для новых товаров — название и цена.
     * @return string[] ошибки
     */
    public static function validateStart(array $job): array
    {
        $f = array_flip(array_filter($job['mapping'], static fn($v) => $v !== ''));
        $opt = $job['options'];
        $err = [];
        if (!$f) return ['Не сопоставлено ни одной колонки — выберите, какие данные в каких колонках файла.'];
        $key = (string) $opt['key'];
        if (!isset($f[$key])) $err[] = 'Колонка для ключа поиска «' . (self::KEYS[$key] ?? $key) . '» не выбрана — товары не найти. Сопоставьте её или выберите другой ключ.';
        if ($opt['mode'] !== 'update') {
            if (!isset($f['name'])) $err[] = 'Для создания новых товаров нужна колонка «Название».';
            if (!isset($f['price']) && !isset($f['price_box'])) $err[] = 'Для создания новых товаров нужна колонка «Цена за пару» или «Цена за ящик».';
            if (!isset($f['category']) && !(int) $opt['default_category']) $err[] = 'Для новых товаров выберите колонку «Категория» или «Категорию по умолчанию».';
        }
        if (!empty($opt['hide_missing']) && trim((string) $opt['supplier']) === '') $err[] = 'Чтобы скрывать отсутствующие в файле товары, укажите поставщика.';
        return $err;
    }

    /** Лимит размера файла: настройка import.max_size (по умолчанию 100 МБ) */
    public static function maxFileSize(): int
    {
        return (int) App::config('import.max_size', 100 * 1048576);
    }

    /** Лимит загрузки через форму с учётом php.ini */
    public static function maxUploadSize(): int
    {
        $ini = static function (string $k): int {
            $v = trim((string) ini_get($k));
            if ($v === '' || $v === '0' || $v === '-1') return PHP_INT_MAX;
            $n = (int) $v;
            return match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
        };
        return min(self::maxFileSize(), $ini('upload_max_filesize'), $ini('post_max_size'));
    }

    public static function job(int $id): ?array
    {
        $j = App::db()->row('SELECT * FROM import_jobs WHERE id = ?', [$id]);
        if (!$j) return null;
        foreach (['options', 'mapping', 'columns', 'state'] as $k) {
            $v = json_decode((string) ($j[$k] ?? ''), true);
            $j[$k] = is_array($v) ? $v : [];
        }
        $j['options'] += self::defaults();
        foreach (['id', 'total', 'processed', 'last_n', 'created', 'updated', 'unchanged', 'skipped', 'error_count'] as $k) $j[$k] = (int) ($j[$k] ?? 0);
        return $j;
    }

    private static function json($v): string
    {
        return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function saveState(int $jobId, array $state): void
    {
        App::db()->update('import_jobs', ['state' => self::json($state), 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$jobId]);
    }

    /** Ключ сравнения: без регистра, лишних пробелов и диакритики (как сравнивает MySQL utf8mb4_unicode_ci) */
    public static function nk(string $s): string
    {
        $s = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
        if (class_exists(\Normalizer::class) && preg_match('/[^\x00-\x7F]/', $s)) {
            $d = \Normalizer::normalize($s, \Normalizer::FORM_D);
            if (is_string($d)) $s = (string) preg_replace('/\p{Mn}+/u', '', $d);
        } else {
            $s = strtr($s, ['ё' => 'е', 'й' => 'и', 'ї' => 'і']);
        }
        return $s;
    }

    private static function cut(string $s, int $len = 60): string
    {
        return mb_strlen($s) > $len ? mb_substr($s, 0, $len - 1) . '…' : $s;
    }

    // ============================================================ задания

    /**
     * Новое задание по сохранённому файлу. $meta — то, что нашёл Reader::prepare (разделитель, путь к товару).
     * Настройки профиля (если выбран) переносятся в задание; сопоставление дополняется автоматически после разбора.
     */
    public static function createJob(string $file, string $name, string $format, array $meta, ?array $profile, int $userId = 0): int
    {
        self::ensureSchema();
        $opt = self::defaults();
        $map = [];
        if ($profile) {
            $po = json_decode((string) $profile['options'], true);
            $pm = json_decode((string) $profile['mapping'], true);
            if (is_array($po)) $opt = array_merge($opt, array_intersect_key($po, $opt));
            if (is_array($pm)) $map = $pm;
        } else {
            $opt['_auto_key'] = 1;
        }
        foreach ($meta as $k => $v) {
            if ($k === 'item_path' && !empty($opt['item_path'])) continue;   // путь из профиля важнее
            $opt[$k] = $v;
        }
        if ($format === 'xml' && !empty($opt['yml'])) $format = 'yml';
        return App::db()->insert('import_jobs', [
            'profile_id' => $profile ? (int) $profile['id'] : null, 'user_id' => $userId ?: null,
            'file' => basename($file), 'name' => mb_substr($name, 0, 255), 'format' => $format, 'status' => 'parsing',
            'options' => self::json($opt), 'mapping' => self::json($map), 'columns' => '[]', 'state' => '{}',
            'errors' => null, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Разобрать файл заново (после смены разделителя / пути к товару) или повторить импорт с начала */
    public static function restart(int $jobId): void
    {
        self::withJobLock($jobId, static function () use ($jobId): void {
            $db = App::db();
            foreach (['import_rows', 'import_seen', 'import_images', 'import_errors'] as $t) $db->query("DELETE FROM `$t` WHERE job_id = ?", [$jobId]);
            $db->update('import_jobs', ['status' => 'parsing', 'total' => 0, 'processed' => 0, 'last_n' => 0, 'created' => 0, 'updated' => 0,
                'unchanged' => 0, 'skipped' => 0, 'error_count' => 0, 'errors' => null, 'columns' => '[]', 'state' => '{}',
                'started_at' => null, 'finished_at' => null, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$jobId]);
        });
    }

    public static function deleteJob(int $jobId): void
    {
        self::withJobLock($jobId, static function () use ($jobId): void {
            $db = App::db();
            $file = (string) $db->value('SELECT file FROM import_jobs WHERE id = ?', [$jobId]);
            foreach (['import_rows', 'import_seen', 'import_images', 'import_errors'] as $t) $db->query("DELETE FROM `$t` WHERE job_id = ?", [$jobId]);
            $db->delete('import_jobs', 'id = ?', [$jobId]);
            if ($file !== '' && !(int) $db->value('SELECT COUNT(*) FROM import_jobs WHERE file = ?', [$file])) {
                $p = self::dir() . '/' . basename($file);
                if (is_file($p)) @unlink($p);
            }
        });
    }

    /**
     * Та же блокировка, что у шага (step): удаление или перезапуск ждёт окончания шага, который идёт
     * в другой вкладке или в cron (до 20 с), иначе шаг дописал бы строки уже удалённому заданию.
     */
    private static function withJobLock(int $jobId, callable $fn): void
    {
        $db = App::db();
        $lock = 'tomobuv_import_' . $jobId;
        $got = (int) $db->value('SELECT GET_LOCK(?, 20)', [$lock]);
        try {
            $fn();
        } finally {
            if ($got) $db->value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /** Задание в работе: не завершено и менялось за последние 7 дней (шаг, разбор, настройки) — уборка его не трогает */
    private const ACTIVE_SQL = "status IN ('parsing', 'new', 'running', 'finishing', 'images') AND COALESCE(updated_at, created_at) >= DATE_SUB(NOW(), INTERVAL 7 DAY)";

    /**
     * Уборка (bin/cron.php, bin/import.php, изредка — страница импорта). Задания в работе не трогаются,
     * даже если начаты давно (импорт на 100 тыс. строк с паузами, задание ждёт запуска):
     *   - разобранные строки (import_rows) — через 7 дней, встреченные товары и очередь фото — через 30 дней,
     *     сами задания с журналом — через 180 дней;
     *   - файлы заданий в storage/import — через 7 дней, кроме файлов заданий в работе; занятый файл
     *     блокировки bin/import.php (cli-*.lock) не удаляется;
     *   - import/tmp (загрузка по частям, скачивание по ссылке) — через час без изменений: пока загрузка
     *     идёт, файл дописывается и его время обновляется.
     */
    public static function cleanup(): void
    {
        $db = App::db();
        $idle = static fn(int $days): array => array_map('intval', $db->col('SELECT id FROM import_jobs
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY) AND NOT (' . self::ACTIVE_SQL . ')', [$days]));
        foreach ([7 => ['import_rows'], 30 => ['import_seen', 'import_images']] as $days => $tables) {
            foreach (array_chunk($idle($days), 500) as $part) {
                [$ph, $vals] = $db->in($part);
                foreach ($tables as $t) $db->query("DELETE FROM `$t` WHERE job_id IN ($ph)", $vals);
            }
        }
        foreach ($idle(180) as $id) self::deleteJob($id);

        $dir = self::dir();
        $keep = array_flip(array_map('basename', $db->col('SELECT file FROM import_jobs WHERE ' . self::ACTIVE_SQL)));
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (!is_file($f) || filemtime($f) >= time() - 86400 * 7 || isset($keep[basename($f)])) continue;
            if (str_ends_with($f, '.lock')) {                                 // идёт импорт из cron — файл заблокирован
                $h = @fopen($f, 'r');
                $free = $h && flock($h, LOCK_EX | LOCK_NB);
                if ($h) { if ($free) flock($h, LOCK_UN); fclose($h); }
                if (!$free) continue;
            }
            @unlink($f);
        }
        foreach (glob($dir . '/tmp/*') ?: [] as $f) if (is_file($f) && filemtime($f) < time() - 3600) @unlink($f);
    }

    private static function fail(int $jobId, string $msg): void
    {
        App::db()->update('import_jobs', ['status' => 'error', 'errors' => mb_substr($msg, 0, 2000), 'finished_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$jobId]);
    }

    // ============================================================ разбор файла

    /** Шаг разбора файла в import_rows (не дольше $budget секунд) */
    public static function parse(int $jobId, float $budget = 12.0): array
    {
        $t0 = microtime(true);
        $db = App::db();
        $job = self::job($jobId);
        if (!$job || $job['status'] !== 'parsing') return self::progress($job);
        if (function_exists('set_time_limit')) @set_time_limit((int) $budget + 60);
        @ini_set('memory_limit', '512M');
        $path = self::dir() . '/' . basename((string) $job['file']);
        if (!is_file($path)) {
            self::fail($jobId, 'Файл задания не найден (файлы хранятся 7 дней) — загрузите его заново.');
            return self::progress(self::job($jobId));
        }
        $state = $job['state'];
        $cols = array_flip($job['columns']);
        $total = (int) $job['total'];
        $finished = true; $truncated = false;
        $buf = []; $bytes = 0;
        $flush = static function (array $readerState) use (&$buf, &$bytes, &$cols, &$total, &$state, $jobId, $db): void {
            $db->transaction(static function () use ($buf, $readerState, $cols, $total, &$state, $jobId, $db) {
                if ($buf) $db->insertMany('import_rows', $buf, true, 500);
                $state['parse'] = $readerState;
                $db->update('import_jobs', ['total' => $total, 'columns' => self::json(array_keys($cols)), 'state' => self::json($state),
                    'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$jobId]);
            });
            $buf = []; $bytes = 0;
        };
        try {
            $reader = Reader::make((string) $job['format'], $path, $job['options']);
            foreach ($reader->rows($state['parse'] ?? []) as $n => $row) {
                foreach ($row as $k => $_) {
                    if (!isset($cols[$k]) && count($cols) < 500) $cols[$k] = count($cols);
                }
                $json = self::json($row);
                $buf[] = ['job_id' => $jobId, 'n' => (int) $n, 'data' => $json];
                $bytes += strlen($json);
                $total++;
                if ($total >= self::MAX_ROWS) { $truncated = true; break; }
                if (count($buf) >= 500 || $bytes > 2097152) {
                    $flush($reader->state());
                    if (microtime(true) - $t0 > $budget) { $finished = false; break; }
                }
            }
            if ($buf || $finished) $flush($reader->state());
        } catch (\Throwable $e) {
            Log::error('import parse #' . $jobId . ': ' . $e->getMessage());
            self::fail($jobId, 'Не удалось прочитать файл: ' . $e->getMessage());
            return self::progress(self::job($jobId));
        }
        if ($finished) self::parsed($jobId, $truncated);
        return self::progress(self::job($jobId));
    }

    /** Файл разобран: автосопоставление колонок, ключ поиска, статус «ждёт запуска» */
    private static function parsed(int $jobId, bool $truncated): void
    {
        $job = self::job($jobId);
        if (!$job) return;
        if ($job['total'] === 0) {
            self::fail($jobId, 'В файле не найдено строк с товарами. Проверьте разделитель колонок'
                . ($job['format'] === 'xml' ? ' или путь к элементу товара.' : ' и строку заголовков.'));
            return;
        }
        $opt = $job['options'];
        $map = self::autoMap($job['columns'], (string) $job['format'], $job['mapping']);
        if (!empty($opt['_auto_key'])) $opt['key'] = self::guessKey($map, (string) $job['format']);
        $state = $job['state'];
        $state['parsed_at'] = date('Y-m-d H:i:s');
        if ($truncated) $state['truncated'] = self::MAX_ROWS;
        App::db()->update('import_jobs', ['status' => 'new', 'mapping' => self::json($map), 'options' => self::json($opt),
            'state' => self::json($state), 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$jobId]);
    }

    /** Первые строки файла (как есть) для предпросмотра */
    public static function sample(int $jobId, int $limit = 20): array
    {
        $out = [];
        foreach (App::db()->pairs('SELECT n, data FROM import_rows WHERE job_id = ? ORDER BY n LIMIT ' . max(1, $limit), [$jobId]) as $n => $json) {
            $out[(int) $n] = json_decode((string) $json, true) ?: [];
        }
        return $out;
    }

    // ============================================================ сопоставление колонок

    private static function hk(string $s): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}@]+/u', '', mb_strtolower($s));
    }

    /** Варианты для выбора поля: [значение => подпись], характеристики — «feature:код» */
    public static function fieldChoices(): array
    {
        $out = ['' => '— не загружать —'];
        foreach (self::FIELDS as $k => $v) $out[$k] = $v;
        foreach (App::db()->all('SELECT code, name FROM features ORDER BY sort, id') as $f) {
            if (in_array($f['code'], self::SYSTEM_FEATURES, true)) continue;
            $out['feature:' . $f['code']] = 'Характеристика: ' . $f['name'];
        }
        return $out;
    }

    /** Сопоставление по заголовкам: сначала сохранённое (профиль/задание), затем синонимы и названия характеристик */
    public static function autoMap(array $columns, string $format, array $known = []): array
    {
        $choices = self::fieldChoices();
        $syn = [];
        foreach (self::SYNONYMS as $field => $list) foreach ($list as $w) $syn[$w] ??= $field;
        foreach (App::db()->all('SELECT code, name FROM features') as $f) {
            if (in_array($f['code'], self::SYSTEM_FEATURES, true)) continue;
            foreach ([self::hk($f['name']), 'param' . self::hk($f['name']), self::hk($f['code'])] as $w) $syn[$w] ??= 'feature:' . $f['code'];
        }
        $map = []; $used = [];
        foreach ($columns as $col) {
            if (array_key_exists($col, $known) && isset($choices[(string) $known[$col]])) {
                $map[$col] = (string) $known[$col];
                if ($map[$col] !== '') $used[$map[$col]] = true;
            }
        }
        foreach ($columns as $col) {
            if (array_key_exists($col, $map)) continue;
            $h = self::hk((string) $col);
            $field = $syn[$h] ?? '';
            if ($field === 'url' && $format !== 'csv' && $format !== 'xlsx') $field = '';   // в YML url — страница у поставщика
            if ($field !== '' && isset($used[$field]) && $field !== 'images') $field = '';
            $map[$col] = $field;
            if ($field !== '') $used[$field] = true;
        }
        return $map;
    }

    /** Ключ поиска по сопоставленным полям */
    public static function guessKey(array $map, string $format): string
    {
        $f = array_flip(array_filter($map));
        if (isset($f['id'])) return 'id';
        // код поставщика надёжнее артикула: в каталоге «Артикул» — обычно размерный ряд (36-41), он не уникален
        if (isset($f['supplier_code'])) return 'supplier_code';
        if (isset($f['sku'])) return 'sku';
        return 'name';
    }

    /**
     * Настройки из формы страницы задания (всё по белым спискам).
     * @return array{0: array, 1: array, 2: bool} [сопоставление, настройки, нужен повторный разбор файла]
     */
    public static function settingsFromPost(array $post, array $job): array
    {
        $cols = $job['columns'];
        $choices = self::fieldChoices();
        $map = [];
        foreach ((array) ($post['map'] ?? []) as $i => $field) {
            if (!is_numeric($i) || !isset($cols[(int) $i]) || !is_string($field)) continue;
            $field = trim($field);
            $map[$cols[(int) $i]] = isset($choices[$field]) ? $field : '';
        }
        $o = is_array($post['opt'] ?? null) ? $post['opt'] : [];
        $s = static fn(string $k): string => isset($o[$k]) && is_scalar($o[$k]) ? trim((string) $o[$k]) : '';
        $opt = $job['options'];
        $old = $opt;
        $opt['key'] = isset(self::KEYS[$s('key')]) ? $s('key') : 'sku';
        $opt['mode'] = isset(self::MODES[$s('mode')]) ? $s('mode') : 'both';
        $opt['supplier'] = self::cleanSupplier($s('supplier'));
        $opt['markup'] = max(-90.0, min(1000.0, round((float) str_replace(',', '.', $s('markup')), 2)));
        $opt['round'] = isset(self::ROUNDS[(int) $s('round')]) ? (int) $s('round') : 0;
        foreach (['price_only', 'hide_missing', 'unhide', 'purchase_from_price', 'images', 'overwrite'] as $b) $opt[$b] = !empty($o[$b]) ? 1 : 0;
        if ($opt['supplier'] === '') $opt['hide_missing'] = 0;
        $cat = (int) $s('default_category');
        $opt['default_category'] = $cat && App::db()->value('SELECT id FROM categories WHERE id = ? AND type = 0', [$cat]) ? $cat : 0;
        $opt['default_box_qty'] = max(0, min(1000, (int) $s('default_box_qty')));
        $opt['new_status'] = $s('new_status') === '0' ? 0 : 1;
        $opt['batch'] = max(50, min(1000, (int) $s('batch') ?: 400));
        $opt['category_map'] = mb_substr(str_replace("\r", '', isset($o['category_map']) && is_string($o['category_map']) ? $o['category_map'] : ''), 0, 20000);
        $opt['source_url'] = self::cleanSourceUrl($s('source_url'));
        // параметры чтения файла
        if (in_array($job['format'], ['csv', 'xlsx'], true)) {
            $opt['header'] = !empty($o['header']) ? 1 : 0;
            if ($job['format'] === 'csv' && array_key_exists($s('delimiter'), CsvReader::DELIMITERS) && $s('delimiter') !== '') $opt['delimiter'] = $s('delimiter');
        } else {
            $ip = $s('item_path');
            if ($ip !== '' && preg_match('#^[\w\-.:/]{1,200}$#u', $ip)) $opt['item_path'] = $ip;
        }
        unset($opt['_auto_key']);
        $reparse = ($old['header'] ?? 1) != $opt['header'] || ($old['delimiter'] ?? '') !== $opt['delimiter'] || ($old['item_path'] ?? '') !== $opt['item_path'];
        return [$map, $opt, $reparse];
    }

    // ============================================================ категории, бренды, характеристики

    /** Статические категории для выбора: [id => «Родитель > Дочерняя»] */
    public static function categoryChoices(): array
    {
        $ctx = self::categoryIndex();
        $out = [];
        foreach ($ctx['cats'] as $id => $c) {
            if ((int) $c['type'] !== 0) continue;
            $out[$id] = $ctx['path'][$id] . ((int) $c['status'] ? '' : ' (скрыта)');
        }
        return $out;
    }

    /** Категории с путями и указателями для поиска по id / url / пути / названию */
    public static function categoryIndex(): array
    {
        $cats = App::db()->keyed('SELECT id, parent_id, name, url, type, status FROM categories ORDER BY lft, sort, id');
        $path = [];
        foreach ($cats as $id => $c) {
            $p = []; $x = $c; $guard = 0;
            while ($x && $guard++ < 10) { array_unshift($p, trim((string) $x['name'])); $x = $cats[(int) $x['parent_id']] ?? null; }
            $path[$id] = implode(' > ', $p);
        }
        $byUrl = []; $byPath = []; $byName = [];
        foreach ($cats as $id => $c) {
            $byUrl[mb_strtolower((string) $c['url'])] = $id;
            $byPath[self::nk($path[$id])] = $id;
            $byName[self::nk((string) $c['name'])][] = $id;
        }
        return ['cats' => $cats, 'path' => $path, 'byUrl' => $byUrl, 'byPath' => $byPath, 'byName' => $byName];
    }

    /** Строки «категория в файле = наша категория» → [nk(в файле) => наша] */
    private static function categoryMap(string $text): array
    {
        $out = [];
        foreach (preg_split('/\n/', $text) ?: [] as $line) {
            $parts = preg_split('/\s*(?:=>|=|\t)\s*/u', trim($line), 2);
            if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') $out[self::nk($parts[0])] = trim($parts[1]);
        }
        return $out;
    }

    /**
     * Категория по значению из файла: соответствие из настроек → id → url → полный путь «A > B» →
     * окончание пути → название (если однозначно). @return array{0:?int, 1:?string} [id, ошибка]
     */
    private static function resolveCategory(string $ref, array &$ctx): array
    {
        $k = self::nk($ref);
        if (isset($ctx['memo'][$k])) return $ctx['memo'][$k];
        $ci = $ctx['ci'];
        $target = $ctx['cmap'][$k] ?? $ref;
        $r = [null, 'категория «' . self::cut($ref) . '» не найдена'];
        $t = trim($target);
        $id = null;
        if (ctype_digit($t) && isset($ci['cats'][(int) $t])) $id = (int) $t;
        elseif (isset($ci['byUrl'][mb_strtolower($t)])) $id = $ci['byUrl'][mb_strtolower($t)];
        else {
            $parts = array_values(array_filter(array_map('trim', preg_split('/\s*(?:>|»|\/|\\\\)\s*/u', $t) ?: [])));
            $joined = self::nk(implode(' > ', $parts));
            if (isset($ci['byPath'][$joined])) $id = $ci['byPath'][$joined];
            elseif (count($parts) > 1) {
                $hits = [];
                foreach ($ci['path'] as $cid => $p) if (str_ends_with(self::nk($p), ' > ' . $joined)) $hits[] = $cid;
                if (count($hits) === 1) $id = $hits[0];
                elseif ($hits) $r = [null, 'категория «' . self::cut($ref) . '» неоднозначна — укажите полный путь'];
            } else {
                $hits = $ci['byName'][$joined] ?? [];
                if (count($hits) === 1) $id = $hits[0];
                elseif ($hits) $r = [null, 'категорий «' . self::cut($ref) . '» несколько — укажите путь «Родитель > ' . self::cut($ref, 30) . '» или соответствие'];
            }
        }
        if ($id !== null) {
            $r = (int) $ci['cats'][$id]['type'] === 1
                ? [null, 'категория «' . self::cut($ci['path'][$id]) . '» динамическая — в неё нельзя добавить товар']
                : [$id, null];
        }
        return $ctx['memo'][$k] = $r;
    }

    private static function context(array $opt): array
    {
        $db = App::db();
        $brandByNk = [];
        foreach ($db->all('SELECT id, name FROM brands ORDER BY id') as $b) $brandByNk[self::nk((string) $b['name'])] ??= (int) $b['id'];
        $features = $db->keyed('SELECT id, code, name, multiple FROM features');
        $fByCode = [];
        foreach ($features as $f) $fByCode[(string) $f['code']] = $f;
        return [
            'ci' => self::categoryIndex(), 'memo' => [], 'cmap' => self::categoryMap((string) $opt['category_map']),
            'brandByNk' => $brandByNk, 'fByCode' => $fByCode,
            'sysF' => ['brand' => (int) ($fByCode['brand']['id'] ?? 0), 'size' => (int) ($fByCode['size']['id'] ?? 0), 'box' => (int) ($fByCode['kol_vo_par']['id'] ?? 0)],
        ];
    }

    // ============================================================ разбор значений

    /** «1 020,50 грн.» → 1020.5; null — не число */
    public static function num(string $v): ?float
    {
        $v = str_replace(["\xC2\xA0", ' ', "'", '’'], '', $v);
        if (!preg_match('/-?\d+(?:[.,]\d+)*/', $v, $m)) return null;
        $s = $m[0];
        $dots = substr_count($s, '.'); $commas = substr_count($s, ',');
        if ($dots && $commas) {                                        // 1.020,50 или 1,020.50: последний знак — десятичный
            $dec = strrpos($s, '.') > strrpos($s, ',') ? '.' : ',';
            $s = str_replace($dec === '.' ? ',' : '.', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif ($commas > 1 || $dots > 1 || preg_match('/^-?[1-9]\d{0,2},\d{3}$/', $s)) {   // 1.020.500 и 1,020 — разделители тысяч
            $s = str_replace([',', '.'], '', $s);
        } else {
            $s = str_replace(',', '.', $s);
        }
        return is_numeric($s) ? (float) $s : null;
    }

    /** да/нет, 1/0, +/−, true/false, «в наличии»… → 1/0; null — непонятно */
    public static function bool(string $v): ?int
    {
        $s = mb_strtolower(trim($v));
        static $yes = ['1', '+', 'да', 'yes', 'y', 'true', 'есть', 'в наличии', 'наличие', 'available', 'instock', 'in stock', 'on',
            'опубликован', 'показывать', 'показан', 'активен', 'active', 'так', 'є', 'в наявності', 'published', 'visible'];
        static $no = ['0', '-', '−', '–', '—', 'нет', 'no', 'n', 'false', 'нет в наличии', 'отсутствует', 'outofstock', 'out of stock', 'off',
            'скрыт', 'скрыть', 'скрытый', 'hidden', 'ні', 'немає', 'немає в наявності', 'не показывать', 'inactive'];
        if (in_array($s, $yes, true)) return 1;
        if (in_array($s, $no, true)) return 0;
        $n = self::num($s);
        return $n === null ? null : ($n > 0 ? 1 : 0);
    }

    /**
     * Время изменения товара из нашей выгрузки (колонка updated_at): «2026-09-28 10:15:42»; после сохранения
     * в Excel — «28.09.2026 10:15» или «9/28/2026 10:15 AM» (секунды теряются), в XLSX — число (дни с 30.12.1899).
     * @return ?array{0:int, 1:int} [unix-время, точность в секундах: 1 | 60 — без секунд | 86400 — только дата]
     */
    public static function stamp(string $v): ?array
    {
        $v = trim($v);
        if (preg_match('/^\d{5}(?:[.,]\d+)?$/', $v)) {                  // дата Excel
            $x = (float) str_replace(',', '.', $v);
            if ($x < 20000 || $x > 80000) return null;
            $ts = strtotime(gmdate('Y-m-d H:i:s', (int) round(($x - 25569) * 86400)));
            return $ts === false ? null : [$ts, 1];
        }
        if (!preg_match('~^(\d{1,4})([./-])(\d{1,2})\2(\d{2,4})(?:[ T]+(\d{1,2}):(\d{2})(?::(\d{2}))?(?:\.\d+)?\s*([AaPp]\.?[Mm]\.?)?)?$~', $v, $m)) return null;
        if (strlen($m[1]) === 4) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[3], (int) $m[4]];        // 2026-09-28
        } elseif ($m[2] === '/' && (int) $m[1] <= 12) {
            [$mo, $d, $y] = [(int) $m[1], (int) $m[3], (int) $m[4]];        // 9/28/2026 (Excel с английскими настройками)
        } else {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[3], (int) $m[4]];        // 28.09.2026, 28/09/2026
        }
        if ($y < 100) $y += 2000;
        $h = isset($m[5]) && $m[5] !== '' ? (int) $m[5] : 0;
        $i = isset($m[6]) && $m[6] !== '' ? (int) $m[6] : 0;
        $sec = isset($m[7]) && $m[7] !== '' ? (int) $m[7] : 0;
        if (!empty($m[8])) {
            if ($h < 1 || $h > 12) return null;
            $h = strtolower($m[8][0]) === 'p' ? ($h % 12) + 12 : $h % 12;
        }
        if (!checkdate($mo, $d, $y) || $h > 23 || $i > 59 || $sec > 59) return null;
        $prec = isset($m[7]) && $m[7] !== '' ? 1 : (isset($m[5]) && $m[5] !== '' ? 60 : 86400);
        return [(int) mktime($h, $i, $sec, $mo, $d, $y), $prec];
    }

    /**
     * Ссылки на фото из ячейки: через запятую, «|», «;» или перевод строки.
     * @return array{0: string[], 1: int} [ссылки, сколько частей отброшено]
     */
    private static function splitUrls(string $v): array
    {
        $out = []; $bad = 0;
        foreach (preg_split('/\s*[|;,\n]\s*|\s+(?=https?:)/u', $v) ?: [] as $u) {
            $u = trim($u);
            if ($u === '') continue;
            if (preg_match('#^https?://[^\s]+$#i', $u) && strlen($u) <= 1000) $out[] = $u; else $bad++;
        }
        return [$out, $bad];
    }

    /** Адрес страницы товара: из «https://site/product/abc/» берётся «abc»; недопустимые символы → слаг */
    private static function cleanUrl(string $v): string
    {
        $v = trim($v);
        if (str_contains($v, '/')) {
            $parts = array_values(array_filter(explode('/', (string) (parse_url($v, PHP_URL_PATH) ?? $v))));
            $v = (string) end($parts);
        }
        $v = mb_strtolower($v);
        if (preg_match('/^[a-z0-9][a-z0-9_.\-]{0,190}$/', $v)) return $v;
        return $v === '' ? '' : Str::slug($v);
    }

    /**
     * Описание из файла. Прайс поставщика — только безопасные теги без атрибутов (защита от XSS на витрине,
     * HtmlSanitizer::supplier). Своя выгрузка сайта ($own, Importer::ownExport) — белый список, как HTML менеджера
     * (HtmlSanitizer::clean): ссылки, картинки, таблицы описаний, написанных на сайте, остаются после правки в Excel.
     */
    private static function cleanHtml(string $html, bool $own = false): string
    {
        return $own ? HtmlSanitizer::clean($html) : HtmlSanitizer::supplier($html);
    }

    /**
     * Файл — выгрузка самого сайта (/admin/export/): сопоставлены обе служебные колонки выгрузки — «ID товара на сайте»
     * и «Изменён на сайте (из выгрузки)». Заголовок второй узнаётся только наш (updated_at, см. SYNONYMS), в прайсах
     * поставщиков и в шаблоне для поставщика её нет.
     */
    public static function ownExport(array $map): bool
    {
        $f = array_flip(array_filter($map, 'is_string'));
        return isset($f['id'], $f['updated_at']);
    }

    // ============================================================ строка файла → запись

    /** Значения строки по сопоставлению, приведённые к типам. Ошибка — в 'err', мелкие замечания — в 'warn'. */
    private static function record(int $n, array $row, array $map, array $ctx): array
    {
        $raw = []; $feat = []; $img = []; $badImg = 0;
        foreach ($map as $col => $field) {
            if ($field === '' || !isset($row[$col])) continue;
            $v = trim((string) $row[$col]);
            if ($v === '') continue;
            if ($v[0] === "'" && isset($v[1]) && str_contains('=+-@', $v[1])) $v = substr($v, 1);   // апостроф из нашего экспорта (защита от формул)
            if (str_starts_with($field, 'feature:')) { $feat[substr($field, 8)][] = $v; continue; }
            if ($field === 'images') {
                [$urls, $bad] = self::splitUrls($v);
                foreach ($urls as $u) $img[$u] = $u;
                $badImg += $bad;
                continue;
            }
            $raw[$field] ??= $v;
        }
        $f = []; $warn = []; $err = null;
        if ($badImg) $warn[] = 'Фото: пропущено ссылок — ' . $badImg . ' (нужен адрес http:// или https://)';
        foreach ($raw as $k => $v) {
            switch ($k) {
                case 'id':
                    if (ctype_digit($v) && (int) $v > 0) $f['id'] = (int) $v; else $err = 'неверный ID «' . self::cut($v, 20) . '»';
                    break;
                case 'price': case 'price_box': case 'compare_price': case 'purchase_price':
                    $x = self::num($v);
                    if ($x === null || $x < 0 || $x > 9999999999) $warn[] = self::FIELDS[$k] . ': не число «' . self::cut($v, 20) . '»';
                    else $f[$k] = $x;
                    break;
                case 'box_qty': case 'min_qty':
                    $x = self::num($v);
                    if ($x === null || $x < 1) $warn[] = self::FIELDS[$k] . ': неверное значение «' . self::cut($v, 20) . '»';
                    else $f[$k] = (int) min(65535, round($x));
                    break;
                case 'stock':
                    $x = self::num($v);
                    if ($x === null) $warn[] = 'Остаток: не число «' . self::cut($v, 20) . '»'; else $f['stock'] = (int) max(0, min(2000000000, round($x)));
                    break;
                case 'in_stock': case 'status':
                    $x = self::bool($v);
                    if ($x === null) $warn[] = self::FIELDS[$k] . ': непонятное значение «' . self::cut($v, 20) . '»'; else $f[$k] = $x;
                    break;
                case 'name':
                    $f['name'] = mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8')))), 0, 255);
                    break;
                case 'url':
                    $u = self::cleanUrl($v);
                    if ($u !== '') $f['url'] = $u;
                    break;
                case 'description': case 'description_uk':
                    $f[$k] = self::cleanHtml($v, $ctx['own']);
                    break;
                case 'summary': case 'summary_uk':
                    $f[$k] = mb_substr(trim(strip_tags($v)), 0, 2000);
                    break;
                case 'name_uk':
                    $f[$k] = mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8')))), 0, 255);
                    break;
                case 'meta_title': case 'meta_description': case 'meta_keywords':
                case 'meta_title_uk': case 'meta_description_uk': case 'meta_keywords_uk':
                    $f[$k] = mb_substr(trim(strip_tags($v)), 0, self::TEXT_COLS[$k]);
                    break;
                case 'sku': case 'brand': case 'category':
                    $f[$k] = mb_substr($v, 0, 255);
                    break;
                case 'supplier_code':
                    $f[$k] = mb_substr($v, 0, 100);
                    break;
                case 'supplier':
                    $f[$k] = mb_substr($v, 0, 64);
                    break;
                case 'size':
                    $f[$k] = mb_substr($v, 0, 64);
                    break;
                case 'updated_at':
                    $st = self::stamp($v);
                    if ($st === null) $warn[] = self::FIELDS[$k] . ': непонятная дата «' . self::cut($v, 25) . '» — проверка изменений на сайте не выполнена';
                    else $f['updated_at'] = $st;
                    break;
            }
        }
        $fv = [];
        foreach ($feat as $code => $vals) {
            $fe = $ctx['fByCode'][$code] ?? null;
            if (!$fe) continue;
            $list = [];
            foreach ($vals as $v) {
                foreach ((int) $fe['multiple'] ? (preg_split('/\s*[,;|]\s*/u', $v) ?: []) : [$v] as $p) {
                    $p = mb_substr(trim($p), 0, 255);
                    if ($p !== '') $list[self::nk($p)] = $p;
                }
            }
            if ($list) $fv[(int) $fe['id']] = $list;
        }
        $label = $f['name'] ?? ($f['sku'] ?? ($f['supplier_code'] ?? ''));
        return ['n' => $n, 'f' => $f, 'feat' => $fv, 'img' => array_slice(array_values($img), 0, self::IMG_PER_PRODUCT),
            'warn' => $warn, 'err' => $err, 'label' => self::cut((string) $label, 50)];
    }

    private static function keyValue(array $rec, string $key): string
    {
        return isset($rec['f'][$key]) ? trim((string) $rec['f'][$key]) : '';
    }

    /**
     * Существующие товары по ключу: [nk(значение) => [строки]]. Значение, которое есть у многих товаров
     * (больше KEY_MAX_MATCHES), — [nk => ['many' => сколько, 'ids' => первые id]] без загрузки строк.
     */
    private static function findExisting(string $key, array $values, string $supplier): array
    {
        if (!$values) return [];
        $db = App::db();
        $col = ['id' => 'id', 'sku' => 'sku', 'supplier_code' => 'supplier_code', 'url' => 'url', 'name' => 'name'][$key] ?? 'sku';
        $vals = array_values(array_unique($values));
        if ($key === 'id') $vals = array_map('intval', $vals);
        $bySupplier = $supplier !== '' && in_array($key, ['sku', 'supplier_code', 'name'], true);
        $out = [];
        foreach (array_chunk($vals, 1000) as $part) {
            if ($key !== 'id' && $key !== 'url') {                        // id и url уникальны — считать не нужно
                [$ph, $p] = $db->in($part);
                if ($bySupplier) $p[] = $supplier;
                $many = $db->all("SELECT `$col` k, COUNT(*) c, SUBSTRING_INDEX(GROUP_CONCAT(id ORDER BY id), ',', 5) ids FROM products
                    WHERE `$col` IN ($ph)" . ($bySupplier ? ' AND supplier = ?' : '') . " GROUP BY `$col` HAVING COUNT(*) > " . self::KEY_MAX_MATCHES, $p);
                foreach ($many as $r) {
                    $nk = self::nk((string) $r['k']);
                    $out[$nk] = ['many' => (int) ($out[$nk]['many'] ?? 0) + (int) $r['c'], 'ids' => $out[$nk]['ids'] ?? array_map('intval', explode(',', (string) $r['ids']))];
                }
                if ($many) $part = array_values(array_filter($part, static fn($v) => !isset($out[self::nk((string) $v)]['many'])));
                if (!$part) continue;
            }
            [$ph, $p] = $db->in($part);
            $sql = 'SELECT ' . self::EX_COLS . " FROM products WHERE `$col` IN ($ph)";
            if ($bySupplier) { $sql .= ' AND supplier = ?'; $p[] = $supplier; }
            foreach ($db->all($sql, $p) as $r) {
                $nk = self::nk((string) $r[$col]);
                if (!isset($out[$nk]['many'])) $out[$nk][] = $r;
            }
        }
        // ключ «Название», в файле код («60189A»), а товар уже назван правилом ProductName — «Зимняя обувь Tom.m 60189A»
        // с артикулом 60189A: ищем по артикулу и окончанию названия, иначе каждый такой прайс создавал бы дубль
        if ($key === 'name') {
            $miss = array_values(array_filter($vals, static fn($v) => !isset($out[self::nk((string) $v)]) && ProductName::isBare((string) $v)));
            foreach (array_chunk($miss, 1000) as $part) {
                [$ph, $p] = $db->in($part);
                $sql = 'SELECT ' . self::EX_COLS . " FROM products WHERE sku IN ($ph) AND RIGHT(name, CHAR_LENGTH(sku) + 1) = CONCAT(' ', sku)";
                if ($bySupplier) { $sql .= ' AND supplier = ?'; $p[] = $supplier; }
                foreach ($db->all($sql . ' LIMIT 5000', $p) as $r) $out[self::nk((string) $r['sku'])][] = $r;
            }
        }
        return $out;
    }

    /** Цена за пару (из цены за ящик), наценка, округление; закупочная — из цены поставщика */
    private static function prices(array $f, int $box, array $opt): array
    {
        $price = $f['price'] ?? (isset($f['price_box']) && $box > 0 ? $f['price_box'] / $box : null);
        $supplierPrice = $price;
        $compare = $f['compare_price'] ?? null;
        if ($price !== null) $price = self::markup($price, $opt);
        if ($compare !== null && $compare > 0) $compare = self::markup($compare, $opt);
        $purchase = $f['purchase_price'] ?? (!empty($opt['purchase_from_price']) && $supplierPrice !== null ? round($supplierPrice, 2) : null);
        return [$price, $compare, $purchase];
    }

    private static function markup(float $p, array $opt): float
    {
        $m = (float) $opt['markup'];
        if ($m != 0.0) $p *= 1 + $m / 100;
        $r = (int) $opt['round'];
        if ($r > 0) $p = ceil(round($p / $r, 6)) * $r;
        return round($p, 2);
    }

    // ============================================================ план обработки пачки строк

    /**
     * Что сделать с каждой строкой пачки: создать, обновить (только изменившиеся поля), пропустить.
     * Только чтение из БД — используется и для предпросмотра, и для реальной обработки.
     */
    private static function plan(array $rows, array $map, array $opt): array
    {
        $db = App::db();
        $ctx = self::context($opt);
        $ctx['own'] = self::ownExport($map);                               // своя выгрузка — описания с разметкой
        $key = isset(self::KEYS[$opt['key']]) ? (string) $opt['key'] : 'sku';
        $mode = (string) $opt['mode'];
        $supplierOpt = trim((string) $opt['supplier']);
        $recs = [];
        foreach ($rows as $n => $row) $recs[] = self::record((int) $n, (array) $row, $map, $ctx);

        // 1. существующие товары — одним запросом по всем ключам пачки
        $keys = [];
        foreach ($recs as $r) {
            if ($r['err'] === null && ($kv = self::keyValue($r, $key)) !== '') $keys[] = $kv;
        }
        $found = self::findExisting($key, $keys, $supplierOpt);

        // 2. решение по каждой строке
        $items = []; $seenKeys = []; $foundIds = [];
        foreach ($recs as $r) {
            $it = ['n' => $r['n'], 'rec' => $r, 'action' => '', 'msg' => '', 'warn' => $r['warn'], 'ex' => null, 'id' => null,
                'upd' => [], 'row' => [], 'feat' => [], 'texts' => [], 'img' => [], 'cat' => null, 'oldcat' => null, 'changes' => [], 'show' => []];
            if ($r['err'] !== null) { $it['action'] = 'error'; $it['msg'] = $r['err']; $items[] = $it; continue; }
            $kv = self::keyValue($r, $key);
            $nkv = self::nk($kv);
            if ($kv !== '' && isset($seenKeys[$nkv])) {
                $it['action'] = 'skip'; $it['msg'] = 'повтор ключа «' . self::cut($kv, 40) . '» (уже была строка ' . $seenKeys[$nkv] . ')';
                $items[] = $it; continue;
            }
            if ($kv !== '') $seenKeys[$nkv] = $r['n'];
            $cands = $kv !== '' ? ($found[$nkv] ?? []) : [];
            if (isset($cands['many'])) {
                $it['action'] = 'error';
                $it['msg'] = 'по ключу «' . self::cut($kv, 40) . '» найдено ' . $cands['many'] . ' ' . plural($cands['many'], 'товар', 'товара', 'товаров') . ' (ID ' . implode(', ', $cands['ids']) . '…) — ключ не уникален:'
                    . ' выберите другой ключ поиска (например, «Поставщик + код поставщика» или «ID товара на сайте»)';
                $items[] = $it; continue;
            }
            if (count($cands) > 1 && $supplierOpt === '' && !empty($r['f']['supplier'])) {
                $cands = array_values(array_filter($cands, static fn($c) => self::nk((string) $c['supplier']) === self::nk($r['f']['supplier'])));
            }
            if (count($cands) > 1) {
                $it['action'] = 'error';
                $it['msg'] = 'по ключу «' . self::cut($kv, 40) . '» найдено ' . count($cands) . ' товаров (ID ' . implode(', ', array_slice(array_column($cands, 'id'), 0, 5))
                    . ') — выберите другой ключ поиска или укажите поставщика';
            } elseif ($cands) {
                $it['ex'] = $cands[0];
                $it['id'] = (int) $cands[0]['id'];
                if ($mode === 'create') { $it['action'] = 'skip'; $it['msg'] = 'товар уже есть на сайте (ID ' . $it['id'] . ')'; }
                else { $it['action'] = 'update'; $foundIds[] = $it['id']; }
            } elseif ($key === 'id' && $kv !== '') {
                $it['action'] = 'error'; $it['msg'] = 'товар с ID ' . $kv . ' не найден';
            } elseif ($mode === 'update') {
                $it['action'] = 'skip'; $it['msg'] = 'нет на сайте' . ($kv !== '' ? ' («' . self::cut($kv, 40) . '»)' : '');
            } elseif ($kv === '' && $key !== 'id' && $mode !== 'create') {
                $it['action'] = 'error'; $it['msg'] = 'пустое поле «' . self::KEYS[$key] . '» — товар не найти при следующем импорте';
            } else {
                $it['action'] = 'create';
            }
            $items[] = $it;
        }

        // 3. данные найденных товаров для сравнения: тексты и характеристики
        $mappedFields = array_flip(array_filter($map));
        $texts = [];
        if ($foundIds && array_intersect_key($mappedFields, array_flip(self::TEXT_FIELDS))) {
            [$ph, $vals] = $db->in($foundIds);
            $texts = $db->keyed("SELECT product_id, summary, description, summary_uk, description_uk FROM product_texts WHERE product_id IN ($ph)", $vals);
        }
        $featIds = array_filter(array_merge(array_values($ctx['sysF']), array_map(static fn($c) => (int) ($ctx['fByCode'][substr($c, 8)]['id'] ?? 0),
            array_filter(array_keys($mappedFields), static fn($c) => str_starts_with((string) $c, 'feature:')))));
        $pf = [];
        if ($foundIds && $featIds) {
            [$ph1, $v1] = $db->in($foundIds);
            [$ph2, $v2] = $db->in(array_values(array_unique($featIds)));
            foreach ($db->all("SELECT product_id, feature_id, value_id FROM product_features WHERE product_id IN ($ph1) AND feature_id IN ($ph2)", array_merge($v1, $v2)) as $x) {
                $pf[(int) $x['product_id']][(int) $x['feature_id']][] = (int) $x['value_id'];
            }
        }

        // 4. значения характеристик, которые понадобятся (включая размер и «Кол-во пар»), — одним запросом
        $wanted = [];
        foreach ($items as $it) {
            if ($it['action'] !== 'create' && $it['action'] !== 'update') continue;
            foreach ($it['rec']['feat'] as $fid => $vals) foreach ($vals as $nk => $v) $wanted[$fid . '|' . $nk] = ['feature_id' => $fid, 'value' => $v];
            $f = $it['rec']['f'];
            if ($ctx['sysF']['size'] && isset($f['size'])) $wanted[$ctx['sysF']['size'] . '|' . self::nk($f['size'])] = ['feature_id' => $ctx['sysF']['size'], 'value' => $f['size']];
            $box = $f['box_qty'] ?? ($it['action'] === 'create' ? (int) ($opt['default_box_qty'] ?: 1) : null);
            if ($ctx['sysF']['box'] && $box !== null) $wanted[$ctx['sysF']['box'] . '|' . $box] = ['feature_id' => $ctx['sysF']['box'], 'value' => (string) $box];
        }
        $fv = self::featureValueIds($wanted);

        // 5. адреса страниц: уникальные для новых, проверка занятости при смене у найденных
        $urlBase = []; $urlChange = [];
        foreach ($items as $i => $it) {
            if ($it['action'] === 'create') {
                $urlBase[$i] = $it['rec']['f']['url'] ?? Str::slug($it['rec']['f']['name'] ?? '', 150);
            } elseif ($it['action'] === 'update' && isset($it['rec']['f']['url']) && $it['rec']['f']['url'] !== $it['ex']['url'] && empty($opt['price_only'])) {
                $urlChange[$i] = $it['rec']['f']['url'];
            }
        }
        $urls = self::uniqueUrls($urlBase, $urlChange, $items);

        // 6. окончательный расчёт
        $brandsNew = [];
        foreach ($items as $i => &$it) {
            if ($it['action'] === 'create') self::buildCreate($it, $opt, $ctx, $fv, $urls['new'][$i] ?? '', $brandsNew);
            elseif ($it['action'] === 'update') self::buildUpdate($it, $opt, $ctx, $fv, $pf, $texts, $urls['change'][$i] ?? null, $brandsNew);
        }
        unset($it);
        return ['items' => $items, 'brandsNew' => $brandsNew, 'fv' => $fv, 'wanted' => $wanted, 'sysF' => $ctx['sysF'], 'ci' => $ctx['ci']];
    }

    /** id значений характеристик: ["fid|nk" => id] */
    private static function featureValueIds(array $wanted): array
    {
        if (!$wanted) return [];
        $db = App::db();
        $out = [];
        $byF = [];
        foreach ($wanted as $k => $w) $byF[(int) $w['feature_id']][] = $w['value'];
        foreach ($byF as $fid => $values) {
            foreach (array_chunk(array_values(array_unique($values)), 500) as $part) {
                [$ph, $vals] = $db->in($part);
                foreach ($db->all("SELECT id, value FROM feature_values WHERE feature_id = ? AND value IN ($ph)", array_merge([$fid], $vals)) as $r) {
                    $out[$fid . '|' . self::nk((string) $r['value'])] ??= (int) $r['id'];
                }
            }
        }
        return array_intersect_key($out, $wanted);
    }

    /** Свободные адреса для новых товаров (slug, slug-2 … slug-9, иначе slug-случайный) и проверка смены адреса */
    private static function uniqueUrls(array $base, array $change, array $items): array
    {
        $db = App::db();
        $cand = [];
        foreach ($base as $b) { $cand[$b] = 1; for ($k = 2; $k <= 9; $k++) $cand[$b . '-' . $k] = 1; }
        foreach ($change as $u) $cand[$u] = 1;
        $taken = [];
        foreach (array_chunk(array_keys($cand), 1000) as $part) {
            [$ph, $vals] = $db->in($part);
            foreach ($db->all("SELECT id, url FROM products WHERE url IN ($ph)", $vals) as $r) $taken[mb_strtolower((string) $r['url'])] = (int) $r['id'];
        }
        $used = [];
        $new = [];
        foreach ($base as $i => $b) {
            $url = null;
            foreach (array_merge([$b], array_map(static fn($k) => $b . '-' . $k, range(2, 9))) as $c) {
                if (!isset($taken[$c]) && !isset($used[$c])) { $url = $c; break; }
            }
            $url ??= $b . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
            $used[$url] = 1;
            $new[$i] = $url;
        }
        $ch = [];
        foreach ($change as $i => $u) {
            $owner = $taken[$u] ?? null;
            $ch[$i] = ($owner === null || $owner === (int) $items[$i]['id']) && !isset($used[$u]) ? $u : false;
            if ($ch[$i] !== false) $used[$u] = 1;
        }
        return ['new' => $new, 'change' => $ch];
    }

    /** Бренд по названию: id существующего или «new:ключ» (создаётся при записи) */
    private static function brandRef(string $name, array $ctx, array &$brandsNew)
    {
        $nk = self::nk($name);
        if (isset($ctx['brandByNk'][$nk])) return $ctx['brandByNk'][$nk];
        $brandsNew[$nk] ??= $name;                                         // написание — из первой строки файла
        return 'new:' . $nk;
    }

    private static function buildCreate(array &$it, array $opt, array &$ctx, array $fv, string $url, array &$brandsNew): void
    {
        $f = $it['rec']['f'];
        if (($f['name'] ?? '') === '') { $it['action'] = 'error'; $it['msg'] = 'нет названия — новый товар не создан'; return; }
        $box = (int) ($f['box_qty'] ?? ($opt['default_box_qty'] ?: 1));
        [$price, $compare, $purchase] = self::prices($f, $box, $opt);
        if (!$price || $price <= 0) { $it['action'] = 'error'; $it['msg'] = 'нет цены — новый товар не создан'; return; }
        $cat = null; $catErr = null;
        if (($f['category'] ?? '') !== '') [$cat, $catErr] = self::resolveCategory($f['category'], $ctx);
        if ($cat === null && (int) $opt['default_category']) {
            $cat = (int) $opt['default_category'];
            if ($catErr) { $it['warn'][] = $catErr . ' — товар добавлен в категорию по умолчанию'; $it['badcat'] = $f['category']; }
        }
        if ($cat === null) {
            $it['action'] = 'error';
            $it['msg'] = ($catErr ?? 'не указана категория') . ' — задайте «Категорию по умолчанию» или соответствие категорий';
            $it['badcat'] = $f['category'] ?? '';
            return;
        }
        $brand = ($f['brand'] ?? '') !== '' ? self::brandRef($f['brand'], $ctx, $brandsNew) : null;
        $inStock = $f['in_stock'] ?? (isset($f['stock']) ? ($f['stock'] > 0 ? 1 : 0) : 1);
        $now = date('Y-m-d H:i:s');
        $supplier = trim((string) $opt['supplier']) !== '' ? trim((string) $opt['supplier']) : (($f['supplier'] ?? '') !== '' ? $f['supplier'] : null);
        $it['row'] = [
            'url' => $url, 'name' => $f['name'], 'sku' => $f['sku'] ?? '', 'category_id' => $cat, 'brand_id' => $brand,
            'price' => $price, 'compare_price' => $compare ?? 0, 'purchase_price' => $purchase ?? 0,
            'box_qty' => max(1, $box), 'min_qty' => max(1, (int) ($f['min_qty'] ?? $box)), 'size' => $f['size'] ?? '',
            'stock' => $f['stock'] ?? null, 'in_stock' => $inStock, 'status' => $f['status'] ?? (int) $opt['new_status'],
            'supplier' => $supplier, 'supplier_code' => $f['supplier_code'] ?? null, 'created_at' => $now, 'updated_at' => $now,
        ];
        foreach (self::TEXT_COLS as $c => $_) $it['row'][$c] = ($f[$c] ?? '') !== '' ? $f[$c] : null;   // все строки пачки — с одинаковым набором колонок
        // название-код («88888X») → «Категория Бренд Код», как в админке (ProductName); адрес — из кода в файле
        $auto = ProductName::fix(['name' => $f['name'], 'name_uk' => $it['row']['name_uk'], 'sku' => $it['row']['sku'], 'category_id' => $cat]
            + (is_string($brand) ? ['brand_name' => $brandsNew[substr($brand, 4)] ?? ''] : ['brand_id' => $brand]));
        $it['row'] = array_merge($it['row'], $auto);
        $it['cat'] = $cat;
        $it['feat'] = self::featPlan($it['rec']['feat'], $fv);
        if ($brand !== null && $ctx['sysF']['brand']) $it['feat'][$ctx['sysF']['brand']] = ['brand' => $brand];
        if (isset($f['size']) && $ctx['sysF']['size']) $it['feat'][$ctx['sysF']['size']] = self::featPlan([$ctx['sysF']['size'] => [self::nk($f['size']) => $f['size']]], $fv)[$ctx['sysF']['size']];
        if ($ctx['sysF']['box']) $it['feat'][$ctx['sysF']['box']] = self::featPlan([$ctx['sysF']['box'] => [(string) $box => (string) $box]], $fv)[$ctx['sysF']['box']];
        foreach (self::TEXT_FIELDS as $t) if (isset($f[$t])) $it['texts'][$t] = $f[$t];
        if (!empty($opt['images'])) $it['img'] = $it['rec']['img'];
        $it['show'] = ['name' => $it['row']['name'], 'price' => $price, 'box_qty' => $box, 'category' => $ctx['ci']['path'][$cat] ?? '',
            'brand' => $f['brand'] ?? '', 'brand_new' => is_string($brand), 'size' => $f['size'] ?? '', 'in_stock' => $inStock, 'images' => count($it['img']), 'url' => $url];
    }

    private static function buildUpdate(array &$it, array $opt, array &$ctx, array $fv, array $pf, array $texts, $newUrl, array &$brandsNew): void
    {
        $f = $it['rec']['f'];
        $ex = $it['ex'];
        $id = (int) $ex['id'];
        $priceOnly = !empty($opt['price_only']);
        $upd = []; $changes = [];
        $set = static function (string $col, $new, string $type = 's') use (&$upd, &$changes, $ex): void {
            $old = $ex[$col] ?? null;
            $same = match ($type) {
                'n' => $old !== null && abs((float) $old - (float) $new) < 0.005,
                'i' => $old !== null && $new !== null && (int) $old === (int) $new,
                'in' => ($old === null && $new === null) || ($old !== null && $new !== null && (int) $old === (int) $new),
                // название: в файле пробелы схлопнуты — «Кроссовки  Tom.m» в базе не считается изменением
                'ws' => trim((string) preg_replace('/\s+/u', ' ', (string) $old)) === trim((string) $new),
                default => trim((string) $old) === trim((string) $new),
            };
            if ($same) return;
            $upd[$col] = $new;
            $show = static fn($v): string => $v === null || $v === '' ? '—'
                : ($col === 'in_stock' || $col === 'status' ? ((int) $v ? 'да' : 'нет')
                : self::cut($type === 'n' ? rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.') : (string) $v, 30));
            $changes[] = (self::CHANGE_NAMES[$col] ?? $col) . ': ' . $show($old) . ' → ' . $show($new);
        };
        $box = (int) ($f['box_qty'] ?? $ex['box_qty']);
        [$price, $compare, $purchase] = self::prices($f, max(1, $box), $opt);
        if ($price !== null && $price > 0) $set('price', $price, 'n');
        if ($compare !== null) $set('compare_price', $compare, 'n');
        if ($purchase !== null) $set('purchase_price', $purchase, 'n');
        if (isset($f['stock'])) $set('stock', $f['stock'], 'in');
        $inStock = $f['in_stock'] ?? (isset($f['stock']) ? ($f['stock'] > 0 ? 1 : 0) : null);
        if ($inStock !== null) $set('in_stock', $inStock, 'i');
        $sysFeat = [];
        $showCat = $ctx['ci']['path'][(int) $ex['category_id']] ?? '';
        $bare = false;
        if (!$priceOnly) {
            // название-код в файле («60189A») — после категории и бренда (ниже): ProductName, как при создании
            $bare = ($f['name'] ?? '') !== '' && ProductName::isBare($f['name']);
            if (($f['name'] ?? '') !== '' && !$bare) $set('name', $f['name'], 'ws');
            if (($f['name_uk'] ?? '') !== '' && !$bare) $set('name_uk', $f['name_uk'], 'ws');
            foreach (['sku', 'supplier_code', 'size'] as $c) if (isset($f[$c]) && $f[$c] !== '') $set($c, $f[$c]);
            foreach (self::TEXT_COLS as $c => $_) if ($c !== 'name_uk' && ($f[$c] ?? '') !== '') $set($c, $f[$c]);
            foreach (['box_qty', 'min_qty', 'status'] as $c) if (isset($f[$c])) $set($c, $f[$c], 'i');
            if ($newUrl === false) $it['warn'][] = 'адрес «' . $f['url'] . '» занят другим товаром — не изменён';
            elseif (is_string($newUrl)) {
                $set('url', $newUrl);
                if (isset($upd['url'])) $changes[count($changes) - 1] .= ' (301 со старого)';   // редирект пишет apply
            }
            $supplier = ($f['supplier'] ?? '') !== '' ? $f['supplier'] : (trim((string) $opt['supplier']) !== '' && trim((string) $ex['supplier']) === '' ? trim((string) $opt['supplier']) : null);
            if ($supplier !== null) $set('supplier', $supplier);
            if (($f['brand'] ?? '') !== '') {
                $b = self::brandRef($f['brand'], $ctx, $brandsNew);
                if (is_string($b) || (int) $b !== (int) $ex['brand_id']) {
                    $upd['brand_id'] = $b;
                    $changes[] = 'бренд → ' . self::cut($f['brand'], 30) . (is_string($b) ? ' (новый)' : '');
                }
            }
            if (($f['category'] ?? '') !== '') {
                [$cat, $catErr] = self::resolveCategory($f['category'], $ctx);
                if ($cat === null) { $it['warn'][] = $catErr . ' — категория не изменена'; $it['badcat'] = $f['category']; }
                elseif ($cat !== (int) $ex['category_id']) {
                    $upd['category_id'] = $cat;
                    $it['cat'] = $cat;
                    $it['oldcat'] = $ex['category_id'] !== null ? (int) $ex['category_id'] : null;
                    $changes[] = 'категория → ' . self::cut($ctx['ci']['path'][$cat] ?? '', 40);
                    $showCat = $ctx['ci']['path'][$cat] ?? '';
                }
            }
            if ($bare) self::bareName($f, $ex, $upd, $brandsNew, $set);
            // характеристики: перезаписываются только отличающиеся
            $want = self::featPlan($it['rec']['feat'], $fv);
            if (array_key_exists('brand_id', $upd) && $ctx['sysF']['brand']) $want[$ctx['sysF']['brand']] = ['brand' => $upd['brand_id']];
            if (array_key_exists('size', $upd) && $ctx['sysF']['size']) $want += self::featPlan([$ctx['sysF']['size'] => [self::nk((string) $upd['size']) => (string) $upd['size']]], $fv);
            if (array_key_exists('box_qty', $upd) && $ctx['sysF']['box']) $want += self::featPlan([$ctx['sysF']['box'] => [(string) $upd['box_qty'] => (string) $upd['box_qty']]], $fv);
            foreach ($want as $fid => $vals) {
                $cur = $pf[$id][$fid] ?? [];
                if (isset($vals['brand'])) {
                    if (is_string($vals['brand']) || $cur !== [(int) $vals['brand']]) $sysFeat[$fid] = $vals;
                    continue;
                }
                $ids = array_filter($vals, 'is_int');
                if (count($ids) !== count($vals)) { $sysFeat[$fid] = $vals; continue; }   // есть новые значения
                sort($ids); sort($cur);
                if ($ids !== $cur) $sysFeat[$fid] = $vals;
            }
            if ($sysFeat) {
                $names = [];
                foreach (array_keys($sysFeat) as $fid) foreach ($ctx['fByCode'] as $fe) if ((int) $fe['id'] === (int) $fid) $names[] = $fe['name'];
                $changes[] = 'характеристики: ' . implode(', ', $names);
            }
            $tNames = ['description' => 'описание', 'summary' => 'краткое описание', 'description_uk' => 'описание (укр.)', 'summary_uk' => 'краткое описание (укр.)'];
            foreach (self::TEXT_FIELDS as $t) {
                if (!isset($f[$t])) continue;
                $old = trim((string) ($texts[$id][$t] ?? ''));
                // в файле текст уже очищен — сравниваем с так же очищенным текстом из базы (экспорт → импорт ничего не меняет)
                $oldClean = str_starts_with($t, 'description') ? self::cleanHtml($old, $ctx['own']) : mb_substr(trim(strip_tags($old)), 0, 2000);
                if ($old !== trim($f[$t]) && $oldClean !== trim($f[$t])) { $it['texts'][$t] = $f[$t]; $changes[] = $tNames[$t]; }
            }
            if (!empty($opt['images']) && $ex['image_id'] === null && $it['rec']['img']) {
                $it['img'] = $it['rec']['img'];
                $changes[] = 'фото: ' . count($it['img']);
            }
        }
        if (!empty($opt['unhide']) && (int) $ex['status'] === 0 && !isset($f['status'])) $set('status', 1, 'i');
        $it['upd'] = $upd;
        $it['feat'] = $sysFeat;
        $it['changes'] = $changes;
        if (!$upd && !$sysFeat && !$it['texts'] && !$it['img']) $it['action'] = 'same';
        // товар изменён на сайте позже, чем выгружен файл (колонка updated_at нашего экспорта), — не затираем чужие правки
        if ($it['action'] === 'update' && isset($f['updated_at'])) {
            [$fileTs, $prec] = $f['updated_at'];
            $siteTs = $ex['updated_at'] !== null ? strtotime((string) $ex['updated_at']) : false;
            if ($siteTs !== false && $siteTs >= $fileTs + $prec) {
                $when = 'на сайте ' . date('d.m.Y H:i:s', $siteTs) . ', в файле ' . date($prec === 1 ? 'd.m.Y H:i:s' : ($prec === 60 ? 'd.m.Y H:i' : 'd.m.Y'), $fileTs);
                if (empty($opt['overwrite'])) {
                    $it['action'] = 'conflict';
                    $it['msg'] = 'изменён на сайте после выгрузки (' . $when . ') — не перезаписан';
                    $it['upd'] = []; $it['feat'] = []; $it['texts'] = []; $it['img'] = []; $it['cat'] = null; $it['oldcat'] = null;
                } else {
                    $it['warn'][] = 'изменён на сайте после выгрузки (' . $when . ') — перезаписан данными файла';
                }
            }
        }
        $u = $it['upd'];
        $it['show'] = ['name' => $u['name'] ?? ($bare ? $ex['name'] : ($f['name'] ?? $ex['name'])), 'price' => $u['price'] ?? (float) $ex['price'], 'box_qty' => $u['box_qty'] ?? (int) $ex['box_qty'],
            'category' => $it['action'] === 'conflict' ? ($ctx['ci']['path'][(int) $ex['category_id']] ?? '') : $showCat,
            'brand' => $f['brand'] ?? '', 'brand_new' => isset($u['brand_id']) && is_string($u['brand_id']),
            'size' => $u['size'] ?? $ex['size'], 'in_stock' => $u['in_stock'] ?? (int) $ex['in_stock'], 'images' => count($it['img']), 'url' => $u['url'] ?? $ex['url']];
    }

    /**
     * Название-код из файла у найденного товара: «60189A» → «Категория Бренд Код» (ProductName) с категорией и брендом
     * после этой строки файла. Название, собранное правилом из этого же кода, пересобирается (сменились категория или
     * бренд); своё полное название с этим кодом в конце («Ботинки зимние Tom.m 60189A») не меняется — в файле просто код.
     */
    private static function bareName(array $f, array $ex, array $upd, array $brandsNew, callable $set): void
    {
        $code = trim((string) preg_replace('/\s+/u', ' ', (string) $f['name']));
        $old = trim((string) preg_replace('/\s+/u', ' ', (string) $ex['name']));
        $gen = ProductName::generated($ex);                                // [название, UA, код] — собрано правилом
        $auto = $gen !== null && mb_strtolower($gen[2]) === mb_strtolower($code);
        if ($auto) $code = $gen[2];                                        // «60189a» в файле — написание кода с сайта
        elseif (!ProductName::isBare($old) && str_ends_with(mb_strtolower($old), ' ' . mb_strtolower($code))) return;
        $brand = array_key_exists('brand_id', $upd) ? $upd['brand_id'] : $ex['brand_id'];
        // UA: из файла; своё UA-название остаётся, если русское было кодом (как в админке) или UA своё у собранного правилом;
        // собранное правилом и после смены названия на другой код — пересобирается
        $exUk = trim((string) $ex['name_uk']);
        $ukOld = ($f['name_uk'] ?? '') !== '' ? (string) $f['name_uk']
            : (ProductName::isBare($old) || ($auto && $exUk !== $gen[1]) ? $exUk : '');
        // артикул, который записало правило (= прежний код), а в файле артикула нет — вместе с новым кодом
        $ownSku = $gen !== null && ($f['sku'] ?? '') === '' && trim((string) $ex['sku']) === $gen[2];
        $auto = ProductName::fix(['name' => $code, 'name_uk' => $ukOld, 'sku' => $ownSku ? '' : ($upd['sku'] ?? $ex['sku']),
            'category_id' => $upd['category_id'] ?? $ex['category_id']]
            + (is_string($brand) ? ['brand_name' => $brandsNew[substr($brand, 4)] ?? ''] : ['brand_id' => $brand]));
        $set('name', $auto['name'] ?? $code, 'ws');
        if (isset($auto['name_uk'])) $set('name_uk', $auto['name_uk'], 'ws');
        elseif (($f['name_uk'] ?? '') !== '') $set('name_uk', $f['name_uk'], 'ws');
        elseif ($ukOld === '' && trim((string) $ex['name_uk']) !== '') $set('name_uk', null);   // прежнее UA-название — от другого товара-названия
        if (isset($auto['sku'])) $set('sku', $auto['sku']);
        elseif ($ownSku) $set('sku', '');
    }

    /** [fid => [nk => значение]] → [fid => [id значения | "fid|nk" для новых]] */
    private static function featPlan(array $feat, array $fv): array
    {
        $out = [];
        foreach ($feat as $fid => $vals) {
            foreach ($vals as $nk => $v) {
                $k = $fid . '|' . $nk;
                $out[$fid][] = $fv[$k] ?? $k;
            }
        }
        return $out;
    }

    // ============================================================ запись пачки

    /** Записать пачку. @return int[] id созданных и изменённых товаров (для переиндексации) */
    private static function apply(array $plan, array $job, int $lastN, bool $isLast): array
    {
        $db = App::db();
        $jobId = (int) $job['id'];
        $items = $plan['items'];
        $now = date('Y-m-d H:i:s');

        // 1. новые бренды (id бренда = id значения характеристики «Бренд», как в Webasyst)
        $need = [];
        foreach ($items as $it) {
            if ($it['action'] === 'create' && is_string($it['row']['brand_id'] ?? null)) $need[substr($it['row']['brand_id'], 4)] = 1;
            if ($it['action'] === 'update' && is_string($it['upd']['brand_id'] ?? null)) $need[substr($it['upd']['brand_id'], 4)] = 1;
        }
        $brandIds = $need ? self::createBrands(array_intersect_key($plan['brandsNew'], $need)) : [];
        $brand = static fn($v) => is_string($v) && str_starts_with($v, 'new:') ? ($brandIds[substr($v, 4)] ?? null) : $v;

        // 2. новые значения характеристик
        $fv = $plan['fv'];
        $missing = [];
        foreach ($items as $it) {
            if ($it['action'] !== 'create' && $it['action'] !== 'update') continue;
            foreach ($it['feat'] as $vals) foreach ($vals as $v) if (is_string($v) && isset($plan['wanted'][$v])) $missing[$v] = $plan['wanted'][$v];
        }
        if ($missing) $fv += self::createFeatureValues($missing);

        // 3. новые товары — пакетной вставкой, id — по уникальному url
        $creates = array_keys(array_filter($items, static fn($i) => $i['action'] === 'create'));
        if ($creates) {
            $rows = [];
            foreach ($creates as $i) { $r = $items[$i]['row']; $r['brand_id'] = $brand($r['brand_id']); $rows[] = $r; }
            $db->insertMany('products', $rows, false, 200);
            $urls = array_column($rows, 'url');
            [$ph, $vals] = $db->in($urls);
            $ids = $db->pairs("SELECT url, id FROM products WHERE url IN ($ph)", $vals);
            foreach ($creates as $i) $items[$i]['id'] = (int) ($ids[$items[$i]['row']['url']] ?? 0);
        }

        // 4. обновления: один UPDATE … CASE на 200 товаров
        $upd = [];
        foreach ($items as $it) {
            if ($it['action'] !== 'update' || !$it['upd']) continue;
            $u = $it['upd'];
            if (array_key_exists('brand_id', $u)) $u['brand_id'] = $brand($u['brand_id']);
            $upd[(int) $it['id']] = $u + ['updated_at' => $now];
        }
        self::bulkUpdate($upd);
        // смена адреса (колонка «Адрес» — прайс поставщика или своя выгрузка): 301 со старого, как в админке —
        // AdminCatalog::addRedirects, без цепочек и петель; путь без /ua — на /ua/ редирект тот же (/ua/старый → /ua/новый)
        $moved = [];
        foreach ($items as $it) {
            if ($it['action'] === 'update' && isset($it['upd']['url']) && (string) $it['ex']['url'] !== '') {
                $moved[] = ['/product/' . $it['ex']['url'] . '/', '/product/' . $it['upd']['url'] . '/'];
            }
        }
        AdminCatalog::addRedirects($moved);
        $touched = [];
        foreach ($items as $it) {
            if ($it['action'] === 'update' && !$it['upd'] && ($it['feat'] || $it['texts'])) $touched[] = (int) $it['id'];
        }
        if ($touched) {
            [$ph, $vals] = $db->in($touched);
            $db->query("UPDATE products SET updated_at = ? WHERE id IN ($ph)", array_merge([$now], $vals));
        }

        // 5. категории товара
        $link = []; $unlink = [];
        foreach ($items as $it) {
            if (!$it['id'] || $it['cat'] === null || ($it['action'] !== 'create' && $it['action'] !== 'update')) continue;
            $link[] = ['category_id' => (int) $it['cat'], 'product_id' => (int) $it['id'], 'sort' => 0];
            if ($it['oldcat']) $unlink[] = [(int) $it['oldcat'], (int) $it['id']];
        }
        foreach (array_chunk($unlink, 300) as $part) {
            $db->query('DELETE FROM category_products WHERE ' . implode(' OR ', array_fill(0, count($part), '(category_id = ? AND product_id = ?)')), array_merge(...$part));
        }
        $db->insertMany('category_products', $link, true, 500);

        // 6. характеристики: удалить старые значения изменённых характеристик, вставить новые
        $del = []; $ins = [];
        foreach ($items as $it) {
            if (!$it['id'] || !$it['feat'] || ($it['action'] !== 'create' && $it['action'] !== 'update')) continue;
            foreach ($it['feat'] as $fid => $vals) {
                if ($it['action'] === 'update') $del[(int) $fid][] = (int) $it['id'];
                if (isset($vals['brand'])) {
                    $b = $brand($vals['brand']);
                    if ($b) $ins[] = ['product_id' => (int) $it['id'], 'feature_id' => (int) $fid, 'value_id' => (int) $b];
                    continue;
                }
                foreach ($vals as $v) {
                    $vid = is_int($v) ? $v : ($fv[$v] ?? null);
                    if ($vid) $ins[] = ['product_id' => (int) $it['id'], 'feature_id' => (int) $fid, 'value_id' => (int) $vid];
                }
            }
        }
        foreach ($del as $fid => $pids) {
            foreach (array_chunk($pids, 500) as $part) {
                [$ph, $vals] = $db->in($part);
                $db->query("DELETE FROM product_features WHERE feature_id = ? AND product_id IN ($ph)", array_merge([$fid], $vals));
            }
        }
        $db->insertMany('product_features', $ins, true, 1000);

        // 7. описания
        $tx = [];
        foreach ($items as $it) {
            if ($it['id'] && $it['texts'] && ($it['action'] === 'create' || $it['action'] === 'update')) $tx[(int) $it['id']] = $it['texts'];
        }
        self::saveTexts($tx);

        // 8. фото — в очередь (скачиваются по 20 за шаг)
        $img = [];
        foreach ($items as $it) {
            if (!$it['id'] || !$it['img']) continue;
            foreach ($it['img'] as $k => $u) $img[] = ['job_id' => $jobId, 'product_id' => (int) $it['id'], 'n' => $it['n'], 'url' => $u, 'sort' => $k, 'status' => 0];
        }
        $db->insertMany('import_images', $img, false, 500);

        // 9. встреченные товары (для «скрыть отсутствующие», переиндексации и списка «изменены на сайте»)
        $seen = []; $changed = [];
        foreach ($items as $it) {
            if (!$it['id'] || !in_array($it['action'], ['create', 'update', 'same', 'skip', 'conflict'], true)) continue;
            if ($it['action'] === 'skip' && !$it['ex']) continue;
            $ch = in_array($it['action'], ['create', 'update'], true);
            if ($ch) $changed[] = (int) $it['id'];
            $seen[] = [(int) $it['id'], $it['action'] === 'create' ? 1 : 0, $ch ? 1 : 0, $it['action'] === 'conflict' ? 1 : 0];
        }
        foreach (array_chunk($seen, 500) as $part) {
            $params = [];
            foreach ($part as [$pid, $c, $ch, $cf]) array_push($params, $jobId, $pid, $c, $ch, $cf);
            $db->query('INSERT INTO import_seen (job_id, product_id, created, changed, conflict) VALUES ' . implode(',', array_fill(0, count($part), '(?,?,?,?,?)'))
                . ' ON DUPLICATE KEY UPDATE changed = GREATEST(changed, VALUES(changed)), created = GREATEST(created, VALUES(created)),
                    conflict = GREATEST(conflict, VALUES(conflict))', $params);
        }

        // 10. ошибки, пропуски, изменённые на сайте и предупреждения
        $err = []; $cnt = ['create' => 0, 'update' => 0, 'same' => 0, 'skip' => 0, 'error' => 0, 'conflict' => 0];
        foreach ($items as $it) {
            $cnt[$it['action']] = ($cnt[$it['action']] ?? 0) + 1;
            $label = $it['rec']['label'] !== '' ? $it['rec']['label'] . ': ' : '';
            if (in_array($it['action'], ['error', 'skip', 'conflict'], true)) {
                $msg = $it['action'] === 'conflict' ? $label . 'ID ' . (int) $it['id'] . ' ' . $it['msg'] : $label . $it['msg'];
                $err[] = ['job_id' => $jobId, 'n' => $it['n'], 'level' => $it['action'], 'message' => mb_substr($msg, 0, 500)];
            }
            foreach ($it['warn'] as $w) $err[] = ['job_id' => $jobId, 'n' => $it['n'], 'level' => 'warn', 'message' => mb_substr($label . $w, 0, 500)];
        }
        $db->insertMany('import_errors', $err, false, 500);

        // 11. счётчики задания (изменённые на сайте — среди пропущенных)
        $db->query('UPDATE import_jobs SET processed = processed + ?, last_n = ?, created = created + ?, updated = updated + ?, unchanged = unchanged + ?,
            skipped = skipped + ?, error_count = error_count + ?, status = ?, updated_at = ? WHERE id = ?',
            [count($items), $lastN, $cnt['create'], $cnt['update'], $cnt['same'], $cnt['skip'] + $cnt['conflict'], $cnt['error'], $isLast ? 'finishing' : 'running', $now, $jobId]);
        return $changed;
    }

    /** UPDATE products SET col = CASE id WHEN … END — пачкой, колонки только из белого списка */
    private static function bulkUpdate(array $upd): void
    {
        if (!$upd) return;
        $db = App::db();
        foreach (array_chunk($upd, 200, true) as $part) {
            $cols = [];
            foreach ($part as $id => $u) foreach ($u as $c => $v) $cols[$c][$id] = $v;
            $set = []; $params = [];
            foreach ($cols as $c => $vals) {
                if (!in_array($c, self::UPDATABLE, true)) continue;
                $sql = "`$c` = CASE id";
                foreach ($vals as $id => $v) { $sql .= ' WHEN ? THEN ?'; $params[] = (int) $id; $params[] = is_float($v) ? (string) round($v, 2) : $v; }
                $set[] = $sql . " ELSE `$c` END";
            }
            if (!$set) continue;
            [$ph, $ids] = $db->in(array_keys($part));
            $db->query('UPDATE products SET ' . implode(', ', $set) . " WHERE id IN ($ph)", array_merge($params, $ids));
        }
    }

    private static function saveTexts(array $tx): void
    {
        if (!$tx) return;
        $db = App::db();
        $groups = [];
        foreach ($tx as $pid => $t) $groups[implode(',', array_keys($t))][$pid] = $t;
        foreach ($groups as $colsKey => $rows) {
            $cols = explode(',', $colsKey);                                   // description / summary — из кода, не из файла
            $upd = implode(', ', array_map(static fn($c) => "`$c` = VALUES(`$c`)", $cols));
            foreach (array_chunk($rows, 200, true) as $part) {
                $params = [];
                foreach ($part as $pid => $t) { $params[] = (int) $pid; foreach ($cols as $c) $params[] = $t[$c]; }
                $ph = '(' . implode(',', array_fill(0, count($cols) + 1, '?')) . ')';
                $db->query('INSERT INTO product_texts (product_id, `' . implode('`, `', $cols) . '`) VALUES ' . implode(',', array_fill(0, count($part), $ph))
                    . ' ON DUPLICATE KEY UPDATE ' . $upd, $params);
            }
        }
    }

    /** Создать бренды: brands + значение характеристики «Бренд» с тем же id. @return [nk => id] */
    private static function createBrands(array $new): array
    {
        $db = App::db();
        $bf = (int) $db->value("SELECT id FROM features WHERE code = 'brand'");
        $out = [];
        // повторная проверка: бренд мог появиться в соседнем шаге или в админке
        [$ph, $vals] = $db->in(array_values($new));
        foreach ($db->all("SELECT id, name FROM brands WHERE name IN ($ph)", $vals) as $b) $out[self::nk((string) $b['name'])] ??= (int) $b['id'];
        $need = array_diff_key($new, $out);
        if (!$need) return $out;
        // сначала значения характеристики «Бренд» (автоинкремент), затем бренды с теми же id — как AdminCatalog::valueId
        $fvIds = [];
        if ($bf) {
            $sort = self::nextSort([$bf])[$bf];
            $db->insertMany('feature_values', array_map(static function ($n) use ($bf, &$sort) { return ['feature_id' => $bf, 'value' => $n, 'sort' => $sort++]; }, array_values($need)), true);
            [$ph2, $v2] = $db->in(array_values($need));
            foreach ($db->all("SELECT id, value FROM feature_values WHERE feature_id = ? AND value IN ($ph2)", array_merge([$bf], $v2)) as $r) {
                $fvIds[self::nk((string) $r['value'])] ??= (int) $r['id'];
            }
        }
        // адрес бренда — как в Webasyst: имя без «/ \ ? # %» (уникальный)
        $urls = [];
        foreach ($need as $nk => $name) $urls[$nk] = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\/\\\\?#%]+/u', ' ', $name))), 0, 180) ?: 'brand';
        [$ph3, $v3] = $db->in(array_values($urls));
        $taken = [];
        foreach ($db->col("SELECT url FROM brands WHERE url IN ($ph3)", $v3) as $u) $taken[self::nk((string) $u)] = 1;
        $rows = [];
        foreach ($need as $nk => $name) {
            $id = $fvIds[$nk] ?? null;
            $url = $urls[$nk];
            if (isset($taken[self::nk($url)])) $url .= '-' . ($id ?? substr(bin2hex(random_bytes(3)), 0, 5));
            $taken[self::nk($url)] = 1;
            if ($id) { $rows[] = ['id' => $id, 'name' => $name, 'url' => $url, 'hidden' => 0, 'sort' => 0]; $out[$nk] = $id; }
            else $out[$nk] = $db->insert('brands', ['name' => $name, 'url' => $url, 'hidden' => 0, 'sort' => 0]);   // нет характеристики «Бренд» — редкий случай
        }
        $db->insertMany('brands', $rows, true);
        return $out;
    }

    /** Следующий номер сортировки значений для каждой характеристики: [fid => MAX(sort) + 1] */
    private static function nextSort(array $featureIds): array
    {
        $featureIds = array_values(array_unique(array_map('intval', $featureIds)));
        if (!$featureIds) return [];
        [$ph, $vals] = App::db()->in($featureIds);
        $max = App::db()->pairs("SELECT feature_id, MAX(sort) FROM feature_values WHERE feature_id IN ($ph) GROUP BY feature_id", $vals);
        $out = [];
        foreach ($featureIds as $fid) $out[$fid] = (int) ($max[$fid] ?? 0) + 1;
        return $out;
    }

    /** Создать недостающие значения характеристик. @return ["fid|nk" => id] */
    private static function createFeatureValues(array $missing): array
    {
        $db = App::db();
        $rows = [];
        $sort = self::nextSort(array_map(static fn($w) => (int) $w['feature_id'], $missing));   // новые значения — в конец списка (как в админке)
        foreach ($missing as $w) $rows[] = ['feature_id' => (int) $w['feature_id'], 'value' => $w['value'], 'sort' => $sort[(int) $w['feature_id']]++];
        $db->insertMany('feature_values', $rows, true, 500);
        $out = self::featureValueIds($missing);
        foreach ($missing as $k => $w) {                          // редкий случай: значение отличается от найденного только по сравнению MySQL
            if (isset($out[$k])) continue;
            $id = $db->value('SELECT id FROM feature_values WHERE feature_id = ? AND value = ? LIMIT 1', [(int) $w['feature_id'], $w['value']]);
            if ($id) $out[$k] = (int) $id;
        }
        return $out;
    }

    // ============================================================ шаги

    /**
     * Один шаг задания (вызывается из админки по AJAX и из bin/import.php).
     * Параллельный запуск одного задания исключён блокировкой GET_LOCK.
     * Взаимная блокировка InnoDB (1213) или ожидание блокировки строк (1205) — например, в это же время идёт
     * bin/reindex.php или админка сохраняет те же товары: транзакцию шага сервер откатил целиком, шаг повторяется
     * здесь же (до LOCK_RETRIES раз, с паузой), пользователь ошибку не видит — в журнале app-*.log запись о повторе.
     */
    public static function step(int $jobId, float $budget = 15.0, bool $start = false): array
    {
        self::ensureSchema();
        $db = App::db();
        $lock = 'tomobuv_import_' . $jobId;
        if (!(int) $db->value('SELECT GET_LOCK(?, 0)', [$lock])) {
            return ['busy' => true] + self::progress(self::job($jobId));
        }
        $t0 = microtime(true);
        try {
            if (function_exists('set_time_limit')) @set_time_limit((int) $budget + 60);
            @ini_set('memory_limit', '512M');
            for ($try = 1; ; $try++) {
                try {
                    $res = self::runStep($jobId, max(2.0, $budget - (microtime(true) - $t0)), $start);
                    break;
                } catch (\PDOException $e) {
                    if ($try > self::LOCK_RETRIES || !CatalogIndexer::lockError($e) || $db->pdo()->inTransaction()) throw $e;
                    Log::info('import step #' . $jobId . ': повтор шага после ' . ((int) ($e->errorInfo[1] ?? 0) === 1213
                        ? 'взаимной блокировки' : 'ожидания блокировки') . ' (попытка ' . ($try + 1) . '): ' . $e->getMessage());
                    usleep(300000 * $try);
                }
            }
            if (isset($res['return'])) return $res['return'];
            $st = self::job($jobId)['state'] ?? [];
            $st['time_ms'] = (int) (($st['time_ms'] ?? 0) + (microtime(true) - $t0) * 1000);
            $st['last_step_ms'] = (int) ((microtime(true) - $t0) * 1000);
            self::saveState($jobId, $st);
            $out = self::progress(self::job($jobId));
            if (!empty($res['wait'])) {                                        // идёт перестройка индекса — следующий шаг через 3 с
                $out['busy'] = true;
                $out['note'] = 'Идёт перестройка индекса каталога — импорт продолжится, как только она закончится.';
            }
            return $out;
        } catch (\Throwable $e) {
            Log::error('import step #' . $jobId . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            return ['ok' => false, 'error' => 'Ошибка на шаге импорта: ' . $e->getMessage()] + self::progress(self::job($jobId));
        } finally {
            $db->value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /**
     * Работа шага (см. step): ['return' => ответ] — сразу вернуть его; ['wait' => true] — ждали блокировку индекса
     * каталога (идёт перестройка) и не дождались, пачка не обработана; [] — шаг сделан. Повторяемо целиком:
     * задание читается заново, транзакции шага при сбое откатываются.
     */
    private static function runStep(int $jobId, float $budget, bool $start): array
    {
        $db = App::db();
        $t0 = microtime(true);
        $job = self::job($jobId);
        if (!$job) return ['return' => ['ok' => false, 'error' => 'Задание не найдено']];
        if ($job['status'] === 'new' && $start) {
            $db->update('import_jobs', ['status' => 'running', 'started_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$jobId]);
            $job['status'] = 'running';
        }
        switch ($job['status']) {
            case 'parsing':
                return ['return' => self::parse($jobId, min($budget, 12.0))];
            case 'running':
                if (!self::processRows($job, min(10.0, $budget / 2))) return ['wait' => true];
                $left = $budget - (microtime(true) - $t0);
                if (!empty($job['options']['images']) && $left > $budget / 2) self::downloadImages($jobId, self::IMG_PER_STEP, $left - 1);
                break;
            case 'finishing':
                if (!self::finish($job, min(self::REINDEX_BUDGET, $budget * 0.6))) return ['wait' => true];
                break;
            case 'images':
                if (self::downloadImages($jobId, self::IMG_PER_STEP, $budget - (microtime(true) - $t0) - 1) === 0) self::done($jobId);
                break;
        }
        return [];
    }

    /**
     * Обработать очередную пачку строк (одна транзакция). Индекс каталога обновляется сразу для товаров
     * пачки — точечно, в той же транзакции (снимок характеристик — до записи): после импорта 3 товаров
     * «Завершение» больше не перестраивает категории на 50 тыс. товаров. Когда изменённых за задание
     * набирается больше FULL_REINDEX_FROM, дальше — как раньше: в конце перестраиваются все категории.
     * Транзакция с индексом — под блокировкой записи индекса (CatalogIndexer::locked, берётся ДО транзакции):
     * перестройка не читает товары пачки, пока та не записана, а пачка не пишет в индекс во время перестройки.
     * Не дождались её за $wait секунд (идёт bin/reindex.php) — false, пачка не тронута (шаг повторит админка).
     */
    private static function processRows(array $job, float $wait = 10.0): bool
    {
        $db = App::db();
        $batch = max(50, min(1000, (int) $job['options']['batch']));
        $rows = $db->pairs('SELECT n, data FROM import_rows WHERE job_id = ? AND n > ? ORDER BY n LIMIT ' . $batch, [$job['id'], $job['last_n']]);
        if (!$rows) {
            if ($job['processed'] < $job['total']) {       // разобранные строки удалены уборкой (задание не двигалось 7 дней)
                $db->insert('import_errors', ['job_id' => $job['id'], 'n' => 0, 'level' => 'error',
                    'message' => 'Не обработано строк: ' . ($job['total'] - $job['processed']) . ' — разобранные строки удалены (задание не продолжалось больше 7 дней). Загрузите файл заново.']);
                $db->query('UPDATE import_jobs SET error_count = error_count + 1 WHERE id = ?', [$job['id']]);
            }
            $db->update('import_jobs', ['status' => 'finishing', 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$job['id']]);
            return true;
        }
        $data = [];
        foreach ($rows as $n => $json) $data[(int) $n] = json_decode((string) $json, true) ?: [];
        $plan = self::plan($data, $job['mapping'], $job['options']);
        $lastN = (int) max(array_keys($data));
        $isLast = count($data) < $batch || !$db->value('SELECT 1 FROM import_rows WHERE job_id = ? AND n > ? LIMIT 1', [$job['id'], $lastN]);

        $st = $job['state'];
        if ((int) $job['processed'] === 0) $st['perbatch'] = 1;              // задания, начатые до этой версии, — по-старому (в конце)
        $upd = []; $expect = 0;
        foreach ($plan['items'] as $it) {
            if ($it['action'] === 'update') $upd[] = (int) $it['id'];
            if ($it['action'] === 'update' || $it['action'] === 'create') $expect++;
        }
        $inc = !empty($st['perbatch']) && ($st['reindex'] ?? '') !== 'full';
        if ($inc && $expect && (int) ($st['indexed'] ?? 0) + $expect > self::FULL_REINDEX_FROM) {
            $inc = false;
            $st['reindex'] = 'full';                                          // много изменений — в конце перестроить все категории
        }
        $run = static fn() => $db->transaction(static function () use ($db, $plan, $job, $lastN, $isLast, $upd, $inc, $st): void {
            $snap = $inc ? CatalogIndexer::snapshot($upd) : null;
            $changed = self::apply($plan, $job, $lastN, $isLast);
            if ($inc && $changed) {
                // созданные товары — в снимок «пустыми»: до пачки их не было (ни фильтров, ни бренда),
                // иначе индексатор пересчитал бы счётчики всех брендов
                $snap['ids'] = array_values(array_unique(array_merge($snap['ids'], $changed)));
                $t = microtime(true);
                CatalogIndexer::products($changed, $snap, false);           // кэш сайта сбросит finish()
                $st['indexed'] = (int) ($st['indexed'] ?? 0) + count($changed);
                $st['reindex'] = 'partial';
                $st['reindex_ms'] = (int) ($st['reindex_ms'] ?? 0) + (int) ((microtime(true) - $t) * 1000);
            }
            if ($st !== $job['state']) $db->update('import_jobs', ['state' => self::json($st)], 'id = ?', [(int) $job['id']]);
        });
        if (!$inc) { $run(); return true; }                              // индекс не трогаем — блокировка не нужна
        return CatalogIndexer::locked($run, $wait, false);
    }

    /**
     * Завершение (может занять несколько шагов): скрыть отсутствующие товары поставщика и обновить
     * их в индексе (точечно), при большом числе изменений — перестроить все категории (порциями, чтобы
     * шаг укладывался в бюджет времени), пересчитать счётчики и сбросить кэш.
     * false — идёт перестройка индекса (bin/reindex.php), не дождались её: шаг повторится.
     */
    private static function finish(array $job, float $budget): bool
    {
        $db = App::db();
        $jobId = (int) $job['id'];
        $opt = $job['options'];
        $state = $job['state'];
        // скрытие, его индексация и план перестройки — одной транзакцией: после обрыва шаг повторится целиком
        // (иначе скрытые товары уже не «отсутствующие» и остались бы в индексе); блокировка записи индекса — до транзакции
        $hide = static function () use ($db, $job, $jobId, &$state): void { $db->transaction(static function () use ($db, $job, $jobId, &$state): void {
            $state = self::hideMissing($job);
            $hidden = $state['hidden_ids'] ?? [];
            unset($state['hidden_ids']);
            if (!empty($state['perbatch'])) {                                 // изменённые товары уже в индексе (по пачкам)
                $full = ($state['reindex'] ?? '') === 'full' || count($hidden) > self::FULL_REINDEX_FROM;
                if (!$full && $hidden) {
                    $t = microtime(true);
                    CatalogIndexer::products($hidden, CatalogIndexer::snapshot($hidden), false);   // скрытие не меняет характеристик и бренда
                    $state['reindex'] = 'partial';
                    $state['reindex_ms'] = (int) ($state['reindex_ms'] ?? 0) + (int) ((microtime(true) - $t) * 1000);
                }
                $state['reindex'] = $full ? 'full' : ($state['reindex'] ?? 'none');
                $state['reindex_queue'] = $full ? self::affectedCategories([], true) : [];
            } else {                                                          // задание начато до индексации по пачкам
                $changed = array_map('intval', $db->col('SELECT product_id FROM import_seen WHERE job_id = ? AND changed = 1', [$jobId]));
                $ids = array_values(array_unique(array_merge($changed, $hidden)));
                $full = count($ids) > self::FULL_REINDEX_FROM;
                $state['reindex'] = !$ids ? 'none' : ($full ? 'full' : 'partial');
                $state['reindex_queue'] = $ids ? self::affectedCategories($ids, $full) : [];
                $state['reindex_ms'] = 0;
            }
            $state['reindex_total'] = count($state['reindex_queue']);
            self::saveState($jobId, $state);
        }); };
        if (!isset($state['reindex_queue']) && !CatalogIndexer::locked($hide, min(5.0, $budget / 2), false)) return false;
        // перестройка категорий порциями: одна категория на 50 тыс. товаров — 1–2 с. Под общей блокировкой с
        // bin/reindex.php и кнопкой «Перестроить индекс» (CatalogIndexer::exclusive): идёт другая перестройка —
        // ждём до min(5 с, половина бюджета шага), не дождались — продолжим на следующем шаге (очередь сохранена)
        $t = microtime(true);
        $queue = $state['reindex_queue'];
        if ($queue || !empty($state['reindex_total'])) {
            $ok = CatalogIndexer::exclusive(static function () use ($db, &$queue, &$state, $t, $budget): void {
                if ($queue) {
                    $all = $db->all('SELECT id, parent_id, lft, rgt, type, conditions, include_sub, status FROM categories');
                    $byId = array_column($all, null, 'id');
                    while ($queue && microtime(true) - $t < $budget) {
                        $cid = (int) array_shift($queue);
                        if (isset($byId[$cid])) CatalogIndexer::rebuildCategory($byId[$cid], $all);
                    }
                }
                if (!$queue && !empty($state['reindex_total'])) {                 // категории перестраивались целиком
                    if (($state['reindex'] ?? '') === 'full') CatalogIndexer::rebuildSignatures();   // все учтены по текущим характеристикам
                    CatalogIndexer::updateCounters();
                }
            }, min(5.0, $budget / 2));
            if (!$ok) return false;                                           // не дождались — очередь сохранена, шаг повторится
        }
        $state['reindex_queue'] = $queue;
        if ($queue) {
            $state['reindex_ms'] = (int) ($state['reindex_ms'] ?? 0) + (int) ((microtime(true) - $t) * 1000);
            self::saveState($jobId, $state);
            return true;
        }
        $state['reindex_ms'] = (int) ($state['reindex_ms'] ?? 0) + (int) ((microtime(true) - $t) * 1000);
        if (($state['reindex'] ?? 'none') !== 'none') Cache::flush();
        unset($state['reindex_queue']);
        $pending = (int) $db->value('SELECT COUNT(*) FROM import_images WHERE job_id = ? AND status = 0', [$jobId]);
        $next = $pending && !empty($opt['images']) ? 'images' : 'done';
        $db->update('import_jobs', ['status' => $next, 'state' => self::json($state), 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$jobId]);
        if ($next === 'done') self::done($jobId, false);
        return true;
    }

    /**
     * Категории, индекс которых надо перестроить (как в CatalogIndexer::products): прямые категории
     * товаров и их предки, категории, где товары уже были в индексе, и все динамические.
     * При большом числе товаров — все категории (как CatalogIndexer::rebuildAll, но без TRUNCATE:
     * витрина не остаётся с пустым каталогом, пока идёт перестройка).
     */
    private static function affectedCategories(array $ids, bool $full): array
    {
        $db = App::db();
        $all = $db->all('SELECT id, lft, rgt, type FROM categories ORDER BY lft');
        if ($full) return array_map(static fn($c) => (int) $c['id'], $all);
        $direct = []; $affected = [];
        foreach (array_chunk($ids, 1000) as $part) {
            [$ph, $vals] = $db->in($part);
            foreach ($db->col("SELECT DISTINCT category_id FROM category_products WHERE product_id IN ($ph)", $vals) as $c) $direct[(int) $c] = 1;
            foreach ($db->col("SELECT DISTINCT category_id FROM catalog_index WHERE product_id IN ($ph)", $vals) as $c) $affected[(int) $c] = 1;
        }
        $byId = array_column($all, null, 'id');
        foreach (array_keys($direct) as $cid) {
            $affected[$cid] = 1;
            $x = $byId[$cid] ?? null;
            if (!$x) continue;
            foreach ($all as $a) {
                if ((int) $a['lft'] < (int) $x['lft'] && (int) $a['rgt'] > (int) $x['rgt']) $affected[(int) $a['id']] = 1;
            }
        }
        foreach ($all as $a) if ((int) $a['type'] === 1) $affected[(int) $a['id']] = 1;
        // сначала маленькие (вложенные) категории — порядок по дереву снизу вверх
        $out = [];
        foreach (array_reverse($all) as $a) if (isset($affected[(int) $a['id']])) $out[] = (int) $a['id'];
        return $out;
    }

    /** «Скрыть товары поставщика, которых нет в файле» — с защитой от неполного файла. @return array state задания */
    private static function hideMissing(array $job): array
    {
        $db = App::db();
        $jobId = (int) $job['id'];
        $opt = $job['options'];
        $state = $job['state'];
        $hiddenIds = [];
        $supplier = trim((string) $opt['supplier']);
        if (!empty($opt['hide_missing']) && $supplier !== '') {
            $seenOwn = (int) $db->value('SELECT COUNT(*) FROM import_seen s JOIN products p ON p.id = s.product_id WHERE s.job_id = ? AND p.supplier = ?', [$jobId, $supplier]);
            $active = (int) $db->value('SELECT COUNT(*) FROM products WHERE supplier = ? AND status = 1', [$supplier]);
            $missing = array_map('intval', $db->col('SELECT p.id FROM products p LEFT JOIN import_seen s ON s.job_id = ? AND s.product_id = p.id
                WHERE p.supplier = ? AND p.status = 1 AND s.product_id IS NULL', [$jobId, $supplier]));
            if ($seenOwn === 0) {
                $state['hide_note'] = 'Скрытие отменено: в файле не найдено ни одного товара поставщика «' . $supplier . '».';
            } elseif ($missing && count($missing) > $active * 0.5) {
                $state['hide_note'] = 'Скрытие отменено: пришлось бы скрыть ' . count($missing) . ' из ' . $active . ' товаров поставщика — похоже, файл неполный.';
            } elseif ($missing) {
                foreach (array_chunk($missing, 1000) as $part) {
                    [$ph, $vals] = $db->in($part);
                    $db->query("UPDATE products SET status = 0, updated_at = NOW() WHERE id IN ($ph)", $vals);
                }
                $hiddenIds = $missing;
            }
            $state['hidden'] = count($hiddenIds);
        }
        $state['hidden_ids'] = $hiddenIds;
        return $state;
    }

    /** Задание завершено: сброс кэша (новые фото), удаление разобранных строк (файл остаётся 7 дней) */
    private static function done(int $jobId, bool $flush = true): void
    {
        $db = App::db();
        $db->query('UPDATE import_images SET status = 3 WHERE job_id = ? AND status = 0', [$jobId]);
        if ($flush && (int) $db->value('SELECT COUNT(*) FROM import_images WHERE job_id = ? AND status = 1', [$jobId])) Cache::flush();
        $db->update('import_jobs', ['status' => 'done', 'finished_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$jobId]);
        $db->query('DELETE FROM import_rows WHERE job_id = ?', [$jobId]);
    }

    /** Пропустить оставшиеся фото (кнопка «Не загружать фото») */
    public static function skipImages(int $jobId): void
    {
        $job = self::job($jobId);
        if ($job && $job['status'] === 'images') self::done($jobId);
        elseif ($job) App::db()->query('UPDATE import_images SET status = 3 WHERE job_id = ? AND status = 0', [$jobId]);
    }

    /** Состояние задания для прогресс-бара */
    public static function progress(?array $job): array
    {
        if (!$job) return ['ok' => false, 'error' => 'Задание не найдено', 'done' => true];
        $img = ['total' => 0, 'done' => 0, 'failed' => 0, 'pending' => 0];
        if (!in_array($job['status'], ['parsing', 'new'], true)) {
            foreach (App::db()->pairs('SELECT status, COUNT(*) FROM import_images WHERE job_id = ? GROUP BY status', [$job['id']]) as $s => $c) {
                $img['total'] += (int) $c;
                $key = [0 => 'pending', 1 => 'done', 2 => 'failed', 3 => 'skipped'][(int) $s] ?? 'failed';
                $img[$key] = ($img[$key] ?? 0) + (int) $c;
            }
        }
        $total = (int) $job['total'];
        $percent = 0;
        if ($job['status'] === 'parsing') {
            $size = @filesize(self::dir() . '/' . basename((string) $job['file'])) ?: 0;
            $off = (int) ($job['state']['parse']['offset'] ?? 0);
            $percent = $size && $job['format'] === 'csv' ? (int) floor($off / $size * 100) : 0;
        } elseif ($job['status'] === 'images' && $img['total']) {
            $percent = (int) floor(($img['total'] - $img['pending']) / $img['total'] * 100);
        } elseif ($job['status'] === 'finishing' && !empty($job['state']['reindex_total'])) {
            $rt = (int) $job['state']['reindex_total'];
            $percent = (int) floor(($rt - count($job['state']['reindex_queue'] ?? [])) / $rt * 100);
        } elseif ($total) {
            $percent = in_array($job['status'], ['done', 'finishing', 'images'], true) ? 100 : (int) floor($job['processed'] / $total * 100);
        }
        return [
            'ok' => $job['status'] !== 'error', 'id' => (int) $job['id'], 'status' => $job['status'], 'label' => self::STATUSES[$job['status']] ?? $job['status'],
            'total' => $total, 'processed' => (int) $job['processed'], 'created' => (int) $job['created'], 'updated' => (int) $job['updated'],
            'unchanged' => (int) $job['unchanged'], 'skipped' => (int) $job['skipped'], 'errors' => (int) $job['error_count'],
            'images' => $img, 'percent' => min(100, $percent), 'done' => in_array($job['status'], ['done', 'error'], true),
            'error' => $job['status'] === 'error' ? (string) $job['errors'] : null,
            'step_ms' => (int) ($job['state']['last_step_ms'] ?? 0), 'time_ms' => (int) ($job['state']['time_ms'] ?? 0),
            'note' => $job['status'] === 'finishing' && !empty($job['state']['reindex_total'])
                ? 'Обновление каталога: категорий ' . ((int) $job['state']['reindex_total'] - count($job['state']['reindex_queue'] ?? [])) . ' из ' . (int) $job['state']['reindex_total']
                : ($job['status'] === 'images' ? 'Загрузка фото по ссылкам: ' . ($img['total'] - $img['pending']) . ' из ' . $img['total'] : null),
        ];
    }

    // ============================================================ предпросмотр

    /** Как будут обработаны первые строки (без записи в базу) */
    public static function preview(array $job, int $limit = 20): array
    {
        $rows = self::sample((int) $job['id'], $limit);
        $plan = self::plan($rows, $job['mapping'], $job['options']);
        $out = []; $sum = []; $badCats = [];
        foreach ($plan['items'] as $it) {
            $sum[$it['action']] = ($sum[$it['action']] ?? 0) + 1;
            if (!empty($it['badcat'])) $badCats[self::nk($it['badcat'])] = $it['badcat'];
            $s = $it['show'];
            $out[] = [
                'n' => $it['n'], 'action' => $it['action'], 'msg' => $it['msg'], 'warn' => implode('; ', $it['warn']),
                'id' => $it['id'], 'name' => $s['name'] ?? ($it['rec']['label'] ?? ''), 'price' => isset($s['price']) ? round((float) $s['price'], 2) : null,
                'box_qty' => $s['box_qty'] ?? null, 'category' => $s['category'] ?? '', 'brand' => ($s['brand'] ?? '') . (!empty($s['brand_new']) ? ' (новый)' : ''),
                'size' => $s['size'] ?? '', 'in_stock' => $s['in_stock'] ?? null, 'images' => $s['images'] ?? 0, 'url' => $s['url'] ?? '',
                'key' => self::keyValue($it['rec'], (string) $job['options']['key']), 'changes' => $it['changes'],
            ];
        }
        return ['rows' => $out, 'summary' => $sum, 'badCategories' => array_values(array_filter($badCats))];
    }

    // ============================================================ загрузка по ссылкам (фото, файлы)

    /**
     * Проверка адреса: только http(s), хост должен указывать на публичный IP (защита от обращений
     * к внутренним адресам сервера). @return array{0:bool, 1:string, 2:string} [ok, ошибка, строка для CURLOPT_RESOLVE]
     */
    private static function safeUrl(string $url, array &$dns): array
    {
        $p = parse_url($url);
        if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) return [false, 'неверная ссылка', ''];
        $host = strtolower(trim($p['host'], '[]'));
        // IPv6-адрес в ссылке (в т. ч. «::ffff:127.0.0.1» — это IPv4 сервера) не проверить надёжно; поставщики дают имя сайта
        if (str_contains($host, ':')) return [false, 'ссылка с IPv6-адресом не поддерживается — укажите имя сайта', ''];
        $port = (int) ($p['port'] ?? (strtolower($p['scheme']) === 'https' ? 443 : 80));
        if (!isset($dns[$host])) {
            $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : @gethostbynamel($host);
            $dns[$host] = $ips ? (string) $ips[0] : '';
        }
        $ip = $dns[$host];
        if ($ip === '') return [false, 'сайт не найден (' . $host . ')', ''];
        // + FILTER_FLAG_GLOBAL_RANGE (PHP 8.2+): ещё 100.64/10 (CGNAT, метаданные некоторых облаков), 192.0.0/24, 198.18/15 и т. п.
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | (defined('FILTER_FLAG_GLOBAL_RANGE') ? FILTER_FLAG_GLOBAL_RANGE : 0);
        if (!filter_var($ip, FILTER_VALIDATE_IP, $flags) || preg_match('/^(22[4-9]|23\d)\./', $ip)) return [false, 'внутренний адрес запрещён', ''];
        return [true, '', $host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)];
    }

    /**
     * Скачать несколько ссылок параллельно (curl_multi), каждая — с таймаутом и лимитом размера.
     * Редиректы (до 3) проверяются так же, как исходная ссылка.
     * @return array [ключ => ['file' => путь, 'type' => content-type] | ['error' => текст]]
     */
    public static function fetchMany(array $urls, int $maxBytes, int $timeout, bool $imagesOnly, ?float $maxTime = null): array
    {
        $out = [];
        $dir = self::dir() . '/tmp';
        // хостинг может отключить только curl_multi_exec (disable_functions) — тогда тоже по одной ссылке
        if (!function_exists('curl_multi_init') || !function_exists('curl_multi_exec')) return self::fetchPlain($urls, $maxBytes, $timeout, $imagesOnly);
        $mh = curl_multi_init();
        $dns = [];
        $jobs = [];
        $add = static function ($key, string $url, int $hops) use (&$jobs, &$out, &$dns, $mh, $dir, $maxBytes, $timeout): void {
            [$ok, $err, $resolve] = self::safeUrl($url, $dns);
            if (!$ok) { $out[$key] = ['error' => $err]; return; }
            $tmp = (string) tempnam($dir, 'dl');
            $fh = fopen($tmp, 'wb');
            $size = 0;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                CURLOPT_TIMEOUT => $timeout,          // на одну ссылку; общий лимит — $deadline ниже
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_RESOLVE => [$resolve],
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TomobuvImport/1.0)',
                CURLOPT_ENCODING => '',
                CURLOPT_MAXFILESIZE => $maxBytes,
                CURLOPT_HEADER => false,
                CURLOPT_WRITEFUNCTION => static function ($ch, string $data) use ($fh, &$size, $maxBytes): int {
                    $size += strlen($data);
                    if ($size > $maxBytes) return 0;                     // прервать: слишком большой файл
                    return (int) fwrite($fh, $data);
                },
            ]);
            curl_multi_add_handle($mh, $ch);
            $jobs[spl_object_id($ch)] = ['ch' => $ch, 'key' => $key, 'url' => $url, 'hops' => $hops, 'tmp' => $tmp, 'fh' => $fh];
        };
        foreach ($urls as $k => $u) $add($k, (string) $u, 0);
        $deadline = microtime(true) + ($maxTime ?? $timeout * 2 + 5);    // общий лимит на все ссылки (редиректы — повторные запросы)
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 0.5);
            while (($info = curl_multi_info_read($mh)) !== false) {
                $ch = $info['handle'];
                $j = $jobs[spl_object_id($ch)] ?? null;
                if (!$j) continue;
                unset($jobs[spl_object_id($ch)]);
                fclose($j['fh']);
                $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $type = strtolower((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
                $loc = (string) (curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: '');
                $cerr = $info['result'] !== CURLE_OK ? curl_error($ch) : '';
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                if ($code >= 300 && $code < 400 && $loc !== '' && $j['hops'] < 3) {
                    @unlink($j['tmp']);
                    $add($j['key'], $loc, $j['hops'] + 1);
                    $running = true;
                    continue;
                }
                if ($cerr !== '' || $code !== 200) {
                    @unlink($j['tmp']);
                    $out[$j['key']] = ['error' => $cerr !== '' ? (str_contains($cerr, 'size') || str_contains($cerr, 'Failed writing') ? 'файл больше ' . round($maxBytes / 1048576) . ' МБ' : $cerr) : 'ответ сервера ' . $code];
                    continue;
                }
                if ($imagesOnly && !str_starts_with($type, 'image/')) {
                    @unlink($j['tmp']);
                    $out[$j['key']] = ['error' => 'по ссылке не картинка (' . ($type ?: 'тип не указан') . ')'];
                    continue;
                }
                $out[$j['key']] = ['file' => $j['tmp'], 'type' => $type];
            }
            if (microtime(true) > $deadline) break;
        } while ($running || $jobs);
        foreach ($jobs as $j) {                                              // не уложились в общий лимит времени
            curl_multi_remove_handle($mh, $j['ch']);
            curl_close($j['ch']);
            @fclose($j['fh']);
            @unlink($j['tmp']);
            $out[$j['key']] = ['error' => 'превышено время ожидания', 'retry' => true];
        }
        curl_multi_close($mh);
        return $out;
    }

    /** Запасной вариант без curl: по одной ссылке через потоки PHP */
    private static function fetchPlain(array $urls, int $maxBytes, int $timeout, bool $imagesOnly): array
    {
        $out = []; $dns = [];
        foreach ($urls as $k => $u) {
            [$ok, $err] = self::safeUrl((string) $u, $dns);
            if (!$ok) { $out[$k] = ['error' => $err]; continue; }
            $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'follow_location' => 0, 'user_agent' => 'Mozilla/5.0 (compatible; TomobuvImport/1.0)']]);
            $in = @fopen((string) $u, 'rb', false, $ctx);
            if (!$in) { $out[$k] = ['error' => 'не удалось скачать']; continue; }
            $tmp = (string) tempnam(self::dir() . '/tmp', 'dl');
            $size = @stream_copy_to_stream($in, $fo = fopen($tmp, 'wb'), $maxBytes + 1);
            fclose($in); fclose($fo);
            if ($size === false || $size > $maxBytes) { @unlink($tmp); $out[$k] = ['error' => 'файл слишком большой']; continue; }
            if ($imagesOnly && !@getimagesize($tmp)) { @unlink($tmp); $out[$k] = ['error' => 'по ссылке не картинка']; continue; }
            $out[$k] = ['file' => $tmp, 'type' => ''];
        }
        return $out;
    }

    /** Скачать очередную порцию фото. @return int сколько фото было в работе (0 — очередь пуста) */
    private static function downloadImages(int $jobId, int $limit, float $maxTime): int
    {
        $db = App::db();
        $items = $db->all('SELECT id, product_id, n, url, sort FROM import_images WHERE job_id = ? AND status = 0 ORDER BY id LIMIT ' . max(1, $limit), [$jobId]);
        if (!$items) return 0;
        $maxTime = max(3.0, $maxTime);
        $res = self::fetchMany(array_column($items, 'url', 'id'), self::IMG_MAX_BYTES, (int) min(self::IMG_TIMEOUT, ceil($maxTime)), true, $maxTime);
        $ok = []; $bad = []; $pids = []; $errs = [];
        // не успели за время шага — такие ссылки повторяются следующим шагом (если шаг хоть что-то скачал, иначе — ошибка)
        $progress = count(array_filter($res, static fn($r) => empty($r['retry'])));
        foreach ($items as $it) {
            $r = $res[$it['id']] ?? ['error' => 'не скачано'];
            if (!empty($r['retry']) && $progress > 0) continue;
            $err = $r['error'] ?? null;
            if ($err === null) {
                $err = self::storeImage((int) $it['product_id'], (string) $r['file'], (int) $it['sort']);
                @unlink((string) $r['file']);
            }
            if ($err === null) { $ok[] = (int) $it['id']; $pids[(int) $it['product_id']] = 1; }
            else {
                $bad[] = (int) $it['id'];
                $errs[] = ['job_id' => $jobId, 'n' => (int) $it['n'], 'level' => 'warn', 'message' => mb_substr('Фото не загружено: ' . $err . ' — ' . $it['url'], 0, 500)];
            }
        }
        if ($ok) { [$ph, $v] = $db->in($ok); $db->query("UPDATE import_images SET status = 1 WHERE id IN ($ph)", $v); }
        if ($bad) { [$ph, $v] = $db->in($bad); $db->query("UPDATE import_images SET status = 2 WHERE id IN ($ph)", $v); }
        $db->insertMany('import_errors', $errs);
        if ($pids) {                                                          // главное фото — первое по порядку
            [$ph, $v] = $db->in(array_keys($pids));
            $db->query("UPDATE products p SET
                p.image_id = (SELECT i.id FROM product_images i WHERE i.product_id = p.id ORDER BY i.sort, i.id LIMIT 1),
                p.image_ext = (SELECT i.ext FROM product_images i WHERE i.product_id = p.id ORDER BY i.sort, i.id LIMIT 1)
                WHERE p.id IN ($ph) AND p.image_id IS NULL", $v);
        }
        return count($items);
    }

    /**
     * Сохранить скачанную картинку как оригинал фото товара (путь как в Webasyst):
     * проверка getimagesize, пересохранение через GD (отсекает всё, кроме самой картинки).
     * @return ?string ошибка или null
     */
    private static function storeImage(int $productId, string $tmp, int $sort): ?string
    {
        $info = @getimagesize($tmp);
        if (!$info) return 'файл не является картинкой';
        $mime = (string) $info['mime'];
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime] ?? null;
        if ($ext === null) return 'неподдерживаемый формат ' . $mime;
        [$w, $h] = $info;
        if ($w < 10 || $h < 10 || $w * $h > 30000000) return 'недопустимый размер ' . $w . '×' . $h;
        if (!function_exists('imagecreatetruecolor')) return 'на сервере нет GD';
        $img = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($tmp),
            'image/png'  => @imagecreatefrompng($tmp),
            'image/gif'  => @imagecreatefromgif($tmp),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
            default      => false,
        };
        if (!$img) return 'картинка повреждена';
        if ($ext === 'webp' && !function_exists('imagewebp')) $ext = 'jpg';
        if ($ext === 'gif') $ext = 'png';
        $db = App::db();
        $iid = $db->insert('product_images', ['product_id' => $productId, 'sort' => $sort, 'ext' => $ext, 'width' => min(65535, $w),
            'height' => min(65535, $h), 'filename' => '', 'description' => null, 'created_at' => date('Y-m-d H:i:s')]);
        $dst = Image::originalPath($productId, $iid, $ext);
        @mkdir(dirname($dst), 0775, true);
        if ($ext !== 'jpg') { imagealphablending($img, false); imagesavealpha($img, true); }
        $q = (int) App::config('images.jpeg_quality', 85);
        $saved = match ($ext) {
            'png'  => @imagepng($img, $dst, 6),
            'webp' => @imagewebp($img, $dst, $q),
            default => @imagejpeg($img, $dst, 90),
        };
        imagedestroy($img);
        if (!$saved) {
            $db->delete('product_images', 'id = ?', [$iid]);
            return 'не удалось сохранить файл';
        }
        return null;
    }

    /** Скачать файл поставщика по ссылке (фид YML/XML, CSV, XLSX) в storage/import */
    public static function download(string $url, string $dest): void
    {
        $r = self::fetchMany(['f' => $url], self::maxFileSize(), 120, false)['f'] ?? ['error' => 'не скачано'];
        if (isset($r['error'])) throw new \RuntimeException('Не удалось скачать файл: ' . $r['error']);
        if (!@rename($r['file'], $dest)) { @copy($r['file'], $dest); @unlink($r['file']); }
    }
}
