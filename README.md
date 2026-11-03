# Executive Grooming Studio

An online booking system for a men's grooming salon, built in PHP and MySQL/MariaDB as the Year 3 IT group project at **Richfield Graduate Institute of Technology** (Durban).

**Live site:** https://executivegroomingstudio.alwaysdata.net
**Team:** Group 4 "Royal" · **Submitted:** 9 November 2026

Clients book online in under a minute, without needing an account, and the system only offers times that are really free. Stylists see their day or week with allergy notes and inspiration photos, and can reschedule, cancel or complete their own bookings. Administrators run services, stylists and their working hours, other administrators, loyalty points and six printable reports from one place.

---

## Team

| Name | Role | Student number |
|------|------|----------------|
| Yusuf Ally | Project Manager | _add number_ |
| Lufuno Ramarunmo | Business Analyst | _add number_ |
| Bhoavik Iswarlall | Developer | _add number_ |
| Taahir Inder | Systems / Solutions Architect | _add number_ |
| Calvin Van Niekerk | Tester (QA) | _add number_ |

## Project status

| Phase | Deliverable | Status |
|-------|-------------|--------|
| 1 | Project proposal | Done |
| 2 | Planning phase (feasibility, WBS, PERT and Gantt) | Done |
| 3 | Analysis phase (13 functional requirements) | Done |
| 4 | System design | Done, approved |
| 5 | Implementation, testing and installation | Complete and live |

---

## What it does

### Clients (no account needed)
- Choose a service, a stylist (or "any available stylist") and a day, and see only the times that really fit. Free times come from the service length, the stylist's working blocks and existing bookings, and are checked again inside a locked database transaction, so two people can never take the same time.
- The soonest free time is suggested.
- Add **allergy notes**, general notes and an optional **inspiration photo**. The photo is checked by its real content, re-saved, given a random name and stored privately.
- Receive a confirmation email and a reminder shortly before the appointment.
- Use the private link in the email to **reschedule or cancel** until 2 hours before the appointment.
- Optionally **create an account** through an emailed one-time link (no password to invent) to see upcoming and past appointments and loyalty points. Earlier guest bookings with the same email appear automatically.

### Stylists
- Log in to a **day or week schedule**, with allergy notes highlighted and photos one click away.
- On their **own** bookings: **History**, **Reschedule**, **Cancel** (the client is emailed) and **Completed** (the client earns loyalty points). **No-show** is available once the appointment has started. Completed can be recorded early, after a "not started yet, are you sure?" prompt, and the history notes it.
- Add bookings taken by phone.

### Administrators
- **Bookings dashboard** with filters (date, stylist, status, client search), 25 bookings per page, and the same four actions on any booking.
- **Add booking** on behalf of a client.
- **Services:** names, descriptions, durations, prices and pictures.
- **Stylists:** tick the services each stylist performs, set working hours as **blocks** (a lunch break is simply two blocks), and press **Stop taking bookings** or **Start taking bookings**. If a change would leave upcoming bookings outside the new hours or services, a warning lists them. Bookings are never changed automatically.
- **Admins:** give or take away administrator access from the website. Removal takes effect immediately.
- **Loyalty:** balances and a ledger for each client, with rewards and corrections recorded. 1 point for every R10 of a completed appointment, and 100 points can be exchanged in the salon for R50 off.
- **Reports** (each prints on A4 and downloads as CSV): Bookings register, Takings, Stylist activity, Cancellations and no-shows, Customer history, and a busy-periods overview. **Allergy notes never appear in any report or download.**
- **Booking history:** an audit trail of who booked, moved, cancelled or marked each booking, and when.

### Everywhere
- Light and dark themes, a responsive layout for phones and computers, and clear error messages.

---

## Technology

| Layer | Choice |
|-------|--------|
| Language | PHP 8 (plain PHP, no framework), object-oriented classes |
| Database | MySQL / MariaDB through PDO and prepared statements (10 tables) |
| Email | PHPMailer over SMTP (Gmail in production, Mailpit for local testing) |
| Front end | Server-rendered HTML, one stylesheet, one small script (the theme switch) |
| Hosting | Alwaysdata free plan, with scheduled tasks for reminders, backups and photo clean-up |
| Local development | XAMPP on Windows |

