<?php

declare(strict_types=1);

namespace core;

use core\config\Config;
use core\db\Connection;
use core\db\Db;
use core\http\Aliases;
use core\http\HttpException;
use core\http\Request as HttpRequest;
use core\http\Response;
use core\http\Route as ResolvedRoute;
use core\http\Router;
use core\http\Url;
use core\log\Writer as LogWriter;
use core\session\Store as SessionStore;
use core\tools\Silently;
use ReflectionClass;
use RuntimeException;

final class Kernel
{
    private const string MODULES_NS = 'modules\\';

    private function __construct(
        private readonly Container $container,
    ) {
    }

    public static function boot(string $basePath): self
    {
        $paths = new Paths($basePath);
        Instance::init($paths->base);
        Config::init($paths);
        self::setTimezone(Config::project()->timezone);

        $container = new Container();
        $container->instance(Paths::class, $paths);
        $logConfig = Config::log();
        $container->bind(LogWriter::class, static fn(): LogWriter => new LogWriter(
            $paths->logs,
            level: $logConfig->level,
            rotateDaily: $logConfig->rotateDaily,
            retentionDays: $logConfig->retentionDays,
            redactKeys: $logConfig->redactKeys,
        ));
        $container->bind(Connection::class, static fn(): Connection => new Connection(Config::database()));
        $container->bind(SessionStore::class, static fn(): SessionStore => new SessionStore(Config::session()));
        $container->bind(HttpRequest::class, HttpRequest::capture(...));
        $container->bind(Router::class, static fn(): Router => new Router(
            Config::route(),
            Config::language(),
            new Aliases(Config::route()),
        ));
        $container->bind(View::class, static fn(): View => new View($paths->templates, Config::project()->debug));
        $container->bind(Extensions::class, static fn(): Extensions => Extensions::load(
            $paths->extensions,
            $paths->bundleDir . '/' . Bundle::EXTENSIONS_FILE,
        ));

        Log::init($container->log());
        $container->log()->prune();

        ErrorHandler::register(debug: Config::project()->debug, log: $container->log(), view: $container->view());

        Db::init($container->db(...));
        Request::init($container->request());
        Session::init($container->session());

        return new self($container);
    }

    public function run(): void
    {
        $request = $this->container->request();

        $this->validateHost($request);

        $route = $this->container->router()->resolve($request);

        if ($route === null) {
            throw new HttpException(404);
        }

        // Bootables run before controller autoload; route-scoped hooks wait until existence is known.
        $extensions = $this->container->extensions();

        if (!$this->routeExists($route)) {
            throw new HttpException(404);
        }

        $response = $extensions->before($request, $route);

        if ($response === null) {
            $result = $this->dispatch($route);
            $response = $result instanceof Response
                ? $result
                : $this->layoutWrap($route, $result);
        }

        $extensions->after($request, $route, $response);

        if ($request->isMethod('HEAD')) {
            $response->body('');
        }

        $response->send();
    }

    /** Before the error handler and the log, both of which need the timezone to report anything. */
    private static function setTimezone(string $timezone): void
    {
        if (!Silently::call(static fn(): bool => date_default_timezone_set($timezone))) {
            throw new RuntimeException("Config: 'project.timezone' must be a timezone PHP knows, got '{$timezone}'");
        }
    }

    private function routeExists(ResolvedRoute $route): bool
    {
        return self::isController($this->controllerClass($route))
            || $this->container->view()->exists("{$route->module}/{$route->controller}");
    }

    /**
     * A URL can name any class under modules\; only a concrete Controller is a route.
     *
     * @psalm-assert-if-true class-string<Controller> $class
     */
    private static function isController(string $class): bool
    {
        return class_exists($class)
            && is_subclass_of($class, Controller::class)
            && !new ReflectionClass($class)->isAbstract();
    }

    private function controllerClass(ResolvedRoute $route): string
    {
        $base = str_replace(search: '-', replace: '', subject: ucwords($route->controller, separators: '-'));

        return self::MODULES_NS . $route->module . '\\' . $base;
    }

    private function dispatch(ResolvedRoute $route): Template|Response
    {
        $view = $this->container->view();
        $controllerClass = $this->controllerClass($route);
        $templatePath = "{$route->module}/{$route->controller}";
        $hasController = self::isController($controllerClass);
        $hasTemplate = $view->exists($templatePath);

        if ($hasController) {
            $instance = $this->makeController($controllerClass, $route);
            $template = $hasTemplate ? $view->load($templatePath) : new Template();
            $result = $instance->compose($template);

            if ($result instanceof Response) {
                return $result;
            }
            if (!$hasTemplate) {
                throw new HttpException(404);
            }
            return $result;
        }

        return $view->load($templatePath);
    }

    private function layoutWrap(ResolvedRoute $route, Template $page): Response
    {
        $extensions = $this->container->extensions();
        $class = self::MODULES_NS . 'Layout';
        if (!class_exists($class)) {
            return Response::html($extensions->compose($page, $route)->render());
        }

        $result = $this->makeController($class, $route)->compose($page);
        if ($result instanceof Response) {
            return $result;
        }
        return Response::html($extensions->compose($result, $route)->render());
    }

    private function validateHost(HttpRequest $request): void
    {
        $allowedHosts = Config::project()->allowedHosts;
        // Empty disables Host validation; generated links must still use project.url.
        if ($allowedHosts === []) {
            return;
        }

        $host = Url::host($request->header('Host') ?? '');

        if ($host === null || !in_array($host, $allowedHosts, strict: true)) {
            throw new HttpException(400);
        }
    }

    private function makeController(string $class, ResolvedRoute $route): Controller
    {
        if (!self::isController($class)) {
            throw new RuntimeException("{$class} must be a concrete class extending " . Controller::class);
        }
        return new $class(
            $this->container->view(),
            $this->container->paths(),
            $route,
            $this->container->request(),
        );
    }
}
