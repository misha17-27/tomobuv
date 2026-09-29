<?php
declare(strict_types=1);

namespace App\Core;

/**
 * SEO-мета страницы. Логика повторяет плагин SEO старого сайта (Webasyst shop.seo):
 *   1) если у товара/категории/страницы заполнен свой meta_title/description — берётся он (как есть);
 *   2) иначе — шаблон из настроек (seo.product_meta_title и т.д.) с переменными
 *      {$product.name}, {$category.full_name}, {$store_info.name}, {$page_number}…;
 *   3) на страницах пагинации (?page=N) к title и description добавляется « | Страница N» (один раз: если номер
 *      страницы в тексте уже есть, суффикса нет), canonical указывает на первую страницу.
 * Шаблоны перенесены из shop_seo_storefront_settings и редактируются в админке (Настройки → SEO).
 *
 * Норма длины (как в SEO-обзоре): title 30–70 символов, description 70–170. Результат шаблона подгоняется под норму:
 *   [[…]]   — необязательная часть: пропадает, если в ней пустая переменная (пусто или «0»), а если текст длиннее
 *             нормы — такие части убираются справа налево (последней в шаблоне пишется наименее важная);
 *   {$x|plural:пара,пары,пар} — число со склонением: «8 пар» (UA — «пара,пари,пар»); 0 или не число — пусто;
 *   остальные модификаторы Smarty из старых шаблонов ({$x|escape}) игнорируются, как раньше;
 *   после подстановки схлопываются пробелы, пробелы перед знаками препинания, с краёв снимаются « — | , ; :»;
 *   если текст всё ещё длиннее нормы — Seo::fit (title — по слову без «…», description — по предложению); у обрезанного
 *   по слову снимаются незакрытая скобка с хвостом и обрывки («…бронзовый 4» от «4 пары:…») — Seo::cutTail.
 * Старые шаблоны вида «{$product.name} купить…» работают как раньше (без «[[» и модификаторов).
 */
final class Seo
{
    public const TITLE_MIN = 30;
    public const TITLE_MAX = 70;
    public const DESC_MIN = 70;
    public const DESC_MAX = 170;
    /** Поле → [минимум, максимум] */
    public const LIMITS = ['title' => [self::TITLE_MIN, self::TITLE_MAX], 'description' => [self::DESC_MIN, self::DESC_MAX]];

    /** Висячие предлоги и союзы, которые не оставляем в конце обрезанного текста (RU и UA) */
    private const HANGING = ['в', 'во', 'и', 'с', 'со', 'на', 'от', 'для', 'по', 'за', 'к', 'ко', 'о', 'об', 'у', 'из', 'а', 'но', 'или',
        'без', 'до', 'при', 'под', 'над', 'про', 'через', 'не', 'з', 'із', 'зі', 'від', 'або', 'та', 'й', 'і', 'під', 'чи'];

    private const VAR_RE = '/\{\$([a-z_]+)(?:\.([a-z_]+))?((?:\|[^}]*)?)\}/i';

    public string $title = '';
    public string $description = '';
    public string $keywords = '';
    public string $h1 = '';
    public ?string $canonical = null;
    public ?string $robots = null;          // 'noindex, follow' и т.п.
    public string $ogType = 'website';
    public ?string $ogImage = null;
    public ?string $ogTitle = null;
    public ?string $ogDescription = null;
    public array $jsonLd = [];              // массивы schema.org, выводятся в <head>
    public ?string $prev = null;
    public ?string $next = null;

    /** Разобранные шаблоны: текст → [[кусок, необязательный], …] */
    private static array $parsed = [];

    public static function make(string $title = '', string $description = '', string $keywords = ''): self
    {
        $s = new self();
        $s->title = $title;
        $s->description = $description;
        $s->keywords = $keywords;
        return $s;
    }

    /** Подстановка переменных в шаблон ({$product.name}, {$page_number}, [[…]], |plural) — без подгонки длины */
    public static function tpl(?string $template, array $vars): string
    {
        return self::build($template, $vars, null);
    }

