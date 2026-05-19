<?php

declare(strict_types=1);

namespace core\session;

use core\config\dto\Session as SessionConfig;
use RuntimeException;

final class Store
{
    private bool $started = false;

    public function __construct(
        private readonly SessionConfig $config,
    ) {
    }

    public function configure(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        ini_set(option: 'session.use_strict_mode', value: '1');
        ini_set(option: 'session.use_only_cookies', value: '1');

        if ($this->config->savePath !== '') {
            $path = $this->config->savePath;
            if (!is_dir($path) && !mkdir(directory: $path, permissions: 0o700, recursive: true) && !is_dir($path)) {
                throw new RuntimeException("Session: cannot create save path: {$path}");
            }
            if (!is_writable($path)) {
                throw new RuntimeException("Session: save path is not writable: {$path}");
            }
            session_save_path($path);
        }

        session_name($this->config->name);
        session_set_cookie_params([
            'lifetime' => $this->config->lifetime,
            'path' => $this->config->path,
            'domain' => $this->config->domain,
            // `secure` is deployment intent, not a per-request inference. The
            // operator's contract wins. If the site is HTTPS-only, set
            // session.secure=true and redirect HTTP->HTTPS at the server level.
            // For HTTP-only dev, set session.secure=false in framework.local.php.
            'secure' => $this->config->secure,
            'httponly' => $this->config->httpOnly,
            'samesite' => $this->config->sameSite,
        ]);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->ensureStarted();
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        $this->ensureStarted();
        return array_key_exists($key, $_SESSION);
    }

    public function forget(string $key): void
    {
        $this->ensureStarted();
        unset($_SESSION[$key]);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();

        /** @var mixed $value */
        $value = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);
        return $value;
    }

    public function regenerate(bool $deleteOld = true): void
    {
        $this->ensureStarted();
        session_regenerate_id($deleteOld);
    }

    public function destroy(): void
    {
        if (!$this->started && session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            $name = ($name = session_name()) !== false ? $name : $this->config->name;
            setcookie(name: $name, value: '', expires_or_options: [
                'expires' => time() - 42_000,
                'path' => $p['path'],
                'domain' => $p['domain'],
                'secure' => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'],
            ]);
        }
        session_destroy();
        $this->started = false;
    }

    public function isStarted(): bool
    {
        return $this->started || session_status() === PHP_SESSION_ACTIVE;
    }

    private function ensureStarted(): void
    {
        if ($this->started) {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }
        if (PHP_SAPI === 'cli') {
            throw new RuntimeException('Session is not available in CLI context.');
        }

        $file = '';
        $line = 0;
        if (headers_sent($file, $line)) {
            throw new RuntimeException("Cannot start session: headers already sent in {$file}:{$line}.");
        }

        session_start();
        $this->started = true;
    }
}
