# 11. Deploying to Laravel Cloud (or another PaaS)

## Before the first deploy

1. Put the project in Git (`git init`, commit, push to GitHub). Laravel Cloud deploys from a repository. `.env` is git-ignored: it holds the Gmail password, so it must never be committed.
2. Create the Cloud app from the repo, PHP 8.4.
3. Attach a **MySQL** (or Postgres) database. SQLite is fine locally, but the spec's row locks (`SELECT ... FOR UPDATE`) need a real database server.
4. Attach an **object storage bucket** (S3-compatible). The app's local disk is wiped on every deploy, so punch photos and avatars must go to the bucket.
5. Add a **queue worker** (verification and reset emails, and every notification, are queued). Without one, no email is sent and the bell stays empty.
6. Turn on the **scheduler** for the environment. It runs `routes/console.php`: probation alerts daily at 07:00, 201 document alerts at 07:05, license and document due-date alerts at 07:10, no-shows (a guard on the roster with no clock-in and no notice) every 5 minutes, patrol and away-from-post alerts every 15 minutes, closing forgotten time away every 15 minutes, deleting time-away GPS trails past the retention period at 02:30, scheduled announcements every 5 minutes. Without it, no alert is ever sent.
7. Attach a **WebSockets** (Reverb) cluster so the bell updates live. Cloud fills in `BROADCAST_CONNECTION=reverb` and the `REVERB_*` / `VITE_REVERB_*` values; the front end must be **built after** they're set (the `VITE_` key is baked into the JS). Without it the bell still works, but only on page loads.

## Environment variables (set in Cloud, not in a file)

```
APP_NAME=OSSI
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...            # generate once; NEVER change it later (it decrypts government IDs)
APP_URL=https://your-domain
APP_TIMEZONE=Asia/Manila

DB_CONNECTION=mysql            # Cloud injects DB_* when you attach the database
SESSION_DRIVER=database
SESSION_ENCRYPT=true
QUEUE_CONNECTION=database
CACHE_STORE=database

FILESYSTEM_DISK=s3
ATTENDANCE_PHOTO_DISK=s3       # private bucket; files are streamed by the app, never public
LOAD_PROOFS_DISK=              # Borrow Load receipts (load confirmations); empty = same disk as the photos
HR_DOCUMENTS_DISK=             # 201 scans; empty = same disk as the photos
COMPANY_DOCUMENTS_DISK=        # Admin's documents, licenses and signed policies; empty = HR documents disk
AWS_*                          # Cloud injects these when you attach the bucket

MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=ossi.rnd@gmail.com
MAIL_PASSWORD=<app password>   # Cloud secret
MAIL_FROM_ADDRESS=ossi.rnd@gmail.com
MAIL_FROM_NAME=OSSI

PRIVACY_NOTICE_VERSION=2026-10-13.1
PRIVACY_OFFICER_EMAIL=...
PRIVACY_CONTROLLER_ADDRESS=...
NOMINATIM_CONTACT_EMAIL=...    # optional, sent to OpenStreetMap in the User-Agent

OFFICE_NAME="OSSI Head Office" # where office staff's time out is measured from (Office Away)
OFFICE_LATITUDE=...
OFFICE_LONGITUDE=...
```

## Deploy commands

Build: `composer install --no-dev --optimize-autoloader && npm ci && npm run build`
Deploy: `php artisan migrate --force && php artisan optimize`

## First account

Seeders only create demo users outside production. On the server console:

```
php artisan db:seed --force                 # shifts + holidays only in production
php artisan app:create-super-admin you@company.com "Your Name"
php artisan app:create-super-admin president@company.com "Name" --role=president
php artisan app:create-super-admin vp@company.com "Name" --role=vice-president
```

Each asks for a password only. **The Super Admin, President and Vice President have no employee record and no employee number** (company decision, 2026-10-21): they aren't on payroll, attendance or headcount, so they can't register like staff and are created here. Then sign in, add HR staff under Employees, give them the HR role under Roles & Access, turn on 2FA, and let HR add everyone else. Someone who is on payroll *and* needs one of these three roles gets two accounts: Roles & Access refuses to give these roles to anyone with an employee record.

