<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Session-backed CSRF tokens (synchronizer-token pattern).
 * Every state-changing form (register, login, logout, comments) posts a
 * hidden `_token` field that must match the token stored in the session.
 */
final class Csrf
{
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::KEY]) || !is_string($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::KEY];
    }

    public static function isValid(mixed $submitted): bool
    {
        $expected = $_SESSION[self::KEY] ?? null;

        return is_string($expected)
            && is_string($submitted)
            && $submitted !== ''
            && hash_equals($expected, $submitted);
    }

    /** Issue a fresh token, e.g. after login or logout. */
    public static function rotate(): void
    {
        $_SESSION[self::KEY] = bin2hex(random_bytes(32));
    }
}
