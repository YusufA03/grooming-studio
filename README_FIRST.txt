EXECUTIVE GROOMING STUDIO - complete, latest code (bundle of 2026-10-15)

NEW IN THIS BUNDLE
 - SMART SCHEDULING: the booking page suggests the soonest free time, or the next best stylist if the chosen one is full.
 - ALLERGY NOTES: a dedicated, highlighted field for allergies and sensitivities (stylists see it in a red box).
 - LOYALTY POINTS: 1 point for every R10 of a completed appointment; 100 points = R50 off in the salon.
   Administrator menu: Loyalty.
 - REPORTS: busy periods, popular services and stylist performance, with a spreadsheet (CSV) download.
   Administrator menu: Reports.
 - AUDIT TRAIL: every booking keeps a history of who did what and when. Dashboard: History button.
 - Plus everything from the last bundle (inspiration photos, staff rescheduling, backups, one-command updater).

STEP 1 - UPDATE THE FILES (on your computer), ONE COMMAND
 1. Extract this whole zip into a temporary folder (for example C:\Temp\gs-update).
 2. Open a terminal in that folder and run, using YOUR project folder:
      C:\xampp\php\php.exe install_update.php "C:\xampp\htdocs\grooming-studio" --check     (only shows what it would do)
      C:\xampp\php\php.exe install_update.php "C:\xampp\htdocs\grooming-studio"             (does it)

STEP 2 - UPDATE THE DATABASE (ONCE; this is NEW and REQUIRED)
 IMPORTANT: use ONLY migration_2026_10_15.sql on a database that already has data. schema.sql is for a brand-new,
 empty database; since this version it refuses to run on a database that already has tables (it never deletes anything).
 This version adds two tables and two columns. Without this step, booking will fail.
 In phpMyAdmin: click your database (grooming_studio) > Import > choose
      database\migration_2026_10_15.sql   (it is inside the update, and now also in your project's database folder)
 It is SAFE TO RUN MORE THAN ONCE and it deletes nothing. Existing completed appointments earn their points.
 (A brand-new installation does not need this: schema.sql already includes it.)

STEP 3 - CHECK, AND RUN THE AUTOMATED TESTS (NEW)
      C:\xampp\php\php.exe tools\run_tests.php
      Start XAMPP MySQL first (and Mailpit, for the email tests). It builds its OWN test database and web server,
      runs about 80 checks mapped to your Phase 5 test-case numbers, and saves a report in test-results\ (open the
      .html file and screenshot it). Your real database is never touched. If Windows asks about php.exe, click Allow.
      C:\xampp\php\php.exe tools\verify_files.php
      C:\xampp\php\php.exe tools\check_install.php

WHAT THE UPDATER DOES / NEVER TOUCHES
   It copies only files that are new or different, saves a copy of everything it replaces next to your project,
   removes known stray files, and checks every file afterwards.
   It NEVER touches config\db_config.php, mail_config.php, cron_config.php, vendor, .git, uploaded pictures, composer files.

SECRETS: real passwords go ONLY in config\db_config.php, config\mail_config.php and config\cron_config.php.
The .example.php files are published to GitHub, so they must contain only placeholders.

ON THE LIVE SITE (Alwaysdata)
 1. FIRST run the migration on the live database: phpMyAdmin > your live database > Import > RUN_FIRST_migration_2026_10_15.sql
    (or database\migration_2026_10_15.sql).
 2. THEN upload classes, config, public, storage, tools and MANIFEST.json over SFTP (overwrite). Do NOT upload install_update.php.
 3. Open https://YOUR-SITE/server_check.php?key=YOUR-SECRET : sections 3, 4 and 7 must be all OK.
 4. Finally DELETE from the live site: public/server_check.php  public/email_test.php  public/cron_reminders.php
