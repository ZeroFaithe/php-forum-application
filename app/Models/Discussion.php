<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int    $id
 * @property int    $user_id
 * @property string $title
 * @property string $body
 * @property string $created_at
 */
final class Discussion extends Model
{
    protected $table = 'discussions';
    public $timestamps = false;

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'discussion_id');
    }

    /** One discussion with its author, or null when the ID does not exist. */
    public static function findWithAuthor(int $id): ?self
    {
        return self::query()->with('author:id,username')->find($id);
    }

    /** @return Collection<int, self> */
    public static function latestWithCounts(): Collection
    {
        return self::query()
            ->with('author:id,username')
            ->withCount('comments')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }
}
