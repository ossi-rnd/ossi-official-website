# 30. Promotions and position history

Roadmap item 22. A promotion is a change of position on a date, and pay follows the position, so what matters is **which cut-offs** it pays from. The history makes that exact.

## The rule pay follows

`EffectiveRatesQuery` (the one place a daily rate is decided) uses:

1. the person's **personal rate**, if they have one (Daily Rates), else
2. the company rate of **the position they held on the cut-off's start date**, from the position history (`PositionChange::positionsOn`), else
3. the schedule's default.

Before this, step 2 used the *current* position for any date. A promotion would then have re-priced earlier cut-offs too, and the **corrections from earlier cut-offs** check ([08](08-payroll.md#corrections-from-earlier-cut-offs-prior-period-adjustments)) would have suggested back pay for months before the promotion.

## Promoting someone (`ChangePosition`, HR: `manage-people`)

201 file → **Position history** → **Promote or change position**:

- **Promotion** or **Change of position** (a lateral move).
- **The new position**: only those for their staff type (field ranks for guards, office jobs for office staff).
- **From**: a **cut-off start, the 1st or the 16th**, because pay works per cut-off; this says exactly which payslip changes. The form starts on the next one and shows what the position pays then ("The position pays ₱800.00 a day then").
  - **Ahead** (up to a year): the record switches on its day (`app:apply-position-changes`, daily 00:05). Pay already uses it from that cut-off either way. It can be **cancelled** until then.
  - **Back-dated** (up to 90 days): the record changes now, and the cut-offs already paid at the old rate are **suggested as corrections** on the next payroll for Accounting to accept. Example from the tests: promoted from Oct 1 after the Oct 1–15 payslip was paid at ₱610 → *"daily rate ₱610.00 → ₱800.00", +₱950.00* (5 days) on the next run.
- **Why**: required.
- One change waiting at a time.

**Who's told:**
- **The employee:** *"Congratulations: promoted to Shift-in-Charge. From Nov 1, 2026 (your Nov 1 payslip onward)."*
- **Accounting:** the new position's rate. Or, if the person has a **personal rate**, that it still applies over the position's and should be reviewed on Daily Rates. Or, if the position has no company rate yet, to set one.

## Edits on the employee form

Changing the position directly on the employee record is still possible, and it's recorded in the history too (kind "Edited on the record", from today). Earlier cut-offs keep the position they were paid at. For a real promotion, use **Promote**: it has the date, the reason and the notices.

## Data

`position_changes`: profile, from / to position, kind (promotion | transfer | edit), effective_on, reason, recorded_by, applied_at. Migration `2026_10_16_100005`. Audited: `position.promotion`, `position.transfer`, `position.applied`, `position.cancelled`.

Tests: `tests/Feature/People/PromotionsTest.php` (old rate until the date and new rate from it, applied on its day, one waiting at a time; back-dated → the right correction; form edits kept in the history; the date / position / reason rules and HR only; the personal-rate notice; cancel).

**The scheduler** must run for future promotions to show on the record on their day (pay doesn't wait for it).
