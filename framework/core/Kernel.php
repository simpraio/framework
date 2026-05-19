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
use core\log\Writer as LogWriter;
use core\session\Store as SessionStore;
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

        $container = new Container();
        $container->instance(Paths::class, $paths);
        $container->bind(LogWriter::class, static fn(): LogWriter => new LogWriter($paths->logs));
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

        $logConfig = Config::log();
        Log::init($container->log());
        $writer = $container->log();
        $writer->level = $logConfig->level;
        $writer->rotateDaily = $logConfig->rotateDaily;
        $writer->retentionDays = $logConfig->retentionDays;
        $writer->prune();

        ErrorHandler::register(debug: Config::project()->debug, log: $container->log(), view: $container->view());
        date_default_timezone_set(Config::project()->timezone);

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

        $extensions = $this->container->extensions();
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

    private function dispatch(ResolvedRoute $route): Template|Response
    {
        $view = $this->container->view();
        $base = str_replace(search: '-', replace: '', subject: ucwords($route->controller, separators: '-'));
        $controllerClass = self::MODULES_NS . $route->module . '\\' . $base;
        $templatePath = "{$route->module}/{$route->controller}";
        $hasController = class_exists($controllerClass);
        $hasTemplate = $view->exists($templatePath);

        if (!$hasController && !$hasTemplate) {
            throw new HttpException(404);
        }

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
        if ($allowedHosts === []) {
            return;
        }

        $parsedHost = parse_url('http://' . strtolower($request->header('Host') ?? ''), PHP_URL_HOST);
        $host = is_string($parsedHost) ? $parsedHost : '';

        if (!in_array($host, $allowedHosts, strict: true)) {
            throw new HttpException(400);
        }
    }

    private function makeController(string $class, ResolvedRoute $route): Controller
    {
        if (!is_subclass_of($class, Controller::class)) {
            throw new RuntimeException("{$class} must extend " . Controller::class);
        }
        return new $class(
            $this->container->view(),
            $this->container->paths(),
            $route,
            $this->container->request(),
        );
    }
}
