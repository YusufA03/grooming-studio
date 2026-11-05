EXECUTIVE GROOMING STUDIO - LIVE UPDATE: PDF DOWNLOADS FOR EVERY REPORT (4 November 2026)
=========================================================================================

WHAT IS NEW
 Every report now has a "Download PDF" button next to Print and Download CSV:
 Bookings register, Takings, Stylist activity, Cancellations and no-shows, Customer history and the overview.
 The PDF is made from the same figures as the CSV, on A4, with the salon name, the report title, the period, who made it and when,
 tables with totals, and page numbers. Allergy notes never appear in a report.

NO DATABASE CHANGE. Do NOT import any SQL file. Nothing is added to the database.
This update has 8 files. It does not contain db_config.php, mail_config.php or cron_config.php, so your live settings are never replaced.

BEFORE YOU START (2 minutes)
 A. With SFTP, download these 7 files from the live server into a folder called "live-before-pdf-update" (your way back):
      config/report_helpers.php
      public/admin_report_cancellations.php, admin_report_customer.php, admin_report_overview.php,
      public/admin_report_register.php, admin_report_stylists.php, admin_report_takings.php
 B. Extract this zip on your computer.

STEP 1 - UPLOAD (SFTP, overwrite). Keep the same folders on the server:
      config/report_pdf.php          (NEW file)
      config/report_helpers.php
      public/admin_report_cancellations.php
      public/admin_report_customer.php
      public/admin_report_overview.php
      public/admin_report_register.php
      public/admin_report_stylists.php
      public/admin_report_takings.php
      MANIFEST.json                  (into the main folder, replacing the old one, so the file checker knows the new versions)
 Do NOT upload this README.

STEP 2 - CHECK IT WORKS (3 minutes)
 1. Log in as the administrator and open Reports.
 2. Open each of the six reports. Each one must show Print (A4), Download PDF and Download CSV.
 3. Press Download PDF on a few of them. A PDF file must download and open, showing the salon name, the title and the period.
 4. Download a CSV once as well: it must still work as before.
 5. (Optional) Over SSH:  cd ~   then   php tools/verify_files.php   should say every file is the official, latest version.

IF SOMETHING GOES WRONG
 Upload the 7 files you saved in step A back to the server. Nothing in the database was changed, so there is nothing else to undo.
