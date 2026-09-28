<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;

/**
 * Очистка HTML по белому списку тегов и атрибутов.
 *
 *  - staff($html) — HTML-поля форм админки (страницы, статьи, описания товаров, категорий, брендов): администратору — как есть
 *    («HTML пишет админ»: можно вставить виджет со скриптом), остальным сотрудникам — clean(). Иначе менеджер мог бы сохранить
 *    <script> или onerror=…, код выполнился бы у администратора на витрине (тот же домен) и действовал бы в админке от его имени.
 *    Формы редакторов вызывают его через AdminCatalog::staffHtml($html, $изБазы): поле, которое менеджер не менял, остаётся
 *    как в базе (виджет со <script> от администратора не пропадает при правке title), изменённое — очищается.
 *  - clean($html) — обычная разметка контента остаётся: абзацы, заголовки, списки, таблицы, ссылки, картинки, iframe с YouTube
 *    и Google Maps, class/style, microdata, разметка Word (class="MsoNormal", <o:p> раскрывается в текст). Удаляются: script, style,
 *    object/embed, поля форм, base/meta/link, атрибуты on*, srcdoc, ссылки javascript:/vbscript:/data: (картинке — data:image можно),
 *    опасное в style (expression(), url(javascript:), behavior), остальные iframe. Неизвестные теги раскрываются — текст остаётся.
 *    <style> удаляется целиком: CSS из него действует на всю страницу (можно спрятать или подменить кнопки, цены, шапку),
 *    а селекторы по атрибутам с url() умеют выносить значения атрибутов на чужой сервер. Надёжно проверить таблицу стилей
 *    намного сложнее, чем одно объявление в style="…", а в текущем контенте сайта тегов <style> нет.
 *    Разбор — DOMDocument (libxml), вывод — свой сериализатор: кириллица как есть, адреса ссылок не перекодируются в %XX,
 *    без добавленных <html><body>; текст и значения атрибутов всегда экранируются, поэтому браузер не соберёт из результата новый тег.
 *  - supplier($html) — описания из фидов поставщиков (импорт): только простые теги без атрибутов.
 */
final class HtmlSanitizer
{
    /** Разрешённые теги → их собственные атрибуты (общие — в ATTRS) */
    private const TAGS = [
        'p' => [], 'br' => [], 'hr' => ['size', 'width', 'noshade'], 'div' => [], 'span' => [], 'center' => [],
        'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [], 's' => [], 'strike' => [], 'del' => [], 'ins' => [],
        'mark' => [], 'small' => [], 'big' => [], 'sup' => [], 'sub' => [], 'font' => ['color', 'size', 'face'],
        'abbr' => [], 'cite' => [], 'q' => [], 'code' => [], 'pre' => [], 'kbd' => [], 'address' => [], 'time' => ['datetime'], 'wbr' => [],
        'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'ul' => ['type'], 'ol' => ['type', 'start', 'reversed'], 'li' => ['type', 'value'], 'dl' => [], 'dt' => [], 'dd' => [],
        'table' => ['border', 'cellpadding', 'cellspacing', 'width', 'height', 'bgcolor', 'summary'], 'caption' => [],
        'thead' => [], 'tbody' => [], 'tfoot' => [], 'colgroup' => ['span', 'width'], 'col' => ['span', 'width'],
        'tr' => ['height', 'bgcolor'],
        'th' => ['colspan', 'rowspan', 'scope', 'width', 'height', 'bgcolor', 'nowrap'],
        'td' => ['colspan', 'rowspan', 'width', 'height', 'bgcolor', 'nowrap'],
        'a' => ['href', 'target', 'rel', 'name', 'hreflang'],
        'img' => ['src', 'alt', 'width', 'height', 'loading', 'decoding', 'border', 'hspace', 'vspace'],
        'blockquote' => [], 'figure' => [], 'figcaption' => [],
        'section' => [], 'article' => [], 'header' => [], 'footer' => [], 'aside' => [],
        'iframe' => ['src', 'width', 'height', 'frameborder', 'allowfullscreen', 'allow', 'loading', 'referrerpolicy', 'scrolling', 'marginwidth', 'marginheight'],
        'meta' => ['itemprop', 'content'],   // только microdata: <meta itemprop="…" content="…"> (остальные meta удаляются)
    ];