**Size:** 64 application PHP files and about 10,100 lines of PHP, plus about 790 lines of CSS and JavaScript. There are 14 classes, 31 pages (28 on the live site; three are temporary deployment helpers), 9 command-line tools and 2 test runners.

### Structure

```
grooming-studio/
├── classes/      Business logic: Account, AdminAccess, AuditLog, Auth, Booking, Client, Database,
│                 ImageUpload, Loyalty, Mailer, Reminder, Report, Service, Stylist
├── config/       Shared helpers and page parts, time zone, *.example.php settings templates
├── public/       The only folder the web server exposes: pages, CSS, JavaScript
├── storage/      Clients' private photos (closed to the web)
├── tools/        Command-line tools and the two test runners (see below)
├── database/     schema.sql, schema_hosting.sql and two migration files
├── MANIFEST.json File fingerprints used by the file checker
└── install_update.php  Updates a project folder in one command
```

### Database (10 tables)
`Administrator` · `AccountToken` · `Booking` · `BookingLog` · `Client` · `LoyaltyLedger` · `Service` · `Stylist` · `StylistService` · `WorkingBlock`

### Security
- Prepared statements for every query. All output is HTML-escaped.
- Passwords are stored only as `password_hash` (bcrypt) hashes.
- A random security (CSRF) token on every form that changes data.
- A role check on every protected page. A stylist can only reach their own bookings. Someone else's booking looks exactly like "not found".
- Sessions: strict mode, HttpOnly, SameSite=Lax, Secure on HTTPS, a new id after login and a 30-minute inactivity limit.
- Private photos are served through a script that checks who is asking. Uploads are judged by content, re-saved and renamed.
- Private booking and account links hold 64 random characters. Account links are one-time and only a hash of the secret is stored.
- The three settings files (`db_config.php`, `mail_config.php`, `cron_config.php`) are git-ignored and never included in update packages.

---

## Getting started (local, Windows with XAMPP)

**1. Get the code and the mail library**
```
git clone https://github.com/YusufA03/grooming-studio.git C:\xampp\htdocs\grooming-studio
cd C:\xampp\htdocs\grooming-studio
composer install
```

