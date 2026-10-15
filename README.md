# Executive Grooming Studio — Booking & Scheduling System

A web-based booking and scheduling system for **Executive Grooming Studio**, a fictional
hair salon created for academic purposes. The system replaces a paper appointment diary
and telephone bookings with an online, duration-aware, double-booking-proof booking engine.

**Live site:** https://executivegroomingstudio.alwaysdata.net

> **Note:** The salon and all data in this repository are fictional. No real or personal
> client data is used anywhere in this project.

---

## 1. Module and assessment context

| Item | Detail |
|---|---|
| Module | IT Project 2026 |
| Institution | Richfield Graduate Institute of Technology |
| Faculty | Faculty of Information Technology |
| Qualification | Diploma: Information Technology |
| Semester | 2 |
| Group | Group 4 — "Royal" |

### Assessment phases

| Phase | Weight | Due date | Status |
|---|---|---|---|
| Project Proposal | 10% | 28 Aug 2026 | ✅ Submitted |
| Planning Phase | 25% | 18 Sept 2026 | ✅ Submitted |
| Analysis Phase | 20% | 9 Oct 2026 | ✅ Submitted (revised version) |
| System Design Phase | 20% | 30 Oct 2026 | ✅ Written; being updated to match the final build |
| Implementation Phase | 25% | 9 Nov 2026 | 🔜 Code complete and deployed; test evidence and report being finalised |

<!-- TODO: adjust the Status column to match what has actually been submitted and marked. -->

---

## 2. Team roster

| Member | Student number | Role |
|---|---|---|
| Yusuf Ally | _TODO_ | Project Manager — oversees progress, schedules meetings, ensures deadlines are met, communicates with the supervisor |
| Lufuno Ramarumo | _TODO_ | Business Analyst — gathers requirements, documents user stories, ensures the solution meets business needs |
| Bhoavik Iswarlall | _TODO_ | Developer — implements core features, writes code, collaborates on technical tasks |
| Taahir Inder | _TODO_ | Systems/Solutions Architect — designs the system architecture and database schema, and ensures integration between components |
| Calvin Van Niekerk | _TODO_ | Tester (QA) — develops and executes test plans, documents bugs, verifies the system meets its requirements |

Roles as stated in the submitted proposal.

<!-- TODO: add the five student numbers. -->

---

## 3. What the system does

Three user roles, each with a distinct set of capabilities:

| Role | Capabilities |
|---|---|
| **Client** (no account needed) | Browse services (description, duration, price, picture); see the **soonest available time**; choose a stylist or *any available stylist*; see real-time free times; book at any hour; add **allergies**, notes and an **inspiration photo**; receive a confirmation email and a reminder; reschedule or cancel through a private link until 2 hours before the appointment; see their **loyalty points** |
| **Stylist** | Log in; see **own** schedule by day or week with allergies highlighted, notes and photos; mark appointments **completed** or **no-show**; add bookings taken by phone |
| **Administrator** | Manage services (with pictures), stylists, working hours and passwords; search and filter every booking; cancel or reschedule any booking on a client's behalf; see the **history of every booking** (who did what, and when); manage **loyalty points**; run **reports** (busy periods, popular services, stylist performance) with a spreadsheet (CSV) download |

### The core engine

Availability is **duration-aware**. The system does not divide the day into fixed-length
slots. A candidate appointment is offered only if:

1. it fits entirely within the stylist's working hours for that date,
2. it starts in the future, and
3. it overlaps **no** existing non-cancelled booking for that stylist.

Overlap is tested as a real time-interval comparison:

```
new_start < existing_end  AND  new_end > existing_start
```

Booking is enforced inside a **database transaction that locks the stylist's row**
(`SELECT ... FOR UPDATE`) and re-checks the time before saving, so two people confirming
the same time at the same instant cannot both succeed. The automated tests prove this by
starting several separate PHP processes at once (see §9).

### Features

