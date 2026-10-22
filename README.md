# OSSI Attendance & Payroll

Security guards clock in and out with a selfie and GPS, supervisors approve leave and overtime, and accounting turns attendance into payslips. Laravel 13 · Livewire 4 · Fortify · Tailwind 4 · Pest.

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed     # demo accounts, password "password"
npm run build
herd secure ossi_official_site       # camera and GPS need HTTPS
composer dev                         # server + queue worker + Vite
composer test                        # Pint + PHPStan level 8 + Pest
```

Sign in at https://ossi_official_site.test as `superadmin@example.test` / `password`.

Read **[docs/README.md](docs/README.md)** for the architecture and one guide per feature, and **[docs/features/11-deployment.md](docs/features/11-deployment.md)** before deploying.

**[CLAUDE.md](CLAUDE.md)** holds the project's standing rules and how work is done here (read automatically by Claude Code), including **moving to another machine**: `.env` and the databases are never in git, and a copied database needs the **same `APP_KEY`** to read its encrypted fields, so don't run `key:generate` over a real `.env`.
