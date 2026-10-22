# 3. Privacy notice, idle timeout and session security

The app holds selfies, GPS trails, government IDs and pay. These controls are MVP, not polish.

## Middleware stack (`bootstrap/app.php`, appended to `web`)

| Middleware | Does | Why it's in `web` |
| --- | --- | --- |
| `EnforceIdleTimeout` | Signs out after 15 idle minutes | Livewire's update endpoint is in `web`, so clicks are covered too |
| `EnsureAccountIsActive` | Ends sessions of resigned/terminated staff | Same |
| `RequirePrivacyAcknowledgement` | Holds users on the notice until they acknowledge the current version | Same; Livewire requests get a 403 JSON instead of a redirect |
| `PreventAuthenticatedPageCaching` | `Cache-Control: no-store` for signed-in pages | Back button after logout shows nothing |
| `AddSecurityHeaders` | `X-Frame-Options`, `nosniff`, `Permissions-Policy: camera=(self), geolocation=(self)`, HSTS in production | Camera/GPS allowed only for this site |

Order matters: idle check first, so a timed-out session is signed out rather than sent to the privacy notice.

## Privacy notice (RA 10173 sec. 16(a))

- Text lives in `config/privacy.php` (plain strings, rendered escaped).
- Users store the **version** they acknowledged. Bump `PRIVACY_NOTICE_VERSION` and everyone is re-prompted.
- Wording is "acknowledge", never "consent": the legal basis is the employment contract and the law (reasoning is in the config file).
- The notice is public, so the registration page links to it before collecting anything, and the camera modal restates it at the moment the photo is taken.

## Idle timeout: two halves

| Server (`EnforceIdleTimeout`) | Browser (`resources/js/idle-timeout.js`) |
| --- | --- |
| Stores `last_activity_at` in the session on every request | Countdown dialog 60 s before the deadline, "Stay signed in" |
| Signs out when idle ≥ 15 min; 419 for Livewire | Shares the clock across tabs through `localStorage` |
| Keep-alive pings only reset the clock when they say `active: true` | Pings only after real input, so an abandoned tab never stays alive |

The server half is the enforcement; JavaScript can be disabled or the tab closed.

## Other protections

- Government IDs and medical notes use the `encrypted` cast; the audit log records "(changed)" instead of their values.
- Punch photos and avatars live on a private disk and are streamed by controllers after a policy check (`TimeLogPunchPolicy`, `UserPolicy`).
- `Model::shouldBeStrict()` outside production: lazy loading, missing attributes and silently dropped mass assignment throw.
- Ownership foreign keys (`user_id`, `reviewed_by`…) are never `#[Fillable]`; Actions set them through relationships.
- Strong passwords in production (12+, mixed case, symbols, not in breach lists), optional TOTP 2FA.
- `SESSION_ENCRYPT=true`.

Tests: `tests/Feature/Security/SessionSecurityTest.php`.
