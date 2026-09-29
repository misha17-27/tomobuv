<?php
/**
 * Украинские переводы контента → колонки *_uk и настройки '*.uk'.
 * Данные лежат в репозитории: database/i18n/uk/*.php (правятся руками или в админке).
 * Запускать после bin/import-webasyst.php (в т.ч. при финальном переносе в день переключения):
 *
 *   php bin/i18n-seed-uk.php            — всё
 *   php bin/i18n-seed-uk.php products   — только один шаг
 *   php bin/i18n-seed-uk.php --force    — перезаписать и то, что уже переведено вручную в админке
 *
 * Без --force заполняются только пустые *_uk (ручные правки в админке не затираются). Пустыми считаются и SEO-поля *_uk,
 * которые перенос заполнил сам по русскому тексту, пока перевода не было (SeoFix::autoFilledUk), — их заменяет перевод.
 * В конце — SEO-стандарт title/description (App\Services\SeoFix, как php bin/seo-autofix.php; «seo» — только он).
 */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use App\Core\App;
use App\Core\Cache;
use App\Services\ProductName;
use App\Services\SeoFix;

ini_set('memory_limit', '1024M');
if (function_exists('set_time_limit')) set_time_limit(0);

$db = App::db();
$dir = ROOT . '/database/i18n/uk';
$force = in_array('--force', $argv, true);
$only = null;
foreach (array_slice($argv, 1) as $a) if ($a !== '--force') $only = $a;
$t0 = microtime(true);
$say = static fn(string $s) => printf("[%5.1fs] %s\n", microtime(true) - $t0, $s);
$data = static function (string $name) use ($dir): array {
    $f = "$dir/$name.php";
    return is_file($f) ? (array) include $f : [];
};
/** UPDATE только пустых колонок (или всех при --force); $auto — [колонка => true], заполненные автоисправлением без перевода */
$setCols = static function (string $table, string $where, array $params, array $cols, array $auto = []) use ($db, $force): int {
    $cols = array_filter($cols, static fn($v) => $v !== null && $v !== '');
    if (!$cols) return 0;
    $set = [];
    $vals = [];
    foreach ($cols as $c => $v) {
        $set[] = ($force || isset($auto[$c])) ? "`$c` = ?" : "`$c` = IF(`$c` IS NULL OR `$c` = '', ?, `$c`)";
        $vals[] = $v;
    }
    return $db->query("UPDATE `$table` SET " . implode(', ', $set) . " WHERE $where", array_merge($vals, $params))->rowCount();
};

// ---------------------------------------------------------------- словарь слов для названий товаров
$words = $data('product_words');           // ['Кроссовки' => 'Кросівки', …]
uksort($words, static fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));   // длинные фразы раньше
// Слова от 3 букв меняются и вплотную к цифрам («99377-9чёрные», «Туфли68131A», «5пары»),
// короткие («и», «из») — только отдельно стоящие, чтобы не трогать артикулы вида «465И».
$reList = static fn(array $l) => implode('|', array_map(static fn($w) => preg_quote((string) $w, '/'), $l));
$longWords = array_filter(array_keys($words), static fn($w) => mb_strlen((string) $w) >= 3);
$shortWords = array_filter(array_keys($words), static fn($w) => mb_strlen((string) $w) < 3);
$wordRe = $words ? '/' . ($longWords ? '(?<!\p{L})(' . $reList($longWords) . ')(?!\p{L})' : '(?!)')
    . ($shortWords ? '|(?<![\p{L}\p{N}])(' . $reList($shortWords) . ')(?![\p{L}\p{N}])' : '') . '/u' : null;
$lower = [];
foreach ($words as $ru => $uk) $lower[mb_strtolower($ru)] = $uk;
$trWords = static function (?string $s) use ($wordRe, $words, $lower): ?string {
    if ($s === null || $s === '' || !$wordRe) return $s;
    return preg_replace_callback($wordRe . 'i', static function ($m) use ($words, $lower) {
        $w = ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '');
        $uk = $words[$w] ?? $lower[mb_strtolower($w)] ?? $w;
        // сохранить регистр первой буквы
        $first = mb_substr($w, 0, 1);
        if ($first === mb_strtoupper($first)) $uk = mb_strtoupper(mb_substr($uk, 0, 1)) . mb_substr($uk, 1);
        else $uk = mb_strtolower(mb_substr($uk, 0, 1)) . mb_substr($uk, 1);
        return $uk;
    }, $s);
};

$steps = [];

