<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Helper;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Throwable;

/**
 * 富文本 HTML 的**服务端白名单清洗**。
 *
 * 为什么必须做在服务端：前端清洗可以被绕过（直接 POST cURL 就行），
 * 而富文本的值最终会以 `v-html` 渲染，等于一个持久化 XSS 入口。
 *
 * 策略（宁严勿宽）：
 *
 *  1. 先做**中文/Quill 方言归一化**：Quill 的列表是 `<ol><li data-list="bullet">`、
 *     代码块是 `<div class="ql-code-block-container">`，这里都转成标准 HTML
 *     （`<ul><li>` / `<pre><code>`），这样落库的内容不绑定编辑器
 *  2. 标签白名单：不在名单里的**直接拆掉外壳保留文字**；
 *     `script/style/iframe/object/embed/svg/math/form/input/...` 连内容一起删
 *  3. 属性白名单：逐标签限定；`on*`、`style`、`srcdoc`、`formaction` 一律剥掉
 *  4. 协议白名单：`href` 只放 http/https/mailto/tel/相对；`src` 另加 data:image
 *  5. `a[target=_blank]` 自动补 `rel="noopener noreferrer"`
 *
 * 已知取舍（v1）：不保留 `class`（除了 code 的 `language-*` 与 Quill 的高亮类）、
 * 不保留 `style`（颜色/对齐会被丢弃）。想放宽改 {@see ALLOWED}。
 */
final class HtmlSanitizer
{
    /**
     * 允许的标签 => 允许的属性。
     *
     * @var array<string, array<int, string>>
     */
    private const ALLOWED = [
        'p' => ['class'],
        'br' => [],
        'hr' => [],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'u' => [],
        's' => [],
        'del' => [],
        'ins' => [],
        'sub' => [],
        'sup' => [],
        'h1' => [],
        'h2' => [],
        'h3' => [],
        'h4' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'blockquote' => [],
        'pre' => ['class'],
        'code' => ['class'],
        'span' => ['class'],
        'a' => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
        'table' => [],
        'thead' => [],
        'tbody' => [],
        'tr' => [],
        'th' => [],
        'td' => [],
    ];

    /**
     * 连内容一起丢弃的标签（不是「拆壳」）。
     *
     * @var array<int, string>
     */
    private const DROP = [
        'script', 'style', 'iframe', 'object', 'embed', 'applet', 'frame', 'frameset',
        'svg', 'math', 'form', 'input', 'button', 'select', 'textarea', 'option',
        'link', 'meta', 'base', 'noscript', 'template', 'canvas', 'audio', 'video',
        'source', 'track', 'map', 'area',
    ];

    /**
     * `class` 只在这些标签、且匹配这些前缀时才保留（Quill / highlight.js 的产物）。
     */
    private const CLASS_ALLOW = '/^(?:language-[a-z0-9+#-]+|ql-syntax|ql-token|hljs(?:-[a-z-]+)?)$/i';

    /** 单元格预览的默认截断长度 0 表示不截断 */
    private int $limit = 0;

    /** 是否允许外链图片（对应 CSP 的 img-src 放宽） */
    private bool $externalImages = true;

    public static function make(): self
    {
        $instance = new self();

        try {
            $instance->externalImages = (bool) Auth::config('rich_text.external_images', true);
        } catch (Throwable) {
            // 没有容器（纯单测）时用默认值
        }

        return $instance;
    }

    public function withLimit(int $limit): self
    {
        $this->limit = max(0, $limit);

        return $this;
    }

