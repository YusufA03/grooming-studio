# Executive Grooming Studio

An online booking system for a men's grooming salon, built in PHP and MySQL/MariaDB as the Year 3 IT group project at **Richfield Graduate Institute of Technology** (Durban).

**Live site:** https://executivegroomingstudio.alwaysdata.net
**Team:** Group 4 "Royal" · **Project due:** 9 November 2026

Clients book online in under a minute, without needing an account. Stylists see their day and week, with allergy notes and inspiration photos, and can cancel, move or complete their own appointments. Administrators run the whole salon from one dashboard: bookings, services, stylists and their hours, other administrators, loyalty points and a suite of printable reports.

---

## Team

| Name | Student number | Main contribution |
|------|----------------|-------------------|
| Yusuf Ally | _add number_ | _add_ |
| _Member 2_ | _add number_ | _add_ |
| _Member 3_ | _add number_ | _add_ |
| _Member 4_ | _add number_ | _add_ |
| _Member 5_ | _add number_ | _add_ |

## Project status

| Phase | Deliverable | Status |
|-------|-------------|--------|
| 1 | Project proposal | Done |
| 2 | Requirements and analysis | Done |
| 3 | System design (13 functional requirements) | Done |
| 4 | Detailed design | Done |
| 5 | Implementation, testing and evaluation | Code complete and deployed; test evidence and final report in progress |

---

## What it does

### For clients (no account needed)
- Choose a service, a stylist and a day, and see only the times that really fit. Availability comes from the service length, the stylist's working blocks and existing bookings, and is checked again inside a locked database transaction so two people can never take the same time.
- Add **allergy notes**, general notes and an optional **inspiration photo** (checked, resized and stored privately, never publicly browsable).
- Receive a confirmation email and a reminder shortly before the appointment.
- Use a private link in the email to **reschedule or cancel** up to 2 hours before the appointment.
- Optionally **create an account** with an emailed one-time link (no password to invent at the counter) to see upcoming and past appointments and loyalty points, with their details pre-filled next time. Old guest bookings made with the same email appear automatically.

### For stylists
- Log in to a **day or week schedule** with allergy notes highlighted and photos one click away.
- On their **own** bookings: **History**, **Reschedule**, **Cancel** (the client is emailed) and **Completed** (the client earns loyalty points). **No-show** is available once the appointment has started. Completed can be recorded early, after a "not started yet, are you sure?" prompt, and the history notes it.
- Add phone and walk-in bookings.