$steps['settings'] = function () use ($db, $data, $force, $say) {
    $n = 0;
    foreach ($data('settings') as $key => $val) {
        if (is_array($val)) $val = json_encode($val, JSON_UNESCAPED_UNICODE);
        $exists = $db->value('SELECT value FROM settings WHERE name = ?', [$key]);
        if (!$force && $exists !== null && $exists !== '') continue;
        $db->upsert('settings', ['name' => $key, 'value' => (string) $val], ['value']);
        $n++;
    }
    // способы доставки/оплаты: name_uk/description_uk в элементах JSON
    $names = $data('methods');   // ['Новая почта' => ['name' => 'Нова пошта', 'description' => '…'], …]
    foreach (['shipping_methods', 'payment_methods'] as $key) {
        $list = json_decode((string) $db->value('SELECT value FROM settings WHERE name = ?', [$key]), true) ?: [];
        foreach ($list as &$m) {
            $tr = $names[trim((string) ($m['name'] ?? ''))] ?? null;
            if (!$tr) continue;
            if ($force || empty($m['name_uk'])) $m['name_uk'] = $tr['name'] ?? '';
            if (($force || empty($m['description_uk'])) && !empty($tr['description'])) $m['description_uk'] = $tr['description'];
        }
        unset($m);
        $db->upsert('settings', ['name' => $key, 'value' => json_encode($list, JSON_UNESCAPED_UNICODE)], ['value']);
    }
    $say("Настройки: $n");
};

$steps['categories'] = function () use ($data, $setCols, $say) {
    $n = 0;
    $auto = SeoFix::autoFilledUk('category');
    foreach ($data('categories') as $id => $f) $n += $setCols('categories', 'id = ?', [(int) $id], $f, $auto[(int) $id] ?? []);
    $say("Категории: $n");
};

$steps['pages'] = function () use ($db, $data, $setCols, $say) {
    $n = 0;
    $auto = SeoFix::autoFilledUk('page');
    $ids = $auto ? $db->pairs('SELECT url, id FROM pages') : [];
    foreach ($data('pages') as $url => $f) $n += $setCols('pages', 'url = ?', [(string) $url], $f, $auto[(int) ($ids[$url] ?? 0)] ?? []);
    $say("Страницы: $n");
};

$steps['blog'] = function () use ($db, $data, $setCols, $say) {
    $n = 0;
    $auto = SeoFix::autoFilledUk('blog');
    $ids = $auto ? $db->pairs('SELECT url, id FROM blog_posts') : [];
    foreach ($data('blog') as $url => $f) $n += $setCols('blog_posts', 'url = ?', [(string) $url], $f, $auto[(int) ($ids[$url] ?? 0)] ?? []);
    $say("Статьи: $n");
};

$steps['banners'] = function () use ($data, $setCols, $say) {
    $n = 0;
    foreach ($data('banners') as $ruTitle => $f) $n += $setCols('banners', 'title = ?', [(string) $ruTitle], $f);
    $say("Баннеры: $n");
};

$steps['brands'] = function () use ($db, $data, $setCols, $force, $say) {
    $n = 0;
    $auto = SeoFix::autoFilledUk('brand');
    foreach ($data('brands') as $id => $f) $n += $setCols('brands', 'id = ?', [(int) $id], $f, $auto[(int) $id] ?? []);
    // название бренда — это и значение характеристики «Бренд» (id совпадают): фильтр и характеристики товара на /ua/
    $v = 0;
    $bf = (int) $db->value("SELECT id FROM features WHERE code = 'brand'");
    if ($bf) {
        $v = $db->query("UPDATE feature_values fv JOIN brands b ON b.id = fv.id SET fv.value_uk = b.name_uk
            WHERE fv.feature_id = ? AND b.name_uk IS NOT NULL AND b.name_uk <> ''"
            . ($force ? '' : " AND (fv.value_uk IS NULL OR fv.value_uk = '')"), [$bf])->rowCount();
    }
    $say("Бренды: $n, названий в характеристике «Бренд»: $v");
};

$steps['features'] = function () use ($db, $data, $setCols, $say) {
    $n = 0;
    foreach ($data('features') as $code => $name) $n += $setCols('features', 'code = ?', [(string) $code], ['name_uk' => $name]);
    $v = 0;
    foreach ($data('feature_values') as $code => $map) {
        $fid = (int) $db->value('SELECT id FROM features WHERE code = ?', [(string) $code]);
        if (!$fid) continue;
        foreach ($map as $ru => $uk) $v += $setCols('feature_values', 'feature_id = ? AND value = ?', [$fid, (string) $ru], ['value_uk' => $uk]);
    }
    $say("Характеристики: $n, значений: $v");
};