    /**
     * 清洗并归一化。`$html` 为 null / 空串时返回 `''`。
     */
    public function clean(?string $html): string
    {
        $html = (string) ($html ?? '');

        if (trim($html) === '') {
            return '';
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        // 用 wrapper 承载片段：不这样包一层，loadHTML 会补 <html><body>
        $doc->loadHTML(
            '<?xml encoding="UTF-8"><div data-ea-root="1">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $this->findRoot($doc);

        if (! $root instanceof DOMElement) {
            return '';
        }

        $this->normalize($root);
        $this->walk($root);

        $output = '';

        foreach ($root->childNodes as $child) {
            $output .= $doc->saveHTML($child);
        }

        return trim($output);
    }

    /**
     * 只取纯文本（列表单元格预览用），并做截断。
     */
    public function text(?string $html, int $limit = 80): string
    {
        $clean = $this->clean($html);

        if ($clean === '') {
            return '';
        }

        $text = html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($limit > 0 && mb_strlen($text, 'UTF-8') > $limit) {
            return mb_substr($text, 0, $limit, 'UTF-8') . '…';
        }

        return $text;
    }

    /* ------------------------------------------------------------ 归一化 --- */

    /**
     * Quill 方言 → 标准 HTML。
     */
    private function normalize(DOMElement $root): void
    {
        // 1. 代码块：<div class="ql-code-block-container"><div class="ql-code-block">行</div></div>
        foreach ($this->query($root, '//div[contains(@class,"ql-code-block-container")]') as $container) {
            $lines = [];

            foreach ($container->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    $lines[] = (string) $child->textContent;
                }
            }

            $pre = $container->ownerDocument->createElement('pre');
            $code = $container->ownerDocument->createElement('code');
            $code->appendChild($container->ownerDocument->createTextNode(implode("\n", $lines)));
            $pre->appendChild($code);
            $container->parentNode?->replaceChild($pre, $container);
        }

        // 2. 列表：Quill 用 <ol><li data-list="bullet"> 表示无序列表，转成 <ul>
        foreach ($this->query($root, '//li[@data-list]') as $li) {
            $kind = strtolower((string) $li->getAttribute('data-list'));
            $parent = $li->parentNode;

            if (! $parent instanceof DOMElement || ! in_array($parent->nodeName, ['ol', 'ul'], true)) {
                $li->removeAttribute('data-list');

                continue;
            }

            $want = $kind === 'bullet' ? 'ul' : 'ol';

            if ($parent->nodeName !== $want) {
                // 只有「所有 li 都是同一类型」时才改父标签，避免混排列表被改错
                $allSame = true;

                foreach ($parent->childNodes as $sibling) {
                    if ($sibling instanceof DOMElement && $sibling->nodeName === 'li') {
                        $siblingKind = strtolower((string) $sibling->getAttribute('data-list'));
                        $siblingWant = $siblingKind === 'bullet' ? 'ul' : 'ol';

                        if ($siblingWant !== $want) {
                            $allSame = false;

                            break;
                        }
                    }
                }

                if ($allSame) {
                    $renamed = $parent->ownerDocument->createElement($want);

                    while ($parent->firstChild !== null) {
                        $renamed->appendChild($parent->firstChild);
                    }

                    $parent->parentNode?->replaceChild($renamed, $parent);
                }
            }

            $li->removeAttribute('data-list');
        }

        // 3. Quill 的占位/光标 span：直接删
        foreach ($this->query($root, '//span[contains(@class,"ql-ui") or contains(@class,"ql-cursor")]') as $span) {
            $span->parentNode?->removeChild($span);
        }

        // 4. div 一律拍平成 p（粘贴来的内容常见）
        foreach ($this->query($root, '//div') as $div) {
            if ($div === $root) {
                continue;
            }

            $p = $div->ownerDocument->createElement('p');

            while ($div->firstChild !== null) {
                $p->appendChild($div->firstChild);
            }

            $div->parentNode?->replaceChild($p, $div);
        }
    }

    /* -------------------------------------------------------------- 遍历 --- */

    private function walk(DOMNode $node): void
    {
        // 先收集再处理：边遍历边改节点会漏
        $children = [];

        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof DOMText) {
                continue;
            }

            if (! $child instanceof DOMElement) {
                $node->removeChild($child);

                continue;
            }

            $tag = strtolower($child->nodeName);

            if (in_array($tag, self::DROP, true)) {
                $node->removeChild($child);

                continue;
            }

            if (! array_key_exists($tag, self::ALLOWED)) {
                // 不在白名单：拆掉外壳，保留文字（递归处理它的子节点）
                $this->walk($child);

                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }

                $node->removeChild($child);

                continue;
            }

