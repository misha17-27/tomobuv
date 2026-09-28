<?php
declare(strict_types=1);

namespace App\Services\Import;

/**
 * XML и YML (Яндекс.Маркет / Prom / Rozetka): потоковый XMLReader, каждый товар разворачивается
 * в DOM по отдельности и превращается в плоскую строку:
 *   атрибуты товара         → «@id», «@available»
 *   простые элементы        → «name», «price», «vendor» (повторяющиеся — через « | »: picture)
 *   <param name="Размер">   → «param:Размер»
 *   вложенные элементы      → «images/image»
 *   YML: categoryId         → дополнительно «category» (название) и «category_path» (путь в дереве поставщика)
 * Путь к элементу товара задаётся (item_path: «offer», «products/product») или определяется автоматически.
 */
final class XmlReader extends Reader
{
    private const FLAGS = self::XML_FLAGS;

    public function rows(array $state = []): \Generator
    {
        $this->state = $state;
        $want = self::pathParts((string) ($this->opt['item_path'] ?? ''));
        if (!$want) throw new \RuntimeException('Не указан путь к элементу товара');
        $yml = !empty($this->opt['yml']);
        if ($yml && !isset($this->state['cats'])) $this->state['cats'] = $this->categories();
        $cats = $this->state['cats'] ?? [];

        $r = $this->open();
        $doc = new \DOMDocument();
        $stack = [];
        $idx = 0;
        $skip = (int) ($state['n'] ?? 0);
        $prev = self::xmlErrorsOn();
        try {
            $ok = @$r->read();
            while ($ok) {
                self::checkDoctype($r, true);
                if ($r->nodeType === \XMLReader::ELEMENT) {
                    $d = $r->depth;
                    $stack[$d] = strtolower($r->localName);
                    if (count($stack) > $d + 1) $stack = array_slice($stack, 0, $d + 1);
                    if (self::matches($stack, $want)) {
                        $idx++;
                        if ($idx > $skip) {
                            $node = @$r->expand($doc);
                            if (!$node instanceof \DOMElement) self::badNode('Товар №' . $idx);
                            $this->state['n'] = $idx;
                            $row = self::flatten($node);
                            if ($yml) self::ymlCategory($row, $cats);
                            if ($row) yield $idx => $row;
                        }
                        $ok = @$r->next();
                        continue;
                    }
                }
                $ok = @$r->read();
            }
            self::xmlFatal($prev);
        } finally {
            $r->close();
            libxml_use_internal_errors($prev);
        }
    }

    private function open(): \XMLReader
    {
        $r = new \XMLReader();
        if (!$r->open($this->path, null, self::FLAGS)) throw new \RuntimeException('Не удалось открыть XML');
        return $r;
    }

    /** Совпадает ли конец пути $stack с искомым путём */
    private static function matches(array $stack, array $want): bool
    {
        $k = count($want);
        if (count($stack) < $k) return false;
        return array_slice($stack, -$k) === $want;
    }

    private static function pathParts(string $p): array
    {
        return array_values(array_filter(array_map(static fn($s) => strtolower(trim($s)), explode('/', trim($p, "/ \t")))));
    }

    /** Элемент товара → [ключ => значение] */
    private static function flatten(\DOMElement $el): array
    {
        $out = [];
        foreach ($el->attributes as $a) $out['@' . $a->name] = trim($a->value);
        self::walk($el, '', $out, 0);
        return $out;
    }

    private static function walk(\DOMElement $el, string $prefix, array &$out, int $depth): void
    {
        foreach ($el->childNodes as $c) {
            if (!$c instanceof \DOMElement) continue;
            $name = $c->localName;
            $hasEl = false; $hasText = false;
            foreach ($c->childNodes as $x) {
                if ($x instanceof \DOMElement) $hasEl = true;
                elseif (($x instanceof \DOMText || $x instanceof \DOMCdataSection) && trim($x->nodeValue ?? '') !== '') $hasText = true;
            }
            $attrName = $c->getAttribute('name');
            if ($hasEl && !$hasText && $depth < 4) {                         // вложенная структура
                self::walk($c, $prefix . $name . '/', $out, $depth + 1);
                continue;
            }
            $key = $prefix . ($attrName !== '' ? $name . ':' . trim($attrName) : $name);
            if ($hasEl) {                                                     // смешанное содержимое (HTML в описании)
                $v = '';
                foreach ($c->childNodes as $x) $v .= (string) $c->ownerDocument->saveXML($x);
                $v = trim($v);
            } else {
                $v = trim((string) $c->textContent);
            }
            if ($v === '' && $c->attributes->length) {                        // <image url="…"/>
                foreach ($c->attributes as $a) {
                    if ($a->name === 'name') continue;
                    self::put($out, $key . '@' . $a->name, trim($a->value));
                }
                continue;
            }
            self::put($out, $key, $v);
        }
    }

