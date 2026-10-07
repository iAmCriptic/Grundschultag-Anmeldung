<?php

require dirname(__DIR__) . '/vendor/autoload.php';

$s = new App\Services\HtmlContentService();
echo $s->sanitize('<p style="color: red; font-size: 18px"><strong>Hi</strong><script>alert(1)</script></p><a href="javascript:alert(1)">x</a><a href="https://example.com">ok</a>');
echo "\n---\n";
echo $s->toSafeHtml("Plain\nline");
echo "\n";
