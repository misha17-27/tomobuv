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
 * «Машинные» title/description (= название, «купити … в Одесі», набор ключевых слов) вне нормы при пустом русском своём
 * не пишутся и с --force (SeoFix::seedSkipsUk): автоисправление в конце всё равно очистило бы их — повторный запуск
 * сида ничего не меняет. Название товара, перевод которого совпадает с русским (Угги, Дутики, Балетки… — одинаковые
 * в обоих языках, бренды, коды), в name_uk НЕ копируется: пустое name_uk на /ua/ показывает актуальное русское название,
 * а копия устарела бы после переименования товара. В отчёте — названия с русскими словами, которых нет в словаре.
 * В конце — SEO-стандарт title/description (App\Services\SeoFix, как php bin/seo-autofix.php; «seo» — только он).
 */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use App\Core\App;
use App\Core\Cache;
use App\Core\Seo;
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
/**
 * UPDATE только пустых колонок (или всех при --force); $auto — [колонка => true], заполненные автоисправлением без перевода;
 * $seo — [сущность SeoFix, текущая строка]: «машинные» title/description *_uk, которые автоисправление очистило бы, не пишутся
 */
$setCols = static function (string $table, string $where, array $params, array $cols, array $auto = [], ?array $seo = null) use ($db, $force): int {
    $cols = array_filter($cols, static fn($v) => $v !== null && $v !== '');
    if ($seo !== null) {
        [$entity, $row] = $seo;
        $nc = $entity === 'blog' ? 'title' : 'name';                // название объекта (у статьи — заголовок)
        $names = [$row[$nc] ?? '', $row[$nc . '_uk'] ?? '', $cols[$nc . '_uk'] ?? ''];
        foreach ($cols as $c => $v) {
            if (str_ends_with($c, '_uk') && SeoFix::seedSkipsUk($entity, $c, (string) $v, $row[substr($c, 0, -3)] ?? null, $names)) unset($cols[$c]);
        }
    }
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
        if (mb_strtolower($uk) === mb_strtolower($w)) return $w;   // одинаковое в обоих языках — написание как в названии («УГГИ»)
        // сохранить регистр первой буквы
        $first = mb_substr($w, 0, 1);
        if ($first === mb_strtoupper($first)) $uk = mb_strtoupper(mb_substr($uk, 0, 1)) . mb_substr($uk, 1);
        else $uk = mb_strtolower(mb_substr($uk, 0, 1)) . mb_substr($uk, 1);
        return $uk;
    }, $s);
};
/**
 * Название (после перевода) без русских слов — уже украинское: каждое кириллическое слово — из переводов словаря (в том
 * числе одинаковые в обоих языках: Угги, Дутики, Балетки…), из названия бренда (не переводятся), с украинской буквой
 * (і, ї, є, ґ — слова поставщика «шкіра», «білий») или сокращение/код до 3 букв («БП», «шт», «нат.», «ТА2301G»),
 * кроме русских «с», «со», «от»; код заглавными вплотную к сокращению — по частям («НКчор.» = «НК» + «чор»).
 * Слово с ы/э/ё/ъ не из бренда или незнакомое длиннее 3 букв — нет ($unknown — какие).
 */
