<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Account.php';

// Who is an administrator, and giving or taking away admin access from the website. (The command-line tool
// tools/set_password.php stays available as a backup way to make an administrator.)
//
// An administrator is either
//   - an account with a password of its own (made with the tool), or
//   - a CLIENT account that was given admin access here. It signs in with the client account's password.
class AdminAccess
{
    // Every administrator, for the Admins page.
    public static function listAdmins(): array
    {
        return Database::getConnection()->query(
            'SELECT a.admin_id, a.name, a.email, a.client_id,
                    (a.password_hash IS NOT NULL) AS own_password,
                    (c.password_hash IS NOT NULL) AS client_active
             FROM Administrator a LEFT JOIN Client c ON c.client_id = a.client_id
             ORDER BY a.name'
        )->fetchAll();
    }

    // Gives a person admin access. Returns ['error' => ?string, 'message' => ?string, 'link' => ?string].
    //  - If the email already has a client account, that account becomes an administrator and keeps its own password.
    //  - Otherwise a client account is made for it, and a one-time link to choose a password is emailed AND returned,
    //    so the administrator can hand it over (the page shows it only once).
    public static function grant(string $name, string $email): array
    {
        $name  = trim($name);
        $email = trim($email);
        if ($name === '' || mb_strlen($name) > 100) {
            return ['error' => 'Please enter the name (up to 100 characters).', 'message' => null, 'link' => null];
        }
        if ($email === '' || mb_strlen($email) > 150 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['error' => 'Please enter a valid email address.', 'message' => null, 'link' => null];
        }

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $exists = $db->prepare('SELECT COUNT(*) FROM Administrator WHERE email = :email');
            $exists->execute([':email' => $email]);
            if ((int) $exists->fetchColumn() > 0) {
                $db->rollBack();
                return ['error' => 'That email address is already an administrator.', 'message' => null, 'link' => null];
            }

            $find = $db->prepare('SELECT client_id, name, password_hash FROM Client WHERE email = :email ORDER BY client_id LIMIT 1 FOR UPDATE');
            $find->execute([':email' => $email]);
            $client = $find->fetch();
            if ($client === false) {
                $db->prepare('INSERT INTO Client (name, email) VALUES (:name, :email)')->execute([':name' => $name, ':email' => $email]);
                $client = ['client_id' => (int) $db->lastInsertId(), 'name' => $name, 'password_hash' => null];
            }

            $db->prepare('INSERT INTO Administrator (name, email, password_hash, client_id) VALUES (:name, :email, NULL, :client_id)')
               ->execute([':name' => $client['name'], ':email' => $email, ':client_id' => $client['client_id']]);
            $db->commit();
        } catch (Throwable $ex) {
            if ($db->inTransaction()) { $db->rollBack(); }
            throw $ex;
        }

        if ($client['password_hash'] !== null) {
            return ['error' => null, 'message' => $client['name'] . ' now has admin access and keeps their own password.', 'link' => null];
        }

        $secret = Account::requestLink($client['name'], $email, 24 * 7, false);
        $base   = Mailer::siteUrl() ?? '';
        return [
            'error'   => null,
            'message' => 'An account was created for ' . $client['name'] . ' and a link to choose a password was emailed to them. '
                       . 'You can also give them this link yourself. It is shown only once, works once, and expires in 7 days.',
            'link'    => ($secret === null) ? null : $base . '/set_password.php?token=' . $secret,
        ];
    }

    // Takes admin access away. A person's client account (and their bookings) stays. Returns '' when it worked, otherwise a message.
    // You cannot remove yourself, and the last administrator cannot be removed.
    public static function revoke(int $adminId, int $currentAdminId): string
    {
        if ($adminId === $currentAdminId) {
            return 'You cannot remove your own admin access. Ask another administrator.';
        }
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $count = (int) $db->query('SELECT COUNT(*) FROM Administrator FOR UPDATE')->fetchColumn();
            if ($count <= 1) {
                $db->rollBack();
                return 'The last administrator cannot be removed.';
            }
            $del = $db->prepare('DELETE FROM Administrator WHERE admin_id = :id');
            $del->execute([':id' => $adminId]);
            if ($del->rowCount() !== 1) {
                $db->rollBack();
                return 'We could not find that administrator.';
            }
            $db->commit();
            return '';
        } catch (Throwable $ex) {
            if ($db->inTransaction()) { $db->rollBack(); }
            throw $ex;
        }
    }
}