### Or: all office accounts at once

Instead of adding each office account by hand, put them in a CSV **on the server only** (never commit it) and import it:

```
name,email,employee_number,job,role,hire_date,status
Juan Dela Cruz,owner@company.com,150301-OFF-0001,Admin,Super Admin,2015-03-01,regular
Ana Reyes,hr@company.com,180115-OFF-0002,HR,HR,,
Liza Cruz,payroll@company.com,190601-OFF-0003,Accounting,Accounting,,
Mark Santos,it@company.com,200210-OFF-0004,IT,Developer,,
```

```
php artisan app:import-staff /path/to/staff.csv
```

- **Jobs:** Admin, HR, Accounting, IT, Operations Head, Satellite Operations Officer (or a guard rank).
- **Roles:** Super Admin, Admin, HR, Accounting, Developer. Put several in one cell with `|` (e.g. `HR|Admin`). Everyone also gets Employee.
- `job`, `hire_date` and `status` are optional. `status` defaults to **regular**.
- **Validated first:** if any row is wrong, **nothing is created**, and the output lists each bad line.
- **Already in the system:** people who exist are skipped, so running it twice is harmless.
- **Passwords:** each new person gets a random password nobody knows and a **set-your-password email** (valid 60 minutes; after that, *Forgot password* on the sign-in page sends a new one). Use `--no-email` to skip the emails.
- Every account is in the audit log (`staff.imported`).
- **Then:** delete the CSV, set the rates on Daily Rates, and have everyone turn on 2FA.

Run this **instead of** `app:create-super-admin`, with the owner as the first row. Or run it after that command; the owner's row is then skipped.

Never run `DemoSeeder` in production: it creates `@example.test` accounts with the password `password`.

## Checklist

- [ ] `APP_DEBUG=false`, HTTPS domain, `APP_KEY` backed up somewhere safe
- [ ] Queue worker running; send yourself a password reset to confirm mail
- [ ] Scheduler on; `php artisan schedule:list` shows every command
- [ ] **Accounting has set the daily rates** (Daily Rates → Rate per position, plus personal exceptions; nobody listed under *No rate yet*), and Accounting has checked the rules on **Tax & Contributions** against the SSS, PhilHealth, Pag-IBIG and BIR circulars
- [ ] Bucket attached and `ATTENDANCE_PHOTO_DISK=s3`; clock in once and open the photo from Attendance Log
- [ ] Privacy notice company name, address and DPO filled in
- [ ] Movable holidays for the year entered
- [ ] Operation HQ, Admin or the Super Admin has added the **clients** (Clients: Region → Province → Client) and HR has picked each guard's client
- [ ] Every **Satellite Operation** account has its region set (Roles & Access); without one they see nobody
- [ ] Every client has its **post coordinates** (Clients → Edit), so the geofence works; "No geofence yet" means anyone can punch from anywhere for that client
- [ ] Each site with roving guards has its **patrol checkpoints** (Patrols → Checkpoints), the QR stickers are printed and posted, and the roving duties are set to **Roving patrol** (Type of duty)
- [ ] Operations has filled the **Duty Roster** for the first weeks (Copy last week / Usual shifts). Guards never rostered still clock in on their usual shift
- [ ] Admin has uploaded the **licenses with their due dates** (Documents → Licenses), so renewal alerts start
- [ ] Daily rates set for the new office positions (President, Vice President, Operations Head, Satellite Operations Officer) if those people are paid through payroll
- [ ] Super Admin, HR and Accounting have 2FA on

## Notes

- **Gmail limits:** about 500 messages a day and Google may throttle bulk sends. Fine for verification and reset emails at this size; move to a transactional provider (Postmark, Resend, SES) if volume grows.
- **The Gmail app password was shared in chat while building this.** Revoke it in your Google Account and create a new one before going live.
- `trustProxies(at: '*')` is set so HTTPS is detected behind Cloud's load balancer; HSTS turns on automatically in production.