$steps['products'] = function () use ($db, $data, $trWords, $force, $say) {
    // Названия и мета товаров: перевод типовых слов (Кроссовки → Кросівки) + шаблоны старого сайта
    $last = 0; $upd = 0;
    ProductName::reset();                     // категории и бренды — с name_uk из шагов выше
    $auto = SeoFix::autoFilledUk('product');  // title/description *_uk, которые перенос заполнил по русскому тексту, — как пустые
    while (true) {
        $rows = $db->all('SELECT id, name, sku, category_id, brand_id, meta_title, meta_description, meta_keywords, name_uk, meta_title_uk, meta_description_uk, meta_keywords_uk
            FROM products WHERE id > ? ORDER BY id LIMIT 3000', [$last]);
        if (!$rows) break;
        $batch = [];
        foreach ($rows as $p) {
            $last = (int) $p['id'];
            // автоназвание «Зимняя обувь Tom.m 60189A» (ProductName) — UA из name_uk категории и бренда, как при сборке;
            // с --force результат тот же, что записали bin/product-names.php и перенос
            $gen = ProductName::generated($p);
            $nameUk = $gen ? $gen[1] : $trWords($p['name']);
            $new = [];
            if ($nameUk !== $p['name'] && ($force || !$p['name_uk'])) $new['name_uk'] = $nameUk;
            $n = $new['name_uk'] ?? ($p['name_uk'] ?: $nameUk);
            $autoUk = $auto[(int) $p['id']] ?? [];
            if ($p['meta_title'] !== null && ($force || !$p['meta_title_uk'] || isset($autoUk['meta_title_uk']))) {
                $mt = trim((string) $p['meta_title']) === trim((string) $p['name']) ? $n : $trWords($p['meta_title']);
                if ($mt !== $p['meta_title']) $new['meta_title_uk'] = $mt;
            }
            if ($p['meta_description'] !== null && ($force || !$p['meta_description_uk'] || isset($autoUk['meta_description_uk']))) {
                $md = preg_match('/^\s*купить\s+(.+?)\s+в\s+Одессе\s*$/u', (string) $p['meta_description'])
                    ? 'купити ' . $n . ' в Одесі' : $trWords($p['meta_description']);
                if ($md !== $p['meta_description']) $new['meta_description_uk'] = $md;
            }
            if ($p['meta_keywords'] !== null && ($force || !$p['meta_keywords_uk'])) {
                $mk = $trWords($p['meta_keywords']);
                if ($mk !== $p['meta_keywords']) $new['meta_keywords_uk'] = $mk;
            }
            if ($new) $batch[] = ['id' => (int) $p['id']] + $new + ['name_uk' => null, 'meta_title_uk' => null, 'meta_description_uk' => null, 'meta_keywords_uk' => null];
        }
        // одна транзакция на пачку: на MySQL 8 с binlog каждый autocommit — отдельная запись на диск (106 тыс. UPDATE шли 5+ минут)
        $upd += (int) $db->transaction(static function ($db) use ($batch) {
            foreach ($batch as $b) {
                $cols = array_filter(['name_uk' => $b['name_uk'], 'meta_title_uk' => $b['meta_title_uk'], 'meta_description_uk' => $b['meta_description_uk'], 'meta_keywords_uk' => $b['meta_keywords_uk']], static fn($v) => $v !== null);
                $db->update('products', $cols, 'id = ?', [$b['id']]);
            }
            return count($batch);
        });
    }
    // Описания: одинаковые тексты переводятся один раз (ключ — md5 русского текста без пробелов по краям)
    $desc = $data('product_descriptions');
    $d = 0;
    if ($desc) {
        foreach ($db->all("SELECT DISTINCT description FROM product_texts WHERE description IS NOT NULL AND description <> ''") as $r) {
            $uk = $desc[md5(trim((string) $r['description']))] ?? null;
            if (!$uk) continue;
            $d += $db->query('UPDATE product_texts SET description_uk = ? WHERE description = ?' . ($force ? '' : " AND (description_uk IS NULL OR description_uk = '')"),
                [$uk, $r['description']])->rowCount();
        }
    }
    $say("Товары: обновлено $upd, описаний: $d");
};

foreach ($steps as $name => $fn) {
    if ($only && $only !== $name) continue;
    $fn();
}
// SEO-стандарт после сида (App\Services\SeoFix): сид повторяет на украинском «машинные» мета старого сайта («купити … в Одесі»),
// а с --force возвращает шаблоны «seo.*.uk» — приводим title/description обеих версий к норме, одним пакетом в журнале
// (откат: php bin/seo-autofix.php --revert=N; повторный запуск ничего не меняет). Отдельный шаг — только его часть; «seo» — только это.
$seoParts = ['settings' => ['settings'], 'categories' => ['categories'], 'pages' => ['pages'], 'blog' => ['blog'], 'brands' => ['brands'],
    'products' => ['products'], 'seo' => array_keys(\App\Services\SeoFix::PARTS)];
if (!$only || isset($seoParts[$only])) {
    if (!\App\Services\SeoFix::hasTables()) {
        $say('SEO: нет таблиц журнала — выполните php bin/install.php, затем php bin/seo-autofix.php');
    } else {
        $r = \App\Services\SeoFix::run(true, ['parts' => $only ? $seoParts[$only] : array_keys(\App\Services\SeoFix::PARTS), 'source' => 'i18n']);
        $say('SEO title/description приведены к норме: изменено ' . $r['changes'] . ($r['batch'] ? ' (пакет №' . $r['batch'] . ')' : '')
            . ', не в норме осталось ' . count($r['left']));
    }
}
Cache::flush();
$say('Готово (кэш сброшен).');