    /**
     * Результат шаблона для поля $field (title | description; null — keywords, h1 — без подгонки длины).
     * $fit = false — без последней обрезки Seo::fit (для оценки, укладывается ли шаблон в норму сам).
     */
    public static function build(?string $template, array $vars, ?string $field = null, bool $fit = true): string
    {
        if ($template === null || trim($template) === '') return '';
        $parts = self::parse($template);
        $strict = count($parts) > 1 || ($parts[0][1] ?? false);     // шаблон нового вида (с [[…]])
        $kept = [];
        foreach ($parts as $i => [$chunk, $optional]) {
            $empty = false;
            $missing = false;
            $text = (string) preg_replace_callback(self::VAR_RE, static function (array $m) use ($vars, &$empty, &$missing): string {
                $v = self::value($vars, $m[1], $m[2] ?? '', $m[3] ?? '');
                if ($v === '' || $v === '0') $empty = true;
                if ($v === '') $missing = true;
                return $v;
            }, $chunk);
            if ($optional && $empty) continue;            // необязательная часть с пустой переменной
            // в шаблоне нового вида пустая переменная обязательной части (нет названия) — текста нет, сайт возьмёт запасной
            // (название, пусто); старые шаблоны Webasyst — как раньше, с пустым местом
            if (!$optional && $missing && $strict) return '';
            $kept[$i] = [$text, $optional];
        }
        $out = self::join($kept);
        $max = self::LIMITS[$field][1] ?? null;
        if ($max !== null) {
            // длиннее нормы — убираем необязательные части справа налево
            $opt = array_keys(array_filter($kept, static fn($x) => $x[1]));
            while (mb_strlen($out) > $max && $opt) {
                unset($kept[array_pop($opt)]);
                $out = self::join($kept);
            }
            if ($fit) $out = self::fit($out, $field);
        }
        return $out;
    }

    /** Шаблон → куски: обычный текст и необязательные части [[…]] (без вложенности) */
    private static function parse(string $template): array
    {
        if (isset(self::$parsed[$template])) return self::$parsed[$template];
        $parts = [];
        foreach (preg_split('/(\[\[.*?\]\])/su', $template, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $p) {
            $opt = str_starts_with($p, '[[') && str_ends_with($p, ']]');
            $parts[] = [$opt ? substr($p, 2, -2) : $p, $opt];
        }
        if (count(self::$parsed) > 200) self::$parsed = [];
        return self::$parsed[$template] = $parts;
    }

    /** Значение переменной с модификатором: plural — «8 пар», прочие модификаторы старых шаблонов — без изменений */
    private static function value(array $vars, string $a, string $b, string $mod): string
    {
        $v = $vars[$a] ?? '';
        if ($b !== '') $v = is_array($v) ? ($v[$b] ?? '') : '';
        $v = is_scalar($v) ? trim((string) $v) : '';
        if ($mod !== '' && preg_match('/^\|\s*plural\s*:(.+)$/u', $mod, $m)) {
            $forms = array_map('trim', explode(',', $m[1]));
            $n = (int) preg_replace('/\D/', '', $v);
            if ($v === '' || !preg_match('/^[\d\s]+$/u', $v) || $n <= 0 || count($forms) < 3) return '';
            return number_format($n, 0, '', ' ') . ' ' . plural($n, $forms[0], $forms[1], $forms[2]);
        }
        return $v;
    }

    /** Склеить куски и привести текст в порядок: пробелы, знаки препинания, края */
    private static function join(array $kept): string
    {
        return self::tidy(implode('', array_column($kept, 0)));
    }

    /**
     * Чистка текста: один пробел вместо нескольких, без пробела перед знаком препинания, края без « — | , ; :».
     * Края — только регуляркой /u: trim() с «—» в списке символов режет байты UTF-8 (ломает кириллицу на «р»).
     */
    public static function tidy(string $s): string
    {
        $s = (string) preg_replace('/\s+/u', ' ', $s);
        $s = (string) preg_replace('/ ([,.;:!?…)])/u', '$1', $s);
        $s = (string) preg_replace('/([(«]) /u', '$1', $s);
        $s = (string) preg_replace('/([,;:])(?:\s*[,;:])+/u', '$1', $s);      // «, ,» от пустых подстановок
        return (string) preg_replace('/^[\s—–\-|,;:]+|[\s—–\-|,;:]+$/u', '', $s);
    }

    /** Поле по ключу настройки: …meta_title → title, …meta_description → description, прочее (h1, keywords) — null */
    public static function fieldOf(string $key): ?string
    {
        if (str_ends_with($key, '.uk')) $key = substr($key, 0, -3);
        return str_ends_with($key, 'meta_title') ? 'title' : (str_ends_with($key, 'meta_description') ? 'description' : null);
    }

    /** Длина в норме: title 30–70, description 70–170 (по тексту без пробелов по краям) */
    public static function inNorm(string $text, string $field): bool
    {
        [$min, $max] = self::LIMITS[$field === 'title' ? 'title' : 'description'];
        $n = mb_strlen(trim($text));
        return $n >= $min && $n <= $max;
    }

    /**
     * Подогнать длиннее нормы: title — по слову без «…» (с края снимаются знаки и висячие предлоги/союзы);
     * description — по концу предложения, если остаётся не меньше минимума, иначе по слову + «…».
     */
    public static function fit(string $text, string $field): string
    {
        $field = $field === 'title' ? 'title' : 'description';
        [$min, $max] = self::LIMITS[$field];
        $text = trim($text);
        if (mb_strlen($text) <= $max) return $text;
        if ($field === 'description') {
            $cut = self::sentenceCut($text, $min, $max);
            if ($cut !== '') return $cut;
            return self::wordCut($text, $max - 1, $min) . '…';
        }
        return self::wordCut($text, $max, $min);
    }

    /** Самый длинный кусок из целых предложений длиной $min…$max ('' — такого нет) */
    public static function sentenceCut(string $text, int $min, int $max): string
    {
        $best = '';
        // конец предложения: . ! ? … перед пробелом и заглавной буквой/цифрой/кавычкой или в конце текста
        if (preg_match_all('/[.!?…](?=\s+[\p{Lu}\d«"„]|\s*$)/u', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$ch, $off]) {
                $len = mb_strlen(substr($text, 0, $off)) + 1;
                if ($len > $max) break;
                if ($len >= $min) $best = mb_substr($text, 0, $len);
            }
        }
        return rtrim($best);
    }

