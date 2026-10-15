<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Mailer.php';

// OPTIONAL client accounts. A client never needs one (they can always book as a guest), but with one they can
// see all their appointments and their loyalty points.
//
// An account is made, or a forgotten password reset, with the SAME emailed one-time link. Opening the link proves
// the person owns the email address, so nobody can read someone else's booking history just by typing their email.
// Only the SHA-256 of the link's secret is stored, a link works once, and it expires.
class Account
{
    public const MIN_PASSWORD = 10;
    public const MAX_PASSWORD = 72;           // the longest password bcrypt can use
    public const LINK_HOURS   = 24;
    private const RESEND_SECONDS = 60;        // one link per minute for each email address, so nobody can flood an inbox

    // Emails a link to choose a password. Returns the link's secret (the administrator page shows it once), or null when
    // nothing was sent: an invalid address, or a link was already sent less than a minute ago.
    // The caller always shows the same message, so the page never reveals whether an email has an account.
    public static function requestLink(string $name, string $email, int $validHours = self::LINK_HOURS, bool $throttle = true): ?string
    {
        $email = trim($email);
        $name  = trim($name);
        if ($name === '' || mb_strlen($name) > 100 || mb_strlen($email) > 150 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        $db  = Database::getConnection();
        $now = new DateTimeImmutable('now');

        if ($throttle) {
            $recent = $db->prepare('SELECT COUNT(*) FROM AccountToken WHERE email = :email AND created_at > :since');
            $recent->execute([':email' => $email, ':since' => $now->modify('-' . self::RESEND_SECONDS . ' seconds')->format('Y-m-d H:i:s')]);
            if ((int) $recent->fetchColumn() > 0) {
                return null;
            }
        }

        $has = $db->prepare('SELECT password_hash FROM Client WHERE email = :email ORDER BY client_id LIMIT 1');
        $has->execute([':email' => $email]);
        $existing = $has->fetchColumn();
        $existingAccount = ($existing !== false && $existing !== null);

        $secret = bin2hex(random_bytes(32));

        $db->beginTransaction();
        try {
            // an older unused link stops working as soon as a newer one is made
            $db->prepare('UPDATE AccountToken SET used_at = :now WHERE email = :email AND used_at IS NULL')
               ->execute([':now' => $now->format('Y-m-d H:i:s'), ':email' => $email]);
            $db->prepare(
                'INSERT INTO AccountToken (token_hash, email, name, created_at, expires_at)
                 VALUES (:hash, :email, :name, :created, :expires)'
            )->execute([
                ':hash'    => hash('sha256', $secret),
                ':email'   => $email,
                ':name'    => $name,
                ':created' => $now->format('Y-m-d H:i:s'),
                ':expires' => $now->modify('+' . $validHours . ' hours')->format('Y-m-d H:i:s'),
            ]);
            $db->commit();
        } catch (Throwable $ex) {
            if ($db->inTransaction()) { $db->rollBack(); }
            throw $ex;
        }

        // If the email cannot be sent the person simply asks again; the page says the same thing either way.
        Mailer::sendAccountLink($email, $name, $secret, $validHours, $existingAccount);
        return $secret;
    }

    // The link's row if the secret is real, unused and not expired. Otherwise null.
    public static function findValid(string $secret): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $secret)) {
            return null;
        }
        $stmt = Database::getConnection()->prepare(
            'SELECT token_id, email, name FROM AccountToken
             WHERE token_hash = :hash AND used_at IS NULL AND expires_at > :now'
        );
        $stmt->execute([':hash' => hash('sha256', $secret), ':now' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s')]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    // Uses the link: saves the password (creating the client record if this email has none yet) and marks the link used.
    // Returns ['error' => message|null, 'client_id' => int|null].
    public static function setPassword(string $secret, string $password): array
    {
        $length = strlen($password);
        if ($length < self::MIN_PASSWORD) {
            return ['error' => 'Please choose a password of at least ' . self::MIN_PASSWORD . ' characters.', 'client_id' => null];
        }
        if ($length > self::MAX_PASSWORD) {
            return ['error' => 'Please choose a password of at most ' . self::MAX_PASSWORD . ' characters.', 'client_id' => null];
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $secret)) {
            return ['error' => 'This link is not valid.', 'client_id' => null];
        }

        $db   = Database::getConnection();
        $now  = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $db->beginTransaction();
        try {
            // lock the link, so two clicks at the same moment cannot both use it
            $find = $db->prepare('SELECT token_id, email, name FROM AccountToken WHERE token_hash = :hash AND used_at IS NULL AND expires_at > :now FOR UPDATE');
            $find->execute([':hash' => hash('sha256', $secret), ':now' => $now]);
            $token = $find->fetch();
            if ($token === false) {
                $db->rollBack();
                return ['error' => 'This link has expired or has already been used. Please ask for a new one.', 'client_id' => null];
            }

            $client = $db->prepare('SELECT client_id FROM Client WHERE email = :email ORDER BY client_id LIMIT 1 FOR UPDATE');
            $client->execute([':email' => $token['email']]);
            $clientId = $client->fetchColumn();
            if ($clientId === false) {
                $db->prepare('INSERT INTO Client (name, email, password_hash) VALUES (:name, :email, :hash)')
                   ->execute([':name' => $token['name'], ':email' => $token['email'], ':hash' => $hash]);
                $clientId = (int) $db->lastInsertId();
            } else {
                $clientId = (int) $clientId;
                $db->prepare('UPDATE Client SET password_hash = :hash WHERE client_id = :id')->execute([':hash' => $hash, ':id' => $clientId]);
            }

            $db->prepare('UPDATE AccountToken SET used_at = :now WHERE email = :email AND used_at IS NULL')
               ->execute([':now' => $now, ':email' => $token['email']]);

            $db->commit();
            return ['error' => null, 'client_id' => $clientId];
        } catch (Throwable $ex) {
            if ($db->inTransaction()) { $db->rollBack(); }
            throw $ex;
        }
    }
}
