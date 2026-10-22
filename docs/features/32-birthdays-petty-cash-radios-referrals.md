# Birthdays, petty cash, the radio masterlist, referrals

Four features added on 2026-10-22.

## Birthday reminders

| | |
|---|---|
| Field | *Date of birth* on the employee record (`employee_profiles.birth_date`, `encrypted`, "Y-m-d"; read with `EmployeeProfile::birthDate()`). 18 or over. In `SENSITIVE`. |
| Opt-out | *Keep their birthday private* (`birthday_private`): nobody is reminded and the person isn't listed. This is the employee's right to object to a reminder based on legitimate interest (Sec. 12(f)). |
| Reminder | `app:send-birthday-reminders`, daily 7:15 → `SendBirthdayReminders`. HR gets a bell for everyone; for guards, Operation HQ and the Satellite of the guard's client region get one too. Never the person themselves. Once a year per person (`SentAlert` `birthday.YYYY`). Bell only (`BirthdayToday`), with day and month, never the age. |
| List | Dashboard → *Birthdays this month* (`BirthdaysQuery`, ability `see-birthdays`: HR, Operation HQ, Satellite (its region, guards only), Super Admin). |
| Feb 29 | Falls on Feb 28 in a common year (`birthdayIn()`). |
| Privacy | The notice lists date of birth as sensitive (it reveals age) under 13(b) (SSS / PhilHealth / Pag-IBIG records), and the reminder under 12(f), with the right to object. Version `2026-10-22.1`. |

## Petty cash (imprest)

- A **fund** (`petty_cash_funds`): name, optional OSSI office, custodian (anyone on payroll; one active fund each), amount (₱100 to ₱1,000,000), active. Accounting sets it up (`manage-petty-cash`: Accounting, Super Admin).
- An **expense** (`petty_cash_expenses`) is entered by the custodian: date (not in the future), amount (at most the cash on hand; the fund row is locked), paid to, what it was for, and the receipt (always; JPG, PNG or PDF via `PrivateUploadStore`).
- **Cash on hand** = fund − expenses not yet replenished (`PettyCashFund::cashOnHand()`). It's computed and never stored.
- **Replenishment** (`petty_cash_replenishments`): *Ask to replenish* puts every expense not yet requested into one request (one waiting at a time), and Accounting is told. Accounting **releases** it (the fund is whole again) or **returns** it with a reason (the expenses go back on the list). Nobody decides their own request.
- Seeing: Accounting and the President / VP see every fund (`view-petty-cash`); the custodian sees only their own (`PettyCashFund::custodianOf`, menu *Personal → Petty Cash*). Receipts go through `ShowPettyCashReceiptController`.
- *Running low* is shown below 25% of the fund. That threshold is a display hint only.
- Audited under **Finance**.

## NTC radio masterlist

- `radios`: brand, model, serial (unique, upper case), frequency, NTC permit number, permit expiry, status (In use, Spare, For repair, Lost, Disposed), client post and/or guard, and notes.
- Kept by Admin and Operation HQ (`manage-radios`, page *Radios*). Only an in-use radio has a post or a guard, and it needs one of them. Other statuses clear both.
- Permit alerts: `app:send-radio-permit-alerts`, daily 7:20 → `SendRadioPermitAlerts`. The steps are `documents.expiry_alert_days` (60, 30, 7, 0), once per radio per expiry date, with email from 30 days. Disposed radios are skipped. The menu badge counts permits due within 30 days.
- Resignation clearance: *Firearm, radio and post equipment returned* names any radio still assigned to the person.
- Delete is only for a radio added by mistake. A radio that leaves the agency (sold, scrapped or returned) is marked **Disposed**, or **Lost**. The status was called "Retired" until 2026-10-22; migration `2026_10_22_100006` renamed existing rows.

## Referrals (hiring perks)

- **Refer a Friend** (`/referrals`, anyone on payroll): name, mobile (made readable), optional job (`Position::offered()`) and a note. While a referral is open, the same mobile can't be referred again. HR is told.
- **Referrals** (`/referral-cases`, `manage-referrals`: HR, Super Admin): *Hired* links the hire's record. That record must still be active, have a hire date, not be linked already and not be the referrer. The bonus and its due date (hire date + days) are frozen then. *Not hired* needs a reason. The referrer is told either way and can withdraw a referral while it's with HR.
- **Terms**: *Bonus terms* (`SaveReferralTerms`, audited, Setting `referrals`). The starting values in `config/hr.php` (₱1,000, 90 days) are placeholders.
- **Payment**: `GeneratePayslip` → `PayDueReferralBonuses`. A bonus due by the cut-off's end becomes an approved *Referral bonus* adjustment (`AdjustmentCategory::ReferralBonus`, not taxed as part of the ₱90,000 13th month and other benefits ceiling) if `Referral::hireStayed()`: the hire still works here, or their last day was on or after the due date. Otherwise the referral is *No bonus (left early)* and the referrer is told.
