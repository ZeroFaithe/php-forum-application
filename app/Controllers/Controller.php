<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\Csrf;
use App\Support\View;

abstract class Controller
{
    public function __construct(protected View $view)
    {
    }

    /**
     * Parse a route ID. Returns null for anything that is not a positive
     * integer within the INT UNSIGNED range (e.g. "abc", "0", "-1", "1.5", "01").
     */
    protected function parseId(string $raw): ?int
    {
        if (!preg_match('/^[1-9][0-9]{0,9}$/', $raw)) {
            return null;
        }
        $id = (int) $raw;

        return $id <= 4294967295 ? $id : null;
    }

    /** Read a string field from the POST body; arrays and missing fields become ''. */
    protected function input(string $key): string
    {
        $value = $_POST[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /** Send 403 and return false when the CSRF token is missing or wrong. */
    protected function verifyCsrf(): bool
    {
        if (Csrf::isValid($_POST['_token'] ?? null)) {
            return true;
        }

        $this->view->error(
            403,
            'Form expired',
            'The security token for this form was missing or invalid. Go back, reload the page and try again.'
        );

        return false;
    }
}
