<?php

declare(strict_types=1);

namespace core;

use core\config\Config;
use core\http\HttpException;
use core\tools\Format;
use RuntimeException;
use Throwable;

final class ErrorPage
{
    public function __construct(
        private readonly bool $debug,
        private readonly ?View $view,
    ) {}

    public function html(Throwable $e, int $status): string
    {
        try {
            return $this->template($e, $status);
        } catch (Throwable) {
            return $this->debug ? $this->fallbackDebug($e) : self::fallbackProduction();
        }
    }

    public function text(Throwable $e): string
    {
        if (!$this->debug) {
            return 'Error: ' . $e->getMessage();
        }

        return ErrorHandler::describe($e) . "\n" . $e->getTraceAsString();
    }

    private function template(Throwable $e, int $status): string
    {
        if ($this->view === null) {
            throw new RuntimeException('View not registered');
        }

        $isBug = $status >= 500;
        $url = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
        $exposeDetails = $this->debug || !$isBug;

        return $this->view->load('error')
            ->tokens([
                'PROJECT' => self::projectName(),
                'TITLE' => $this->title($e, $isBug),
                'STATUS' => (string) $status,
                'ERROR' => $this->message($e, $isBug),
                'REFERENCE' => strtoupper(bin2hex(random_bytes(4))),
                'DATE' => Format::datetime(),
                'FILE' => $exposeDetails ? $e->getFile() : '',
                'LINE' => $exposeDetails ? (string) $e->getLine() : '',
                'TRACE' => $exposeDetails ? $e->getTraceAsString() : '',
                'SQL_QUERY' => '',
                'SQL_BINDS' => '',
                'URL' => $url,
            ])
            ->blocks([
                'is403' => $status === 403,
                'is404' => $status === 404,
                'isBug' => $isBug,
                'isDebug' => $this->debug && $isBug,
                'isNotDebug' => !$this->debug && $isBug,
                'isSQL' => false,
            ])
            ->render();
    }

    private function title(Throwable $e, bool $isBug): string
    {
        if ($isBug) {
            return $this->debug ? $e::class : 'Internal Error';
        }

        if ($this->debug) {
            return $e->getMessage();
        }

        return $e instanceof HttpException ? $e->publicMessage() : 'Error';
    }

    private function message(Throwable $e, bool $isBug): string
    {
        if ($this->debug) {
            return $e->getMessage();
        }

        if ($isBug) {
            return '';
        }

        return $e instanceof HttpException ? $e->publicMessage() : '';
    }

    private static function projectName(): string
    {
        try {
            return Config::project()->name;
        } catch (Throwable) {
            return 'App';
        }
    }

    private function fallbackDebug(Throwable $e): string
    {
        $title = htmlspecialchars($e::class, flags: ENT_QUOTES, encoding: 'UTF-8');
        $msg = htmlspecialchars($e->getMessage(), flags: ENT_QUOTES, encoding: 'UTF-8');
        $file = htmlspecialchars($e->getFile(), flags: ENT_QUOTES, encoding: 'UTF-8');
        $trace = htmlspecialchars($e->getTraceAsString(), flags: ENT_QUOTES, encoding: 'UTF-8');
        $line = (string) $e->getLine();

        return self::fallbackHtml($title, <<<HTML
            <div class="status">Fallback debug error</div>
            <h1>{$title}</h1>
            <p class="msg">{$msg}</p>
            <p class="loc"><code>{$file}:{$line}</code></p>
            <pre>{$trace}</pre>
            HTML, true);
    }

    private static function fallbackProduction(): string
    {
        return self::fallbackHtml('500 - Server Error', <<<HTML
            <h1>Something went wrong</h1>
            <p>The server encountered an error and could not complete your request. Please try again later.</p>
            HTML);
    }

    private static function fallbackHtml(string $title, string $content, bool $debug = false): string
    {
        $cardClass = $debug ? ' class="debug"' : '';

        return <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$title}</title>
            <style>
              :root{color-scheme:light dark;--font-mono:ui-monospace,"JetBrains Mono",Menlo,Consolas,monospace;--background:0 0% 98%;--foreground:0 0% 10%;--muted:0 0% 42%;--primary:150 70% 28%;--surface:0 0% 100%;--border:0 0% 88%;--code-bg:240 8% 8%;--code-fg:0 0% 92%;--shadow:0 18px 60px hsla(0,0%,0%,.12);--radius:.5rem}
              @media (prefers-color-scheme:dark){:root{--background:240 6% 6%;--foreground:0 0% 92%;--muted:0 0% 62%;--primary:150 70% 55%;--surface:240 6% 10%;--border:240 4% 18%;--code-bg:240 8% 4%;--shadow:0 18px 60px hsla(0,0%,0%,.45)}}
              *{box-sizing:border-box}body{display:grid;min-height:100vh;margin:0;place-items:center;padding:1.25rem;background:radial-gradient(circle at 50% 30%,hsla(var(--primary)/.18),transparent 22rem),linear-gradient(hsla(var(--foreground)/.055) 1px,transparent 1px),linear-gradient(90deg,hsla(var(--foreground)/.055) 1px,transparent 1px),hsl(var(--background));background-size:auto,4rem 4rem,4rem 4rem,auto;color:hsl(var(--foreground));font:1rem/1.6 system-ui,-apple-system,Roboto,sans-serif}
              main{width:min(100%,32rem);padding:clamp(2rem,1.4rem + 4vw,3rem);border:1px solid hsl(var(--border));border-radius:var(--radius);background:hsla(var(--surface)/.92);box-shadow:var(--shadow);text-align:center;backdrop-filter:blur(10px)}main.debug{width:min(100%,46rem);text-align:left}
              .brand,.status,code,pre{font-family:var(--font-mono),monospace}.brand{display:inline-flex;margin-bottom:1rem;color:hsl(var(--primary));font-size:.95rem;font-weight:800;text-decoration:none}.brand:hover{color:hsl(var(--foreground))}.status{display:inline-flex;margin-bottom:.75rem;color:hsl(var(--muted));font-size:.8rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
              h1,p{margin:0}h1{font-size:clamp(2rem,1.55rem + 3vw,3.25rem);line-height:1.08}.debug h1{font-size:clamp(1.6rem,1.25rem + 2vw,2.4rem);line-height:1.12}p{margin-top:.85rem;color:hsl(var(--muted))}.loc{margin-top:1rem;color:hsl(var(--foreground))}pre{max-height:22rem;margin:1.25rem 0 0;overflow:auto;padding:1rem;border-radius:var(--radius);background:hsl(var(--code-bg));color:hsl(var(--code-fg));font-size:.9rem;white-space:pre-wrap;word-break:break-word}
            </style>
            </head>
            <body>
            <main{$cardClass}>
            <a class="brand" href="https://simpra.io/" target="_blank" rel="noopener noreferrer" aria-label="Simpra website">&gt; simpra_</a>
            {$content}
            </main>
            </body>
            </html>
            HTML;
    }
}