1. **Inspiration photo upload** — JPG, PNG or WebP up to 8 MB. Photos are stored outside the public folder, judged by their real content, re-saved clean (location data removed), shown only to the booked stylist, the administrator and the client, and deleted 30 days after the appointment.
2. **Allergy and sensitivity notes** — a dedicated field, shown in a highlighted box on the stylist's schedule and repeated in the client's emails.
3. **Duration-aware scheduling** — the engine described above.
4. **Smart scheduling** — suggests the soonest free time with the chosen stylist, or the soonest time with another stylist if theirs is full for 21 days.
5. **Automated reminder email** — sent about one hour before the appointment by a scheduled task, and safe to run repeatedly: a reminder is never sent twice.
6. **Loyalty points** — 1 point for every R10 of a *completed* appointment; 100 points can be exchanged in the salon for R50 off. Every earning and redemption is a line in a ledger, and one booking can earn points only once.
7. **Reports** — busy days and hours, popular services, stylist performance and revenue for any period, plus a CSV download.
8. **Audit trail** — every booking records when it was made, moved, cancelled, marked or reminded, and by whom.

---

## 4. Out of scope

These are **deliberately excluded**, as named in the submitted proposal:

- ❌ Payment or deposit processing (loyalty rewards are recorded, not applied to a payment)
- ❌ Native mobile application (the system is responsive **web only**)
- ❌ SMS or WhatsApp notifications
- ❌ Any real or personal client data

---

## 5. Technology stack

| Layer | Technology |
|---|---|
| Frontend | HTML, CSS, JavaScript — responsive, with a light/dark theme switch; no framework |
| Backend | PHP 8.2 or later (8.4 on the live site) |
| Database | MySQL / MariaDB (MariaDB 11.8 live) |
| Email | PHPMailer over Gmail SMTP (port 587, TLS, app password) |
| Hosting | Alwaysdata free plan |
| Version control | Git / GitHub |

**No application framework.** Plain PHP, structured as classes: PDO with prepared statements
throughout, output always escaped, and configuration kept out of version control.

---

## 6. Repository layout

```
grooming-studio/
├── public/               ← the web root (the ONLY publicly reachable folder)
│   ├── *.php             ← the pages (booking, schedules, administrator pages)
│   └── assets/           ← css and js
├── classes/              ← application logic (not web-reachable)
│   ├── Booking.php       ← availability, suggestions, create, move, cancel, outcomes
│   ├── Loyalty.php       ← points: earning, rewards, corrections
│   ├── Report.php        ← the administrator's reports
│   ├── AuditLog.php      ← booking history
│   ├── Auth.php  Client.php  Database.php  ImageUpload.php
│   └── Mailer.php  Reminder.php  Service.php  Stylist.php
├── config/               ← settings. The *.example.php files are tracked;
│                           db_config.php, mail_config.php and cron_config.php are git-ignored
├── database/             ← schema.sql (new local database), schema_hosting.sql (live),
│                           migration_2026_10_15.sql (updates an existing database)
├── storage/inspiration/  ← private inspiration photos (git-ignored, web access denied)
├── backups/              ← daily database backups (git-ignored, web access denied)
├── tools/                ← command-line tools (see §8)
├── test-results/         ← reports written by the test runner (git-ignored)
├── vendor/               ← PHPMailer, installed by Composer (git-ignored)
└── MANIFEST.json         ← SHA-256 fingerprints used by tools/verify_files.php
```

Everything except `public/` sits **above** the web root, so source code, settings, photos
and backups cannot be requested over HTTP even if the PHP handler fails.

---

## 7. Setup instructions (local, Windows with XAMPP)

### 7.1 Prerequisites