    /** Атрибуты, разрешённые у любого разрешённого тега (плюс aria-*) */
    private const ATTRS = ['class', 'style', 'title', 'id', 'align', 'valign', 'dir', 'lang', 'role',
        'itemprop', 'itemscope', 'itemtype', 'itemid', 'itemref'];

    /** Удаляются вместе с содержимым */
    private const DROP = ['script', 'style', 'noscript', 'template', 'object', 'embed', 'applet', 'param', 'base', 'link', 'title',
        'head', 'input', 'button', 'select', 'option', 'optgroup', 'textarea', 'datalist', 'keygen', 'output', 'frame', 'frameset',
        'noframes', 'noembed', 'svg', 'math', 'xml', 'audio', 'video', 'source', 'track', 'canvas', 'portal', 'xmp', 'plaintext'];

    /** Схемы ссылок <a href> (без схемы — относительный адрес, можно) */
    private const HREF_SCHEMES = ['http', 'https', 'mailto', 'tel', 'sms', 'viber', 'tg', 'whatsapp'];

    /** Теги без закрывающего */
    private const VOID = ['br', 'hr', 'img', 'col', 'wbr', 'meta'];

    /** Атрибуты-флаги: без значения выводятся без ="" */
    private const BOOL_ATTRS = ['itemscope', 'allowfullscreen', 'reversed', 'nowrap', 'noshade'];

    /** Что удалил последний clean(): ['<script>' => 1, 'onerror' => 2, …] — всё, включая мусорные атрибуты Word */
    private static array $removed = [];
    /** Только небезопасное из последнего clean() (для сообщения менеджеру) */
    private static array $unsafe = [];
    /** Небезопасное, удалённое staff() за этот запрос (сбрасывает notice()) */
    private static array $staffUnsafe = [];

    /**
     * HTML из формы админки: администратору — как есть, остальным сотрудникам — clean().
     * null → null; пустой после очистки → null (как у AdminCatalog::postHtml).
     */
    public static function staff(?string $html): ?string
    {
        if ($html === null || trim($html) === '' || Auth::isAdmin()) return $html;
        $out = self::clean($html);
        foreach (self::$unsafe as $k => $n) self::$staffUnsafe[$k] = (self::$staffUnsafe[$k] ?? 0) + $n;
        return trim($out) === '' ? null : $out;
    }

    /** Добавка к сообщению «Сохранено», если staff() что-то вырезал: « Из HTML удалено небезопасное: <script> ×1, onerror ×2.» */
    public static function notice(): string
    {
        if (!self::$staffUnsafe) return '';
        $parts = [];
        foreach (self::$staffUnsafe as $k => $n) $parts[] = $k . ($n > 1 ? ' ×' . $n : '');
        self::$staffUnsafe = [];
        return ' Из HTML удалено небезопасное (такое может сохранять только администратор): ' . implode(', ', array_slice($parts, 0, 12))
            . (count($parts) > 12 ? ' и др.' : '') . '.';
    }

    /** Подсказка под HTML-редактором для сотрудника без прав администратора (администратору — пусто) */
    public static function hint(): string
    {
        if (Auth::isAdmin()) return '';
        return '<small class="hint">При сохранении из HTML удаляются скрипты (&lt;script&gt;, onclick=…, onerror=…), ссылки javascript:, '
            . '&lt;style&gt;, формы и iframe не с YouTube / Google Maps. Остальная разметка и текст сохраняются.</small>';
    }

    /** Отчёт последнего clean(): [всё удалённое, только небезопасное] */
    public static function report(): array
    {
        return [self::$removed, self::$unsafe];
    }

