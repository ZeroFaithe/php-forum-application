<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int      $id
 * @property int      $discussion_id
 * @property int|null $parent_id
 * @property int      $user_id
 * @property string   $body
 * @property string   $created_at
 */
final class Comment extends Model
{
    protected $table = 'comments';
    public $timestamps = false;
    protected $fillable = ['discussion_id', 'parent_id', 'user_id', 'body'];

    /** Upper bound on rows loaded for one page (traversal limit). */
    public const MAX_LOADED = 2000;

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class, 'discussion_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * All comments of one discussion with their authors, oldest first.
     * Eloquent sends this as a prepared statement with bound parameters:
     *   select * from comments where discussion_id = ? order by created_at, id limit 2000
     *
     * @return Collection<int, self>
     */
    public static function forDiscussion(int $discussionId): Collection
    {
        return self::query()
            ->with('author:id,username')
            ->where('discussion_id', $discussionId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::MAX_LOADED)
            ->get();
    }

    /**
     * Displayed depth of an existing comment (top level = 0), computed by
     * walking parent links inside one discussion. Returns null if the
     * comment is not in that discussion, or if a cycle or an over-long chain
     * is found.
     */
    public static function depthInDiscussion(int $commentId, int $discussionId, int $limit): ?int
    {
        /** @var array<int, int|null> $parents id => parent_id */
        $parents = self::query()
            ->where('discussion_id', $discussionId)
            ->pluck('parent_id', 'id')
            ->all();

        if (!array_key_exists($commentId, $parents)) {
            return null;
        }

        $depth = 0;
        $seen = [];
        $current = $commentId;
        while ($parents[$current] !== null) {
            $seen[$current] = true;
            $current = (int) $parents[$current];
            if (isset($seen[$current]) || !array_key_exists($current, $parents) || ++$depth > $limit) {
                return null;
            }
        }

        return $depth;
    }

    public static function post(int $discussionId, ?int $parentId, int $userId, string $body): self
    {
        return self::create([
            'discussion_id' => $discussionId,
            'parent_id'     => $parentId,
            'user_id'       => $userId,
            'body'          => $body,
        ]);
    }
}