- XAMPP with **PHP 8.2 or later** (extensions `pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `openssl`, `zlib`)
- MySQL / MariaDB (started from the XAMPP control panel)
- Composer and Git
- [Mailpit](https://github.com/axllent/mailpit) (a free local test mailbox) for the email features

### 7.2 Clone and install

```bash
cd C:\xampp\htdocs
git clone https://github.com/YusufA03/grooming-studio.git
cd grooming-studio
composer install          # installs PHPMailer into vendor/
```

### 7.3 Create the database

> ⚠️ `database/schema.sql` is for a **new, empty** database. It only creates tables, and it
> **refuses to run on a database that already has tables** (it never deletes anything).
> To bring an **existing** database up to date, import `database/migration_2026_10_15.sql`
> instead. It is safe to run twice and keeps all your data.

In phpMyAdmin (`http://localhost/phpmyadmin`): choose **Import** and select
`database/schema.sql`. It creates the database `grooming_studio`, seven tables and the
sample data (five services, three stylists).

### 7.4 Configure

Copy each example file in `config/` to the same name without `.example`:

| Copy | To | What to set |
|---|---|---|
| `db_config.example.php` | `db_config.php` | The defaults suit XAMPP (`localhost`, `grooming_studio`, `root`, empty password) |
| `mail_config.example.php` | `mail_config.php` | The defaults send to Mailpit; nothing to change locally |
| `cron_config.example.php` | `cron_config.php` | Replace the placeholder with a long random key |

**These three files are git-ignored and must never be committed.** The `.example.php`
files are published, so they must contain placeholders only.

### 7.5 Create the accounts

```bash
C:\xampp\php\php.exe tools\set_password.php
```

The tool asks whether the account is an administrator or a stylist, then the email, the
name (administrators) and the password twice (at least 10 characters). It stores only a
`password_hash()` hash. **There are no default passwords in this repository.**

### 7.6 Check the installation and open the site

```bash
C:\xampp\php\php.exe tools\check_install.php
```

Then open `http://localhost/grooming-studio/public/`. Start Mailpit and open
`http://localhost:8025` to read every email the system sends.

---

## 8. Command-line tools

| Tool | What it does |
|---|---|
| `tools/set_password.php` | Creates or resets an administrator or stylist account |
| `tools/check_install.php` | Checks PHP, extensions, files, database tables and settings |
| `tools/verify_files.php` | Compares every file with `MANIFEST.json` to prove nothing is old or altered |
| `tools/send_reminders.php` | Sends the reminder emails that are due (run by a scheduled task) |
| `tools/backup_db.php` | Saves a compressed database backup (keeps 7 days, never fewer than 3) |
| `tools/cleanup_images.php` | Deletes photos of appointments older than 30 days (`--dry-run` to preview) |
| `tools/apply_theme.php` | Adds the light/dark switch to the pages |
| `tools/run_tests.php` | The automated test runner (see §9) |

All tools refuse to run from a web browser.

---

## 9. Running the tests

```bash
C:\xampp\php\php.exe tools\run_tests.php
```

Start MySQL first, and Mailpit as well if you want the email tests to run.

The runner builds **its own test database** (`grooming_studio_test`) and its **own temporary
web server**, runs about 80 checks mapped to the test-case numbers in the Phase 5 report
(TC-B01, TC-S04 and so on), saves a report in `test-results\` (`.html` and `.csv`), and then
removes everything it created. Your real database is never touched, it only runs against a
database on the same computer, and test emails go to Mailpit only.

It covers **77 of the 82** documented test cases. Five cannot be checked by a program and
are done by hand: TC-B11, TC-E04, TC-O04, TC-N01 and TC-N04, together with the live-site
checks (HTTPS cookies, real email delivery, load times) and the browser and usability tests.

People confirming the same time at the same instant are simulated with several **separate
PHP processes** started together, so the booking lock is tested for real.

---

## 10. Deployment (Alwaysdata)

| Item | Detail |
|---|---|
| URL | https://executivegroomingstudio.alwaysdata.net |
| Web root | The project's `public/` folder |
| PHP | 8.4 |
| Database | MariaDB, created from `database/schema_hosting.sql` (it does not create or switch databases) |
| Email | Gmail SMTP with an app password, set in `config/mail_config.php` on the server |

Summary of a live installation:

1. Create the site (type PHP), pointing its root at `public/`.
2. Create a database and a user, then import `database/schema_hosting.sql` into it.
3. Upload `classes`, `config`, `public`, `storage`, `tools` and `vendor` by SFTP. **Never upload your local settings files.**
4. Create `config/db_config.php`, `config/mail_config.php` and `config/cron_config.php` on the server.
5. Create the administrator with `php tools/set_password.php` over SSH.
6. Create the scheduled tasks below.
7. Open `server_check.php` with its key and confirm every section is OK. Then **delete** `server_check.php`, `email_test.php` and `cron_reminders.php` from the live site.

### Scheduled tasks

| Task | Command | When |
|---|---|---|
| Reminder emails | `php /home/ACCOUNT/tools/send_reminders.php` | Every 5 minutes |
| Database backup | `php /home/ACCOUNT/tools/backup_db.php` | Daily at 02:00 |
| Photo clean-up | `php /home/ACCOUNT/tools/cleanup_images.php` | Daily |

The reminder task is idempotent: extra runs send nothing twice.

### Updating an existing database

Run **only** `database/migration_2026_10_15.sql` on a database that already has data. Run it
on the live database **before** uploading new code, or the booking pages will fail until it
is done. `check_install.php` and `server_check.php` name the file if it has not been run.

### Backups and restore

`tools/backup_db.php` writes `backups/grooming-studio-DATE-TIME.sql.gz`. To restore, open
phpMyAdmin, click the database, choose **Import** and select that file. Download the
backups folder now and then so a copy exists outside the hosting account.

---

## 11. Implementation status

| Area | Status |
|---|---|
| Database — 7 tables with foreign keys, CHECK constraints and indexes | ✅ Built and verified |
| Duration-aware availability engine | ✅ Built and tested |
| Double-booking prevention (transaction and row lock) | ✅ Built; tested with simultaneous processes |
| Client booking journey (no account) | ✅ Built |
| Smart scheduling (soonest time suggestion) | ✅ Built |
| Allergy notes, inspiration photo upload | ✅ Built; hostile files tested |
| Confirmation, change and cancellation emails; reminder task | ✅ Built; reminder tested for no duplicates |
| Client reschedule and cancel by private link (2-hour cut-off) | ✅ Built |
| Stylist schedule, outcomes, phone bookings | ✅ Built |
| Administrator: services, stylists, hours, bookings, reschedule, cancel | ✅ Built |
| Loyalty points and redemption | ✅ Built; double-spend tested |
| Reports and CSV download | ✅ Built; checked against known data |
| Audit trail (booking history) | ✅ Built |
| Role-based access, CSRF protection, escaped output | ✅ Built; tested |
| Database backups, photo clean-up, file integrity check | ✅ Built; backup restore tested |
| Light and dark themes | ✅ Built |
| Automated test runner | ✅ Built; 77 of 82 test cases |

### Known limitations

- **Emails may reach the spam folder at first.** They are sent from a free Gmail account with no sending history, with links to a free hosting address. A custom domain with SPF, DKIM and DMARC records through a transactional email service is the lasting fix.
- **No password reset or change by the user.** The administrator sets stylist passwords, and the administrator's own password is set with `tools/set_password.php`. There is no lockout after repeated failed logins and no two-factor login.
- **Free hosting** limits storage and usage.
- **Loyalty rewards are recorded in the system and given in the salon**; there is no online payment.
- The audit trail starts when it was introduced, so older bookings show no history.
- The test runner has been run on Windows with XAMPP; it is not tested on other systems.

---

## 12. Security measures

- Passwords stored only as `password_hash()` (bcrypt) hashes; a generic message for a wrong password or an unknown email.
- PDO prepared statements everywhere; all output escaped.
- A security token on every staff form; sessions with `HttpOnly`, `SameSite=Lax` and `Secure` cookies, and a 30-minute idle timeout.
- Role checks on every staff page; a stylist sees only their own bookings and photos.
- Client links hold 64 random characters and stop changing a booking 2 hours before it starts.
- Uploaded photos judged by content, size-limited, re-saved clean and stored outside the web root.
- Settings, photos and backups kept outside the public folder and out of Git.
- CSV downloads defuse text that a spreadsheet could run as a formula.

---

## 13. Documentation

The assessed project documents (Phases 1 to 5) are kept in the `docs/` folder.

<!-- TODO: add the Phase 1–5 files to docs/ and link them here, for example:
- [Phase 3: Analysis](docs/phase-3-analysis.pdf)
-->

---

## 14. Contributing conventions

- `main` must always be in a working, demonstrable state.
- Commit in logical units with descriptive messages in the imperative mood ("Add loyalty ledger", not "added stuff").
- **Never commit** `config/db_config.php`, `config/mail_config.php`, `config/cron_config.php`, `vendor/`, `backups/`, `test-results/` or any photo under `storage/`.
- Check before every push: `git status`, then confirm no settings file or password is staged.
- All SQL uses prepared statements; all output goes through the `e()` helper.
- Run `tools/run_tests.php` before pushing a change to the booking or security code.
- **Database safety:** use `database/migration_*.sql` on a database that has data. Take a backup first with `tools/backup_db.php`.
