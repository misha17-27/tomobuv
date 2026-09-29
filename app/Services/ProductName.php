<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;

/**
 * Автоназвание товара. Обычные товары называются «Тип Бренд Артикул» («Кроссовки Baas L1873-1»), но часть товаров
 * заводят одним кодом («60189A», «B31127-3», «68188С»). Такое «голое» название дополняется до
 * «{Категория} {Бренд} {Код}» → «Зимняя обувь Tom.m 60189A» (UA: «Зимове взуття Tom.m 60189A»).
 *
 * Где применяется: сохранение товара в админке, импорт прайсов (новые товары и явная смена названия на код),
 * перенос с Webasyst (после шага products) и bin/product-names.php для уже заведённых товаров.
 * Адрес товара (url) правило не меняет. Названия, в которых уже есть слово, не трогаются.
 */
final class ProductName
{
    /** Максимальная длина products.name / name_uk */
    public const MAX = 255;

    /** Бренды-заглушки: в название не добавляются */
    private const NO_BRAND = ['не указано', 'не указан', 'не вказано', 'без бренда', 'нет'];

    /** После scan(): товары с названием-кодом, которым менять нечего [id => название] (нет категории, бренда нет или он уже в названии) */
    public static array $bareLeft = [];

    /** Справочники [id => row | false] — загружаются один раз за запрос, недостающие id догружаются по одному */
    private static ?array $cats = null;
    private static ?array $brands = null;

    /** Название-код: нет ни одного слова из 3+ кириллических букв («60189A», «B31127-3», «68188С» с кириллической С) */
    public static function isBare(?string $name): bool
    {
        $name = trim((string) $name);
        return $name !== '' && !preg_match('/\p{Cyrillic}{3,}/u', $name);
    }

    /**
     * Собранное название: [name, name_uk, код]. $p — name (код), category_id, brand_id;
     * brand_name — бренд, которого ещё нет в базе (импорт создаёт его при записи пачки).
     * Повторная сборка не удваивает части: «Tom.m 68190W» (товар без категории) остаётся «Tom.m 68190W».
     */
    public static function build(array $p): array
    {
        [$cat, $brand] = self::parts($p);
        $code = self::code((string) ($p['name'] ?? ''), $cat, $brand);
        return [self::join([$cat[0] ?? '', $brand[0] ?? ''], $code), self::join([$cat[1] ?? '', $brand[1] ?? ''], $code), $code];
    }

    /**
     * Что поменять у товара с названием-кодом: ['name' => …, 'name_uk' => …, 'sku' => …] — только отличающиеся поля.
     * name_uk — если пустое или тоже код (своё украинское название не трогаем); пустое остаётся пустым, когда совпадает
     * с русским (витрина /ua/ и так покажет русское). sku — код, если артикул пуст, а код — одно слово с цифрой.
     * $old — товар из базы (правка в админке): если его уже назвало правило, то UA-название и артикул, которые записало
     * правило и которые в $p не меняли, собираются заново вместе с русским — иначе после нового кода или категории
     * осталось бы «Кроссовки Tom.m 60189R» с UA «Зимове взуття Tom.m 60189Q» и артикулом 60189Q.
     */
    public static function fix(array $p, ?array $old = null): array
    {
        $name = (string) ($p['name'] ?? '');
        if (!self::isBare($name)) return [];
        $own = [];                                                   // [поле => прежнее значение правила]
        if ($old !== null && ($gen = self::generated($old)) !== null) {
            if ($gen[1] !== $gen[0] && self::norm((string) ($p['name_uk'] ?? '')) === $gen[1]) { $own['name_uk'] = $p['name_uk']; $p['name_uk'] = ''; }
            if (self::norm((string) ($p['sku'] ?? '')) === $gen[2]) { $own['sku'] = $p['sku']; $p['sku'] = ''; }
        }
        [$ru, $uk, $code] = self::build($p);
        $out = [];
        if ($ru !== $name) $out['name'] = $ru;
        $ukOld = trim((string) ($p['name_uk'] ?? ''));
        if ($ukOld === '' || self::isBare($ukOld)) {
            $ukNew = $uk !== $ru || $ukOld !== '' ? $uk : null;
            if ($ukNew !== null && $ukNew !== $ukOld) $out['name_uk'] = $ukNew;
        }
        // одно «слово» с цифрой («60189A», «B31127-3»); латинское название из слов («Nike Air Max 90») артикулом не становится
        if (trim((string) ($p['sku'] ?? '')) === '' && preg_match('/\d/', $code) && !str_contains($code, ' ')) $out['sku'] = $code;
        // поле правила: сборка дала то же — не менять; иначе новое значение или пусто (UA — как русское, артикул — без кода)
        foreach ($own as $k => $v) {
            $new = $out[$k] ?? ($k === 'sku' ? '' : null);
            if ((string) $new === (string) $v) unset($out[$k]);
            else $out[$k] = $new;
        }
        return $out;
    }

