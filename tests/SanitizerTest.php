<?php

declare(strict_types=1);

/**
 * 富文本清洗器测试（安全关键，用例宁多勿少）。
 *
 *   php tests/SanitizerTest.php
 *
 * 不需要 Swoole、不需要数据库，vendor 定位逻辑与 PayloadTest 相同。
 */

$packagePath = dirname(__DIR__);

$vendor = getenv('HYPERF_VENDOR') ?: null;

if ($vendor === null) {
    $directory = $packagePath;

    while (true) {
        if (is_file($directory . '/vendor/autoload.php')) {
            $vendor = $directory . '/vendor/autoload.php';
            break;
        }

        $parent = dirname($directory);

        if ($parent === $directory) {
            break;
        }

        $directory = $parent;
    }
}

if ($vendor === null || ! is_file($vendor)) {
    fwrite(STDERR, "找不到 vendor/autoload.php，请设置 HYPERF_VENDOR。" . PHP_EOL);
    exit(1);
}

defined('BASE_PATH') || define('BASE_PATH', getenv('HYPERF_BASE_PATH') ?: dirname($vendor, 2));

require $vendor;

spl_autoload_register(static function (string $class) use ($packagePath): void {
    $prefix = 'Awengan\HyperfAdmin\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $file = $packagePath . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
}, true, true);

use Awengan\HyperfAdmin\Helper\HtmlSanitizer;

$passed = 0;
$failed = 0;
$failures = [];

/* ------------------------------------------------------------- 小工具 --- */

function test(string $name, Closure $callback): void
{
    global $passed, $failed, $failures;

    try {
        $callback();
        ++$passed;
        echo "  ok   {$name}\n";
    } catch (Throwable $e) {
        ++$failed;
        $failures[] = $name . '：' . $e->getMessage();
        echo "  FAIL {$name}\n" . '       ' . $e->getMessage() . "\n";
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($message !== '' ? $message . '：' : '') . '期望 ' . var_export($expected, true) . '，实际 ' . var_export($actual, true));
    }
}

function assertTrue(bool $condition, string $message = ''): void
{
    if (! $condition) {
        throw new RuntimeException($message !== '' ? $message : '断言失败');
    }
}

function assertNotContains(string $needle, string $haystack, string $message = ''): void
{
    if (stripos($haystack, $needle) !== false) {
        throw new RuntimeException(($message !== '' ? $message . '：' : '') . '不应包含 ' . var_export($needle, true) . '，实际：' . $haystack);
    }
}

function assertContains(string $needle, string $haystack, string $message = ''): void
{
    if (stripos($haystack, $needle) === false) {
        throw new RuntimeException(($message !== '' ? $message . '：' : '') . '应包含 ' . var_export($needle, true) . '，实际：' . $haystack);
    }
}

$s = HtmlSanitizer::make();

echo "富文本清洗器\n";

/* ------------------------------------------------------------ 基本内容 --- */

test('普通段落原样保留', function () use ($s): void {
    assertSame('<p>你好</p>', $s->clean('<p>你好</p>'));
});

test('格式化标签保留', function () use ($s): void {
    $out = $s->clean('<p><strong>粗</strong><em>斜</em><u>下</u><s>删</s></p>');
    assertSame('<p><strong>粗</strong><em>斜</em><u>下</u><s>删</s></p>', $out);
});

test('标题 / 引用 / 代码 / 分割线', function () use ($s): void {
    $in = '<h2>标题</h2><blockquote>引用</blockquote><pre><code>echo 1;</code></pre><hr>';
    assertSame($in, $s->clean($in));
});

test('列表按 Quill 方言归一化成标准 HTML', function () use ($s): void {
    $in = '<ol><li data-list="bullet"><span class="ql-ui" contenteditable="false"></span>第一项</li>'
        . '<li data-list="bullet">第二项</li></ol>';
    $out = $s->clean($in);
    assertSame('<ul><li>第一项</li><li>第二项</li></ul>', $out);
});

test('有序列表保持 ol', function () use ($s): void {
    $out = $s->clean('<ol><li data-list="ordered">一</li><li data-list="ordered">二</li></ol>');
    assertSame('<ol><li>一</li><li>二</li></ol>', $out);
});

test('Quill 代码块转成 pre>code', function () use ($s): void {
    $in = '<div class="ql-code-block-container"><div class="ql-code-block">line1</div>'
        . '<div class="ql-code-block">line2</div></div>';
    $out = $s->clean($in);
    assertContains('<pre><code>', $out);
    assertContains("line1\nline2", $out);
});

test('Quill 光标 span 被删除', function () use ($s): void {
    $out = $s->clean('<p><span class="ql-cursor">﻿</span>正文</p>');
    assertSame('<p>正文</p>', $out);
});

/* ---------------------------------------------------------------- XSS --- */

test('script 连内容一起删', function () use ($s): void {
    $out = $s->clean('<p>a</p><script>alert(1)</script><p>b</p>');
    assertNotContains('script', $out);
    assertNotContains('alert', $out);
    assertSame('<p>a</p><p>b</p>', $out);
});

