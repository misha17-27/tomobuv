<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Lang;

/**
 * Переменные SEO-шаблонов — одни и те же для витрины (Front\ProductController, CategoryController, BrandController),
 * SEO-обзора и превью админки (SeoAudit, AdminCatalog) и автоисправления (SeoFix): иначе точки в админке разойдутся с витриной.
 *
 *   product.name, seo_name, sku, price, format_price («450 грн.»; пусто при цене ≤ 0), box_qty (пар в ящике),
 *   sizes — только «чистый» диапазон вида 36-41 (мусор вроде «_-39», «One Size» — пусто);
 *   category.name, seo_name, full_name («Детская обувь: кеды 26-32», «Детская зимняя обувь 12-26»),
 *   root_name (корневой раздел: «Женская обувь»; не про обувь — «Обувь»), product_count;
 *   brand.name, product_count.
 * На /ua/ (Lang::isUk) названия категорий — name_uk, если заполнены (как DB::$localize).
 */
final class SeoVars
{
    /** «язык|id|имя» → полное имя категории */
    private static array $full = [];
    /** Скрытые категории (их нет в кэше справочника Catalog): id → строка; null — ещё не загружены */
    private static ?array $hidden = null;
    /** язык → [полное имя строчными → сколько активных категорий с ним] (различение одинаковых имён) */
    private static array $counts = [];

    public static function product(array $p, ?array $cat): array
    {
        $name = (string) ($p['name'] ?? '');
        $seoName = trim((string) ($p['seo_name'] ?? ''));
        $price = (float) ($p['price'] ?? 0);
        $box = (int) ($p['box_qty'] ?? 0);
        return [
            'product' => [
                'name'         => $name,
                'seo_name'     => $seoName !== '' ? $seoName : $name,
                'format_price' => $price > 0 ? price_format($price) : '',
                'price'        => (string) round($price),
                'sku'          => (string) ($p['sku'] ?? ''),
                'box_qty'      => $box > 0 ? (string) $box : '',
                'sizes'        => self::sizes((string) ($p['size'] ?? '')),
            ],
        ] + self::category($cat);
    }

    /** $c — строка категории (из Catalog или базы); seo_name — своё SEO-название категории, если есть */
    public static function category(?array $c): array
    {
        if (!$c) return ['category' => ['name' => '', 'seo_name' => '', 'full_name' => '', 'root_name' => self::shoes(), 'product_count' => '']];
        $name = (string) ($c['name'] ?? '');
        $seo = trim((string) ($c['seo_name'] ?? ''));
        $count = $c['product_count'] ?? (self::row((int) ($c['id'] ?? 0))['product_count'] ?? 0);
        return ['category' => [
            'name'          => $name,
            'seo_name'      => $seo !== '' ? $seo : $name,
            'full_name'     => self::fullName($c),
            'root_name'     => self::rootName($c),
            'product_count' => (string) (int) $count,
        ]];
    }

    /**
     * Корневой раздел категории для шаблонов keywords: «Детская обувь», «Женская обувь», «Мужская обувь», «Подростковая обувь»
     * (на /ua/ — «Дитяче взуття»…); корень не про обувь («Акция») или категории нет — просто «Обувь» / «Взуття».
     */
    public static function rootName(?array $c): string
    {
        $id = (int) ($c['id'] ?? 0);
        $key = 'root|' . Lang::current() . '|' . $id;
        if ($id && isset(self::$full[$key])) return self::$full[$key];
        $chain = $c ? self::chain($c) : [];
        $root = $chain ? nice_case(trim((string) $chain[0]['name'])) : '';
        $out = self::nounOf($root) !== '' ? $root : self::shoes();
        if ($id) self::$full[$key] = $out;
        return $out;
    }

    public static function brand(array $b): array
    {
        return ['brand' => ['name' => trim((string) ($b['name'] ?? '')), 'product_count' => (string) (int) ($b['product_count'] ?? 0)]];
    }