## Laptop pilot (one week)

Before Laravel Cloud, the app can run for a week from this laptop at a fixed address: **https://ossi.tail4ab8aa.ts.net** (Tailscale Funnel, free). It is kept apart from development: its own database `database/pilot.sqlite` (no demo data), production mode (no debug pages, strict passwords, `migrate:fresh` and the like blocked). `scripts/pilot/pilot-env.ps1` sets this as environment variables, which win over `.env`, so `.env` and `database/database.sqlite` are never changed; the mail password still comes from `.env`.

### Already done (2026-10-20)

- Tailscale installed and signed in with the OSSI R&D Google account, HTTPS and Funnel enabled; the public address points at the pilot's web server on `127.0.0.1:8010`.
- `setup-pilot.ps1` ran: empty pilot database with shifts and holidays, assets built.
- `install-autostart.ps1` ran: **OSSI Pilot - start** at sign-in, **OSSI Pilot - backup** every day at 2:00 AM.

### Why Tailscale, not Cloudflare

`cloudflared tunnel --url …` starts a **Quick Tunnel**: anonymous, so it gets a new random `*.trycloudflare.com` address every start. A fixed Cloudflare address needs a domain you own. **Tailscale Funnel** gives a fixed HTTPS address (`<pc-name>.<tailnet>.ts.net`) for free, on the Personal plan. They are different companies; use one or the other. When OSSI buys a domain, a Cloudflare named tunnel (`dev.ourdomain.com`) or Laravel Cloud replaces this.

### Setting it up step by step (on a fresh laptop)

All commands run from the project folder, `C:\Users\IT\Herd\ossi_official_site`, in PowerShell.

1. **Install Tailscale.** Download from tailscale.com/download/windows, or:
   ```
   winget install --id Tailscale.Tailscale
   ```
2. **Sign in.** Click the **^** next to the clock → the Tailscale icon (3×3 dots) → **Log in…** → sign in with Google or Microsoft → **Connect**. Or run this and open the link it prints:
   ```
   & "C:\Program Files\Tailscale\tailscale.exe" login
   ```
   Check: `& "C:\Program Files\Tailscale\tailscale.exe" status` shows this PC, not "Logged out". Its fixed name is in `tailscale status --json` → `Self.DNSName` (here `ossi.tail4ab8aa.ts.net`).
3. **Turn on HTTPS for the account** (once): login.tailscale.com/admin/dns → **HTTPS Certificates** → **Enable HTTPS**. Check: `tailscale status --json` → `CertDomains` lists the PC's name.
4. **Put the address in the pilot settings**, if this is a different PC: in `scripts\pilot\pilot-env.ps1` set `$PilotUrl` to `https://<that name>`.
5. **Create the pilot database** (once; refuses to run twice):
   ```
   powershell -ExecutionPolicy Bypass -File scripts\pilot\setup-pilot.ps1
   ```
   It makes `database\pilot.sqlite`, runs the migrations, seeds only shifts and holidays (never demo data) and builds the assets.
6. **Start the pilot** (web server, queue worker, scheduler, and the public address):
   ```
   powershell -ExecutionPolicy Bypass -File scripts\pilot\start-pilot.ps1
   ```
   The **first time**, Tailscale prints "Funnel is not enabled on your tailnet" with a link: open it, click **Enable**, and run `start-pilot.ps1` again. Then turn on **unattended mode**, so Tailscale keeps running after a restart even before its tray app opens: `& "C:\Program Files\Tailscale\tailscale.exe" set --unattended=true`. Check: `& "C:\Program Files\Tailscale\tailscale.exe" funnel status` shows `https://<name> (Funnel on) → proxy http://127.0.0.1:8010`.
