<?php
// Email settings. This file is NOT uploaded to GitHub (it is in .gitignore).
// These values work with Mailpit, the local test mailbox.
return [
    'host'       => 'localhost',
    'port'       => 1025,
    'username'   => '',        // leave empty for Mailpit
    'password'   => '',
    'encryption' => '',        // '' for Mailpit; 'tls' or 'ssl' for a real email provider
    'from_email' => 'bookings@executivegrooming.test',
    'from_name'  => 'Executive Grooming Studio',
    'site_url'   => 'http://localhost/grooming-studio/public',   // used for links inside emails
];
