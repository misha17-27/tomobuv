<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

/**
 * Разбор страниц hit-obuvi.com.ua (Joomla + JoomShopping, шаблон hit_mazay): список категории, меню, фильтр
 * производителей. Карточка товара не нужна: в списке есть всё (название, цены ящика и пары, ряд, цвет, материал,
 * производитель, пар в упаковке, миниатюра — по ней адрес большого фото).
 *
 * Товар в списке — блок <div class="product productitem_{ID} resprod">: ID — product_id JoomShopping (он же в ссылке
 * корзины /korzina/add?category_id=C&product_id=ID и в имени фото foto{ID}.jpg); C — категория текущего списка
 * (в корневых списках — основная категория товара; у /podrostkovaya-obuv всегда 19). Число в конце адреса товара — НЕ id:
 * у импортированных из Forsage «машинный» алиас кончается forsage-кодом (forsageCode).
 */
final class HitObuviParser
{
    /** Ключи короткого описания RU/UA → единые */
    private const DESC_KEYS = [
        'размер' => 'size', 'розмір' => 'size', 'цвет' => 'color', 'колір' => 'color',
        'производитель' => 'maker', 'виробник' => 'maker', 'материал' => 'material', 'матеріал' => 'material',
        'количество в упаковке' => 'box', 'кількість в упаковці' => 'box', 'кількість у упаковці' => 'box',
    ];

