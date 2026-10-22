# 28. Merits and demerits

Recognition and minor offences, recorded by whoever supervises the person, always with a reason. Roadmap item 21. It feeds the Top performing guards (item 38) later; serious offences go through a Notice to Explain (item 30), not a demerit alone.

## Who gives them (`give-merits`, `MeritRecord::canGive`)

| The employee | Who may give or take back one |
|---|---|
| A guard | Operation HQ; the Satellite Operation of their region; HR |
| Office staff | HR |
| HR staff | the President or Vice President (like their leave and complaints) |
| Anyone | Super Admin. Never yourself. |

The **Merits & Demerits** page lists only the records of people the viewer may give one to (`MeritRecord::scopeVisibleTo`).

## Types and points (`manage-merit-types`: HR, Super Admin)

HR keeps the list everyone picks from, so the same act gets the same points whoever records it. The migration starts it with:

| Merit | Points | Demerit | Points |
|---|---|---|---|
| Commended by the client | +5 | Out of uniform | −1 |
| Prevented an incident or loss | +10 | Logbook not updated | −1 |
| Covered a post on short notice | +2 | Left post without notice | −3 |
| Excellent inspection | +2 | Discourteous to the client or public | −3 |
| | | Sleeping on post (consider an NTE) | −5 |

**Other…** takes its own name and points (1 to 20) with the reason. A type is never deleted, only switched off. Records copy the title and points when given, so changing a type later doesn't rewrite history.

## Giving one (`GiveMerit`)

Merits & Demerits → **Give a merit or demerit** → pick the employee → the type → the date (today or the last 90 days) → **what happened** (required). From someone's 201 file, **Give one** opens the form with them already chosen (`/merits?give={user}`).

**Picking the employee** (`PeopleToActOnQuery`, shared with Notices to Explain): only people you supervise appear. **Search by name or employee number** (from the DTR or logbook). Before you type, it **suggests** some: the people at the client site of the last one you gave, since the same post comes up again. Otherwise it shows the first of the people you supervise (a Satellite Operation: its region's).

The employee is told by bell and email: *"Demerit: Out of uniform (−1). Oct 16: No necktie during the 9 AM inspection. You can add your side on My Profile until Oct 21."*

## The employee's side (`ReplyToDemerit`)

A demerit stays open for **5 days** (`MeritRecord::REPLY_DAYS`, the same span as a Notice to Explain). On **My Profile → Merits & demerits** the employee taps **Add my side** and writes it, once. It's kept next to the demerit for anyone who reads it later, and whoever gave it is told. If the reply changes things, they can take it back.

## Taking one back (`VoidMerit`)

Anyone who may give that person one can **take it back**, with a reason ("given to the wrong guard"). It's never deleted: it stays on record, struck through, with who took it back and why, and no longer counts. The employee is told.

## Totals

The **last 12 months** of standing records count (`MeritTotalsQuery`, one query for a whole list): merit points, demerit points, and the net. Old mistakes stop counting, so people can recover.

- **My Profile** and the **201 file** (`People\MeritHistory`): the three totals and the history.
- **Employees list:** "+5 / −1 pts" under the status, for those who may see merits. Operations see guards' only.

## Nothing touches pay

Penalty deductions need the employee's written consent (Labor Code **Art. 113**), so a demerit never deducts anything. Serious offences need due process (two notices and a chance to explain) before any real sanction: that's the [Notice to Explain](29-notices-to-explain.md), whose decision can also give a demerit.

## Data

- `merit_types`: name, kind (merit / demerit), points (1–20), description, is_active.
- `merit_records`: user, type (null for "Other"), kind, title and signed points (copied), reason, occurred_on, given_by, reply_until, employee_reply / replied_at, voided_at / voided_by / void_reason.
- Migration `2026_10_16_100003_create_merit_tables` (with the starting types).

Tests: `tests/Feature/People/MeritsTest.php` (who may give to whom, points copied and not rewritten, the reply window and once only, "Other" and its limits, voiding with a reason and nothing deleted, 12-month totals, the pages and who sees what).