7. **Check it from outside**, in a browser (a phone on mobile data is the best test): the login page opens **styled**, a development account (Maria) is **refused**, and an unknown page shows a plain 404 without code details.
8. **Start it at sign-in and back it up nightly:**
   ```
   powershell -ExecutionPolicy Bypass -File scripts\pilot\install-autostart.ps1
   ```
   Check: Task Scheduler lists **OSSI Pilot - start** and **OSSI Pilot - backup** (next run 2:00 AM). Test a backup once with `backup-pilot.ps1`.
9. **The first Super Admin**, then the laptop settings: see *Still to do* below.

**What the scripts set** (`pilot-env.ps1`, as environment variables, so `.env` is never edited): `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` and `ASSET_URL` = the `ts.net` address, `DB_DATABASE=database\pilot.sqlite`, `SESSION_SECURE_COOKIE=true`, `BROADCAST_CONNECTION=log`.

### Still to do

1. **The first Super Admin** (done: OSSI-SA; asks for a password only, no employee number), and the **President** and **Vice President** the same way:
   ```
   powershell -ExecutionPolicy Bypass -File scripts\pilot\pilot-artisan.ps1 app:create-super-admin you@example.com "Your Name"
   powershell -ExecutionPolicy Bypass -File scripts\pilot\pilot-artisan.ps1 app:create-super-admin president@example.com "Name" --role=president
   powershell -ExecutionPolicy Bypass -File scripts\pilot\pilot-artisan.ps1 app:create-super-admin vp@example.com "Name" --role=vice-president
   ```
   Then sign in at the address and add clients, HR staff and employees as on the real thing.
2. **The laptop:** plugged in; Settings → Power → sleep **Never**; Control Panel → Power Options → closing the lid **Do nothing**; Windows Update **paused for 1 week**.
3. Close the old Cloudflare tunnel window; it isn't needed.

### Every day