**2. Create the database**
Start Apache and MySQL in XAMPP. In phpMyAdmin (http://localhost/phpmyadmin) click **Import** and import `database/schema.sql`. It creates the database `grooming_studio`, the ten tables and sample data (five services and three stylists). It only creates tables and never drops anything.

**3. Create your settings files**
Copy each `config/*.example.php` to the same name without `.example`, and fill them in:
- `db_config.php`: the defaults suit XAMPP.
- `mail_config.php`: for local testing use host `127.0.0.1`, port `1025`, no username, password or encryption, and `site_url` `http://localhost/grooming-studio/public`.
- `cron_config.php`: replace the placeholder with a long random key, made with `C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(20));"`.

**4. Create the first account**
```
C:\xampp\php\php.exe tools\set_password.php
```
It asks whether the account is an administrator or a stylist, then the email and a password (at least 10 characters).

**5. Check the installation**
```
C:\xampp\php\php.exe tools\check_install.php
```
It must show no FIX line. Then open `http://localhost/grooming-studio/public/`.

**Mailpit (optional, to see the emails the system sends):** download `mailpit-windows-amd64.zip` from the Mailpit releases page, extract `mailpit.exe`, run it and open `http://localhost:8025`.

---

## Command-line tools

Run them from the project folder with `C:\xampp\php\php.exe tools\NAME.php`. Each refuses to run from a browser.

| Tool | What it does |
|------|--------------|
| `set_password.php` | Create or reset an administrator or stylist login |
| `send_reminders.php` | Send reminder emails that are due (run every 5 minutes) |
| `backup_db.php` | Compressed database backup to `backups/`. `--keep=14` keeps 14 days (default 7) |
| `cleanup_images.php` | Delete inspiration photos of appointments over 30 days old. `--dry-run` only reports |
| `check_install.php` | Check files, settings, database and folders. Prints OK, WARN or FIX |
| `verify_files.php` | Confirm every file matches `MANIFEST.json` |
| `reset_test_data.php` | Clear clients and bookings for a fresh round of testing (see below) |
| `cleanup_project.php` | List unnecessary files, and move them to a recovery folder with `--delete` |
| `apply_theme.php` | Developer helper: adds the light/dark switch to pages |
| `run_tests.php` | System test runner (see Testing) |
| `run_unit_tests.php` | Unit test runner (see Testing) |

### Clearing test data
```
php tools/reset_test_data.php --dry-run      # only shows what would be deleted
php tools/reset_test_data.php                # backs up first, then asks you to type the database name
php tools/reset_test_data.php --keep-clients
```
It deletes bookings, booking history, loyalty points, waiting password links, private photos and clients, and restarts booking numbers at 1. It **keeps** services, stylists (with their services and hours) and administrators, including any client account that is also an administrator. If the backup fails, nothing is deleted.

### Tidying the project folder
```
php tools/cleanup_project.php                # only lists what it would clean up
php tools/cleanup_project.php --delete       # moves it to a recovery folder, after you confirm
```
It never touches files listed in `MANIFEST.json`, the settings files, `.git`, `vendor`, `storage` or `backups`.

---

## Testing

The project has two automated runners. Each builds its **own temporary database**, so real data is never touched, runs only against a database on the same computer, and removes everything it created, even after a crash.

| Runner | Command | Result |
|--------|---------|--------|
| System tests | `php tools\run_tests.php` (start Mailpit first) | 112 checks covering 109 of the 114 test cases. All pass |
| Unit tests | `php tools\run_unit_tests.php` | 143 tests of the classes. All pass |

- The system runner starts its own web server and tests whole workflows across all roles, including six clients booking the same time at the same instant (exactly one booking is saved). It saves a report to `test-results\results-DATE.html` and `.csv`.
- The unit runner calls the classes directly, with normal, boundary and invalid values.
- **The tests were tested:** ten bugs were planted in a copy of the code, one at a time, and every one was caught.
- Five test cases need a person (TC-B11, TC-E04, TC-O04, TC-N01 and TC-N04), as do the checks on the live site, the browser matrix and the usability test.
- 23 defects were found during development and testing. All are fixed.

---

## Deployment (Alwaysdata)

The live site is updated with a package that contains **only safe files** (no settings, photos or backups). The order matters, because the old code cannot read the new database:

1. **Back up** the live database (`php tools/backup_db.php`) and download a copy of the current files.
2. **Import the migration first:** `database/migration_2026_10_15.sql`, then `database/migration_2026_10_16.sql` (each can be run more than once and deletes nothing).
3. **Upload the files straight away.**
4. Check the site, then delete the temporary helper pages (`server_check.php`, `email_test.php`, `cron_reminders.php`) from the server.

On a local copy, `php install_update.php "C:\xampp\htdocs\grooming-studio" --check` shows what an update would change. Without `--check` it updates, keeping a copy of everything it replaces.

**Scheduled tasks** (Alwaysdata, Advanced, Scheduled tasks):

| Task | Command | When |
|------|---------|------|
| Reminders | `php /home/ACCOUNT/tools/send_reminders.php` | every 5 minutes |
| Backup | `php /home/ACCOUNT/tools/backup_db.php` | daily |
| Photo clean-up | `php /home/ACCOUNT/tools/cleanup_images.php` | daily |

Replace `ACCOUNT` with the Alwaysdata account name. To restore a backup, open phpMyAdmin, click the database, choose **Import**, and pick the `.sql.gz` file.

---

## Known limitations

- Emails can land in spam at first (a free Gmail account sending from a free hosting address). A custom domain is the lasting fix.
- Loyalty rewards are recorded but not applied to a payment. There is no online payment.
- The audit trail starts from when it was introduced, so older bookings show no history.
- A booking marked Completed or No-show cannot be undone from the site.
- There is no login lockout or two-factor login, and staff passwords are set by an administrator.
- The system serves one salon and runs on a free hosting plan.
- The automated runners are verified on Windows (XAMPP) and Linux. Other systems are untested.

---

## Contributing

- Work on a branch, keep commits small, and describe what changed and why.
- Never commit `config/db_config.php`, `config/mail_config.php`, `config/cron_config.php`, `backups/`, `test-results/`, `vendor/` or uploaded photos. Before committing, run:
  ```
  git diff --cached --name-only | Select-String "db_config|mail_config|cron_config|backups|test-results|vendor"
  ```
  It must print nothing.
- Run both test runners before pushing and keep them green.

## Licence and academic note

Coursework for Richfield Graduate Institute of Technology. Not licensed for commercial use.