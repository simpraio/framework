<?php

declare(strict_types=1);

namespace core;

/**
 * Generic outcome of an app/ service or extension operation.
 *
 * A typed success-or-failure return so application methods can report an
 * expected failure (a reason code) without throwing, and the calling
 * controller can branch on one consistent shape instead of a bare bool or
 * an ad-hoc array.
 *
 *     return Result::ok($user);
 *     return Result::fail('ACCOUNT_LOCKED');
 *
 *     $r = Accounts::register($input);
 *     return $r->ok
 *         ? Response::redirect('/welcome')
 *         : $this->form($template, $r->error);
 *
 * Use HttpException for exceptional, unexpected failures (auth required, a
 * server error) that the caller is not meant to handle inline. Use Result
 * for the expected outcomes a caller is meant to branch on.
 */
final readonly class Result
{
    private function __construct(
        public bool $ok,
        public mixed $value = null,
        public ?string $error = null,
    ) {
    }

    public static function ok(mixed $value = null): self
    {
        return new self(true, $value);
    }

    public static function fail(string $error, mixed $value = null): self
    {
        return new self(false, $value, $error);
    }

    public function failed(): bool
    {
        return !$this->ok;
    }
}
