# CLAUDE.md: OSSI Workforce System

Read this first, on any machine. It's the project's memory: what the system is, the owner's standing rules, and how work is done here. Keep it current: when the owner gives a lasting instruction or a lesson is learned, add it here (not only to a machine-local memory).

## What this is

Attendance, payroll and guard operations for **OSSI (Oriental Sentinel Services Inc.)**, a Philippine security agency. Built for PH labor, tax and data-privacy rules.

- **Stack:** Laravel 13, Livewire 4, Alpine, Tailwind v4, Fortify (2FA opt-in, email verification), Pest, PHPStan level 8, Pint. SQLite locally; MySQL + S3 + queue worker planned on Laravel Cloud.
- **Size (2026-10-22):** about 75,000 lines and 897 tests.
- **Shape:** final Action classes with `handle()` (an architecture test enforces this), Queries, Livewire pages. `App\Enums\Ability::roles()` is the single source of who can open what; one Gate per Ability.
- **Docs:** `docs/README.md`, one guide per feature in `docs/features/*.md`, `docs/CHANGELOG.md` (newest date first), the notification map `docs/features/33-notifications.md`, and the study guide `docs/guide/template.html` (see "Done means").
- **Roles (9, one job role per person):** Super Admin, President, Vice President, Admin, HR, Accounting, Operation HQ, Satellite Operation (one region, `users.operations_region`), Developer. Employee is automatic for anyone with an employee record. Super Admin, President and VP have **no** employee record and are paid outside this payroll (`Role::withoutEmployeeRecord()`).
- **Main areas:** employees (201 file, government IDs encrypted), selfie + GPS clock-in with geofence (client posts for guards, OSSI Offices for office staff), duty roster, relievers and no-shows, patrols (QR checkpoints), leave and overtime chains, payroll (2026 SSS / PhilHealth / Pag-IBIG / BIR, loans, cash bonds, 13th month, final pay, reimbursements), complaints, resignations and clearance, merits and Notices to Explain, documents and licenses, training, petty cash (imprest), NTC radio masterlist, equipment (uniforms charged with an authority to deduct, company property acknowledged, damage or loss charged only after due process), referrals (hiring perks), birthday reminders, announcements, audit log, privacy notice (RA 10173).

## Owner's standing rules

**Words and UI**
- Never show the word **"Executive"**. Say President / Vice President ("the President or Vice President", or "President or VP" where tight). New leadership features use `...Role::leadership()`.
- One **blue primary button** per screen. Colour only for warnings (amber / red); "done" states are blue or gray, not green.
- Keep icons on buttons, unless the owner asks to remove one (they removed the plus on "Add radio").
- Every `dark:bg` needs a `dark:hover`; a light hover background (`hover:bg-white`, `-50`, `-100`, `-200`) always needs a `dark:hover:bg-…`, also inside `x-bind:class` / `@class` strings. Coloured text 500-700 needs a `dark:text-…-400`. A `hover:text-gray-700/800/900` needs a `dark:hover:text-…`. `tests/Unit/DarkModeColorsTest.php` enforces all of these. Run `npm run build` after adding classes.
- Error pages (403/404/419/500/503) are always full screen, even from a Livewire click (`resources/js/error-pages.js`).
- Clean input: government IDs are digits in the agency's format (`App\Support\GovernmentId`). Phones and names go through `App\Support\PhilippineFormats`. Fields drop wrong characters as they're typed, and the server checks again.