            $this->filterAttributes($child);
            $this->walk($child);
        }
    }

    private function filterAttributes(DOMElement $element): void
    {
        $tag = strtolower($element->nodeName);
        $allowed = self::ALLOWED[$tag];
        $remove = [];

        foreach ($element->attributes as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = (string) $attribute->nodeValue;

            if (! in_array($name, $allowed, true)) {
                $remove[] = $attribute->nodeName;

                continue;
            }

            if ($name === 'class') {
                $kept = [];

                foreach (preg_split('/\s+/u', trim($value)) ?: [] as $class) {
                    if ($class !== '' && preg_match(self::CLASS_ALLOW, $class) === 1) {
                        $kept[] = $class;
                    }
                }

                if ($kept === []) {
                    $remove[] = $attribute->nodeName;
                } else {
                    $element->setAttribute('class', implode(' ', $kept));
                }

                continue;
            }

            if ($name === 'href' || $name === 'src') {
                if (! $this->safeUrl($value, $name === 'src')) {
                    $remove[] = $attribute->nodeName;
                }

                continue;
            }

            if ($name === 'target') {
                if (! in_array($value, ['_blank', '_self'], true)) {
                    $remove[] = $attribute->nodeName;
                }
            }
        }

        foreach ($remove as $name) {
            $element->removeAttribute($name);
        }

        // 外链加 noopener：防止 window.opener 反向控制
        if ($tag === 'a' && $element->getAttribute('target') === '_blank') {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private function safeUrl(string $url, bool $isImage): bool
    {
        $url = trim($url);

        if ($url === '') {
            return false;
        }

        // 控制字符 / 空白混进协议（`java\tscript:`）先剔掉再判断
        $probe = (string) preg_replace('/[\x00-\x20\x7f]+/u', '', $url);

        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $probe, $m) === 1) {
            $scheme = strtolower($m[1]);

            if ($scheme === 'http' || $scheme === 'https') {
                return true;
            }

            if (! $isImage && ($scheme === 'mailto' || $scheme === 'tel')) {
                return true;
            }

            // data: 只给图片，且只允许位图（SVG 可以内嵌脚本，直接拒）
            if ($isImage && $scheme === 'data') {
                return preg_match('#^data:image/(png|jpe?g|gif|webp|avif);base64,#i', $probe) === 1;
            }

            return false;
        }

        if (str_starts_with($probe, '//')) {
            return true;   // 协议相对，比如 //cdn.example.com/a.png
        }

        // 相对路径 / 锚点 / 查询串
        return (bool) preg_match('#^(/|\#|\?|\.{1,2}/|[^:/\\\\]+)#', $probe);
    }

    private function findRoot(DOMDocument $doc): ?DOMElement
    {
        foreach ($doc->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return $child;
            }
        }

        return $doc->documentElement instanceof DOMElement ? $doc->documentElement : null;
    }

    /**
     * @return array<int, DOMElement>
     */
    private function query(DOMNode $root, string $xpath): array
    {
        $doc = $root instanceof DOMDocument ? $root : ($root->ownerDocument ?? null);

        if (! $doc instanceof DOMDocument) {
            return [];
        }

        $xpathObj = new \DOMXPath($doc);
        $nodes = $xpathObj->query($xpath);

        if ($nodes === false) {
            return [];
        }

        $result = [];

        foreach ($nodes as $node) {
            if ($node instanceof DOMElement && $this->isInside($node, $root)) {
                $result[] = $node;
            }
        }

        return $result;
    }

    private function isInside(DOMNode $node, DOMNode $root): bool
    {
        // 用 wrap 出来的 root 做边界，避免清洗到 wrapper 之外
        for ($current = $node; $current !== null; $current = $current->parentNode) {
            if ($current === $root) {
                return true;
            }
        }

        return false;
    }
}
