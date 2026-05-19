<?php

declare(strict_types=1);

namespace core;

final readonly class Paths
{
    public string $base;
    public string $public;
    public string $config;
    public string $templates;
    public string $modules;
    public string $app;
    public string $logs;
    public string $extensions;
    public string $bundleDir;

    public function __construct(string $base)
    {
        $this->base = rtrim($base, characters: '/\\');
        $this->public = $this->base . '/public';
        $this->config = $this->base . '/config';
        $this->templates = $this->base . '/templates';
        $this->modules = $this->base . '/modules';
        $this->app = $this->base . '/app';
        $this->logs = $this->base . '/logs';
        $this->extensions = $this->base . '/extensions';
        $bundleDir = getenv('SIMPRA_BUNDLE_DIR');
        $this->bundleDir = rtrim(
            $bundleDir !== false && $bundleDir !== '' ? $bundleDir : $this->base . '/cache',
            characters: '/\\'
        );
    }
}
