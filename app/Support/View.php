<?php

declare(strict_types=1);

namespace App\Support;

use Mustache\Engine;
use Mustache\Loader\FilesystemLoader;

/**
 * Thin wrapper around Mustache. Every page receives the same shared data
 * (base path, current user, CSRF token, flash messages).
 *
 * Escaping: all templates use {{double}} mustaches, which run every value
 * through htmlspecialchars(ENT_QUOTES, UTF-8). No template uses {{{triple}}}
 * or {{& unescaped}} output.
 */
final class View
{
    private Engine $engine;

    public function __construct(string $viewsDir, private string $basePath)
    {
        $this->engine = new Engine([
            'loader'          => new FilesystemLoader($viewsDir),
            'partials_loader' => new FilesystemLoader($viewsDir . '/partials'),
            'entity_flags'    => ENT_QUOTES | ENT_SUBSTITUTE,
            'charset'         => 'UTF-8',
            'strict_callables' => true,
        ]);
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    /** Build an absolute URL path inside the app, e.g. url('/login'). */
    public function url(string $path): string
    {
        return $this->basePath . $path;
    }

    public function render(string $template, array $data = [], int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'self'; img-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");

        $user = Auth::user();
        $shared = [
            'base'       => $this->basePath,
            'csrf'       => Csrf::token(),
            'logged_in'  => $user !== null,
            'current_user' => $user?->username,
            'flash'      => Session::pullFlash(),
            'page_title' => 'Nested Forum',
        ];

        echo $this->engine->render($template, array_merge($shared, $data));
    }

    public function error(int $status, string $title, string $message): void
    {
        $this->render('error', [
            'page_title' => $title,
            'status'     => $status,
            'title'      => $title,
            'message'    => $message,
        ], $status);
    }

    public function redirect(string $path, int $status = 303): void
    {
        header('Location: ' . $this->url($path), true, $status);
    }
}
