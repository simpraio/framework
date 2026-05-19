<?php

declare(strict_types=1);

namespace core\http;

final readonly class Route
{
    public function __construct(
        public string $language,
        public string $module,
        public string $controller,
        public ?string $id = null,
    ) {}

    public function pathId(): string
    {
        return $this->module . '/' . $this->controller;
    }
}