    /** Очистка HTML по белому списку (см. описание класса) */
    public static function clean(string $html): string
    {
        self::$removed = self::$unsafe = [];
        if (trim($html) === '') return $html;
        if (!class_exists(\DOMDocument::class)) {              // без ext-dom — строгая очистка, как у описаний поставщиков
            self::note('разметка (нет расширения dom)', true);
            return self::supplier($html);
        }
        // не-ASCII → &#N; : libxml не перекодирует текст по <meta charset> внутри контента, кириллица не бьётся
        $src = mb_encode_numericentity(str_replace("\0", '', mb_scrub($html, 'UTF-8')), [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
        // теги с префиксом из Word (<o:p>, <st1:city>): libxml отрезает префикс, и <o:p> стал бы абзацем <p> —
        // переименовываем в заведомо неизвестные x-ns-o-p (раскрываются, текст остаётся)
        $src = (string) preg_replace_callback('#<(/?)([a-z][\w.-]*):([a-z][\w.:-]*)#i',
            static fn($m) => '<' . $m[1] . 'x-ns-' . $m[2] . '-' . str_replace(':', '-', $m[3]), $src);
        // </body></html> внутри контента libxml считает концом документа — остаток ушёл бы за пределы <body>
        $src = (string) preg_replace('#<!doctype[^>]*>|</?\s*(?:html|head|body)\b[^>]*>#i', '', $src);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $src . '</body></html>',
            LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $body = $ok ? $doc->getElementsByTagName('body')->item(0) : null;
        if (!$body) {
            self::note('разметка (не разобрана)', true);
            return self::supplier($html);
        }
        // на всякий случай: всё, что парсер вынес за пределы <body> (кроме нашего <head>), возвращаем в конец body
        foreach (iterator_to_array($doc->childNodes, false) as $top) {
            if (!$top instanceof \DOMElement) continue;
            foreach (iterator_to_array($top->childNodes, false) as $n) {
                if ($n === $body || ($top === $body->parentNode && $n instanceof \DOMElement && strtolower($n->nodeName) === 'head')) continue;
                $body->appendChild($n);
            }
        }
        self::walk($body);
        return self::serialize($body);
    }

    /**
     * Описание от поставщика (импорт товаров): только безопасные теги без атрибутов (защита от XSS на витрине).
     * Простой текст → экранированный, переводы строк → <br>.
     */
    public static function supplier(string $html): string
    {
        if ($html === strip_tags($html)) return nl2br(htmlspecialchars($html, ENT_QUOTES, 'UTF-8'), false);
        $html = (string) preg_replace('#<(script|style|iframe|object|embed|form|svg|math)\b[^>]*>.*?</\1\s*>#is', '', $html);
        $html = strip_tags($html, '<p><br><ul><ol><li><b><strong><i><em><u><table><thead><tbody><tr><td><th><h2><h3><h4><span><div>');
        $html = trim((string) preg_replace('#<([a-z][a-z0-9]*)\b[^>]*?(/?)>#i', '<$1$2>', $html));
        // только строчные теги (<b>, <i>…) — переводы строк из файла сохраняем как <br>
        if (!preg_match('#<(p|br|li|div|tr|h[2-4])\b#i', $html)) $html = nl2br($html, false);
        return $html;
    }

    // ------------------------------------------------------------------ обход дерева

    private static function walk(\DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes, false) as $n) {
            if ($n instanceof \DOMText) continue;                   // и CDATA — выводится как экранированный текст
            if ($n instanceof \DOMComment) {
                // комментарии невидимы; оставляем только простые (<!-- more --> из Webasyst), остальные — вектор mXSS («--!>», [if IE])
                if (!preg_match('/^[\w\s-]{0,64}$/u', $n->data)) { self::note('комментарий', false); $parent->removeChild($n); }
                continue;
            }
            if (!$n instanceof \DOMElement) { $parent->removeChild($n); continue; }
            $tag = strtolower($n->nodeName);
            if (in_array($tag, self::DROP, true)
                || ($tag === 'iframe' && !self::iframeOk($n->getAttribute('src')))
                || ($tag === 'meta' && trim($n->getAttribute('itemprop')) === '')) {
                self::note('<' . $tag . '>', true);
                $parent->removeChild($n);
                continue;
            }
            if (!isset(self::TAGS[$tag])) {                            // неизвестный тег: содержимое остаётся на его месте
                self::walk($n);
                while ($n->firstChild) $parent->insertBefore($n->firstChild, $n);
                $parent->removeChild($n);
                if (str_starts_with($tag, 'x-ns-')) $tag = (string) preg_replace('/^x-ns-([^-]+)-/', '$1:', $tag);   // для отчёта: o:p
                self::note('<' . $tag . '> (текст оставлен)', in_array($tag, ['form', 'fieldset', 'label', 'legend', 'marquee', 'blink'], true));
                continue;
            }
            self::attrs($n, $tag);
            if ($tag === 'iframe' || in_array($tag, self::VOID, true)) {
                while ($n->firstChild) $n->removeChild($n->firstChild);
                continue;
            }
            self::walk($n);
        }
    }