$ukKnown = [];
$ruOnly = ['с' => true, 'со' => true, 'от' => true];   // короткие русские слова: не принимаются за сокращение или код
foreach ($words as $ru => $uk) {
    foreach (preg_split("/[^\\p{L}'ʼ]+/u", mb_strtolower((string) $uk), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) $ukKnown[$w] = true;
    if (mb_strtolower((string) $ru) !== mb_strtolower((string) $uk)) $ruOnly[mb_strtolower((string) $ru)] = true;   // «мех», «дет», «из»
}
$ukName = static function (string $s, array $brandWords, array &$unknown = []) use ($ukKnown, $ruOnly): bool {
    $known = static function (string $lw, bool $short = true) use ($ukKnown, $ruOnly, $brandWords): bool {
        if (isset($ukKnown[$lw]) || isset($brandWords[$lw])) return true;
        if (preg_match('/[ыэёъ]/u', $lw)) return false;
        return (bool) preg_match('/[іїєґ]/u', $lw) || ($short && mb_strlen($lw) <= 3 && !isset($ruOnly[$lw]));
    };
    preg_match_all("/\\p{Cyrillic}[\\p{Cyrillic}'ʼ]*/u", $s, $m);
    $ok = true;
    foreach ($m[0] as $w) {
        $w = (string) preg_replace("/['ʼ]+$/u", '', $w);                   // не rtrim: он режет байты (ʼ = CA BC, «м» = D0 BC)
        if ($known(mb_strtolower($w))) continue;
        // «НКчор» — код заглавными + сокращение из словаря («чор»): сокращение — только известное, не любое до 3 букв
        if (preg_match('/^(\p{Lu}{2,3})(\p{Ll}+)$/u', $w, $p) && $known(mb_strtolower($p[1])) && $known($p[2], false)) continue;
        $unknown[] = mb_strtolower($w);
        $ok = false;
    }
    return $ok;
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

$steps['categories'] = function () use ($db, $data, $setCols, $say) {
    $n = 0;
    $auto = SeoFix::autoFilledUk('category');
    $rows = $db->keyed('SELECT id, name, name_uk, meta_title, meta_description FROM categories');
    foreach ($data('categories') as $id => $f) {
        if (isset($rows[(int) $id])) $n += $setCols('categories', 'id = ?', [(int) $id], $f, $auto[(int) $id] ?? [], ['category', $rows[(int) $id]]);
    }
    $say("Категории: $n");
};

$steps['pages'] = function () use ($db, $data, $setCols, $say) {
    $n = 0;
    $auto = SeoFix::autoFilledUk('page');
    $rows = [];                                   // url без учёта регистра — как «url = ?» в базе
    foreach ($db->all('SELECT url, id, name, name_uk, title, meta_description FROM pages') as $r) $rows[mb_strtolower((string) $r['url'])] ??= $r;
    foreach ($data('pages') as $url => $f) {
        $r = $rows[mb_strtolower((string) $url)] ?? null;
        if ($r) $n += $setCols('pages', 'url = ?', [(string) $url], $f, $auto[(int) $r['id']] ?? [], ['page', $r]);
    }
    $say("Страницы: $n");
};

$steps['blog'] = function () use ($db, $data, $setCols, $say) {
    $n = 0;
    $auto = SeoFix::autoFilledUk('blog');
    $rows = [];
    foreach ($db->all('SELECT url, id, title, title_uk, meta_title, meta_description FROM blog_posts') as $r) $rows[mb_strtolower((string) $r['url'])] ??= $r;
    foreach ($data('blog') as $url => $f) {
        $r = $rows[mb_strtolower((string) $url)] ?? null;
        if ($r) $n += $setCols('blog_posts', 'url = ?', [(string) $url], $f, $auto[(int) $r['id']] ?? [], ['blog', $r]);
    }
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
    $rows = $db->keyed('SELECT id, name, name_uk, title, meta_description FROM brands');
    foreach ($data('brands') as $id => $f) {
        if (isset($rows[(int) $id])) $n += $setCols('brands', 'id = ?', [(int) $id], $f, $auto[(int) $id] ?? [], ['brand', $rows[(int) $id]]);
    }
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

$steps['products'] = function () use ($db, $data, $trWords, $ukName, $force, $say) {
    // Названия и мета товаров: перевод типовых слов (Кроссовки → Кросівки) + шаблоны старого сайта
    $last = 0; $upd = 0; $same = 0; $left = 0; $skipped = 0; $unknown = [];
    ProductName::reset();                     // категории и бренды — с name_uk из шагов выше
    $auto = SeoFix::autoFilledUk('product');  // title/description *_uk, которые перенос заполнил по русскому тексту, — как пустые
    $brandWords = [];                         // слова названий брендов — в названиях товаров не переводятся
    foreach ($db->col('SELECT name FROM brands') as $b) {
        foreach (preg_split('/[^\p{L}]+/u', mb_strtolower((string) $b), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) $brandWords[$w] = true;
    }
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
            if ($nameUk !== $p['name']) {
                if ($force || !$p['name_uk']) $new['name_uk'] = $nameUk;
            } elseif (!$p['name_uk'] && (string) $nameUk !== '') {
                // перевод совпал с русским: не копируем (на /ua/ и так русское = украинское название, и оно не устареет
                // при переименовании); считаем только названия с русскими словами не из словаря — их стоит перевести
                if ($ukName((string) $nameUk, $brandWords, $unknown)) $same++;
                else $left++;
            }
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
            // «машинные» title/description, которые автоисправление очистило бы (русское своё пусто или тоже машинное), не пишем
            $names = [$p['name'], $n, Seo::machineName((string) $p['meta_description']), Seo::machineName((string) ($new['meta_description_uk'] ?? $p['meta_description_uk']))];
            foreach (['meta_title_uk' => 'meta_title', 'meta_description_uk' => 'meta_description'] as $c => $ru) {
                if (isset($new[$c]) && SeoFix::seedSkipsUk('product', $c, $new[$c], $p[$ru], $names)) { unset($new[$c]); $skipped++; }
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
    $say("Товары: обновлено $upd (UA-название совпадает с русским, оставлено пустым: $same), описаний: $d"
        . ($skipped ? ", машинных title/description UA не записано: $skipped" : ''));
    if ($unknown) {
        $cnt = array_count_values($unknown);
        arsort($cnt);
        $say("UA-название пустое у $left (слова не из словаря database/i18n/uk/product_words.php, на /ua/ — русское название): "
            . implode(', ', array_map(static fn($w, $k) => "$w ($k)", array_slice(array_keys($cnt), 0, 20), array_slice($cnt, 0, 20))));
    }
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
