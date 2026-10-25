<?php

declare(strict_types=1);

namespace extensions\auth;

/**
 * The access rules of one path, answered for a user. A row with an empty block rules the route,
 * any other row a template block. User and group 0 match anyone; the most specific matching row
 * wins - user over group over global - whether it allows or denies. A rule of an inactive group
 * grants nothing, but its denial still applies: disabling a group never widens access.
 */
final readonly class Rules
{
    /** @param list<array{block: string, user_id: int, group_id: int, policy: string, active: bool}> $rows */
    private function __construct(private array $rows)
    {
    }

    /**
     * @param iterable<array<string, mixed>> $rows block, user_id, group_id, policy, and the status
     *     of the row's group (null when the group does not exist)
     */
    public static function fromRows(iterable $rows): self
    {
        $rules = [];
        foreach ($rows as $row) {
            $groupId = (int)($row['group_id'] ?? 0);
            $rules[] = [
                'block' => (string)($row['block'] ?? ''),
                'user_id' => (int)($row['user_id'] ?? 0),
                'group_id' => $groupId,
                'policy' => (string)($row['policy'] ?? ''),
                'active' => $groupId === 0 || ($row['status'] ?? null) === 'active',
            ];
        }

        return new self($rules);
    }

    public function controlled(): bool
    {
        foreach ($this->rows as $row) {
            if ($row['block'] === '') {
                return true;
            }
        }
        return false;
    }

    /** A controlled route is refused unless a matching rule allows it. */
    public function allowed(int $userId, int $groupId): bool
    {
        return $this->decisions($userId, $groupId)[''] ?? false;
    }

    /**
     * Every block configured for the path, so none is left unanswered: a block with no matching
     * rule is hidden.
     *
     * @return array<string, bool> block name => visible
     */
    public function blocks(int $userId, int $groupId): array
    {
        $blocks = array_fill_keys(array_diff(array_column($this->rows, 'block'), ['']), value: false);
        $decisions = $this->decisions($userId, $groupId);
        unset($decisions['']);

        return array_replace($blocks, $decisions);
    }

    /** @return array<string, bool> block ('' for the route) => allowed, from the most specific matching row */
    private function decisions(int $userId, int $groupId): array
    {
        $matching = array_filter(
            $this->rows,
            static fn(array $row): bool => ($row['user_id'] === 0 || $row['user_id'] === $userId)
                && ($row['group_id'] === 0 || $row['group_id'] === $groupId)
                && ($row['active'] || $row['policy'] === 'deny'),
        );
        usort(
            $matching,
            static fn(array $a, array $b): int => [$b['user_id'], $b['group_id']] <=> [$a['user_id'], $a['group_id']],
        );

        $decisions = [];
        foreach ($matching as $row) {
            $decisions[$row['block']] ??= $row['policy'] === 'allow';
        }
        return $decisions;
    }
}
