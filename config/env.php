<?php
/**
 * Minimal .env loader.
 *
 * Production keeps secrets in shared/.env (outside the release, symlinked as APP_ROOT/.env).
 * Precedence: real environment variable > .env file > default in code.
 * Format: KEY=value per line, # comments, optional single/double quotes.
 */

function app_env(string $key, ?string $default = null): ?string
{
    static $file = null;
    if ($file === null) {
        $file = [];
        $path = dirname(__DIR__) . '/.env';
        if (is_file($path) && is_readable($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                    continue;
                }
                list($k, $v) = explode('=', $line, 2);
                $k = trim($k);
                if (strpos($k, 'export ') === 0) {
                    $k = trim(substr($k, 7));
                }
                $v = trim($v);
                $len = strlen($v);
                if ($len >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[$len - 1] === $v[0]) {
                    $v = substr($v, 1, -1);
                }
                $file[$k] = $v;
            }
        }
    }
    $real = getenv($key);
    if ($real !== false) {
        return $real;
    }
    return array_key_exists($key, $file) ? $file[$key] : $default;
}

function app_env_bool(string $key, bool $default = false): bool
{
    $v = app_env($key);
    if ($v === null || $v === '') {
        return $default;
    }
    return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
}