    /**
     * Товар переименован этим правилом: название = сборка для текущих категории и бренда, артикул = код
     * (правило записывает код в пустой артикул). [name, name_uk, код] или null.
     * Нужен переводам: bin/i18n-seed-uk.php берёт UA-название отсюда (name_uk категории и бренда), а не из словаря.
     * Без условия на артикул сюда попали бы и обычные «Кроссовки Baas L1873-1» (у них артикул — размерный ряд).
     */
    public static function generated(array $p): ?array
    {
        $sku = self::norm((string) ($p['sku'] ?? ''));
        $name = self::norm((string) ($p['name'] ?? ''));
        if ($sku === '' || !str_ends_with(mb_strtolower($name), ' ' . mb_strtolower($sku))) return null;
        [$ru, $uk, $code] = self::build(['name' => $sku] + $p);
        return $ru === $name && $code === $sku && self::isBare($code) ? [$ru, $uk, $code] : null;
    }

    /**
     * Все товары с названием-кодом, которым правило что-то меняет: [id => ['old' => строка товара, 'set' => поля]].
     * $apply — записать (updated_at = сейчас; url не меняется). Индекс каталога и кэш — на вызывающем.
     * Товары с названием-кодом, которым менять нечего, — в ProductName::$bareLeft.
     */
    public static function scan(bool $apply = false): array
    {
        $db = App::db();
        $out = [];
        $last = 0;
        self::$bareLeft = [];
        while (true) {
            // название классифицируется в PHP: REGEXP с кириллицей в MariaDB 10.3 ненадёжен
            $rows = $db->query('SELECT id, url, name, name_uk, sku, category_id, brand_id, status, seo_name, h1, meta_title, meta_description, meta_keywords,
                seo_name_uk, h1_uk, meta_title_uk, meta_description_uk, meta_keywords_uk FROM products WHERE id > ? ORDER BY id LIMIT 5000', [$last])->fetchAll();
            if (!$rows) break;
            foreach ($rows as $r) {
                $last = (int) $r['id'];
                if (!self::isBare((string) $r['name'])) continue;
                $set = self::fix($r);
                if ($set) $out[(int) $r['id']] = ['old' => $r, 'set' => $set];
                else self::$bareLeft[(int) $r['id']] = (string) $r['name'];
            }
        }
        if ($apply && $out) {
            $now = date('Y-m-d H:i:s');
            foreach (array_chunk($out, 200, true) as $part) {
                $db->transaction(static function ($db) use ($part, $now): void {
                    foreach ($part as $id => $x) $db->update('products', $x['set'] + ['updated_at' => $now], 'id = ?', [$id]);
                });
            }
        }
        return $out;
    }

    /** Сбросить справочники (после переноса категорий и брендов в том же процессе) */
    public static function reset(): void
    {
        self::$cats = null;
        self::$brands = null;
    }

    // ------------------------------------------------------------------ внутреннее

    /** [[категория RU, UA] | [], [бренд RU, UA] | []] */
    private static function parts(array $p): array
    {
        $cat = [];
        $id = (int) ($p['category_id'] ?? 0);
        $guard = 0;
        // основная категория; «32-38» и т.п. (размерная подкатегория, без слова) — ближайший предок со словом
        while ($id && ($c = self::category($id)) && $guard++ < 10) {
            $name = self::norm((string) $c['name']);
            if (preg_match('/\p{L}{3,}/u', $name)) {
                $uk = self::norm((string) ($c['name_uk'] ?? ''));
                $cat = [self::sentence($name), self::sentence($uk !== '' ? $uk : $name)];
                break;
            }
            $id = (int) $c['parent_id'];
        }
        $brand = [];
        $bn = self::norm((string) ($p['brand_name'] ?? ''));
        if ($bn !== '') {
            $brand = [$bn, $bn];
        } elseif (($b = self::brand((int) ($p['brand_id'] ?? 0))) !== null) {
            $name = self::norm((string) $b['name']);
            $uk = self::norm((string) ($b['name_uk'] ?? ''));
            if ($name !== '') $brand = [$name, $uk !== '' ? $uk : $name];
        }
        if ($brand && in_array(mb_strtolower($brand[0]), self::NO_BRAND, true)) $brand = [];
        return [$cat, $brand];
    }

    /** Код из названия: пробелы схлопнуты; уже добавленные категория и бренд в начале снимаются (повторная сборка) */
    private static function code(string $name, array $cat, array $brand): string
    {
        $code = self::norm($name);
        foreach ([$cat, $brand] as $part) {
            foreach (array_unique($part) as $w) {
                if ($w !== '' && mb_strlen($code) > mb_strlen($w) + 1 && mb_strtolower(mb_substr($code, 0, mb_strlen($w) + 1)) === mb_strtolower($w) . ' ') {
                    $code = mb_substr($code, mb_strlen($w) + 1);
                    break;
                }
            }
        }
        return $code;
    }

    /** «Категория Бренд Код»; длиннее колонки — сначала без категории, потом без бренда */
    private static function join(array $parts, string $code): string
    {
        $parts = array_values(array_filter($parts, static fn($s) => $s !== ''));
        while (true) {
            $s = trim(implode(' ', array_merge($parts, [$code])));
            if (mb_strlen($s) <= self::MAX || !$parts) return mb_substr($s, 0, self::MAX);
            array_shift($parts);
        }
    }

    private static function norm(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /** Название начинается с заглавной: «шлепанцы» → «Шлепанцы», «МУЖСКАЯ ОБУВЬ» (корневые категории капсом) → «Мужская обувь» */
    private static function sentence(string $s): string
    {
        if (preg_match('/\p{L}{2,}/u', $s) && mb_strtoupper($s) === $s) $s = mb_strtolower($s);
        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }

    private static function category(int $id): ?array
    {
        if (self::$cats === null) {
            self::$cats = [];
            // query()->fetchAll(): без подстановки *_uk (DB::$localize), нужны обе колонки
            foreach (App::db()->query('SELECT id, parent_id, name, name_uk FROM categories')->fetchAll() as $r) self::$cats[(int) $r['id']] = $r;
        }
        if (!array_key_exists($id, self::$cats)) {
            self::$cats[$id] = App::db()->query('SELECT id, parent_id, name, name_uk FROM categories WHERE id = ?', [$id])->fetch() ?: false;
        }
        return self::$cats[$id] ?: null;
    }

    private static function brand(int $id): ?array
    {
        if (!$id) return null;
        if (self::$brands === null) {
            self::$brands = [];
            foreach (App::db()->query('SELECT id, name, name_uk FROM brands')->fetchAll() as $r) self::$brands[(int) $r['id']] = $r;
        }
        if (!array_key_exists($id, self::$brands)) {
            // бренд создан после загрузки справочника (новое значение «Бренда» в админке, импорт в CLI)
            self::$brands[$id] = App::db()->query('SELECT id, name, name_uk FROM brands WHERE id = ?', [$id])->fetch() ?: false;
        }
        return self::$brands[$id] ?: null;
    }
}
