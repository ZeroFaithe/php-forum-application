<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\CommentController;
use App\Controllers\DiscussionController;
use App\Support\View;
use Bramus\Router\Router;

/**
 * Route table. Discussion IDs are captured with a permissive pattern
 * ([^/]+) so that the controller, not the router, decides the response:
 * malformed IDs get 400 Bad Request, well-formed but unknown IDs get 404.
 */
return static function (Router $router, View $view): void {
    $discussions = new DiscussionController($view);
    $comments    = new CommentController($view);
    $auth        = new AuthController($view);

    $router->get('/', [$discussions, 'index']);
    $router->get('/discussions/([^/]+)', [$discussions, 'show']);
    $router->post('/discussions/([^/]+)/comments', [$comments, 'store']);

    $router->get('/register', [$auth, 'showRegister']);
    $router->post('/register', [$auth, 'register']);
    $router->get('/login', [$auth, 'showLogin']);
    $router->post('/login', [$auth, 'login']);
    $router->post('/logout', [$auth, 'logout']);

    $router->set404(static function () use ($view): void {
        $view->error(404, 'Page not found', 'There is no page at this address.');
    });
};
