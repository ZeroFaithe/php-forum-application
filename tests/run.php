<?php

declare(strict_types=1);

/*
 * Test runner (no framework needed).
 *
 *   php tests/run.php            unit tests + HTTP tests against http://localhost:8000
 *   php tests/run.php --unit     unit tests only (no server or database needed)
 *   BASE_URL=http://localhost/nested_forum_lab/public php tests/run.php
 *   php tests/run.php --keep     keep the rows the HTTP tests create
 *
 * The HTTP tests need the dev server running and the schema loaded. They
 * register a throw-away user, post comments, check the database directly,
 * and delete what they created at the end (unless --keep is passed).
 */

use App\Support\CommentTree;
use Illuminate\Database\Capsule\Manager as Capsule;

require dirname(__DIR__) . '/vendor/autoload.php';
date_default_timezone_set('UTC');

$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  PASS  {$name}\n";
    } else {
        $failed++;
        echo "  FAIL  {$name}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
    }
}

// ===========================================================================
// Unit tests: CommentTree
// ===========================================================================
echo "Unit tests: CommentTree\n";

$row = fn (int $id, ?int $parent) => ['id' => $id, 'parent_id' => $parent];

// Lab sample, in id order (the order the model returns them).
$sample = [$row(1, null), $row(2, 1), $row(3, 2), $row(4, 1), $row(5, null), $row(6, 3), $row(7, 6)];
$flat = CommentTree::flatten(CommentTree::build($sample));
check(
    'sample tree renders in thread order with displayed depths',
    array_map(fn ($n) => [$n['id'], $n['depth']], $flat)
        === [[1, 0], [2, 1], [3, 2], [6, 3], [7, 4], [4, 1], [5, 0]]
);

$groups = CommentTree::groupByParent($sample);
check('grouping: comment 1 has children 2 and 4', array_column($groups[1], 'id') === [2, 4]);
check('grouping: two top-level comments', array_column($groups['root'], 'id') === [1, 5]);

// A 12-deep chain: depth labels stay exact, indentation caps at 8.
$chain = [];
for ($i = 1; $i <= 12; $i++) {
    $chain[] = $row($i, $i === 1 ? null : $i - 1);
}
$flat = CommentTree::flatten(CommentTree::build($chain));
$last = end($flat);
check('depth 11 keeps its label', $last['depth'] === 11);
check('indentation is capped at 8', $last['indent'] === 8 && max(array_column($flat, 'indent')) === 8);

// Cycle 2 -> 3 -> 2 is unreachable from a root and must not loop forever.
$flat = CommentTree::flatten(CommentTree::build([$row(1, null), $row(2, 3), $row(3, 2)]));
check('disconnected cycle is skipped without looping', array_column($flat, 'id') === [1]);

// Traversal limit.
$long = [];
for ($i = 1; $i <= 80; $i++) {
    $long[] = $row($i, $i === 1 ? null : $i - 1);
}
$flat = CommentTree::flatten(CommentTree::build($long));
check('traversal stops at MAX_DEPTH', count($flat) === CommentTree::MAX_DEPTH + 1);

if (in_array('--unit', $argv, true)) {
    summary();
}

// ===========================================================================
// HTTP tests
// ===========================================================================
$base = rtrim(getenv('BASE_URL') ?: 'http://localhost:8000', '/');
echo "\nHTTP tests against {$base}\n";

$capsule = new Capsule();
$capsule->addConnection(require dirname(__DIR__) . '/config/database.php');
$capsule->setAsGlobal();
$db = Capsule::connection();

/** Minimal HTTP client with its own cookie jar (one per "browser"). */
final class Client
{
    private CurlHandle $ch;
    public array $headers = [];

    public function __construct(private string $base)
    {
        $this->ch = curl_init();
        curl_setopt_array($this->ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEFILE     => '',  // in-memory cookie jar
            CURLOPT_HEADERFUNCTION => function ($ch, $line) {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $this->headers[strtolower(trim($k))][] = trim($v);
                }
                return strlen($line);
            },
        ]);
    }

    /** @return array{0:int,1:string} */
    public function get(string $path): array
    {
        $this->headers = [];
        curl_setopt($this->ch, CURLOPT_HTTPGET, true);
        curl_setopt($this->ch, CURLOPT_URL, $this->base . $path);
        $body = (string) curl_exec($this->ch);

        return [curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE), $body];
    }

    /** @return array{0:int,1:string} */
    public function post(string $path, array $fields): array
    {
        $this->headers = [];
        curl_setopt($this->ch, CURLOPT_URL, $this->base . $path);
        curl_setopt($this->ch, CURLOPT_POST, true);
        curl_setopt($this->ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        $body = (string) curl_exec($this->ch);

        return [curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE), $body];
    }

    public function location(): string
    {
        return $this->headers['location'][0] ?? '';
    }

    public function sessionCookie(): ?string
    {
        foreach (curl_getinfo($this->ch, CURLINFO_COOKIELIST) as $line) {
            $parts = explode("\t", $line);
            if (($parts[5] ?? '') === 'forum_sid') {
                return $parts[6] ?? null;
            }
        }

        return null;
    }

    /** CSRF token from the first form on a page. */
    public function token(string $path): string
    {
        [, $html] = $this->get($path);
        preg_match('/name="_token" value="([a-f0-9]{64})"/', $html, $m);

        return $m[1] ?? '';
    }
}

