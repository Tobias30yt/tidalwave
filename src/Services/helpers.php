<?php
declare(strict_types=1);
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function safe_url(?string $url): ?string {
    if (!$url || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) return null;
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) return $url;
    return filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https' ? $url : null;
}
function icon(string $name, string $class = ''): string {
    $paths = [
        'arrow' => '<path d="M5 12h14m-6-6 6 6-6 6"/>',
        'diagonal' => '<path d="M6 18 18 6M6 6h12v12"/>',
        'code' => '<path d="m8 6-6 6 6 6m8-12 6 6-6 6M14 3l-4 18"/>',
        'bot' => '<rect x="4" y="7" width="16" height="14" rx="4"/><path d="M12 3v4M2 12v5m20-5v5M8 12v3m8-3v3m-7 3h6"/>',
        'layers' => '<path d="m12 3 10 6-10 6L2 9Zm-10 12 10 6 10-6M2 15l10 6 10-6"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 6 9 7 9-7"/>',
        'copy' => '<rect x="8" y="8" width="12" height="13" rx="2"/><path d="M16 8V3H3v13h5"/>',
        'wave' => '<path d="M2 15c5 0 5-10 10-10 4 0 4 6 1 6 2 5 6 5 9 2M2 20c6-4 10 4 20-1"/>',
        'spark' => '<path d="m12 2 3 7 7 3-7 3-3 7-3-7-7-3 7-3Z"/>',
    ];
    return '<svg class="icon '.e($class).'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($paths[$name] ?? $paths['arrow']).'</svg>';
}
function component(string $name, array $data = []): void {
    extract($data, EXTR_SKIP);
    require dirname(__DIR__).'/Components/'.$name.'.php';
}
