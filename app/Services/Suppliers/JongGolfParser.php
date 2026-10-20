<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

/**
 * Разбор страниц официального сайта Jong•Golf (www.jonggolf.com, своя тема на PHP; проверено на 749 цветах и картах моделей).
 *
 * Список (каталог /ru/catalog/?limit=1000, разделы): товар-цвет — блок «grid-boxes-in one-product-list», данные — атрибуты
 * data-* ссылки на фото: data-id — id модели (= id_product API), data-url — слаг модели (b40678), data-color — код цвета «-3»,
 * data-cost — цена за пару, data-inbox — пар в ящике, data-size — ряд «27-32»; рядом — строка «Ботинки B40678-3 | 27-32 (B)»,
 * цепочка «Сезон | Категория | Бренд» и пол, «За пару / За ящик», старая цена (line-through), метки «Новинка» / «-33%».
 * Цвет без остатка в списке не показывается. Полнота — по фасетам пола формы фильтра (их сумма = число товаров в наличии).
 * Карточка модели (/ru/{слаг}.html): цвета в наличии, характеристики (артикул, сезон-коллекция, стиль, пол, материалы),
 * описание (только RU), размерная сетка «размер → пар в ящике», доп. фото цветов (data-gallery="addi-item"),
 * «Модель снята с продажи». Фото: https://public.jonggolf.com/files/products/{модель}/{фото}/{s_|m_|b_}{hash}.jpg, b_ — самое крупное.
 */
final class JongGolfParser
{
    /** Подписи характеристик карточки → поля загрузчика */
    private const SPEC = [
        'артикул' => 'model', 'размеры' => 'sizes', 'пар в ящике' => 'pairs', 'минимальный заказ' => 'min', 'производитель' => 'maker',
        'сезон' => 'collection', 'стиль' => 'style', 'пол' => 'gender', 'супинатор' => 'supinator', 'led подсветка' => 'led',
        'упаковка каждой пары' => 'packaging', 'застёжка' => 'clasp', 'застежка' => 'clasp',
        'материал, верх' => 'outside', 'материал, внутри' => 'inside', 'стелька' => 'bottom', 'подошва' => 'outsole',
    ];