[$code] = (new Client($base))->get('/');
if ($code !== 200) {
    echo "  Server not reachable at {$base} (HTTP {$code}). Start it with: composer serve\n";
    $failed++;
    summary();
}

$commentCount = fn () => (int) $db->table('comments')->count();
$suffix = substr(bin2hex(random_bytes(4)), 0, 6);
$username = 'tester_' . $suffix;
$email = "tester_{$suffix}@example.test";
$password = 'lab-' . bin2hex(random_bytes(6));

// ---- Public reading & IDs -------------------------------------------------
$guest = new Client($base);
[$code, $html] = $guest->get('/discussions/1');
check('guest can read discussion 1 (200)', $code === 200);
check('guest sees all seeded authors', count(array_filter(['Alice', 'Ben', 'Cara', 'Dee', 'Eli'], fn ($n) => str_contains($html, ">{$n}<"))) === 5);
check('guest sees no reply forms', !str_contains($html, 'name="parent_id"'));
check('malformed ID returns 400', $guest->get('/discussions/abc')[0] === 400);
check('zero ID returns 400', $guest->get('/discussions/0')[0] === 400);
check('missing discussion returns 404', $guest->get('/discussions/999999')[0] === 404);

$fresh = new Client($base);
$fresh->get('/login');
$setCookie = implode("\n", $fresh->headers['set-cookie'] ?? []);
check('session cookie is HttpOnly and SameSite=Lax', stripos($setCookie, 'httponly') !== false && stripos($setCookie, 'samesite=lax') !== false, $setCookie);

// ---- Guest writes are rejected -------------------------------------------
$before = $commentCount();
$token = $guest->token('/discussions/1'); // guests still get a session token
[$code] = $guest->post('/discussions/1/comments', ['_token' => $token, 'body' => 'guest post', 'parent_id' => '']);
check('guest direct POST is redirected to login', $code === 303 && str_ends_with($guest->location(), '/login'), "HTTP {$code} " . $guest->location());
check('guest direct POST created no row', $commentCount() === $before);
[$code] = $guest->post('/discussions/1/comments', ['body' => 'no token either', 'parent_id' => '1']);
check('guest POST without token created no row', $commentCount() === $before);

// ---- Registration ---------------------------------------------------------
$user = new Client($base);
[$code] = $user->post('/register', ['_token' => 'bad', 'username' => $username, 'email' => $email, 'password' => $password, 'password_confirmation' => $password]);
check('register with invalid CSRF token is rejected (403)', $code === 403);
check('no account created by forged register', !$db->table('users')->where('username', $username)->exists());

$token = $user->token('/register');
[$code, $html] = $user->post('/register', ['_token' => $token, 'username' => 'x', 'email' => 'not-an-email', 'password' => 'short', 'password_confirmation' => 'other']);
check('invalid registration input is rejected (422)', $code === 422 && str_contains($html, 'valid email'));

[$code] = $user->post('/register', ['_token' => $token, 'username' => $username, 'email' => $email, 'password' => $password, 'password_confirmation' => $password]);
check('valid registration redirects (303)', $code === 303);
$stored = $db->table('users')->where('username', $username)->value('password_hash');
check('password stored as a hash, not plaintext', is_string($stored) && $stored !== $password && password_verify($password, $stored));
$userId = (int) $db->table('users')->where('username', $username)->value('id');

$dup = new Client($base);
$t = $dup->token('/register');
[$code, $html] = $dup->post('/register', ['_token' => $t, 'username' => strtoupper($username), 'email' => $email, 'password' => $password, 'password_confirmation' => $password]);
check('duplicate username/email rejected (422)', $code === 422 && str_contains($html, 'already'));

// ---- Logout / login -------------------------------------------------------
[$code] = $user->get('/logout');
check('logout is not available via GET', $code === 404);
$t = $user->token('/');
[$code] = $user->post('/logout', ['_token' => $t]);
check('POST logout redirects', $code === 303);
[, $html] = $user->get('/');
check('user is logged out', !str_contains($html, 'Signed in as'));

$t = $user->token('/login');
[$code, $html] = $user->post('/login', ['_token' => $t, 'username' => $username, 'password' => $password . 'x']);
check('incorrect password is rejected (422)', $code === 422 && str_contains($html, 'Incorrect username or password'));

[$code] = $user->post('/login', ['_token' => 'forged', 'username' => $username, 'password' => $password]);
check('login with invalid CSRF token is rejected (403)', $code === 403);

