<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Comment;
use App\Models\Discussion;
use App\Support\Auth;
use App\Support\Session;

final class CommentController extends Controller
{
    public const MAX_BODY_LENGTH = 5000;

    /**
     * POST /discussions/{id}/comments
     * Fields: _token, body, parent_id (empty for a top-level comment).
     */
    public function store(string $rawId): void
    {
        // 1. Authentication is checked on the server for every write.
        $userId = Auth::id();
        if ($userId === null) {
            $id = $this->parseId($rawId);
            if ($id !== null) {
                $_SESSION['intended'] = '/discussions/' . $id;
            }
            Session::flash('error', 'Please log in to post a reply.');
            $this->view->redirect('/login');
            return;
        }

        // 2. CSRF token.
        if (!$this->verifyCsrf()) {
            return;
        }

        // 3. Discussion must be a valid, existing ID.
        $discussionId = $this->parseId($rawId);
        if ($discussionId === null) {
            $this->view->error(400, 'Bad request', 'A discussion ID must be a positive whole number.');
            return;
        }
        if (Discussion::query()->whereKey($discussionId)->doesntExist()) {
            $this->view->error(404, 'Discussion not found', "There is no discussion with ID {$discussionId}.");
            return;
        }

        // 4. Body: required, trimmed, length-limited. Stored as plain text;
        //    escaping happens on output.
        $body = trim(str_replace("\r\n", "\n", $this->input('body')));
        if ($body === '' || !mb_check_encoding($body, 'UTF-8')) {
            Session::flash('error', 'A reply cannot be empty.');
            $this->view->redirect('/discussions/' . $discussionId);
            return;
        }
        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            Session::flash('error', 'A reply can be at most ' . self::MAX_BODY_LENGTH . ' characters.');
            $this->view->redirect('/discussions/' . $discussionId);
            return;
        }

        // 5. Parent: empty = top level. Otherwise it must exist in THIS
        //    discussion, and the new reply must stay within the depth limit.
        //    Parents are only ever existing rows and parent_id is never
        //    updated, so a new row cannot close a cycle.
        $parentId = null;
        $rawParent = $this->input('parent_id');
        if ($rawParent !== '') {
            $parentId = $this->parseId($rawParent);
            $parentDepth = $parentId === null
                ? null
                : Comment::depthInDiscussion($parentId, $discussionId, DiscussionController::MAX_REPLY_DEPTH);

            if ($parentDepth === null) {
                $this->view->error(422, 'Invalid parent comment', 'The comment you replied to does not belong to this discussion.');
                return;
            }
            if ($parentDepth + 1 > DiscussionController::MAX_REPLY_DEPTH) {
                $this->view->error(422, 'Thread too deep', 'Replies are limited to depth ' . DiscussionController::MAX_REPLY_DEPTH . '.');
                return;
            }
        }

        // 6. Save with the session user's ID (never a submitted user ID).
        $comment = Comment::post($discussionId, $parentId, $userId, $body);

        // 7. Post/Redirect/Get.
        Session::flash('success', 'Your reply was posted.');
        $this->view->redirect('/discussions/' . $discussionId . '#comment-' . $comment->id);
    }
}