    private static function text(string $s): string
    {
        return trim((string) preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /** «6 600 грн» → 6600.0; нет числа — 0.0 */
    private static function money(string $s): float
    {
        $s = (string) preg_replace('~[^\d.,]~u', '', self::text($s));
        return $s === '' ? 0.0 : (float) str_replace(',', '.', $s);
    }

    private static function attrs(string $tag): array
    {
        $out = [];
        if (preg_match_all('~\b(data-[a-z-]+|href|title|src)\s*=\s*"([^"]*)"~u', $tag, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) $out[$x[1]] ??= html_entity_decode($x[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $out;
    }

    /**
     * Страница списка. @return array ['items' => [цвет …], 'total' => ?int (сумма фасетов пола), 'facets' => [вид => [ключ => [название, n]]],
     *   'empty' => bool («товаров не найдено»), 'pages' => int (по пагинации)]; цвет: model, slug, color, aid, size, letter, inbox,
     *   pair, box_price, old_pair, old_box, type, season, season_url, cat, cat_url, brand, gender, badges, photo, card, youtube
     */
    public static function listPage(string $h): array
    {
        $out = ['items' => [], 'total' => null, 'facets' => [], 'empty' => (bool) preg_match('~товаров не найдено|товарів не знайдено~u', $h), 'pages' => 1];
        if (preg_match_all('~name="(gender|size_code|brand)\[([^\]"]+)\]"\s*/?>\s*<i></i>\s*([^<]*?)\s*<small>\((\d+)\)~u', $h, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) $out['facets'][$x[1]][$x[2]] = [self::text($x[3]), (int) $x[4]];
        }
        if (!empty($out['facets']['gender'])) $out['total'] = array_sum(array_map(static fn($f) => $f[1], $out['facets']['gender']));
        if (preg_match_all('~[?&](?:amp;)?page=(\d+)~', $h, $pm)) $out['pages'] = max(1, ...array_map('intval', $pm[1]));
        $parts = explode('grid-boxes-in one-product-list', $h);
        array_shift($parts);
        foreach ($parts as $b) {
            if (!str_contains($b, 'data-cost') || !preg_match('~<a\s+href="https://public\.jonggolf\.com/[^"]+"[^>]*>~u', $b, $tag)) continue;
            $a = self::attrs($tag[0]);
            $slug = strtolower(trim((string) ($a['data-url'] ?? '')));
            $color = trim((string) ($a['data-color'] ?? ''));
            if ($slug === '' || (int) ($a['data-id'] ?? 0) <= 0) continue;
            $it = [
                'model' => (int) $a['data-id'], 'slug' => $slug, 'color' => $color, 'aid' => mb_strtoupper($slug) . $color,
                'size' => trim((string) ($a['data-size'] ?? '')), 'letter' => '', 'inbox' => (int) ($a['data-inbox'] ?? 0),
                'pair' => (float) ($a['data-cost'] ?? 0), 'box_price' => 0.0, 'old_pair' => 0.0, 'old_box' => 0.0,
                'available' => (int) ($a['data-available'] ?? 1), 'type' => '', 'season' => '', 'season_url' => '', 'cat' => '', 'cat_url' => '',
                'brand' => '', 'gender' => '', 'badges' => [], 'photo' => (string) ($a['href'] ?? ''), 'card' => '', 'youtube' => (string) ($a['data-youtube'] ?? ''),
            ];
            if (preg_match('~<p class="product-review[^"]*">(.*?)</p>~su', $b, $m)) {
                if (preg_match('~<span class="text-bold">([^<]*)</span>~u', $m[1], $c) && ($code = mb_strtoupper(self::text($c[1]))) !== '') $it['aid'] = $code;
                $it['type'] = self::text(explode('<span', $m[1])[0]);
                if (preg_match('~\(([A-Z])\)\s*$~u', self::text($m[1]), $l)) $it['letter'] = $l[1];
            }
            if (preg_match('~<div class="list_data">(.*?)</div>~su', $b, $m)) {
                preg_match_all('~<a href="([^"]+)"[^>]*>(.*?)</a>~su', $m[1], $aa, PREG_SET_ORDER);
                if (isset($aa[0])) { $it['season_url'] = (string) parse_url($aa[0][1], PHP_URL_PATH); $it['season'] = self::text($aa[0][2]); }
                if (isset($aa[1])) { $it['cat_url'] = (string) parse_url($aa[1][1], PHP_URL_PATH); $it['cat'] = self::text($aa[1][2]); }
                if (isset($aa[2])) $it['brand'] = trim(self::text($aa[2][2]), ' |');
                if (preg_match('~<br\s*/?>(.*)$~su', $m[1], $g)) $it['gender'] = self::text($g[1]);
            }
            if (!$it['inbox'] && preg_match('~:\s*<span class="text-bold">(\d+)</span>~u', $b, $m)) $it['inbox'] = (int) $m[1];
            if (preg_match('~<div class="product-price">(.*?)<!--googleoff~su', $b, $m)) {
                $p = $m[1];
                if (preg_match('~<span class="line-through pull-left">(.*?)</span>~su', $p, $x)) $it['old_pair'] = self::money($x[1]);
                if (preg_match('~<span class="line-through pull-right">(.*?)</span>~su', $p, $x)) $it['old_box'] = self::money($x[1]);
                $p = (string) preg_replace('~<div class="clearfix dscr[^"]*">.*?</div>~su', '', $p);
                if (preg_match('~<span class="pull-right">(.*?)</span>~su', $p, $x)) $it['box_price'] = self::money($x[1]);
                // «За пару» на странице = data-cost (сверено на 749 цветах); без data-cost — по тексту
                if ($it['pair'] <= 0 && preg_match('~<span class="title-price pull-left">(.*?)</span>~su', $p, $x)) $it['pair'] = self::money($x[1]);
            }
            if (preg_match_all('~rgba-banner">([^<]*)<~u', $b, $m)) $it['badges'] = array_values(array_filter(array_map([self::class, 'text'], $m[1]), static fn($v) => $v !== ''));
            if (preg_match('~<a class="btn-u btn-u-sea-shop[^"]*" href="([^"]+)"~u', $b, $m)) $it['card'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $out['items'][] = $it;
        }
        return $out;
    }

    /**
     * Карточка модели. @return array|null ['h1', 'off' => bool (снята с продажи), 'colors' => [цвет => ['cost', 'photo', 'stock']],
     *   'spec' => [поле => значение] (model, collection, style, gender, outside, inside, bottom, outsole, clasp …),
     *   'description' => HTML описания, 'grid' => [размер => пар], 'extra' => [цвет => [адрес фото b_ …]]]; не карточка — null
     */
    public static function card(string $h): ?array
    {
        $h = (string) preg_replace('~<script\b[^>]*>.*?</script>~su', '', $h);
        $h = (string) preg_replace('~\s+~u', ' ', $h);
        $off = (bool) preg_match('~[Мм]одель снята с продажи|[Мм]одель знята з продажу~u', $h);
        if (!preg_match('~<h1[^>]*>(.*?)</h1>~u', $h, $m) || (!str_contains($h, 'specifies-list') && !$off)) return null;
        $c = ['h1' => self::text($m[1]), 'off' => $off, 'colors' => [], 'spec' => [], 'description' => '', 'grid' => [], 'extra' => []];
        if (preg_match_all('~<div class="product-sub-item">(.*?)<!--/noindex-->~u', $h, $mm)) {
            foreach ($mm[1] as $b) {
                if (!preg_match('~<a\s+href="https://public\.jonggolf\.com/[^"]+"[^>]*>~u', $b, $tag)) continue;
                $a = self::attrs($tag[0]);
                $color = trim((string) ($a['data-color'] ?? ''));
                if ($color === '') continue;
                $c['colors'][$color] = ['cost' => (float) ($a['data-cost'] ?? 0), 'photo' => (string) ($a['href'] ?? ''),
                    'stock' => preg_match('~<small class="color-[a-z]+">([^<]*)</small>~u', $b, $s) ? self::text($s[1]) : ''];
            }
        }
        if (preg_match_all('~<ul class="list-unstyled specifies-list">(.*?)</ul>~u', $h, $uu)) {
            foreach ($uu[1] as $u) {
                if (!preg_match_all('~<li>\s*(.*?):\s*<span[^>]*>(.*?)</span>\s*</li>~u', $u, $ll, PREG_SET_ORDER)) continue;
                foreach ($ll as $l) {
                    $k = self::SPEC[mb_strtolower(self::text($l[1]))] ?? null;
                    if ($k === null) continue;
                    $v = self::text((string) preg_replace('~<br\s*/?>~u', ' | ', $l[2]));
                    if ($v !== '') $c['spec'][$k] = $v;
                }
            }
        }
        if (preg_match('~<p class="text-justify noselect">(.*?)</p>~u', $h, $m)) $c['description'] = trim($m[1]);
        if (preg_match('~(?:Размерная сетка|Розмірна сітка)[^<]*</h2>.*?<thead>(.*?)</thead>\s*<tbody>(.*?)</tbody>~u', $h, $m)) {
            preg_match_all('~<th class="text-center">(\d+(?:[.,]5)?)</th>~u', $m[1], $s);
            preg_match_all('~<td class="text-center">(\d+)</td>~u', $m[2], $q);
            if ($s[1] && count($s[1]) === count($q[1])) $c['grid'] = array_combine($s[1], array_map('intval', $q[1]));
        }
        if (preg_match_all('~<a\s+href="(https://public\.jonggolf\.com/files/products/[^"]+)"[^>]*data-gallery="addi-item"[^>]*>~u', $h, $g, PREG_SET_ORDER)) {
            foreach ($g as $x) {
                $a = self::attrs($x[0]);
                $color = trim((string) ($a['data-color'] ?? ''));
                if ($color !== '' && !in_array($x[1], $c['extra'][$color] ?? [], true)) $c['extra'][$color][] = $x[1];
            }
        }
        return $c;
    }

    /** Повторные размеры из сетки ящика: [27 => 1, 29 => 2, 30 => 2] → «29-2,30-2» ('' — повторов нет) */
    public static function repeats(array $grid): string
    {
        $out = [];
        foreach ($grid as $size => $n) if ((int) $n > 1) $out[] = $size . '-' . (int) $n;
        return implode(',', $out);
    }
}
