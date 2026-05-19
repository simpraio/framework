<?php

declare(strict_types=1);

namespace extensions\seo;

use core\config\Config as CoreConfig;
use core\extension\Boot as ExtensionBoot;
use core\extension\Contributor;
use core\Request;
use core\http\Aliases;
use core\http\Route;

final class Boot extends ExtensionBoot implements Contributor
{
    public function contribute(Route $route): array
    {
        $config = $this->config();
        if (!$config->enabled) {
            return [];
        }

        $seo = Store::page($route);
        $project = CoreConfig::project();

        $canonicalUrl = $seo['canonical_url'] !== ''
            ? $seo['canonical_url']
            : rtrim($project->url, characters: '/') . (Aliases::uri($route) ?? Request::path());

        return [
            'tokens' => [
                'SITE_NAME' => $project->name,
                'HEAD_TITLE' => $seo['title'] !== '' ? $seo['title'] : $config->title,
                'HEAD_DESCRIPTION' => $seo['description'] !== '' ? $seo['description'] : $config->description,
                'CANONICAL_URL' => $canonicalUrl,
            ],
        ];
    }

    private function config(): Config
    {
        return CoreConfig::extensionConfig(Config::NAME, Config::class);
    }
}
