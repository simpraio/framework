<?php

declare(strict_types=1);

namespace extensions\oidc_login\microsoft\groups;

use extensions\oidc_login\LoginException;

/**
 * Unknown membership differs from a known empty list and cannot answer authorization checks.
 */
final readonly class Membership
{
    /** @param list<string> $groups */
    private function __construct(
        public array $groups,
        public bool $known,
        public string $reason,
    ) {
    }

    /** @param array<array-key, mixed> $groups */
    public static function of(array $groups): self
    {
        $normalized = [];
        /** @var mixed $group */
        foreach ($groups as $group) {
            if (!is_string($group) || $group === '') {
                continue;
            }
            $normalized[] = strtolower($group);
        }
        return new self(array_values(array_unique($normalized)), true, '');
    }

    public static function unknown(string $reason): self
    {
        return new self([], false, $reason);
    }

    /** @param string|list<string> $groups */
    public function contains(string|array $groups, bool $all = false): bool
    {
        $wanted = [];
        foreach ((array)$groups as $group) {
            $id = strtolower(trim($group));
            if ($id !== '') {
                $wanted[] = $id;
            }
        }
        if ($wanted === []) {
            return false;
        }
        if (!$this->known) {
            throw new LoginException('MS_LOGIN_GROUPS_UNAVAILABLE', $this->reason);
        }
        $matched = array_intersect($wanted, $this->groups);
        return $all ? count($matched) === count($wanted) : $matched !== [];
    }

    /** @param array<string, mixed> $session */
    public static function fromSession(array $session): self
    {
        // An absent groups_known flag means known membership.
        return ($session['groups_known'] ?? true) === false
            ? self::unknown('membership was not established at sign-in')
            : self::of(is_array($session['groups'] ?? null) ? $session['groups'] : []);
    }
}