$seed = new Client($base);
$t = $seed->token('/login');
[$code] = $seed->post('/login', ['_token' => $t, 'username' => 'Alice', 'password' => '']);
check('seed user with NULL hash cannot log in', $code === 422);

$sidBefore = $user->sessionCookie();
$t = $user->token('/login');
[$code] = $user->post('/login', ['_token' => $t, 'username' => $username, 'password' => $password]);
check('correct password logs in (303)', $code === 303);
check('session ID regenerated on login', $sidBefore !== null && $user->sessionCookie() !== null && $sidBefore !== $user->sessionCookie());
[, $html] = $user->get('/discussions/1');
check('logged-in user sees reply forms', str_contains($html, 'name="parent_id" value="7"'));

// ---- Authenticated replies -------------------------------------------------
$t = $user->token('/discussions/1');

$before = $commentCount();
[$code] = $user->post('/discussions/1/comments', ['_token' => $t, 'body' => 'Depth five reply from the test run.', 'parent_id' => '7', 'user_id' => '1']);
$deep = $db->table('comments')->orderByDesc('id')->first();
check('reply to comment 7 saved (303)', $code === 303 && $commentCount() === $before + 1);
check('reply is a child of comment 7', $deep && (int) $deep->parent_id === 7 && (int) $deep->discussion_id === 1);
check('user_id comes from the session, not the form', $deep && (int) $deep->user_id === $userId);

[$code] = $user->post('/discussions/1/comments', ['_token' => $t, 'body' => 'Second top-level comment from the test run.', 'parent_id' => '']);
$top = $db->table('comments')->orderByDesc('id')->first();
check('top-level comment saved with NULL parent', $code === 303 && $top && $top->parent_id === null && (int) $top->user_id === $userId);

[, $html] = $user->get('/discussions/1');
check(
    'depth-5 reply rendered under Eli with its author',
    (bool) preg_match('#id="comment-7".*?id="comment-' . $deep->id . '".*?' . preg_quote($username, '#') . '.*?Depth 5#s', $html)
);
check('new top-level comment rendered at depth 0', (bool) preg_match('#id="comment-' . $top->id . '".*?Depth 0#s', $html));

$before = $commentCount();
[$code] = $user->post('/discussions/1/comments', ['_token' => str_repeat('0', 64), 'body' => 'forged', 'parent_id' => '']);
check('reply with invalid CSRF token rejected (403)', $code === 403 && $commentCount() === $before);

[$code] = $user->post('/discussions/2/comments', ['_token' => $t, 'body' => 'cross-discussion', 'parent_id' => '1']);
check('cross-discussion parent rejected (422)', $code === 422 && $commentCount() === $before);

[$code] = $user->post('/discussions/1/comments', ['_token' => $t, 'body' => 'bad parent', 'parent_id' => 'abc']);
check('malformed parent ID rejected (422)', $code === 422 && $commentCount() === $before);

[$code] = $user->post('/discussions/999999/comments', ['_token' => $t, 'body' => 'nowhere', 'parent_id' => '']);
check('reply to missing discussion returns 404', $code === 404 && $commentCount() === $before);

[$code] = $user->post('/discussions/1/comments', ['_token' => $t, 'body' => "   \n  ", 'parent_id' => '']);
check('empty reply rejected', $code === 303 && $commentCount() === $before);

[$code] = $user->post('/discussions/1/comments', ['_token' => $t, 'body' => str_repeat('a', 5001), 'parent_id' => '']);
check('over-long reply rejected', $commentCount() === $before);

$xss = '<script>alert("xss")</script><b onclick="x()">bold?</b>';
[$code] = $user->post('/discussions/1/comments', ['_token' => $t, 'body' => $xss, 'parent_id' => '5']);
[, $html] = $user->get('/discussions/1');
check('HTML/script-like comment is stored', $code === 303);
check('HTML/script-like comment is escaped on output', str_contains($html, '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;') && !str_contains($html, '<script>alert'));

// ---- After logout, writes are rejected --------------------------------------
$t = $user->token('/');
$user->post('/logout', ['_token' => $t]);
$before = $commentCount();
$t = $user->token('/discussions/1');
[$code] = $user->post('/discussions/1/comments', ['_token' => $t, 'body' => 'after logout', 'parent_id' => '7']);
check('POST after logout redirected to login, no row', $code === 303 && $commentCount() === $before);

// ---- Cleanup -------------------------------------------------------------------
if (!in_array('--keep', $argv, true)) {
    $db->table('comments')->where('user_id', $userId)->orderByDesc('id')->get(['id'])
        ->each(fn ($c) => $db->table('comments')->where('id', $c->id)->delete());
    $db->table('users')->where('id', $userId)->delete();
    echo "  (cleaned up test user and comments)\n";
}

summary();

function summary(): never
{
    global $passed, $failed;
    echo "\n{$passed} passed, {$failed} failed\n";
    exit($failed === 0 ? 0 : 1);
}