    /**
     * Полное имя категории: «Корень: подкатегория» — «Детская обувь: кроссовки», у размерной подкатегории (в имени нет слова)
     * — с ближайшим предком со словом: «Детская обувь: кеды 26-32». Корень — в регистре предложения («ДЕТСКАЯ ОБУВЬ» →
     * «Детская обувь»), кириллица подкатегории — строчными. Корневая категория — просто своё имя.
     * Подкатегория со словом «обувь»/«взуття» — без повтора: «Детская зимняя обувь 12-26», «Дитяче зимове взуття»,
     * «Детская обувь для танцев» (а не «Детская обувь: зимняя обувь»). Одинаковое имя у двух категорий на сайте
     * («Чехлы-бахилы» в корне и в «Весна-Осень») — у вложенной добавляется родитель: «Детская обувь: чехлы-бахилы (весна-осень)».
     */
    public static function fullName(?array $c): string
    {
        $id = (int) ($c['id'] ?? 0);
        $key = Lang::current() . '|' . $id . '|' . ($c['name'] ?? '');     // имя — в ключе: превью ещё не сохранённой правки
        if ($id && isset(self::$full[$key])) return self::$full[$key];
        $chain = $c ? self::chain($c) : [];
        if (!$chain) return '';
        [$out, $qualifier] = self::baseName($chain);
        if ($qualifier !== '' && (self::baseCounts()[mb_strtolower($out)] ?? 0) > 1) $out .= ' (' . $qualifier . ')';
        if ($id) self::$full[$key] = $out;
        return $out;
    }

    /** Цепочка категорий от корня до $c (строки на языке версии сайта) */
    private static function chain(array $c): array
    {
        $chain = [];
        $row = $c;
        $guard = 0;
        while ($row && $guard++ < 10) {
            array_unshift($chain, self::loc($row));
            $pid = (int) ($row['parent_id'] ?? (self::row((int) ($row['id'] ?? 0))['parent_id'] ?? 0));
            $row = $pid ? self::row($pid) : null;
        }
        return $chain;
    }

    /**
     * Полное имя без различения одинаковых: [имя, уточнение] — уточнение (ближайший предок между корнем и категорией,
     * которого ещё нет в имени, строчными) добавляется, только если такое же имя есть у другой категории сайта.
     */
    private static function baseName(array $chain): array
    {
        $root = nice_case(trim((string) $chain[0]['name']));
        if (count($chain) < 2) return [$root, ''];
        $last = trim((string) end($chain)['name']);
        $sub = self::lowerCyr($last);
        $used = count($chain) - 1;                  // до какого звена цепочки имя уже взято
        if (!self::hasWord($last)) {
            for ($i = count($chain) - 2; $i >= 1; $i--) {
                $n = trim((string) $chain[$i]['name']);
                if (self::hasWord($n)) { $sub = self::lowerCyr($n) . ' ' . $sub; $used = $i; break; }
            }
        }
        $qualifier = '';
        for ($i = $used - 1; $i >= 1; $i--) {
            $n = trim((string) $chain[$i]['name']);
            if (self::hasWord($n)) { $qualifier = self::lowerCyr($n); break; }
        }
        return [self::join($root, $sub), $qualifier];
    }

    /**
     * «Корень: подкатегория» без повтора слова обуви корня: «Детская обувь» + «зимняя обувь 12-26» → «Детская зимняя обувь 12-26»,
     * + «обувь для танцев» → «Детская обувь для танцев»; без слова обуви — «Детская обувь: кеды 26-32».
     */
    private static function join(string $root, string $sub): string
    {
        $noun = self::nounOf($root);
        $re = '/(?<!\p{L})' . preg_quote($noun, '/') . '(?!\p{L})/iu';
        if ($noun === '' || !preg_match($re, $sub)) return $root . ': ' . $sub;
        if (preg_match('/^' . preg_quote($noun, '/') . '(?!\p{L})\s*(.*)$/iu', $sub, $m)) return trim($root . ' ' . $m[1]);
        $adj = trim(mb_substr($root, 0, mb_strlen($root) - mb_strlen($noun)));
        return $adj !== '' ? $adj . ' ' . $sub : $root . ': ' . $sub;
    }