    private static function attrs(\DOMElement $el, string $tag): void
    {
        foreach (iterator_to_array($el->attributes, false) as $a) {
            $name = strtolower($a->nodeName);
            $v = (string) $a->value;
            $allowed = in_array($name, self::TAGS[$tag], true) || in_array($name, self::ATTRS, true) || preg_match('/^aria-[a-z]+$/', $name);
            $new = $allowed ? self::attrValue($tag, $name, $v) : null;
            if ($new === null) {
                $el->removeAttributeNode($a);
                $danger = str_starts_with($name, 'on') || in_array($name, ['srcdoc', 'formaction', 'action', 'xlink:href', 'srcset', 'background', 'dynsrc', 'lowsrc', 'ping'], true);
                if ($allowed && in_array($name, ['href', 'src'], true)) {
                    self::note($name . '=' . self::scheme($v) . ':', true);
                } elseif ($name !== 'style') {                        // style отчитывается сам (по объявлениям)
                    self::note($name, $danger);
                }
            } elseif ($new !== $v) {
                $a->value = $new;
            }
        }
    }

    /** Проверенное значение атрибута; null — удалить */
    private static function attrValue(string $tag, string $name, string $v): ?string
    {
        switch ($name) {
            case 'href':
                return self::urlOk($v, self::HREF_SCHEMES, false) ? $v : null;
            case 'src':
                if ($tag === 'iframe') return $v;                    // адрес iframe уже проверен (iframeOk)
                return self::urlOk($v, ['http', 'https'], $tag === 'img') ? $v : null;
            case 'style':
                return self::style($v);
            case 'target':
                return preg_match('/^_(blank|self|parent|top)$/i', trim($v)) ? trim($v) : null;
            case 'id':
            case 'name':
                // якоря: <a name="x">, <a name="_GoBack"> из Word, id="x"; только простые значения
                return preg_match('/^[A-Za-z_][\w\-:.]{0,63}$/', $v) ? $v : null;
            case 'loading':
                return in_array(strtolower(trim($v)), ['lazy', 'eager'], true) ? strtolower(trim($v)) : null;
            case 'rel':
                return preg_match('/^[\w\s-]{0,100}$/', $v) ? $v : null;
            default:
                return $v;
        }
    }

    /** Схема адреса для отчёта: «javascript», «data»… */
    private static function scheme(string $url): string
    {
        $s = strtolower((string) preg_replace('/[\x00-\x20\x7f]+/', '', $url));
        return preg_match('/^([a-z][a-z0-9+.\-]*):/', $s, $m) ? $m[1] : 'адрес';
    }

    /**
     * Адрес ссылки/картинки: относительный (/…, #…, ?…, путь, //хост) или со схемой из списка;
     * $dataImage — картинке можно data:image/…. Браузер игнорирует пробелы и управляющие символы в схеме («java\tscript:») — мы тоже.
     */
    private static function urlOk(string $url, array $schemes, bool $dataImage): bool
    {
        $s = strtolower((string) preg_replace('/[\x00-\x20\x7f]+/', '', $url));
        if (!preg_match('/^([a-z][a-z0-9+.\-]*):/', $s, $m)) return true;
        if ($m[1] === 'data') return $dataImage && (bool) preg_match('#^data:image/[a-z0-9.+\-]+[;,]#', $s);
        return in_array($m[1], $schemes, true);
    }