test('事件属性被剥掉', function () use ($s): void {
    foreach (['onclick', 'onerror', 'onload', 'onmouseover'] as $attr) {
        $out = $s->clean('<img src="/a.png" ' . $attr . '="alert(1)">');
        assertNotContains($attr, $out, $attr);
    }
});

test('style 属性被剥掉（含 expression）', function () use ($s): void {
    $out = $s->clean('<p style="background:url(javascript:alert(1))">x</p>');
    assertSame('<p>x</p>', $out);
});

test('javascript: 协议被拒（含大小写与空白混淆）', function () use ($s): void {
    foreach ([
        '<a href="javascript:alert(1)">x</a>',
        '<a href="JaVaScRiPt:alert(1)">x</a>',
        '<a href="java&#9;script:alert(1)">x</a>',
        "<a href=\"java\tscript:alert(1)\">x</a>",      // HTML 实体解码后的真实制表符
        "<a href=\"java\nscript:alert(1)\">x</a>",
    ] as $in) {
        $out = $s->clean($in);
        assertNotContains('javascript', $out, $in);
        assertNotContains('alert', $out, $in);
        assertTrue(! str_contains($out, 'href'), '应去掉 href：' . $out);
    }
});

test('data:text/html 被拒，data:image 允许', function () use ($s): void {
    assertNotContains('href', $s->clean('<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>'));
    assertNotContains('src', $s->clean('<img src="data:image/svg+xml;base64,PHN2Zz4=">'));

    $png = 'data:image/png;base64,iVBORw0KGgo=';
    assertContains($png, $s->clean('<img src="' . $png . '">'));
});

test('iframe / svg / object / form 被删', function () use ($s): void {
    foreach ([
        '<iframe src="https://evil.test"></iframe>',
        '<svg onload="alert(1)"><circle /></svg>',
        '<object data="x.swf"></object>',
        '<form action="//evil"><input name="a"></form>',
        '<meta http-equiv="refresh" content="0;url=//evil">',
    ] as $in) {
        $out = $s->clean($in);
        assertNotContains('iframe', $out);
        assertNotContains('svg', $out);
        assertNotContains('<object', $out);
        assertNotContains('<form', $out);
        assertNotContains('<input', $out);
        assertNotContains('<meta', $out);
    }
});

test('未知标签拆壳保文字', function () use ($s): void {
    assertSame('<p>保留<b>文字</b></p>', $s->clean('<p><marquee>保留<b>文字</b></marquee></p>'));
});

test('外链自动补 noopener 并保留 target=_blank', function () use ($s): void {
    $out = $s->clean('<a href="https://example.com" target="_blank">x</a>');
    assertContains('target="_blank"', $out);
    assertContains('rel="noopener noreferrer"', $out);
});

test('非法 target 被去掉', function () use ($s): void {
    $out = $s->clean('<a href="https://example.com" target="_top">x</a>');
    assertNotContains('target', $out);
});

test('proto 相对地址与锚点放行', function () use ($s): void {
    assertContains('//cdn.test/a.png', $s->clean('<img src="//cdn.test/a.png">'));
    assertContains('#section', $s->clean('<a href="#section">x</a>'));
});

test('外链图片默认允许（https）', function () use ($s): void {
    assertContains('https://cdn.test/a.png', $s->clean('<img src="https://cdn.test/a.png" alt="图">'));
});

/* ------------------------------------------------------------ 其它细节 --- */

test('class 白名单：只留 language-* / ql-* / hljs', function () use ($s): void {
    $out = $s->clean('<pre><code class="language-php evil">x</code></pre>');
    assertContains('language-php', $out);
    assertNotContains('evil', $out);
});

test('空值返回空串', function () use ($s): void {
    assertSame('', $s->clean(null));
    assertSame('', $s->clean(''));
    assertSame('', $s->clean('   '));
});

test('纯文本原样保留', function () use ($s): void {
    assertSame('一段纯文本', $s->clean('一段纯文本'));
});

test('text() 去标签并截断', function () use ($s): void {
    assertSame('标题内容', $s->text('<p><strong>标题</strong>内容</p>'));
    assertSame('一二三…', $s->text('<p>一二三四五</p>', 3));
    assertSame('', $s->text(null));
});

test('实体不会被二次转义', function () use ($s): void {
    $out = $s->clean('<p>a &amp; b</p>');
    assertContains('a &amp; b', $out);
});

test('中文与 emoji 不被破坏', function () use ($s): void {
    assertSame('<p>中文 😀 测试</p>', $s->clean('<p>中文 😀 测试</p>'));
});

/* ---------------------------------------------------------------- 汇总 --- */

echo PHP_EOL;
printf("%s %d 条用例通过%s%s", $failed === 0 ? 'OK' : 'FAILED', $passed, PHP_EOL, $failed === 0 ? '' : "，{$failed} 条失败：" . PHP_EOL);

foreach ($failures as $failure) {
    echo '  - ' . $failure . PHP_EOL;
}

exit($failed === 0 ? 0 : 1);