    public static function text(string $s): string
    {
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('~\s+~u', ' ', $s));
    }

    /** Число из «3520 Грн.», «1 042,50 грн» → float | null */
    public static function num(string $s): ?float
    {
        if (!preg_match('~(\d[\d\s\x{00A0}]*(?:[.,]\d+)?)~u', $s, $m)) return null;
        return (float) str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $m[1]);
    }

    /**
     * Страница списка: ['h1', 'items' => [товар …], 'last_start' (смещение последней страницы, 0 — одна страница),
     * 'limit' (выбранное «по N»), 'products_block' (есть сетка товаров), 'empty' (страница «товаров нет»)]
     */
    public static function listPage(string $html): array
    {
        $res = ['h1' => null, 'items' => [], 'last_start' => 0, 'limit' => null, 'empty' => false, 'uk' => null];
        if (preg_match('~<h1[^>]*>(.*?)</h1>~s', $html, $m)) $res['h1'] = self::text($m[1]);
        if (preg_match('~href="[^"]*[?&](?:amp;)?start=(\d+)"\s*>\s*(?:В конец|Кінець)~u', $html, $m)) $res['last_start'] = (int) $m[1];
        if (preg_match('~<select[^>]*id="limit".*?<option value="(\d+)"\s+selected~s', $html, $m)) $res['limit'] = (int) $m[1];
        if (preg_match('~<link href="([^"]+)" rel="alternate" hreflang="uk-UA"~', $html, $m)) $res['uk'] = $m[1];
        $parts = preg_split('~<div class="product productitem_(\d+) resprod">~', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        for ($i = 1; $i < count($parts); $i += 2) {
            $id = (int) $parts[$i];
            $b = preg_split('~<div class="jshop_pagination">~', (string) $parts[$i + 1])[0];   // у последнего — без пагинации
            $it = self::item($id, (string) $b);
            if ($it) $res['items'][] = $it;
        }
        // товаров нет: пустая категория (блок JoomShopping с заголовком, без сетки) — иначе чужая страница или новый шаблон
        $res['empty'] = !$res['items'] && $res['h1'] !== null && str_contains($html, 'id="comjshop"') && !str_contains($html, 'productitem_');
        return $res;
    }

    /** Один товар списка */
    private static function item(int $id, string $b): ?array
    {
        $p = ['id' => $id, 'category_id' => null, 'url' => '', 'name' => '', 'thumb' => '', 'label' => '', 'price_box' => null, 'price_unit' => null, 'old_price_box' => null];
        if (preg_match('~category_id=(\d+)&(?:amp;)?product_id=' . $id . '\b~', $b, $m)) $p['category_id'] = (int) $m[1];
        if (preg_match('~<div class="name liname">\s*<a href="([^"#]*)[^"]*"[^>]*>(.*?)</a>~s', $b, $m)) {
            $p['url'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $p['name'] = self::text($m[2]);
        }
        if ($p['name'] === '') return null;
        if (preg_match('~<img class="jshop_img catlist" src="([^"]+)"~', $b, $m)) $p['thumb'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('~img_labels/([a-z0-9_]+)\.png~i', $b, $m)) $p['label'] = strtolower($m[1]);
        if (preg_match('~<div class="old_price">.*?<span>(.*?)</span>~s', $b, $m)) $p['old_price_box'] = self::num(self::text($m[1]));
        if (preg_match('~<span class="pred">(.*?)</span>~s', $b, $m)) $p['price_box'] = self::num(self::text($m[1]));
        if (preg_match('~(?:Цена за ед\.|Ціна за од\.)\s*-\s*<span[^>]*>(.*?)<~su', $b, $m)) $p['price_unit'] = self::num(self::text($m[1]));
        $p['desc'] = [];
        if (preg_match('~<div class="description">(.*?)</div>~s', $b, $m)) $p['desc'] = self::desc($m[1]);
        return $p;
    }

    /**
     * Короткое описание «Размер:32-37<br>Цвет:белый<br>Производитель:Comfort(Китай)<br>Материал:искусственная кожа(искусственный мех)
     * <br>Количество в упаковке:8 пар» (у части товаров по-украински) → size, color, maker, maker_name, country, material,
     * material_top, material_lining, box (текст), box_qty
     */
    public static function desc(string $html): array
    {
        $out = [];
        foreach (preg_split('~<br\s*/?>~i', $html) ?: [] as $part) {
            $t = self::text($part);
            if ($t === '' || !preg_match('~^([^:]{2,40}):\s*(.*)$~u', $t, $m)) continue;
            $key = self::DESC_KEYS[mb_strtolower(trim($m[1]))] ?? null;
            if ($key && !isset($out[$key])) $out[$key] = trim($m[2]);
        }
        if (isset($out['size'])) $out['size'] = (string) preg_replace(['/\s+/u', '/[‐‑‒–—−]/u'], ['', '-'], $out['size']);
        // «Comfort(Китай)» → фабрика и страна; «искусственная кожа(искусственный мех)» → верх и подкладка
        if (isset($out['maker'])) {
            [$out['maker_name'], $out['country']] = preg_match('~^(.*?)\s*\(([^()]*)\)\s*$~u', $out['maker'], $m) ? [trim($m[1]), trim($m[2])] : [$out['maker'], ''];
        }
        if (isset($out['material'])) {
            [$out['material_top'], $out['material_lining']] = preg_match('~^(.*?)\s*\(([^()]*)\)\s*$~u', $out['material'], $m) ? [trim($m[1]), trim($m[2])] : [$out['material'], ''];
        }
        if (isset($out['box']) && preg_match('~\d+~', $out['box'], $m)) $out['box_qty'] = (int) $m[0];
        return $out;
    }

    /** Пункты меню сайта: [путь => название] (категории RU: /zhenskaya-obuv/w-krossovki) */
    public static function menu(string $html): array
    {
        $out = [];
        if (preg_match_all('~<li class="[^"]*bd-menuitem[^"]*">\s*<a href="(/[a-z0-9/_-]*)"[^>]*>\s*<span>(.*?)</span>~su', $html, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $path = rtrim($m[1], '/');
                if ($path === '' || str_starts_with($path, '/uk') || preg_match('~^/(korzina|kabinet|user|component|index)~', $path)) continue;
                $out[$path] ??= self::text($m[2]);
            }
        }
        return $out;
    }

    /** Фильтр «Производитель»: [id => название] */
    public static function manufacturers(string $html): array
    {
        $out = [];
        if (preg_match_all('~name="manufacturers\[\]" value="(\d+)"[^>]*>\s*<label[^>]*>(?:<span>)?(.*?)<~su', $html, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $n = self::text($m[2]);
                if ($n !== '') $out[(int) $m[1]] = $n;
            }
        }
        return $out;
    }

    /**
     * Forsage-код из адреса товара: хвост 5–8 цифр «машинного» алиаса (…/zzgiozszdzizs-bbt-t69623izbzizs885959/),
     * не равный id товара; у новых без алиаса (…/product/view/{cat}/{id}) и заведённых вручную (krosivki-adidas-b8588-3) — null
     */
    public static function forsageCode(string $url, int $id): ?string
    {
        if (str_contains($url, '/product/view/')) return null;
        $slug = basename(rtrim($url, '/'));
        if (!preg_match('~^z[a-z0-9-]*?[a-z](\d{5,8})$~', $slug, $m)) return null;
        return (int) $m[1] === $id ? null : $m[1];
    }

    /**
     * Категория товара по его адресу: «/zhenskaya-obuv/w-krossovki/slug/» → путь категории «/zhenskaya-obuv/w-krossovki»,
     * «/x/product/view/17/1655741» → id 17. @return array{0: ?string путь, 1: ?int id}
     */
    public static function urlCategory(string $url): array
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = (string) preg_replace('~^/uk(?=/)~', '', $path);
        if (preg_match('~/product/view/(\d+)/\d+~', $path, $m)) return [null, (int) $m[1]];
        $parts = array_values(array_filter(explode('/', trim($path, '/')), static fn($p) => $p !== ''));
        if (count($parts) < 2) return [null, null];
        array_pop($parts);                                                  // алиас товара
        return ['/' . implode('/', $parts), null];
    }

    /** Признаки защиты Cloudflare / «проверки браузера» в ответе (общая проверка клиента — SiteClient::challenge) */
    public static function challenge(string $body, array $headers): bool
    {
        return SiteClient::challenge($body, $headers);
    }
}