**Product decisions**
- **Policy numbers live in the app** (Setting + an audited Action + a small "Rules / Limits" card), never only in env or config. When I pick a default number myself, **say so explicitly** ("my default, confirm it").
- Operations (Operation HQ, Satellite) sees **guards only**, except that Operation HQ sees the Satellite Operations it heads. Office staff answer to their head (see the chain below). This is enforced in `User::scopeVisibleTo`, `EmployeeProfile::scopeVisibleTo` and `User::canSeeProfile` (no `view-office-staff` means guards only).
- Office positions mirror the roles: Admin, HR, Accounting, IT, Operations Head, Satellite Operations Officer (`Position::for(true)`). Security Manager, President and VP are retired as positions.
- Clients (guards' sites) and OSSI Offices (head office and branches) are separate tables with the same location picker. There is **no** "OSSI Head Office" office pre-created; the owner adds their own.
- **The chain of heads** (`App\Support\Heads`, used everywhere a "head" acts): a guard → Operation HQ + their region's Satellite (endorse) → HR decides leave; HR, Operation HQ and Accounting → the President or VP; a Satellite Operation → Operation HQ; other office staff (Admin, Developer…) → HR. The head **decides** office staff's leave and overtime, watches and approves their time away, gets their attendance alerts, and issues their Notices to Explain and merits. The President or VP still *see* all office staff's, and HR every non-HR staffer's (they monitor); HR is also copied on attendance alerts for staff it doesn't head (`Heads::toTell`, as timekeeper). Operation HQ and Accounting moved from HR to the President or VP on 2026-10-23, the owner's choice after comparing with the usual agency structure. Nobody heads the Super Admin, President or VP. The Super Admin may act as backup. Use `Heads` for any new "who supervises whom" need; never hand-write the chain again.
- Notify everyone affected: any Action that changes something about **someone other than the actor** tells them (`UpdateNotice::tell()` / `tellProfile()` for plain updates). Settings and the actor's own changes stay quiet.
- The privacy notice never relies on "consent" (a test enforces it). Bump `config/privacy.php` `version` when its text changes (now `2026-10-22.1`; `.env` may override with `PRIVACY_NOTICE_VERSION`).

## Done means

1. Pint (`vendor/bin/pint <paths>`; `--dirty` needs git), PHPStan (`vendor/bin/phpstan analyse --memory-limit=1G`), and the **full** suite (`php artisan test --parallel`) all pass. Run `php artisan view:cache` after Blade edits, and `npm run build` after JS or CSS changes.
2. `docs/CHANGELOG.md` gets an entry under today's date, and the right `docs/features/*.md` is updated.
3. The **study guide** is updated by hand: the right chapter in `docs/guide/template.html` (plus a nav entry and a quiz question for a new idea, and a row in chapter "Patterns & lessons" for a lesson). Then run `php docs/guide/build.php` and republish the Claude artifact **https://claude.ai/artifact/Xf6ow9AahUn9mnyq3KGRq2** (version 166 on 2026-10-23). `build.php` only refreshes `@@code(path)@@` listings; chapters are hand-written. Quiz strings are JS: escape apostrophes as `\'`, never `''`.
4. Mention honestly anything that went wrong, and how it was fixed.

## Safety rules (learned the hard way)

- **Back up before any migration or data edit:** `database/database-before-<what>-<stamp>.sqlite` for dev; `scripts\pilot\backup-pilot.ps1` for the pilot. Never `migrate:fresh` the dev database without telling the owner. Never edit the owner's `.env`.
- **The pilot runs this folder live.** A saved file is live there at once. A change that needs a migration is migrated on dev **and** the pilot in the same step (after the backup), before or with the code. A rename shipped before its pilot migration once broke the Radios page for 4 minutes, and the Equipment menu (new tables) was saved minutes before its migration on 2026-10-23. With a new table, write the migration first and migrate dev and the pilot before saving code that reads it (the menu runs on every page).
- **Never `preg_replace` PHP source.** Use exact `str_replace` with match counts, or the Edit tool, then `php -l`. A bad pattern once emptied files.
- **Blade class sweeps** may touch only `class="..."` attributes or single-line quoted strings made purely of class names. Dry-run first. Then `view:cache` and the full suite. A loose sweep once broke 7 views.
- **Windows PowerShell:** `''` inside double-quoted strings breaks PHP strings; use the Edit tool for apostrophes. Never round-trip a source file through `Get-Content -Raw` + `WriteAllText`: it reads UTF-8 as Windows-1252 and garbles ·, –, ₱ into "Â·" (it happened to `NoticeToExplain.php`). Edit files with the Edit tool or a PHP script, then check `git diff`. Herd's `php` is a shim, so Node must run it through a shell (`execSync`), not `execFileSync`.
- **Don't let the pilot's environment leak:** `scripts\pilot\pilot-artisan.ps1` / `pilot-env.ps1` set `DB_DATABASE` to the pilot database. Run them in a **separate** command from tests, or the tests target `pilot.sqlite_test_*`.
- **Browser checks** use a throwaway copy, never `database.sqlite`. That file holds the owner's real practice data since 2026-10-22, with no demo logins. Build `check.sqlite` with `migrate:fresh --seed` (MAIL_MAILER=log) and serve it with `php -S 127.0.0.1:8123 ..\scripts\pilot\router.php` from `public\`, with env `DB_DATABASE`, `APP_URL`, `MAIL_MAILER=log`, `QUEUE_CONNECTION=sync`, `BROADCAST_CONNECTION=log`. Demo logins: `superadmin@example.test`, `president@example.test`, `vp@example.test`, `hr@example.test`, `accounting@example.test`, `opshq@example.test`, `employee@example.test`, all with password `password` (`executive@example.test` was renamed to `president@` on 2026-10-23). Files a check uploads go to the real `storage/app/private`; delete them afterwards.
- **CI (GitHub Actions) uses `.env.example`, not the real `.env`**, plus PHP 8.5 and the tests run one at a time (`composer ci:check`: Pint `--test` on the whole project, PHPStan, Pest). Tests must not depend on the local `.env`: put needed values in `phpunit.xml`. A blank `MAIL_FROM_ADDRESS` once failed 36 tests on GitHub only. To reproduce CI, `git clone . <scratch>` then `composer setup && composer ci:check` there.
- Secrets live only in `.env` (the Gmail app password for ossi.rnd@gmail.com, APP_KEY). Don't send the owner's email to unrelated services.
- Don't delete the owner's files without asking.

## The pilot (on the owner's office PC)

- **https://ossi.tail4ab8aa.ts.net** (Tailscale Funnel). It runs from this folder, with database `database/pilot.sqlite` and backups in `database/pilot-backups/` (neither is in git). `scripts/pilot/*.ps1` start, stop, back up and run artisan against it (`pilot-artisan.ps1 migrate --force`, then `queue:restart` after code changes). `keep-running.ps1` runs the scheduler.
- **Accounts:** Super Admin `ossi.rnd@gmail.com` (the owner made it). Test accounts use `NUMBER@test.invalid` (no mail is sent; confirmed on registering). Some sample radios have serials `SAMPLE-…`.
- The pilot stays on the office PC. Work done on another machine reaches it only when this folder is updated (`git pull` here), then migrated on the pilot.

## Moving to another machine (laptop)

The repo carries the code and docs. These do **not** travel through git, on purpose:

| Not in git | How to bring it |
|---|---|
| `.env` (APP_KEY, Gmail app password) | Copy privately (USB / password manager), never commit. **Same APP_KEY** is required to read encrypted fields (government IDs, date of birth, medical notes) in a copied database. |
| `database/database.sqlite` (practice data), `pilot.sqlite`, backups | Copy the file if you want the same data. Otherwise `php artisan migrate:fresh --seed` gives demo data (and a new APP_KEY is fine then). |
| `storage/app/private` (selfies, receipts, documents) | Copy if the copied database refers to them. |
| `vendor/`, `node_modules/`, `public/build` | `composer install`, `npm install`, `npm run build`. |
| Claude's machine-local memory (`~/.claude/projects/...`) | Not needed: this file replaces it. |

`composer setup` is safe to run on any machine: it creates `.env` only if missing and generates `APP_KEY` only if empty (it used to replace the key every time, which would have made encrypted data unreadable). Setup on the laptop: install PHP 8.4+ and Composer (Herd), Node, and git. Then `git clone`, `composer install && npm install`, put `.env` in place (or `cp .env.example .env && php artisan key:generate` for a fresh demo), run `php artisan migrate` (or `migrate:fresh --seed` for demo data), `npm run build`, `herd secure` (camera and GPS need HTTPS), and `php artisan test --parallel` to confirm all green.

## Open items (2026-10-22)

- **Owner to decide:** whether to block HR from editing their own employee record; and whether to accept old-format phones, names and IDs until they're edited (today an old value must be corrected on the next save).
- **Owner to check with DOLE or counsel:** charging guards for every uniform (built that way on 2026-10-23, the owner's choice).
- **Defaults to confirm:** equipment damage or loss: 5 days to explain, and a 6-day week for DOLE's 20%-of-weekly-wages limit; attendance alerts (clock-out reminder 30 min after the shift, the head after 120 min, office clock-in reminder 30 min after grace, office days Mon–Fri; HR can change them on the Attendance Log); referral bonus ₱1,000 after 90 days, and the bonus treated as not taxable (₱90k ceiling); petty cash "Running low" below 25%; the reminder times 7:15 (birthdays) and 7:20 (radio permits).
- **Not built yet:** bulletin board, donations, top performing guards (the owner asked; questions are pending); client billing; native mobile app; Laravel Cloud deployment.
- **The owner's step:** create the President / VP accounts with `php artisan app:create-super-admin <email> "<name>" --role=president|vice-president`.
