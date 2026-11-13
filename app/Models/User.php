<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int         $id
 * @property string      $username
 * @property string      $email
 * @property string|null $password_hash
 */
final class User extends Model
{
    protected $table = 'users';
    public $timestamps = false;           // created_at is set by the database
    protected $fillable = ['username', 'email', 'password_hash'];
    protected $hidden = ['password_hash', 'email'];

    public function discussions(): HasMany
    {
        return $this->hasMany(Discussion::class, 'user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'user_id');
    }

    public static function findByUsername(string $username): ?self
    {
        return self::query()->where('username', $username)->first();
    }

    public static function usernameOrEmailTaken(string $username, string $email): array
    {
        $taken = [];
        if (self::query()->where('username', $username)->exists()) {
            $taken[] = 'username';
        }
        if (self::query()->where('email', $email)->exists()) {
            $taken[] = 'email';
        }

        return $taken;
    }

    public static function register(string $username, string $email, string $password): self
    {
        return self::create([
            'username'      => $username,
            'email'         => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
    }

    /** A NULL hash (seed users) never authenticates. */
    public function verifyPassword(string $password): bool
    {
        return is_string($this->password_hash)
            && $this->password_hash !== ''
            && password_verify($password, $this->password_hash);
    }
}