    /**
     * Обрезка по слову не длиннее $max: без висячих знаков, предлогов и союзов в конце, без незакрытой скобки
     * и обрывков отрезанного (cutTail). Обрывки снимаются, только пока текст не короче $min.
     */
    public static function wordCut(string $text, int $max, int $min = 0): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $max) return $text;
        $cut = mb_substr($text, 0, $max + 1);
        $sp = mb_strrpos($cut, ' ');
        $cut = $sp !== false && $sp > 0 ? mb_substr($cut, 0, $sp) : mb_substr($text, 0, $max);
        return self::cutTail($cut, mb_substr($text, mb_strlen($cut)), $min);
    }

    /**
     * Край обрезанного текста ($rest — отрезанная часть). Кроме висячих знаков, предлогов и союзов (cleanTail) снимаются:
     *   незакрытая скобка/кавычка с хвостом: «УЦЕНКА(брак: отклеиваются цепочки» → «УЦЕНКА» (если без хвоста текст короче
     *   $min — только сам знак);
     *   обрывок перечня, который продолжается в отрезанном: «…РОЗПРОДАЖ 4пари:23» (дальше «; 23,5; 24,5») → «…РОЗПРОДАЖ»;
     *   число, от которого отрезана единица: «…бронзовый 4» (дальше «пары:36;37») → «…бронзовый»; знак-символ в конце.
     * Целые значения («2 пары:36; 40», «R200964085 W», «Nike 90» перед «оптом») не трогаются.
     */
    public static function cutTail(string $s, string $rest, int $min = 0): string
    {
        $s = self::cleanTail($s);
        $unit = (bool) preg_match('/^\s*(?:пар|шт|р-?р|разм|розм|см\b|мм\b|%)/iu', $rest);
        $list = (bool) preg_match('/^[\s;,.!]*\d/u', $rest);
        for ($i = 0; $i < 6; $i++) {
            $prev = $s;
            foreach (['(' => ')', '«' => '»', '[' => ']'] as $open => $close) {
                if (mb_substr_count($s, $open) <= mb_substr_count($s, $close)) continue;
                $pos = self::unmatched($s, $open, $close);
                if ($pos === null) continue;
                $head = self::cleanTail(mb_substr($s, 0, $pos));
                $s = $head !== '' && mb_strlen($head) >= $min ? $head
                    : self::cleanTail((string) preg_replace('/\s+/u', ' ', mb_substr($s, 0, $pos) . ' ' . mb_substr($s, $pos + 1)));
            }
            // «N пар:36;37» / «4пари:23» — перечень оборван: отрезанное начинается с числа
            if ($list && preg_match('/\s\d+\s?пар\p{L}*\s?:?[\d\s,.;:!]*$/u', $s, $m, PREG_OFFSET_CAPTURE)) {
                $head = self::cleanTail(substr($s, 0, $m[0][1]));
                if ($head !== '' && mb_strlen($head) >= $min) $s = $head;
            }
            // число без единицы («4» от «4 пары»), не часть перечня («36; 40»); одиночный знак-символ
            if (($unit && preg_match('/(?<![;,:])\s\d{1,3}$/u', $s, $m, PREG_OFFSET_CAPTURE))
                || preg_match('/\s[^\p{L}\p{N}\s]$/u', $s, $m, PREG_OFFSET_CAPTURE)) {
                $head = self::cleanTail(substr($s, 0, $m[0][1]));
                if ($head !== '' && mb_strlen($head) >= $min) $s = $head;
            }
            if ($s === $prev) break;
        }
        return $s;
    }

    /**
     * Обрывок в конце title — признак неудачной обрезки прежними правилами: незакрытая скобка, число без единицы
     * («…бронзовий 4»), оборванный перечень («…РОЗПРОДАЖ 4пари:23»). Целые «2 пары:36;37», «47-50» — не обрывок.
     */
    public static function badTail(string $s): bool
    {
        $s = trim($s);
        return mb_substr_count($s, '(') > mb_substr_count($s, ')')
            || (bool) preg_match('/(?<![;,:])\s\d{1,3}$/u', $s)
            || (bool) preg_match('/\s\d+\s?пар\p{L}*\s?:?\s*\d*$/u', $s);
    }

    /** Позиция (в символах) последней незакрытой открывающей скобки $open или null */
    private static function unmatched(string $s, string $open, string $close): ?int
    {
        $stack = [];
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($chars as $i => $ch) {
            if ($ch === $open) $stack[] = $i;
            elseif ($ch === $close && $stack) array_pop($stack);
        }
        return $stack ? (int) end($stack) : null;
    }

    /** Снять с конца знаки препинания и висячие короткие слова (предлоги, союзы) */
    public static function cleanTail(string $s): string
    {
        $hang = implode('|', array_map(static fn($w) => preg_quote($w, '/'), self::HANGING));
        do {
            $prev = $s;
            $s = (string) preg_replace('/[\s,;:—–\-|(\/«„+&]+$/u', '', $s);
            // прямая кавычка в конце — только открывающая (их нечётное число), закрывающую «"Lian Xin"» не снимаем
            if (str_ends_with($s, '"') && substr_count($s, '"') % 2 === 1) $s = substr($s, 0, -1);
            $s = (string) preg_replace('/\s+(?:' . $hang . ')$/iu', '', $s);
        } while ($s !== $prev && $s !== '');
        return $s;
    }

    /**
     * Описание из текста страницы или статьи: первые связные абзацы, $min…$max символов.
     * Скрытые блоки микроразметки (display:none), заголовки, скрипты не берутся; перевод строки внутри абзаца — не граница.
     * Связный абзац — от 8 слов, не кончается «:», не перечень через запятую. Обрезка — по предложению, иначе по слову + «…».
     * Связного текста нет (или короче нормы description) — ''.
     */
    public static function excerpt(?string $html, int $min = 120, int $max = 160): string
    {
        $html = (string) $html;
        if (trim(strip_tags($html)) === '') return '';
        $paras = self::paragraphs($html);
        $take = [];
        $len = 0;
        foreach ($paras as $p) {
            $words = preg_split('/\s+/u', $p, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $letters = array_filter($words, static fn($w) => preg_match('/\p{L}{2,}/u', $w));
            $isList = !preg_match('/[.!?…]/u', $p) && substr_count($p, ',') >= 2 && substr_count($p, ',') * 3 >= count($words);
            $ok = count($letters) >= 8 && !preg_match('/:\s*$/u', $p) && !$isList;
            if (!$ok) {
                if ($take) break;               // только подряд идущие связные абзацы
                continue;
            }
            $take[] = $p;
            $len += mb_strlen($p) + 1;
            if ($len >= $max) break;
        }
        if (!$take) return '';
        $text = self::tidy(implode(' ', $take));
        if (mb_strlen($text) > $max) {
            $cut = self::sentenceCut($text, $min, $max);
            $text = $cut !== '' ? $cut : self::wordCut($text, $max - 1) . '…';
        }
        return mb_strlen($text) >= self::DESC_MIN ? $text : '';
    }

    /** Абзацы видимого текста HTML (DOM): блоки p/div/li/td…, без скрытых и служебных элементов */
    private static function paragraphs(string $html): array
    {
        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new \DOMXPath($doc);
        $drop = $xp->query('//script|//style|//noscript|//iframe|//h1|//h2|//h3|//h4|//h5|//h6|//*[contains(translate(@style, "DISPLAYNOE ", "displaynoe"), "display:none")]');
        foreach ($drop ?: [] as $n) if ($n->parentNode) $n->parentNode->removeChild($n);
        $blocks = ['p', 'div', 'li', 'ul', 'ol', 'td', 'th', 'tr', 'table', 'blockquote', 'section', 'article', 'header', 'footer', 'dd', 'dt', 'br', 'hr', 'figure', 'figcaption'];
        $buf = '';
        $walk = static function (\DOMNode $n) use (&$walk, &$buf, $blocks): void {
            foreach ($n->childNodes as $c) {
                if ($c instanceof \DOMText) { $buf .= $c->nodeValue; continue; }
                if (!$c instanceof \DOMElement) continue;
                $block = in_array(strtolower($c->nodeName), $blocks, true);
                if ($block) $buf .= "\x01";
                $walk($c);
                if ($block) $buf .= "\x01";
            }
        };
        $root = $doc->getElementsByTagName('body')->item(0) ?? $doc->documentElement;
        if ($root) $walk($root);
        $out = [];
        foreach (explode("\x01", $buf) as $p) {
            $p = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', html_entity_decode($p, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($p !== '') $out[] = $p;
        }
        return $out;
    }

    /** Текст для сравнения: строчными, один пробел, без точки в конце */
    public static function norm(string $s): string
    {
        return rtrim(mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s))), '.');
    }

    /** Название из «купить X в Одессе» / «купити X в Одесі» ('' — не такой вид) */
    public static function machineName(string $desc): string
    {
        return preg_match('/^\s*купи(?:ть|ти)\s+(.+?)\s+в\s+Одес(?:се|і)\s*\.?\s*$/iu', $desc, $m) ? trim($m[1]) : '';
    }

    /**
     * «Машинное» своё значение из выгрузки Forsage в Webasyst: title = название (или название из «купить X в Одессе» —
     * устаревшее имя; или прежнее название с другим типом: «Босоножки 68130A» у товара «Туфли 68130A»), description вида
     * «купить … в Одессе» / «купити … в Одесі». Сравнение без регистра, лишних пробелов и точки в конце.
     * $names — названия объекта на обоих языках.
     */
    public static function isMachine(string $own, array $names, string $field): bool
    {
        $n = self::norm($own);
        if ($n === '') return false;
        if ($field !== 'title') return self::machineName($own) !== '';
        $rest = static fn(string $s): string => (string) preg_replace('/^\S+\s+/u', '', $s);   // без первого слова (типа товара)
        foreach ($names as $x) {
            $x = self::norm((string) $x);
            if ($x === '') continue;
            if ($x === $n) return true;
            // тот же артикул, другой тип: одно слово типа + остаток с цифрой («68130a»), слов столько же
            if (substr_count($x, ' ') === substr_count($n, ' ') && $rest($x) === $rest($n) && $rest($n) !== $n && preg_match('/\d/', $rest($n))) return true;
        }
        return false;
    }

    /** Общие переменные магазина для шаблонов */
    public static function storeInfo(): array
    {
        return [
            'name'  => (string) Settings::get('store_name', 'Tomobuv'),
            'phone' => (string) Settings::get('store_phone', '+(380) 932753070'),
        ];
    }

    /**
     * Взять собственное значение (как есть), иначе шаблон из настроек. Поле для подгонки длины — по ключу
     * (…meta_title, …meta_description); у h1 и keywords длина не подгоняется.
     */
    public static function pick(?string $own, string $settingKey, array $vars): string
    {
        $own = trim((string) $own);
        if ($own !== '') return $own;
        return self::build((string) Settings::get($settingKey, ''), $vars + ['store_info' => self::storeInfo()], self::fieldOf($settingKey));
    }

    /** Пагинация как на старом сайте: « | Страница N» + canonical на первую страницу */
    public function paginate(int $page, string $basePath, int $pages = 0): self
    {
        if ($page > 1) {
            if ($this->title !== '') $this->title = self::pageSuffix($this->title, $page);
            if ($this->description !== '') $this->description = self::pageSuffix($this->description, $page);
            $this->canonical = url($basePath);
        }
        return $this;
    }

    /**
     * « | Страница N» (на /ua/ — « | Сторінка N») к title/description страницы 2, 3…; номер страницы в тексте уже есть
     * (шаблон «… — страница {$page_number}») — без второго суффикса.
     */
    public static function pageSuffix(string $text, int $page): string
    {
        if ($page <= 1 || preg_match('/(?:страниц|сторінк)\p{L}*\s*' . $page . '(?!\d)/iu', $text)) return $text;
        return $text . ' | ' . t('Страница') . ' ' . $page;
    }

    public function ogTitle(): string
    {
        return $this->ogTitle ?? $this->title;
    }

    public function ogDescription(): string
    {
        return $this->ogDescription ?? $this->description;
    }
}
