# Nested Forum Lab

A small PHP forum that shows replies as a tree. Anyone can read a discussion;
only a logged-in user can post. Built for the IPT10 lab *Visualizing Nested
Forum Replies (PHP, MySQL and PDO)*.

## Libraries

| Concern | Library | Why |
|---|---|---|
| Routing | [`bramus/router`](https://github.com/bramus/router) ^1.6 | Lecture library; small regex router with a front controller |
| Data access | [`illuminate/database`](https://github.com/illuminate/database) ^12 (Eloquent) | Lecture library; uses PDO prepared statements underneath |
| Templates | [`mustache/mustache`](https://github.com/bobthecow/mustache.php) ^3.2 | Lecture library; logic-less, escapes `{{ }}` output by default |

No substitutions were made. Installed with:

```bash
composer require bramus/router illuminate/database mustache/mustache
```

## Requirements

- PHP 8.2+ with `pdo_mysql` and `mbstring` (XAMPP 8.2 works)
- MySQL 8 or MariaDB 10.4+ (XAMPP's MariaDB 10.4 was used for testing)
- Composer 2

## Setup

1. Install dependencies:

   ```bash
   composer install
   ```

2. Start MySQL/MariaDB (XAMPP Control Panel → MySQL → Start), then create the
   database, tables and fictional seed data. **This drops and recreates the
   `nested_forum` database's tables.**

   ```bash
   mysql -u root < database/schema.sql
   ```

   On XAMPP, the client is at `C:\xampp\mysql\bin\mysql.exe`.

3. Database settings default to XAMPP's (`root`, empty password, database
   `nested_forum` on `127.0.0.1:3306`). To change them, either set the
   `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` environment variables, or
   copy `config/database.local.example.php` to `config/database.local.php`
   (git-ignored) and edit it. No credentials are committed.

## Run locally

```bash
composer serve
```

which runs `php -S localhost:8000 -t public public/index.php`. Open
<http://localhost:8000/discussions/1>.

The seed users (Alice, Ben, Cara, Dee, Eli) have no password and **cannot log
in**. Use **Register** to create your own account, then reply.

### Production rewrites (explanation only)

The built-in server sends every request that is not a real file to
`public/index.php`. On Apache the same thing is done by pointing the document
root at `public/` and adding a rewrite such as:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [QSA,L]
```

On nginx: `try_files $uri /index.php?$query_string;`. This is not needed for
the local test.

## Project layout

```
public/index.php          front controller (only public PHP entry point)
public/assets/            CSS, default avatar, optional collapse script
config/bootstrap.php      Eloquent, session cookie settings, view setup
config/routes.php         route table
config/database.php       DB settings from environment variables
app/Controllers/          HTTP handling (validate, call models, pick view)
app/Models/               Eloquent models: User, Discussion, Comment
app/Support/              View, Auth, Csrf, Session flash, CommentTree
app/Views/                Mustache templates and partials
database/schema.sql       schema + fictional seed data
tests/run.php             unit + HTTP tests
```

## Routes

| Method | Path | Controller | Notes |
|---|---|---|---|
| GET | `/` | `DiscussionController@index` | List of discussions |
| GET | `/discussions/{id}` | `DiscussionController@show` | Public; 400 malformed ID, 404 unknown ID |
| POST | `/discussions/{id}/comments` | `CommentController@store` | Login + CSRF required; `parent_id` empty = top-level |
| GET/POST | `/register` | `AuthController` | CSRF required on POST |
| GET/POST | `/login` | `AuthController` | CSRF required on POST |
| POST | `/logout` | `AuthController@logout` | POST only, CSRF required |

**Malformed IDs.** The route captures any segment (`[^/]+`) so the controller,
not the router, decides: anything that is not a positive whole number
(`abc`, `0`, `-1`, `01`, `1.5`) returns **400 Bad Request**; a well-formed ID
with no row returns **404 Not Found**. The comment query does not run in
either case.

## Data model: `discussion_id` and `parent_id`

- Every comment has a `discussion_id` (which thread it belongs to) and a
  nullable `parent_id` (which comment it answers).
- `parent_id = NULL` means the comment replies to the discussion itself — a
  top-level comment, displayed depth 0.
- Otherwise `parent_id` points at another comment, and the reply's displayed
  depth is its parent's depth + 1. Depth is **calculated** when the page is
  built, never stored.
- A composite foreign key `(discussion_id, parent_id) → comments(discussion_id, id)`
  makes the database reject a parent from another discussion. It does not
  prevent cycles. The write handler prevents them: a new comment can only
  point to a comment that already exists, and `parent_id` is never updated, so
  a new row can never close a loop.

## Request flow

```
Browser ─GET /discussions/1─▶ public/index.php (front controller)
  ─▶ Bramus Router matches /discussions/([^/]+)
  ─▶ DiscussionController::show("1")
       1. parseId() → 400 if malformed
       2. Discussion::findWithAuthor(1) → 404 if null
       3. Comment::forDiscussion(1)         (Eloquent → PDO prepared statement)
       4. CommentTree::build(rows)          group by parent_id, walk depth-first
  ─▶ View::render('discussion', data)    Mustache, recursive {{> comment}} partial
  ─▶ HTML response
```

Posting a reply: `CommentController::store` checks **login → CSRF token →
discussion ID → body → parent in same discussion and depth limit**, saves with
the **session** user ID (any `user_id` field in the form is ignored), then
redirects back to the new comment (Post/Redirect/Get).

## Tree rendering rules

- `CommentTree::groupByParent()` buckets rows by `parent_id`; `build()` walks
  the buckets depth-first starting from the top-level bucket.
- **Indent cap:** each card gets class `indent-N` with `N = min(depth, 8)`. The
  "Depth N" label always shows the true depth.
- **Traversal limit:** at most `Comment::MAX_LOADED` (2000) rows are loaded and
  the walk stops below depth 50 (`CommentTree::MAX_DEPTH`).
- **Write limit:** new replies may be at most displayed depth 20.
- **Cycle guard:** the walk skips any comment already on the current path.
  Comments in a disconnected cycle are unreachable from a root and do not
  render. This is a renderer safeguard, not a database integrity check.

## Security notes

- **Parameter binding.** All queries go through Eloquent's query builder,
  which sends SQL with `?` placeholders and the values bound separately by PDO,
  e.g. `select * from comments where discussion_id = ? order by created_at, id limit 2000`.
  No user input is concatenated into SQL. There is no SQL in controllers or
  templates.
- **HTML escaping.** Every template uses `{{double}}` mustaches, which pass
  values through `htmlspecialchars(ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`. No
  template uses `{{{triple}}}` or `{{& }}` unescaped output. Comment text is
  stored as typed and escaped on output; newlines are kept with CSS
  `white-space: pre-wrap`. A Content-Security-Policy header blocks inline
  scripts as a second layer.
- **CSRF.** One random 256-bit token per session, compared with `hash_equals`.
  Required on register, login, logout and both comment forms. Rotated on login
  and logout.
- **Passwords.** `password_hash()` (bcrypt) on register, `password_verify()` on
  login. A `NULL` hash never authenticates. Passwords are never logged or
  echoed back into forms.
- **Sessions.** `session_regenerate_id(true)` after login; cookie is
  `HttpOnly`, `SameSite=Lax`, and `Secure` whenever the request is HTTPS;
  `session.use_strict_mode` is on.

## Tests

Start the server and load a fresh schema first, then:

```bash
composer test
```

(`php tests/run.php`; add `--unit` for unit tests only, `--keep` to keep the
rows it creates; set `BASE_URL` if the app is not at `http://localhost:8000`.)

The HTTP tests register a throw-away user, then check — including directly in
the database — that:

- guests can read but a direct guest POST creates no row (also after logout);
- invalid CSRF tokens are rejected on register, login and replies (403);
- wrong passwords and seed users with `NULL` hashes cannot log in;
- the session ID changes on login and the cookie is HttpOnly + SameSite;
- a depth-5 reply (under Eli's comment 7) and a second top-level comment are
  saved with the session user's ID and rendered in the right place;
- a parent from another discussion, a malformed parent ID, a missing
  discussion, an empty body and an over-long body are all rejected;
- `<script>alert("xss")</script>` is shown as text, not run;
- malformed discussion IDs give 400 and missing ones give 404.

They delete the user and comments they created at the end.

### Manual checks (for screenshots)

1. Register, log in, open `/discussions/1`, reply to Eli's "Add replies next."
   → your reply shows **Depth 5** under it. Post a top-level comment → **Depth 0**.
2. Log out. The reply forms disappear. Send a direct POST:

   ```bash
   curl -i -X POST http://localhost:8000/discussions/1/comments -d "body=guest&parent_id="
   ```

   → `303` to `/login`, no new row.
3. In dev tools, change a form's hidden `_token` value and submit → 403.
4. Change a reply form's `parent_id` to `8` (a comment in discussion 2) → 422.
5. Visit `/discussions/abc` → 400 and `/discussions/999` → 404.
6. Post `<script>alert(1)</script>` → it appears as plain text.

## Optional challenge: collapse / expand replies

`public/assets/replies.js` adds a `<button type="button">` ("Hide replies (n)"
/ "Show replies (n)") to every comment that has replies, linked to its
`<ul id="replies-{id}">` through `aria-controls`, with `aria-expanded`
updated on each click and the branch hidden with the `hidden` attribute.
Buttons are created by the script, so with JavaScript disabled every reply is
visible and no dead controls appear. Comments without children get no button.
The script never reads or inserts comment text.

Checked: collapsing Alice hides Ben, Cara and Dee while Eli stays visible;
collapsing Ben hides Cara while Dee stays visible; depth labels are unchanged
after reopening. Because they are native buttons, Tab + Enter/Space operate
them; confirm this by hand before submitting.

## Limitations

- All comments of one discussion are loaded and rendered at once: fine for a
  lab, not for a large public forum (needs pagination or lazy loading).
- No rate limiting on login or registration, no account recovery, no
  moderation. Avatars are a fixed default image (upload/Gravatar are optional
  extensions not built here).
- Cycles are prevented by how writes work, not by a database constraint.
