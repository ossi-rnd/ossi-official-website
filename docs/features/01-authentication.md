# 1. Authentication with Fortify and HR-only registration

## Can we use Fortify? Yes, and here is what it gives us

Fortify is **headless**: it registers the auth routes and runs the logic, and we only supply Blade views and a few hooks.

| Fortify does | We do |
| --- | --- |
| `POST /login` with throttling, session regeneration, "remember me" | Views in `resources/views/auth/*` |
| Password reset tokens and the reset flow | Queue the emails (`QueuedResetPassword`) |
| Email verification links (signed, expiring) | `User implements MustVerifyEmail`, `verified` middleware |
| Two-factor auth (TOTP, QR code, recovery codes) | Buttons on Account Settings |
| Calls our `CreateNewUser` on register | **The HR-only rule** |

Enabled features (`config/fortify.php`): registration, resetPasswords, emailVerification, updateProfileInformation, updatePasswords, twoFactorAuthentication. Passkeys are off (phase 2). Every enabled feature adds live routes, so features without a screen stay off.

## The rule: only employees HR registered can create an account

```
HR adds employee (Employees → Add) ──► employee_profiles row, user_id = NULL
                                         employee_number + email are the "key"
Employee opens /register, types employee number + that email + password
        │
        ▼
CreateNewUser (HTTP concerns: rate limit by IP, input shape)
        │
        ▼
ClaimEmployeeAccount (business rule, in a transaction, profile row LOCKED)
   1. number exists?            → else unknown_employee_number
   2. not claimed yet?          → else already_claimed
   3. email matches HR record?  → else email_mismatch
   4. still employed?           → else not_active
   5. email not used elsewhere? → else email_taken
        │ all pass                            │ any fails
        ▼                                     ▼
 create user, role Employee,           audit "registration.failed" + reason
 link profile, audit succeeded         SAME generic error for every reason
```

Files: `app/Actions/Fortify/CreateNewUser.php`, `app/Actions/Auth/ClaimEmployeeAccount.php`, `app/Enums/RegistrationFailure.php`.

### Test accounts (pilot testers without a real email)

On **Add employee**, HR can switch on **Test account (no real email)** (an `x-ui.switch`) (`employee_profiles.is_test_account`, only when adding). The email field is then replaced by one made from the employee number: `260627PAM-0950@test.invalid` (`EmployeeProfile::testEmailFor`). `.invalid` is reserved (RFC 2606) and never delivers. The number keeps its capitals (the province code reads as on the ID card); the **account keeps the same capitals** (-RH too), so the header and Roles & Access show `261021NCR-7534@test.invalid`. Signing in and registering match the email in any case (`lower(email) = ?`), and Account settings treats the same email in other letters as unchanged (no new confirmation).

- The tester registers with their number and that address. `ClaimEmployeeAccount` confirms the email at once, so no verification link is sent.
- `User::routeNotificationForMail()` returns null for `@test.invalid`, and Laravel's mail channel then sends nothing. In-app notifications still show.
- A **Test account** badge shows on the Employees list and the employee page, so they can be removed before going live.
- Trade-off: nobody proves they own the address, so anyone with the employee number can claim it. It's for testers only. A tester can later put a real email in Account settings, which asks them to confirm it as usual.

### Why these choices

- **One generic error message.** If the form said "that number exists but the email is wrong", a stranger could discover real employee numbers. The *reason* is still recorded in the audit log, for HR.
- **Failure logged outside the transaction.** The failure path throws, which would roll back an audit row written inside the transaction.
- **`lockForUpdate()` on the profile.** Two people racing to claim the same number can't both win.
- **HR's name wins.** The user doesn't type a name: the account takes the name on the HR record.
- **Rate limit 5 attempts / 15 min per IP** (`config/security.php`), because Fortify's register route has no limiter of its own.
- **Email verification** proves the person controls the inbox HR recorded.

## Login rules

`FortifyServiceProvider`:

- `RateLimiter::for('login')`: 5/minute per email+IP (guessing one person's password) **and** 60/minute per IP (stops one password tried on many accounts). The per-IP limit was 20 until 2026-10-24. Smart / Globe mobile data puts many phones behind one shared address, and a site's guards may share one Wi-Fi, so at a shift change the 21st guard was told "Too many attempts". The load test hit exactly that.
- `Fortify::authenticateUsing()`: same as the default check, plus *resigned or terminated employees can't sign in*. `EnsureAccountIsActive` middleware also ends their open sessions.
- `HomeController` (Fortify's `home`) sends employers to the dashboard and everyone else to My Attendance.
- Custom `LogoutResponse`: when the idle timer signs you out, the login page says why.

## Mail

Gmail SMTP with an App Password, configured in `.env` only (never in `.env.example` or code). Verification and reset emails are `ShouldQueue`, so `composer dev` (which runs `queue:listen`) or a queue worker must be running for mail to go out.

## Try it

- Register with 250115-SEC-3001 / juan.delacruz@example.test → lands on "Confirm your email".
- Register with 999999-XXX-9999 → generic error; check Audit Log → `registration.failed`, reason `unknown_employee_number`.
- Turn on 2FA in Account Settings with an authenticator app, log out, log back in.

Tests: `tests/Feature/Auth/RegistrationTest.php`, `LoginTest.php`.
