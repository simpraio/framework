<?php

declare(strict_types=1);

namespace extensions\oidc_login;

use core\config\Cast;
use core\config\Config as CoreConfig;
use InvalidArgumentException;

final readonly class Config
{
    public const string NAME = 'oidc-login';
    public const string DISABLED = 'OIDC_LOGIN_DISABLED';

    /** @param array<string, Provider> $providers */
    private function __construct(public bool $enabled, public array $providers)
    {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $enabled = Cast::bool($raw['enabled'] ?? null, 'extensions.oidc-login.enabled', false);
        $providers = [];
        $keys = [];
        foreach (array_keys(Provider::NAMING) as $name) {
            /** @var mixed $settings */
            $settings = array_key_exists($name, $raw) ? $raw[$name] : [];
            if (!is_array($settings)) {
                throw new InvalidArgumentException('extensions.oidc-login.' . $name . ' must be an array.');
            }
            // Switching the extension off switches its providers off, so their credentials are not required.
            Cast::bool($settings['enabled'] ?? null, 'extensions.oidc-login.' . $name . '.enabled', false);
            /** @var array<string, mixed> $settings */
            $provider = Provider::fromArray($name, $enabled ? $settings : [...$settings, 'enabled' => false]);
            if ($provider->enabled) {
                foreach ([$provider->sessionKey, $provider->sessionKey . '.flow'] as $key) {
                    if (in_array($key, $keys, strict: true)) {
                        throw new InvalidArgumentException('OIDC providers must use separate session and flow keys.');
                    }
                    $keys[] = $key;
                }
            }
            $providers[$name] = $provider;
        }

        return new self($enabled, $providers);
    }

    public static function enabled(string $name): Provider
    {
        $config = CoreConfig::enabledExtension(self::NAME, self::class, self::DISABLED);
        $provider = $config->providers[$name] ?? null;
        if ($provider === null) {
            throw new InvalidArgumentException('Unknown OIDC provider: ' . $name);
        }
        CoreConfig::ensureEnabled($provider, 'OIDC_LOGIN_PROVIDER_DISABLED');
        return $provider;
    }
}