### For administrators
- **Bookings dashboard** with filters (date, stylist, status, client search) and the same four actions on any booking.
- **Add booking** on behalf of a client.
- **Services**: names, descriptions, durations, prices and pictures.
- **Stylists**: tick the services each stylist performs, set working hours as **blocks** (a lunch break is simply two blocks), and press **Stop taking bookings** / **Start taking bookings**. If a change would leave upcoming bookings outside the new hours or services, a warning lists them. Bookings are never changed automatically.
- **Admins**: grant or take away administrator access from the website. Access removed takes effect immediately, not at the end of the session.
- **Loyalty**: balances and history per client, with redemptions and corrections recorded in a ledger. Rewards are recorded in the system; they are not applied to payment.
- **Reports** (each prints on A4 and downloads as CSV): Bookings register, Takings (daily and by service), Stylist activity (rostered hours and utilisation), Cancellations and no-shows (notice given), Customer history (one client's bookings and points ledger), and a busy-periods overview. **Allergy notes never appear in any report or CSV.**
- **Booking history**: an audit trail of who booked, moved, cancelled or completed each booking, and when.

### Everywhere
- Light and dark theme switch, responsive layout, accessible forms and clear error messages.

---

## Technology

| Layer | Choice |
|-------|--------|
| Language | PHP 8 (plain PHP, no framework), object-oriented classes |
| Database | MySQL / MariaDB via PDO and prepared statements (10 tables) |
| Email | PHPMailer over SMTP (Gmail in production, Mailpit locally) |
| Front end | Server-rendered HTML, one stylesheet, one small script (theme switch) |
| Hosting | Alwaysdata (free tier), with scheduled tasks for reminders, backups and photo clean-up |
| Local development | XAMPP on Windows |

**Size:** 63 PHP files and about 10,400 lines of PHP: 14 classes, 31 public pages, 9 command-line tools.

### Structure

```
grooming-studio/
├── classes/      Business logic: Booking, Stylist, Client, Account, AdminAccess, Loyalty,
│                 AuditLog, Auth, Mailer, Reminder, Report, Service, ImageUpload, Database
├── config/       Helpers, shared page parts, timezone, and *.example.php settings templates
├── public/       The only folder the web server exposes: pages, CSS, JavaScript
├── storage/      Private inspiration photos (closed to the web)
├── tools/        Command-line tools (see below)
├── database/     schema.sql, schema_hosting.sql and the migration files
└── MANIFEST.json File fingerprints used by the installation checker
```

### Database (10 tables)
`Administrator` · `AccountToken` · `Booking` · `BookingLog` · `Client` · `LoyaltyLedger` · `Service` · `Stylist` · `StylistService` · `WorkingBlock`

### Security measures
- Prepared statements everywhere; all output HTML-escaped.
- Passwords hashed with `password_hash`; no passwords are stored in the code or the repository.
- CSRF token on every form that changes data.
- Role checks on every protected page, and a stylist can only ever reach their own bookings. Someone else's booking looks exactly like "not found".
- Private photos are served through a script that checks who is asking; the folder itself is closed to the web.
- Settings files (`db_config.php`, `mail_config.php`, `cron_config.php`) are git-ignored and never uploaded by the update packages.

---

## Getting started (local, Windows + XAMPP)

**1. Get the code and the mail library**
```
git clone https://github.com/YusufA03/grooming-studio.git C:\xampp\htdocs\grooming-studio
cd C:\xampp\htdocs\grooming-studio
composer install
```

**2. Create the database**
In phpMyAdmin create an empty database called `grooming_studio` (collation `utf8mb4_unicode_ci`), click it, and **Import** `database/schema.sql`. The file only creates tables; it never drops anything.

**3. Create your settings files**
Copy each `config/*.example.php` to the same name without `.example` and fill them in. For local email testing use **Mailpit** and set the mail settings to host `127.0.0.1`, port `1025`, no username, no password, no encryption, and `site_url` `http://localhost/grooming-studio/public`.

**4. Create the first accounts**
```
C:\xampp\php\php.exe tools\set_password.php
```
It asks whether the account is an administrator or a stylist, then the details and a password.

**5. Check the installation, then open the site**
```
C:\xampp\php\php.exe tools\check_install.php
```
Then browse to `http://localhost/grooming-studio/public/`.

**Mailpit (optional, for testing emails):** download `mailpit-windows-amd64.zip` from the Mailpit releases page, extract `mailpit.exe`, run it, allow it through the firewall, and open `http://localhost:8025` to see every email the site sends.

---

## Command-line tools

| Tool | What it does |
|------|--------------|
| `tools/set_password.php` | Create or reset an administrator or stylist login |
| `tools/check_install.php` | Checks files, settings, database and folders; prints what to fix |
| `tools/verify_files.php` | Confirms every file matches `MANIFEST.json` |
| `tools/send_reminders.php` | Sends due reminder emails (run every 5 minutes) |
| `tools/backup_db.php` | Full consistent backup to `backups/*.sql.gz` (run daily) |
| `tools/cleanup_images.php` | Removes photos that no booking uses (run daily) |
| `tools/run_tests.php` | The automated test runner (see Testing) |
| `tools/reset_test_data.php` | Clears clients and bookings for a fresh round of testing (see below) |
| `tools/apply_theme.php` | Developer helper for the stylesheet |

### Clearing test data
```
php tools/reset_test_data.php --dry-run     # only shows what would be deleted
php tools/reset_test_data.php               # backs up first, then asks you to type the database name
php tools/reset_test_data.php --keep-clients
```
It deletes bookings, booking history, loyalty points, waiting password links, private photos and clients, and restarts booking numbers at 1. It **keeps** services, stylists (with their services and hours) and administrators, including any client account that is also an administrator. If the backup fails, nothing is deleted. It is command-line only.

---

## Testing

The automated runner builds its **own temporary database and web server**, so your real data is never touched, and cleans up after itself even if it crashes.

```
C:\xampp\php\php.exe tools\run_tests.php
```

- **112 checks covering 109 of the 114 documented test cases.** On the finished project: 112 passed, 0 failed, 0 skipped (with Mailpit running; the email checks are skipped without it).
- Results are saved as `test-results\results-DATE.html` and `.csv`.
- The runner is itself tested: deliberately breaking features in a copy is caught every time.
- **Still done by hand** (5 test cases): TC-B11, TC-E04, TC-O04, TC-N01, TC-N04, plus live-site checks (HTTPS cookies, real email delivery, load times) and the browser and usability tests.

---

## Deployment (Alwaysdata)

The live site is updated with a package that contains **only safe files** (no settings, no photos, no backups). Order matters:

1. **Back up** the live database (`php tools/backup_db.php`) and download a copy of the current live files.
2. **Import the migration first.** `database/migration_2026_10_15.sql` then `database/migration_2026_10_16.sql` (each is safe to repeat and deletes nothing).
3. **Upload the files straight away** (the old code cannot read the new database).
4. Check the site, then delete any temporary diagnostic pages (`server_check.php`, `email_test.php`) from the server.

**Scheduled tasks** (Alwaysdata → Advanced → Scheduled tasks):

| Task | Command | When |
|------|---------|------|
| Reminders | `php /home/executivegroomingstudio/tools/send_reminders.php` | every 5 minutes |
| Backup | `php /home/executivegroomingstudio/tools/backup_db.php` | daily at 02:00 |
| Photo clean-up | `php /home/executivegroomingstudio/tools/cleanup_images.php` | daily |

Restoring a backup: phpMyAdmin → your database → **Import** → the `.sql.gz` file.

---

## Known limitations

- Emails can land in spam (free Gmail sending from a free hosting domain).
- Loyalty rewards are recorded in the system but not applied to payment.
- The audit trail starts from when it was introduced; older bookings show no history.
- A booking marked Completed or No-show cannot be undone from the site.
- There is no self-service password reset for staff (an administrator sets it) and no login lockout or two-factor sign-in.
- The test runner is verified on Windows (XAMPP) and Linux; other systems are untested.

---

## Contributing

- Work on a branch, keep commits small, and describe *what changed and why*.
- Never commit `config/db_config.php`, `config/mail_config.php`, `config/cron_config.php`, `backups/`, `test-results/`, `vendor/` or uploaded photos. Before committing, run:
  ```
  git diff --cached --name-only | Select-String "db_config|mail_config|cron_config|backups|test-results|vendor"
  ```
  It must print nothing.
- Run `tools\run_tests.php` before pushing and keep it green.

## Licence and academic note

Coursework for Richfield Graduate Institute of Technology. Not licensed for commercial use.
