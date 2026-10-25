<?php

declare(strict_types=1);

namespace extensions\oidc_login\microsoft\groups;

use core\config\Cast;
use extensions\oidc_login\microsoft\Guid;
use InvalidArgumentException;

final readonly class Policy
{
    /** @param list<string> $allowed Group object IDs, lowercased. */
    public function __construct(
        public array $allowed,
        public string $match,
        public bool $graphFallback,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            allowed: self::groupIds(array_key_exists('allowed_groups', $raw) ? $raw['allowed_groups'] : []),
            match: Cast::oneOf(
                $raw['group_match'] ?? null,
                'extensions.oidc-login.microsoft.group_match',
                ['any', 'all'],
                'any'
            ),
            graphFallback: Cast::bool(
                $raw['graph_fallback'] ?? null,
                'extensions.oidc-login.microsoft.graph_fallback',
                true
            ),
        );
    }

    public function required(): bool
    {
        return $this->allowed !== [];
    }

    /**
     * Unknown membership refuses restricted sign-in, but must not deny an unrestricted one.
     */
    public function refusalFor(Membership $membership): ?string
    {
        if (!$this->required()) {
            return null;
        }

        if (!$membership->known) {
            return 'MS_LOGIN_GROUPS_UNAVAILABLE';
        }

        return $this->satisfiedBy($membership->groups) ? null : 'MS_LOGIN_GROUP_DENIED';
    }

    /** @param list<string> $held */
    public function satisfiedBy(array $held): bool
    {
        if (!$this->required()) {
            return true;
        }

        $matched = array_intersect($this->allowed, $held);

        return $this->match === 'all'
            ? count($matched) === count($this->allowed)
            : $matched !== [];
    }

    /** @return list<string> */
    private static function groupIds(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('extensions.oidc-login.microsoft.allowed_groups must be an array of group IDs.');
        }

        $groups = [];
        /** @var mixed $group */
        foreach ($value as $group) {
            $id = is_string($group) ? strtolower(trim($group)) : '';

            // Token group claims contain stable object IDs, not mutable display names.
            if (!Guid::isValid($id)) {
                throw new InvalidArgumentException(
                    'extensions.oidc-login.microsoft.allowed_groups takes group object IDs (GUIDs), not display names.'
                );
            }

            if (!in_array($id, $groups, strict: true)) {
                $groups[] = $id;
            }
        }

        return $groups;
    }
}