    /** Слово обуви в конце имени корня («Детская обувь» → «обувь», «Дитяче взуття» → «взуття»), иначе '' */
    private static function nounOf(string $root): string
    {
        return preg_match('/(?<!\p{L})(обувь|взуття)$/iu', trim($root), $m) ? mb_strtolower($m[1]) : '';
    }

    /** «Обувь» / «Взуття» — корень, если категории нет или корень не про обувь */
    private static function shoes(): string
    {
        return Lang::isUk() ? 'Взуття' : 'Обувь';
    }

    /** Сколько активных категорий сайта с каждым полным именем (без уточнений), в версии сайта — один раз на язык */
    private static function baseCounts(): array
    {
        $lang = Lang::current();
        if (isset(self::$counts[$lang])) return self::$counts[$lang];
        $n = [];
        foreach (Catalog::categories() as $c) {
            $name = self::baseName(self::chain($c))[0];
            $k = mb_strtolower($name);
            $n[$k] = ($n[$k] ?? 0) + 1;
        }
        return self::$counts[$lang] = $n;
    }

    /**
     * Категория товара для шаблонов — как Front\ProductController::seoCategory: основная (даже скрытая),
     * у товара без категории — первая в дереве. Без запросов в цикле: скрытые категории загружаются один раз.
     */
    public static function productCategory(array $p): ?array
    {
        $cid = (int) ($p['category_id'] ?? 0);
        $c = $cid ? self::row($cid) : null;
        if (!$c) {
            $all = Catalog::categories();
            $c = $all ? reset($all) : null;
        }
        return $c ? self::loc($c) : null;
    }

    /** Размеры: «36-41», «28-31,5»; всё прочее (мусор, «One Size», «0-12») — пусто */
    public static function sizes(string $s): string
    {
        if (!preg_match('/^\s*(\d{2}(?:[.,]5)?)\s*-\s*(\d{2}(?:[.,]5)?)\s*$/', $s, $m)) return '';
        $a = (float) str_replace(',', '.', $m[1]);
        $b = (float) str_replace(',', '.', $m[2]);
        return ($a >= 15 && $b <= 50 && $a < $b) ? $m[1] . '-' . $m[2] : '';
    }

    /** Сбросить запомненное (после правок категорий в долгом процессе) */
    public static function reset(): void
    {
        self::$full = [];
        self::$hidden = null;
        self::$counts = [];
    }

    /** Строка категории по id: из справочника (активные) или из базы (скрытые, одним запросом на все) */
    private static function row(int $id): ?array
    {
        if (!$id) return null;
        $c = Catalog::category($id);
        if ($c) return $c;
        if (self::$hidden === null) {
            self::$hidden = [];
            foreach (App::db()->query('SELECT id, parent_id, depth, name, name_uk, url, seo_name, seo_name_uk, status, product_count
                FROM categories WHERE status <> 1')->fetchAll() as $r) {
                self::$hidden[(int) $r['id']] = $r;
            }
        }
        return self::$hidden[$id] ?? null;
    }

    /** Строка с украинскими названиями на /ua/ (повторный вызов на уже подставленной строке ничего не меняет) */
    private static function loc(array $row): array
    {
        return Lang::isUk() ? Lang::localize($row) : $row;
    }

    private static function hasWord(string $s): bool
    {
        return (bool) preg_match('/\p{L}{3,}/u', $s);
    }

    private static function lowerCyr(string $s): string
    {
        return (string) preg_replace_callback('/\p{Cyrillic}+/u', static fn($m) => mb_strtolower($m[0]), $s);
    }
}
