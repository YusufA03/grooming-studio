EXECUTIVE GROOMING STUDIO - complete, latest code (bundle of 2026-10-14c)

THE EASY WAY (recommended): ONE COMMAND, NO COPYING BY HAND
 1. Extract this whole zip into a temporary folder (for example C:\Temp\gs-update).
 2. Open a terminal in that folder and run, using YOUR project folder:

      C:\xampp\php\php.exe install_update.php "C:\xampp\htdocs\grooming-studio" --check
         (only shows what it WOULD do; changes nothing)

      C:\xampp\php\php.exe install_update.php "C:\xampp\htdocs\grooming-studio"
         (does it)

 3. Then, inside your project folder, run:
      C:\xampp\php\php.exe tools\verify_files.php
      C:\xampp\php\php.exe tools\check_install.php

WHAT THE UPDATER DOES
   - copies every official file into the right place (only files that are new or different)
   - first saves a copy of every file it replaces or removes, in a folder NEXT TO your project
   - removes known stray files from earlier copy-and-paste slips
   - adds the missing lines to .gitignore
   - checks every file afterwards and tests that every PHP file is valid
WHAT IT NEVER TOUCHES
   config\db_config.php, config\mail_config.php, config\cron_config.php, the vendor folder, the .git folder,
   uploaded service pictures, your hero photo, composer files.

SECRETS: put real passwords ONLY in config\db_config.php, config\mail_config.php and config\cron_config.php.
The files ending in .example.php are published to GitHub, so they must contain only placeholders.

ON THE LIVE SITE (Alwaysdata)
 Upload the folders  classes  config  database  public  storage  tools  and the file  MANIFEST.json  over SFTP
 (overwrite). Do NOT upload install_update.php. Then open  https://YOUR-SITE/server_check.php?key=YOUR-SECRET
 (look at sections 3 and 7), set up the scheduled tasks, and finally DELETE from the live site:
 public/server_check.php  public/email_test.php  public/cron_reminders.php
