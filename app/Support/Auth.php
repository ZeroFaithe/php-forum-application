<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/** Session-based authentication. Only the user ID is kept in the session. */
final class Auth
{
    private static ?User $cached = null;
    private static bool $loaded = false;

    public static function user(): ?User
    {
        if (!self::$loaded) {
            $id = $_SESSION['user_id'] ?? null;
            self::$cached = is_int($id) ? User::find($id) : null;
            self::$loaded = true;
        }

        return self::$cached;
    }

    public static function id(): ?int
    {
        return self::user()?->id;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function login(User $user): void
    {
        // New session ID after a privilege change prevents session fixation.
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user->id;
        Csrf::rotate();
        self::$cached = $user;
        self::$loaded = true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        Csrf::rotate();
        self::$cached = null;
        self::$loaded = true;
    }
}