| What | How |
|---|---|
| Is it up? | Open the address. If not: `scripts\pilot\start-pilot.ps1` (safe to run again). |
| Logs | `storage\logs\pilot\web.log`, `queue.log`, `scheduler.log`, `backup.log`; app errors in `storage\logs\laravel.log`. |
| Backups | `database\pilot-backups\`, the newest 14. A backup now: `scripts\pilot\backup-pilot.ps1`. |

### Updating the code during the week

| You changed | Then |
|---|---|
| PHP, Blade, routes | Nothing: the next page load uses it. |
| CSS or JS | `npm run build`. (`npm run dev` for your own work is fine: production ignores Vite's dev server.) |
| Notifications, jobs | Nothing needed (the queue worker restarts itself every hour); right away: `scripts\pilot\pilot-artisan.ps1 queue:restart`. |
| A migration | `scripts\pilot\backup-pilot.ps1`, then `scripts\pilot\pilot-artisan.ps1 migrate --force`. |

### Limits to know

- The PHP built-in server answers **one request at a time**: fine for a few dozen people, slow for many at once.
- **Compression and caching** (`scripts\pilot\router.php`, 2026-10-21): the built-in server sends everything uncompressed and uncached, so the pilot uses its own small router. Files in `public\build` (hashed names) get gzip (made once, kept in `storage\framework\cache\pilot-gzip`) and `Cache-Control: max-age=31536000, immutable`; only real files inside `public\build` are served (a `../` path falls through). Whole HTML/JSON/text responses are gzipped too; photos, downloads and anything flushed in parts pass through untouched. Everything else goes to Laravel's own `server.php`. Laravel Cloud does this itself, so production doesn't use it. Measured: the dashboard's HTML went from 292 KB to 25 KB on the wire, and scripts are downloaded once, then come from the browser's cache.
- **Reverb isn't reachable** through the address, so the bell updates on page load, not live.
- The laptop is a single point of failure: no power, no internet or a restart without sign-in means no app. Backups stay on the same disk: copy `database\pilot-backups` somewhere else now and then.
- Uploaded files (selfies, documents) share `storage\app\private` with development, under random names.

### Troubleshooting

| You see | Why, and the fix |
|---|---|
| "This site can't be reached" / `DNS_PROBE_FINISHED_NXDOMAIN` | Tailscale isn't connected, so the address is offline. Seen 2026-10-21 after a restart: `tailscale status` said "Tailscale is starting" (`BackendState: NoState`) because the **tray app hadn't opened**; restarting the service didn't help. Fix: open Tailscale from the Start menu (or `start-pilot.ps1`, which now does it and waits), then `start-pilot.ps1`. Prevented since by **unattended mode** (`tailscale set --unattended=true`), so Tailscale runs without the tray app. |
| `ERR_CONNECTION_REFUSED` on the **new** address after renaming the machine in the Tailscale admin console | The Funnel stays set up under the old name (`tailscale funnel status` still lists it). Seen 2026-10-21 renaming `admin-w10-pc3` to `ossi`. Fix: `tailscale funnel reset`, put the new address in `$PilotUrl` in `scripts\pilot\pilot-env.ps1`, then `stop-pilot.ps1` and `start-pilot.ps1`. The old link stops working; the first visit can be slow while a new certificate is made. |
| The address doesn't open at all | Tailscale signed out, or the laptop asleep. `tailscale status`; sign in again; then `start-pilot.ps1`. |
| "502" or "Bad gateway" | The pilot's web server isn't running. `start-pilot.ps1`; look at `storage\logs\pilot\web.log`. |
| A page without styling | Assets not built: `npm run build`. (Vite's dev server can't be the cause: production ignores it.) |
| Herd's "404 Not Found" | The tunnel points at Herd (`127.0.0.1:443`) instead of the pilot. Herd only answers to its `.test` name. `tailscale funnel --https=443 off`, then `start-pilot.ps1`. |
| `invalid hostname "ossi_official_site.test"` | Tailscale rejects the underscore in Herd's site name. Don't tunnel to Herd; use the pilot (port 8010). |
| PHP "Failed opening required …/index.php" | The PHP server started outside `public\`. `keep-running.ps1` starts it from there; stop and start the pilot. |
| Logs that read "s p a c e d   o u t" | PowerShell 5's `>>` writes UTF-16. The scripts write through `cmd` to avoid it. |
| Opening the address from **this** laptop times out | Calling your own public address from the same PC can fail. Test from a phone on mobile data. |
| "Funnel is not enabled on your tailnet" | First time only: open the link it prints and click **Enable**. |

### Keeping it safe

- **What's exposed:** only the app on port 8010. Not Windows, your files or other Herd sites (they listen on this laptop only), and no router ports are opened.
- **The real risk:** the web server runs as your Windows user. A serious bug in the app or in PHP's built-in server (made for development, not the internet) would give someone your user's access: files, `.env` (the mail password, and `APP_KEY`, which decrypts government IDs), browser data. The address is also public: new HTTPS names are listed publicly, so bots will try the login page (rate-limited).
- **Precautions for the week:** BitLocker / Device encryption on; Microsoft Defender and the Firewall on; two-step verification on the Google account that owns Tailscale; Super Admin and HR turn on two-factor in Account Settings on day one; don't use this laptop for everyday browsing or email; enter only the personal data the trial needs; copy `database\pilot-backups` off the laptop every day or two; end the pilot on time.
- **Fit for:** a one-week, small, supervised trial. **Not** for keeping real employee data longer: that's what Laravel Cloud is for (patching, hardening, backups, uptime).

### Ending the pilot

```
powershell -ExecutionPolicy Bypass -File scripts\pilot\stop-pilot.ps1
powershell -ExecutionPolicy Bypass -File scripts\pilot\uninstall-autostart.ps1
"C:\Program Files\Tailscale\tailscale.exe" funnel --https=443 off
```
Keep `database\pilot.sqlite`: its people and records can be the start of the real deployment.

**Why not Herd for the pilot?** Herd picks the site by its `.test` host name, and Tailscale can't send `ossi_official_site.test` (an underscore isn't valid in a host name), so Herd answered 404. The PHP built-in server with Laravel's router answers any host. It has to start from `public\`, and its output goes through `cmd` so the logs stay plain text.
