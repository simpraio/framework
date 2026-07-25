<?php

declare(strict_types=1);

namespace core\error;

use core\ErrorHandler;
use core\View;
use core\config\Config;
use core\http\HttpException;
use core\tools\Format;
use RuntimeException;
use Throwable;

final class Page
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
            <link rel="stylesheet" href="/assets/css/error.css">
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
