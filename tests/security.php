<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Services/helpers.php';
$cases = [
    'https://example.org/docs' => 'https://example.org/docs',
    '/legal/synatra/privacy' => '/legal/synatra/privacy',
    '//evil.example' => null,
    'javascript:alert(1)' => null,
    'data:text/html,hello' => null,
    "https://example.org\r\nX-Test: injected" => null,
    '/\\evil.example' => null,
    'http://example.org' => null,
];
foreach ($cases as $input => $expected) {
    if (safe_url($input) !== $expected) throw new RuntimeException('URL validation failure');
}
if (e('<script>"&') !== '&lt;script&gt;&quot;&amp;') throw new RuntimeException('Escaping failure');
if (safe_url(null) !== null) throw new RuntimeException('Null URL failure');
echo "PASS: URL scheme validation, control characters, slash/backslash rejection and HTML escaping.\n";