    /** iframe — только YouTube и Google Maps */
    private static function iframeOk(string $src): bool
    {
        $s = trim($src);
        if ($s === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $s)) return false;   // пробелы и «\» браузер трактует иначе, чем мы
        return (bool) preg_match('#^(?:https?:)?//(?:(?:www\.|m\.)?youtube\.com|(?:www\.)?youtube-nocookie\.com'
            . '|(?:www\.)?google\.com(?:\.ua)?/maps|maps\.google\.com(?:\.ua)?)(?:[/?\#]|$)#i', $s);
    }

    // ------------------------------------------------------------------ style="…"

    /** style: опасные объявления убираются, остальные остаются как есть; null — ничего не осталось */
    private static function style(string $css): ?string
    {
        if (!self::cssBad($css)) return $css;
        $keep = [];
        foreach (self::cssSplit($css) as $decl) {
            if (trim($decl) === '') continue;
            if (self::cssBad($decl)) { self::note('style: ' . mb_substr(trim($decl), 0, 40), true); continue; }
            $keep[] = trim($decl);
        }
        $out = implode('; ', $keep);
        if ($out !== '' && self::cssBad($out)) {                      // склейка через комментарии («expres/*;*/sion(») — не рискуем
            self::note('style', true);
            return null;
        }
        return $out === '' ? null : $out;
    }

    /** Опасное в CSS: проверяется текст без комментариев, с раскрытым экранированием (\65 xpression) и без пробелов */
    private static function cssBad(string $css): bool
    {
        $s = (string) preg_replace('#/\*.*?(?:\*/|$)#s', '', $css);
        $s = (string) preg_replace_callback('/\\\\([0-9a-fA-F]{1,6})\s?/', static function ($m) {
            $cp = (int) hexdec($m[1]);
            return $cp > 0 && $cp <= 0x10FFFF ? (string) mb_chr($cp, 'UTF-8') : "\u{FFFD}";
        }, $s);
        $s = (string) preg_replace('/\\\\(.)/su', '$1', $s);
        $s = strtolower((string) preg_replace('/[\x00-\x20\x7f]+/', '', $s));
        if (preg_match('/expression\(|javascript:|vbscript:|livescript:|behavio(?:u)?r|-moz-binding|@import/', $s)) return true;
        // url(…) и image-set(…) — только относительные, http(s) и data:image
        if (preg_match_all('/(?:url\(|image-set\()["\']?([^"\')]*)/', $s, $m)) {
            foreach ($m[1] as $u) {
                if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $u, $sm) && !in_array($sm[1], ['http', 'https'], true)
                    && !preg_match('#^data:image/#', $u)) return true;
            }
        }
        return false;
    }

    /** Объявления style по «;» вне кавычек и скобок */
    private static function cssSplit(string $css): array
    {
        $out = [];
        $buf = '';
        $q = '';
        $depth = 0;
        $len = strlen($css);
        for ($i = 0; $i < $len; $i++) {
            $ch = $css[$i];
            if ($q !== '') {
                if ($ch === '\\' && $i + 1 < $len) { $buf .= $ch . $css[++$i]; continue; }
                if ($ch === $q) $q = '';
            } elseif ($ch === '"' || $ch === "'") {
                $q = $ch;
            } elseif ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($ch === ';' && $depth === 0) {
                $out[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        $out[] = $buf;
        return $out;
    }

    // ------------------------------------------------------------------ вывод

    /** Дочерние узлы → HTML. Текст и атрибуты всегда экранируются; кириллица и адреса — как есть. */
    private static function serialize(\DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $c) {
            if ($c instanceof \DOMElement) {
                $tag = strtolower($c->nodeName);
                $out .= '<' . $tag;
                foreach ($c->attributes as $a) {
                    $name = strtolower($a->nodeName);
                    $v = (string) $a->value;
                    $out .= ' ' . $name . ($v === '' && in_array($name, self::BOOL_ATTRS, true) ? '' : '="' . self::esc($v, true) . '"');
                }
                $out .= '>';
                if (in_array($tag, self::VOID, true)) continue;
                $out .= self::serialize($c) . '</' . $tag . '>';
            } elseif ($c instanceof \DOMText) {
                $out .= self::esc($c->data, false);
            } elseif ($c instanceof \DOMComment) {
                $out .= '<!--' . $c->data . '-->';                    // только простые, проверены в walk()
            }
        }
        return $out;
    }

    private static function esc(string $s, bool $attr): string
    {
        $s = str_replace(['&', '<', '>', "\u{00A0}"], ['&amp;', '&lt;', '&gt;', '&nbsp;'], $s);
        return $attr ? str_replace('"', '&quot;', $s) : $s;
    }

    private static function note(string $what, bool $unsafe): void
    {
        self::$removed[$what] = (self::$removed[$what] ?? 0) + 1;
        if ($unsafe) self::$unsafe[$what] = (self::$unsafe[$what] ?? 0) + 1;
    }
}
