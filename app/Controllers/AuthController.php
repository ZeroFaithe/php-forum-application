<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\User;
use App\Support\Auth;
use App\Support\Session;
use Illuminate\Database\QueryException;

final class AuthController extends Controller
{
    private const USERNAME_PATTERN = '/^[A-Za-z0-9_]{3,30}$/';
    private const MIN_PASSWORD = 8;
    private const MAX_PASSWORD_BYTES = 72; // bcrypt ignores bytes after 72

    // ---- Registration -----------------------------------------------------

    /** GET /register */
    public function showRegister(): void
    {
        if (Auth::check()) {
            $this->view->redirect('/');
            return;
        }
        $this->view->render('register', ['page_title' => 'Register']);
    }

    /** POST /register */
    public function register(): void
    {
        if (!$this->verifyCsrf()) {
            return;
        }

        $username = trim($this->input('username'));
        $email    = trim($this->input('email'));
        $password = $this->input('password');
        $confirm  = $this->input('password_confirmation');

        $errors = [];
        if (!preg_match(self::USERNAME_PATTERN, $username)) {
            $errors[] = 'Username must be 3–30 characters: letters, numbers or underscores.';
        }
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Enter a valid email address.';
        }
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            $errors[] = 'Password must be at least ' . self::MIN_PASSWORD . ' characters.';
        } elseif (strlen($password) > self::MAX_PASSWORD_BYTES) {
            $errors[] = 'Password must be at most ' . self::MAX_PASSWORD_BYTES . ' bytes.';
        }
        if (!hash_equals($password, $confirm)) {
            $errors[] = 'Passwords do not match.';
        }

        if ($errors === []) {
            foreach (User::usernameOrEmailTaken($username, $email) as $field) {
                $errors[] = $field === 'username' ? 'That username is already taken.' : 'An account with that email already exists.';
            }
        }

        if ($errors === []) {
            try {
                $user = User::register($username, $email, $password);
            } catch (QueryException $e) {
                // Unique-key race: another request registered the same name first.
                if (($e->errorInfo[1] ?? null) !== 1062) {
                    throw $e;
                }
                $errors[] = 'That username or email is already registered.';
            }
        }

        if ($errors !== []) {
            $this->view->render('register', [
                'page_title' => 'Register',
                'errors'     => $errors,
                'old'        => ['username' => $username, 'email' => $email],
            ], 422);
            return;
        }

        Auth::login($user);
        Session::flash('success', "Welcome, {$user->username}! Your account was created.");
        $this->view->redirect($this->pullIntended());
    }

    // ---- Login / logout ---------------------------------------------------

    /** GET /login */
    public function showLogin(): void
    {
        if (Auth::check()) {
            $this->view->redirect('/');
            return;
        }
        $this->view->render('login', ['page_title' => 'Log in']);
    }

    /** POST /login */
    public function login(): void
    {
        if (!$this->verifyCsrf()) {
            return;
        }

        $username = trim($this->input('username'));
        $password = $this->input('password');

        $user = $username === '' ? null : User::findByUsername($username);
        $ok = $user !== null && $user->verifyPassword($password);
        if ($user === null) {
            // Spend similar time whether or not the user exists
            // (hash of a random throwaway value, not a real password).
            password_verify($password, '$2y$10$xvOtXj7WRahdZeluT6ajUOHRuCSrRLz3g73VTg/v8WFhdAlkeK6PK');
        }

        if (!$ok) {
            $this->view->render('login', [
                'page_title' => 'Log in',
                'errors'     => ['Incorrect username or password.'],
                'old'        => ['username' => $username],
            ], 422);
            return;
        }

        Auth::login($user); // regenerates the session ID
        Session::flash('success', "Logged in as {$user->username}.");
        $this->view->redirect($this->pullIntended());
    }

    /** POST /logout */
    public function logout(): void
    {
        if (!$this->verifyCsrf()) {
            return;
        }
        Auth::logout();
        Session::flash('success', 'You have been logged out.');
        $this->view->redirect('/');
    }

    /** Where to go after login: a stored internal path, or the home page. */
    private function pullIntended(): string
    {
        $path = $_SESSION['intended'] ?? '/';
        unset($_SESSION['intended']);

        return is_string($path) && preg_match('#^/discussions/[0-9]+$#', $path) ? $path : '/';
    }
}
