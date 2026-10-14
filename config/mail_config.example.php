<?php
// Email settings. This file is NOT uploaded to GitHub (it is in .gitignore).
// Copy this file to mail_config.php and keep ONE of the three blocks below.
//
// 1) LOCAL TESTING with Mailpit (nothing is really sent):
//      host 'localhost', port 1025, no username/password, encryption ''
//
// 2) REAL EMAIL with a Gmail account (500 emails a day; needs 2-Step Verification
//    switched on and an "App password"):
//      host 'smtp.gmail.com', port 587, encryption 'tls',
//      username = the full Gmail address, password = the 16-character app password
//      (type it with no spaces), from_email = that same Gmail address
//
// 3) REAL EMAIL with Brevo (free plan: 300 emails a day):
//      host 'smtp-relay.brevo.com', port 587, encryption 'tls',
//      username = the SMTP login shown on Brevo's SMTP page, password = the SMTP key,
//      from_email = a sender address you have verified in Brevo
//
// On InfinityFree only port 587 works for outgoing email.
return [
    'host'       => 'localhost',
    'port'       => 1025,
    'username'   => '',        // empty for Mailpit
    'password'   => '',        // empty for Mailpit
    'encryption' => '',        // '' for Mailpit, 'tls' for port 587, 'ssl' for port 465
    'from_email' => 'bookings@executivegrooming.test',
    'from_name'  => 'Executive Grooming Studio',
    'site_url'   => 'http://localhost/grooming-studio/public',   // used for links inside emails: on the live site use its real address
];
