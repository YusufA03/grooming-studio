<?php
require_once __DIR__ . '/Database.php';

// Handles staff login, logout, sessions and role checks.
// (This class carries out the login() and logout() behaviour of the User
// class in the class diagram, for both stylists and administrators.)
class Auth
{
    // Staff are logged out after 30 minutes without activity.
    private const IDLE_TIMEOUT_SECONDS = 1800;

    // A real password hash that matches nothing. It is checked when the email
    // does not exist, so a wrong email takes as long to reject as a wrong
    // password (this stops people discovering which emails have accounts).
    private const DUMMY_HASH = '$2y$10$V2ZCGgyXl9u5asLP73sQz.aLd0OVOD75uOlzzGmFJAx0AnF9pnuzK';

    // Starts the session safely. Safe to call more than once.
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1'); // refuse session ids the server did not create
        ini_set('session.use_only_cookies', '1'); // never put the session id in the address

        session_name('grooming_session');
        session_set_cookie_params([
            'lifetime' => 0,                                                       // ends when the browser closes
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', // only over HTTPS when the site uses it
            'httponly' => true,                                                    // JavaScript cannot read the cookie
            'samesite' => 'Lax',                                                   // not sent with requests from other sites
        ]);
        session_start();
    }

    // Checks an email and password. If correct, logs the person in and
    // returns true. Works for administrators and stylists.
    public static function attempt(string $email, string $password): bool
    {
        self::start();

        $account = self::findAccount($email);
        $hash    = ($account !== null) ? $account['password_hash'] : self::DUMMY_HASH;
        $correct = password_verify($password, $hash);

        if ($account === null || !$correct) {
            usleep(500000); // a short pause slows down password guessing
            return false;
        }

        session_regenerate_id(true); // a brand new session id after login
        unset($_SESSION['csrf_token']);
        $_SESSION['user'] = [
            'id'   => (int) $account['id'],
            'name' => $account['name'],
            'role' => $account['role'],   // 'admin' or 'stylist'
        ];
        $_SESSION['last_activity'] = time();

        return true;
    }

    // Returns the logged-in person (id, name, role), or null if nobody is
    // logged in or they have been inactive for too long.
    public static function user(): ?array
    {
        self::start();

        if (!isset($_SESSION['user'], $_SESSION['last_activity'])) {
            return null;
        }
        if (time() - (int) $_SESSION['last_activity'] > self::IDLE_TIMEOUT_SECONDS) {
            self::logout();
            return null;
        }

        $_SESSION['last_activity'] = time();
        return $_SESSION['user'];
    }

    // Use this at the top of a staff-only page. It sends visitors who are
    // not logged in to the login page, and refuses people with the wrong role.
    public static function requireRole(string $role): array
    {
        $user = self::user();

        if ($user === null) {
            header('Location: login.php');
            exit;
        }

        if ($user['role'] !== $role) {
            http_response_code(403);
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
               . '<title>No access</title><link rel="stylesheet" href="assets/css/style.css?v=' . (int) @filemtime(__DIR__ . '/../public/assets/css/style.css') . '"><script src="assets/js/theme.js"></script></head><body>'
               . '<header class="site-header"><h1>Executive Grooming Studio</h1></header>'
               . '<main class="container"><p class="error">You do not have access to this page.</p>'
               . '<p><a href="index.php">&larr; Back to the home page</a></p></main></body></html>';
            exit;
        }

        return $user;
    }

    // Logs the person out and destroys the session completely.
    public static function logout(): void
    {
        self::start();

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => 'Lax',
            ]);
        }

        session_destroy();
    }

    // A secret token placed in every form that changes something. A form from
    // another website cannot know it, which blocks "cross-site request forgery".
    public static function csrfToken(): string
    {
        self::start();

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    // Checks the token that came back with a submitted form.
    public static function csrfValid($token): bool
    {
        self::start();

        return is_string($token)
            && isset($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }

    // Looks for an administrator, then a stylist, with this email.
    private static function findAccount(string $email): ?array
    {
        $db = Database::getConnection();

        $queries = [
            'admin'   => 'SELECT admin_id AS id, name, password_hash FROM Administrator WHERE email = :email',
            'stylist' => 'SELECT stylist_id AS id, name, password_hash FROM Stylist WHERE email = :email',
        ];

        foreach ($queries as $role => $sql) {
            $stmt = $db->prepare($sql);
            $stmt->execute([':email' => $email]);
            $row = $stmt->fetch();
            if ($row !== false) {
                $row['role'] = $role;
                return $row;
            }
        }
        return null;
    }
}
