<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Comment;
use App\Models\Discussion;
use App\Support\Auth;
use App\Support\CommentTree;
use DateTimeImmutable;
use DateTimeZone;

final class DiscussionController extends Controller
{
    /** Largest displayed depth a new reply may have (write-side limit). */
    public const MAX_REPLY_DEPTH = 20;

    /** GET / */
    public function index(): void
    {
        $discussions = Discussion::latestWithCounts()->map(fn (Discussion $d) => [
            'id'            => $d->id,
            'title'         => $d->title,
            'author'        => $d->author?->username ?? '[deleted]',
            'comment_count' => $d->comments_count,
            'created_iso'   => self::iso($d->created_at),
            'created_human' => self::human($d->created_at),
        ])->all();

        $this->view->render('home', [
            'page_title'      => 'Discussions',
            'discussions'     => $discussions,
            'has_discussions' => $discussions !== [],
        ]);
    }

    /** GET /discussions/{id} */
    public function show(string $rawId): void
    {
        // 1. Validate the ID before touching the database.
        $id = $this->parseId($rawId);
        if ($id === null) {
            $this->view->error(400, 'Bad request', 'A discussion ID must be a positive whole number.');
            return;
        }

        // 2. Fetch the discussion; the comment query only runs if it exists.
        $discussion = Discussion::findWithAuthor($id);
        if ($discussion === null) {
            $this->view->error(404, 'Discussion not found', "There is no discussion with ID {$id}.");
            return;
        }

        // 3. Fetch comments, convert to plain arrays, group by parent, nest.
        $rows = Comment::forDiscussion($id)->map(fn (Comment $c) => [
            'id'            => (int) $c->id,
            'parent_id'     => $c->parent_id === null ? null : (int) $c->parent_id,
            'author'        => $c->author?->username ?? '[deleted]',
            'body'          => $c->body,
            'created_iso'   => self::iso($c->created_at),
            'created_human' => self::human($c->created_at),
        ])->all();

        $tree = CommentTree::build($rows);
        $loggedIn = Auth::check();
        $tree = self::decorate($tree, $loggedIn);

        // 4. Hand plain data to the template.
        $this->view->render('discussion', [
            'page_title'    => $discussion->title,
            'discussion'    => [
                'id'            => $discussion->id,
                'title'         => $discussion->title,
                'body'          => $discussion->body,
                'author'        => $discussion->author?->username ?? '[deleted]',
                'created_iso'   => self::iso($discussion->created_at),
                'created_human' => self::human($discussion->created_at),
            ],
            'discussion_id' => $discussion->id,
            'comments'      => $tree,
            'has_comments'  => $tree !== [],
            'comment_count' => count($rows),
            'login_next'    => '/discussions/' . $discussion->id,
        ]);
    }

    /** Add per-node flags the template needs (reply form visibility). */
    private static function decorate(array $nodes, bool $loggedIn): array
    {
        foreach ($nodes as &$node) {
            $node['can_reply'] = $loggedIn && $node['depth'] < self::MAX_REPLY_DEPTH;
            $node['children'] = self::decorate($node['children'], $loggedIn);
        }

        return $nodes;
    }

    private static function iso(?string $utc): string
    {
        return $utc === null ? '' : (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    private static function human(?string $utc): string
    {
        return $utc === null ? '' : (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->format('Y-m-d H:i') . ' UTC';
    }
}
