<?php
// Command-line tool: creates the administrator account, or sets the password
// of the administrator or of a stylist.
//
// Run it from the project folder:   php tools\set_password.php
//
// Passwords are stored as secure hashes (never as plain text).

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This tool can only be run from the command line.');
}

require_once __DIR__ . '/../classes/Database.php';

function ask(string $question): string
{
    echo $question;
    $line = fgets(STDIN);
    return $line === false ? '' : trim($line);
}

function stop(string $message): void
{
    echo "\nSTOPPED: $message\nNothing was changed.\n";
    exit(1);
}

// Asks twice and checks the password is acceptable. Returns its hash.
function askForPasswordHash(): string
{
    echo "\n(The password will be visible as you type. Use a demo password, not one you use anywhere else.)\n";
    $first  = ask('New password (at least 10 characters): ');
    $second = ask('Type it again: ');

    if ($first !== $second) {
        stop('The two passwords did not match.');
    }
    if (strlen($first) < 10) {
        stop('The password must be at least 10 characters long.');
    }
    if (strlen($first) > 72) {
        stop('The password can be at most 72 characters long.');
    }
    return password_hash($first, PASSWORD_DEFAULT);
}

try {
    $db = Database::getConnection();

    echo "Who do you want to set a password for?\n";
    echo "  1 = the administrator (the account is created if it does not exist yet)\n";
    echo "  2 = an existing stylist\n";
    $choice = ask('Type 1 or 2: ');

    if ($choice === '1') {
        $email = ask('Administrator email address: ');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            stop('That is not a valid email address.');
        }

        $clash = $db->prepare('SELECT COUNT(*) FROM Stylist WHERE email = :email');
        $clash->execute([':email' => $email]);
        if ((int) $clash->fetchColumn() > 0) {
            stop('That email already belongs to a stylist. Use a different email for the administrator.');
        }

        $find = $db->prepare('SELECT admin_id FROM Administrator WHERE email = :email');
        $find->execute([':email' => $email]);
        $adminId = $find->fetchColumn();

        if ($adminId === false) {
            $name = ask('Administrator name: ');
            if ($name === '' || strlen($name) > 100) {
                stop('Please give a name (up to 100 characters).');
            }
            $hash = askForPasswordHash();
            $db->prepare('INSERT INTO Administrator (name, email, password_hash) VALUES (:name, :email, :hash)')
               ->execute([':name' => $name, ':email' => $email, ':hash' => $hash]);
            echo "\nDone. The administrator account for $email was created.\n";
        } else {
            $hash = askForPasswordHash();
            $db->prepare('UPDATE Administrator SET password_hash = :hash WHERE admin_id = :id')
               ->execute([':hash' => $hash, ':id' => $adminId]);
            echo "\nDone. The administrator's password for $email was changed.\n";
        }

    } elseif ($choice === '2') {
        echo "\nStylists:\n";
        foreach ($db->query('SELECT name, email FROM Stylist ORDER BY name') as $s) {
            echo "  - {$s['name']}  <{$s['email']}>\n";
        }
        $email = ask("\nType the stylist's email address exactly as shown: ");

        $find = $db->prepare('SELECT stylist_id FROM Stylist WHERE email = :email');
        $find->execute([':email' => $email]);
        $stylistId = $find->fetchColumn();
        if ($stylistId === false) {
            stop('There is no stylist with that email address.');
        }

        $hash = askForPasswordHash();
        $db->prepare('UPDATE Stylist SET password_hash = :hash WHERE stylist_id = :id')
           ->execute([':hash' => $hash, ':id' => $stylistId]);
        echo "\nDone. The password for the stylist $email was set.\n";

    } else {
        stop('Please run the tool again and type 1 or 2.');
    }
} catch (PDOException $ex) {
    echo "\nSTOPPED: could not reach the database (" . $ex->getMessage() . ")\n";
    exit(1);
}