    private static function put(array &$out, string $key, string $v): void
    {
        $key = mb_substr($key, 0, 100);
        if (isset($out[$key]) && $out[$key] !== '') {
            if ($v !== '') $out[$key] .= ' | ' . $v;
        } else {
            $out[$key] = $v;
        }
    }

    /** YML: название и путь категории поставщика по categoryId */
    private static function ymlCategory(array &$row, array $cats): void
    {
        $cid = $row['categoryId'] ?? '';
        if ($cid === '' || !isset($cats[$cid])) return;
        $path = []; $guard = 0; $id = $cid;
        while ($id !== '' && isset($cats[$id]) && $guard++ < 10) {
            array_unshift($path, $cats[$id][0]);
            $id = $cats[$id][1];
        }
        $row['category'] = $cats[$cid][0];
        $row['category_path'] = implode(' > ', $path);
    }

    /** YML: дерево категорий поставщика [id => [название, parentId]] (содержимое offer пропускается) */
    private function categories(): array
    {
        $r = $this->open();
        $doc = new \DOMDocument();
        $out = [];
        $prev = self::xmlErrorsOn();
        try {
            $ok = @$r->read();
            while ($ok) {
                self::checkDoctype($r, true);
                if ($r->nodeType === \XMLReader::ELEMENT) {
                    $n = strtolower($r->localName);
                    if ($n === 'category') {
                        $id = (string) $r->getAttribute('id');
                        $parent = (string) ($r->getAttribute('parentId') ?? '');
                        $node = @$r->expand($doc);
                        if ($id !== '' && $node) $out[$id] = [trim((string) $node->textContent), $parent];
                        $ok = @$r->next();
                        continue;
                    }
                    if ($n === 'offer') { $ok = @$r->next(); continue; }
                    if ($n === 'offers' && $out) break;                         // категории обычно перед товарами
                }
                $ok = @$r->read();
            }
        } finally {
            $r->close();
            libxml_clear_errors();                                            // ошибки разметки покажет чтение товаров
            libxml_use_internal_errors($prev);
        }
        return $out;
    }

    /**
     * Проверка XML и поиск элемента товара: YML — offers/offer; иначе самый частый элемент
     * с дочерними элементами (по первым ~5000 элементам).
     */
    public static function prepareFile(string $path, string $itemPath = ''): array
    {
        // объявления сущностей (<!ENTITY>) в прайсах не нужны, а «XML-бомба» из них может положить сервер
        $h = fopen($path, 'rb');
        $head = (string) fread($h, 65536);
        fclose($h);
        if (stripos($head, '<!ENTITY') !== false) throw new \RuntimeException('XML с объявлениями <!ENTITY> не поддерживается — выгрузите файл без DOCTYPE');
        $r = new \XMLReader();
        if (!@$r->open($path, null, self::FLAGS)) throw new \RuntimeException('Файл не похож на XML');
        $stack = []; $counts = []; $hasChildren = []; $root = ''; $seen = 0; $yml = false;
        $prevErr = self::xmlErrorsOn();
        try {
            while (@$r->read()) {
                self::checkDoctype($r, true);                                 // <!ENTITY> дальше первых 64 КБ (после длинного комментария)
                if ($r->nodeType !== \XMLReader::ELEMENT) continue;
                $d = $r->depth;
                $stack[$d] = strtolower($r->localName);
                if (count($stack) > $d + 1) $stack = array_slice($stack, 0, $d + 1);
                if ($d === 0) $root = $stack[0];
                $p = implode('/', $stack);
                $counts[$p] = ($counts[$p] ?? 0) + 1;
                if ($d > 0) $hasChildren[implode('/', array_slice($stack, 0, $d))] = true;
                if ($stack[$d] === 'offer' && ($stack[$d - 1] ?? '') === 'offers') $yml = true;
                if (++$seen > 5000) break;
            }
        } finally {
            $r->close();
            $err = libxml_get_last_error();
            libxml_clear_errors();
            libxml_use_internal_errors($prevErr);
        }
        $valid = $root !== '';
        if (!$valid) throw new \RuntimeException('Файл не похож на XML' . ($err ? ' (' . trim((string) $err->message) . ')' : ''));
        if ($root === 'yml_catalog') $yml = true;
        if ($itemPath === '') {
            if ($yml) {
                $itemPath = 'offers/offer';
            } else {
                $best = ''; $bestN = 1; $bestDepth = 99;
                foreach ($counts as $p => $n) {
                    if (empty($hasChildren[$p])) continue;
                    $depth = substr_count($p, '/');
                    if ($depth === 0) continue;
                    if ($n > $bestN || ($n === $bestN && $depth < $bestDepth)) { $best = $p; $bestN = $n; $bestDepth = $depth; }
                }
                if ($best === '') throw new \RuntimeException('Не удалось найти в XML повторяющийся элемент товара — укажите путь вручную');
                $parts = explode('/', $best);
                $itemPath = implode('/', array_slice($parts, -2));
            }
        }
        return ['item_path' => $itemPath, 'yml' => $yml ? 1 : 0];
    }
}
