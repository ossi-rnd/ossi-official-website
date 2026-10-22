# 12. Version 2 roadmap

This is the V2 wishlist, with every item checked against the code as it is today. The study guide's chapter 12 has the same list and lets you filter it by status.

| Status | Count | Meaning |
| --- | --- | --- |
| Built | 22 | Works today (3 from V1, 5 from phase 2.0, 4 from 2.1, plus Buy Load, payroll adjustments, the discrepancy report, merits, NTE, promotions, reimbursements, leave requirements, deployment costs and loan summaries) |
| Partly | 7 | The data or plumbing exists; V2 extends it |
| New | 10 | New tables, screens and Actions |
| Limited | 2 | Only partly possible (screenshots, donations) |

**Phase 2.0 is done**: see [13-v2-quick-wins.md](13-v2-quick-wins.md). **The 201 file (2.1) is done**: see [14-201-file.md](14-201-file.md).

## Decisions (2026-10-08)

- **RAE**: the label for now. The old "GKE Opening and Closing Report" becomes "RAE Opening and Closing Report". The full name comes later, and it is a one-line enum change.
- **Late deduction**: show full details, never hide them. List each late day with shift start, actual time in, minutes late and bracket.
- **Buy load**: the app shows the company GCash QR, and the employee submits the reference number and receipt. IT verifies it manually. Free, with no payment API.
- **Operations**: a new role for security operations. Sir JP is the **Head of Operations**, stored as a setting that points to a user, never a hard-coded name.

## Foundations to build first

1. **Notifications**: Laravel notifications on the `database` and `mail` channels, queued, with a bell in the top bar.
2. **Scheduler**: daily commands in `routes/console.php`. Each check is idempotent, recording what it already sent so a rerun never notifies twice.
3. **Private attachments**: one polymorphic `attachments` table on the private disk. Files are streamed through a policy-checked controller, the same pattern as `PunchPhotoStore`.
4. **Approval steps**: ordered approvers per request type, configured as data. This replaces the single `reviewed_by` column.
5. **Operations role**: `Role::Operations` plus new abilities in `Ability::roles()`, and a Head of Operations setting.

Every feature follows the V1 pattern: an Action with a transaction and an audit entry, a policy, and Pest tests.

## Items

| # | Item | Status | Today | V2 |
| --- | --- | --- | --- | --- |
| 1 | Idle auto logout | Built | `EnforceIdleTimeout`, `config/security.php` | Optional pre-logout warning |
| 2 | No screenshots | Limited | **2.0:** `<x-sensitive>` watermark, no print, cover on blur, `payslip.viewed` audit | Cannot be blocked on the web. Use deterrents: viewer watermark, blank on print, blur on focus loss, audit payslip views |
| 3 | DPA acknowledgment | Built | `RequirePrivacyAcknowledgement` | Bump the version in `config/privacy.php` to make everyone re-accept |
| 4 | Extra clock-in / sitrep | Partly | Selfie + GPS pipeline | `sitreps` table; never affects pay |
| 5 | Ops extra time + deployment cost | Built | **2026-10-17:** extra time is an overtime request; deployment costs: Operations files them for the guard on **Deployment Costs** (receipt, the President or Vice President approves, paid on the guard's payslip, not taxed); Operations staff's own are a "Deployment" reimbursement. See [31](31-reimbursements.md#deployment-costs-roadmap-5) | Deployment claim with ₱ cost and receipt, paid on the next payslip |
| 6 | Opening / closing report | New | Old system: 201 checklist entry only | `duty_reports` linked to the time log |
| 7 | Weather / medical leniency | New | None | Attendance excuse request; the approved day is `excused` and gets no late deduction. Punches are never edited |
| 8 | 14-day continuous duty alert | New | `time_logs` | Daily check that notifies Operations and the Head of Operations |
| 9 | GKE → RAE | Built | **2.1:** `Requirement::RaeReport` | Rename the label when RAE gets a full name |
| 10 | "Subject to change" disclaimer + adjustments | Built | **2.0:** disclaimer; **2.2:** audited adjustments (Accounting has full control; optional second-person check, off by default); net never below ₱0 with carry-overs | See [08-payroll.md](08-payroll.md) |
| 11 | Payroll by period | Built | My Payslips per period | Optional year filter |
| 12 | Late deduction: full details | Built | **2.0:** per-day list (`LateDaysQuery`) | Per-day list from the same Query as the payslip total |
| 13 | Loan summary, authority to deduct | Built | **2026-10-17:** the written authority is recorded for every deduction (asked in the app, signed on paper, or a government loan by law; Borrow Load; final pay), and **My Loans** shows each loan's balance, how it was authorized, and what every payslip took. See [08](08-payroll.md#my-loans) | "My loans" page plus an e-signed authority (Labor Code Art. 113) |
| 14 | Payroll discrepancy report | Built | **2026-10-16:** pay that changed after a cut-off is suggested on the next run, and employees **Report a problem** on a payslip; Accounting answers it on **Payroll Concerns** (pay it, explain, or not valid) ([08](08-payroll.md#report-a-problem-employees-flag-what-the-system-cant-see-payroll-concerns)) | "Report a problem" on the payslip notifies Accounting and becomes an adjustment |
| 15 | Past-probation alert | Built | **2.0:** `app:send-probation-alerts`, daily 07:00 | Notify HR before and at 6 months (Art. 296) |
| 16 | 201 photo upload | Built | **2.1:** uploads per document, private, views audited | — |
| 17 | Requirement expiry alerts | Built | **2.1:** `app:send-requirement-alerts`, daily 07:05 | — |
| 18 | Requirements checklist + promised date | Built | **2.1:** checklist, 201 column, "Set date" + overdue reminder | — |
| 19 | IT request: load for employees | Built | **Changed 2026-10-09:** Borrow Load, mobile load on credit deducted from the next payslip (no GCash); see [15](15-load-purchase.md) |
| 20 | Resign / rehire | Partly | Statuses exist | `employment_events` history |
| 21 | Merit / demerit | Built | **2026-10-16:** see [28](28-merits-and-demerits.md): HR's types and points, given by whoever supervises the person, the employee's side of a demerit, 12-month totals | `merit_types`, `merit_records` |
| 22 | Promotion | Built | **2026-10-16:** see [30](30-promotions.md): position history; pay uses the position held on each cut-off's start; back-dated promotions suggested as corrections | Promotion event |
| 23 | Resignation reasons + Others | Built | **2026-10-15:** see [26](26-resignation.md): reasons + Others, 30-day notice or just cause, clearance checklist, separation the day after | The rule is Labor Code **Art. 300**, not "RA 300" |
| 24 | Leave requirements | Built | **2026-10-17:** see [07](07-requests.md#leave-requirements-roadmap-24): leave under special laws (maternity, paternity, solo parent, VAWC, special leave for women) with their proof; a medical certificate for sick leave from 2 days; no approving without the proof; private documents; VAWC confidential | Per-type rules in config |
| 25 | Reimbursement (officers only) | Built | **2026-10-16:** see [31](31-reimbursements.md): office staff file with the receipt, the President or Vice President approves, paid on the next payslip as a non-taxable line | Policy that excludes Security Guard; receipts; non-taxable payslip line |
| 26 | Undertaking for other deduction; stop request | Partly | Accounting can deactivate | E-signed undertaking + stop request |
| 27 | Chain of command routing | Partly | One review step; supervisor on OT | Approval steps |
| 28 | Show filed date | Built | **2.0:** "Filed" column on all request tables | Quick win: a "Filed" column |
| 29 | Inspection / training request | Partly | **2026-10-19:** training requests built, see [20](20-training.md): guards ask on My Training, Admin adds them to a session or declines. Inspection: waiting for OSSI to say which kind | Request type on approval steps |
| 30 | NTE (Operations + Super Admin) | Built | **2026-10-16:** see [29](29-notices-to-explain.md): the twin-notice rule, the explanation, an optional hearing, the decision; on the 201 file | Issue → explain (≥ 5 calendar days) → decision, kept in the 201 file |
| 31 | Complaint with disclosure and proof | Built | **2026-10-19/20:** disclosure (acknowledgement, saved word for word), proof (optional files and witnesses, HR can ask for it, private and audited) and **"I need help now"** (bell and email to HR at once, first on the list), see [21](21-complaints.md) | Encrypted, HR + Super Admin only; "ask for help" alerts HR |
| 32 | 1 complaint per week | Built | **2026-10-20:** checked in `FileComplaint`; the number is HR's (Complaints → Rules, starting at 1, 0 = none); never counts help requests, harassment or resignation cases, see [21](21-complaints.md#one-complaint-a-week-hrs-number) | DB check in the Action. Exempt harassment and "ask for help" (RA 11313) |
| 33 | Report with proof photo | Partly | Photo pipeline | Incident report, same pieces as the sitrep |
| 34 | General feedback | New | None | Simple audited form |
| 35 | FAQs | Built | **2.0:** Help & FAQs, edited in place | HR-editable, stored in the DB |
| 36 | Operation purview (Operations role) | Partly | Free-text detachment and post | `Role::Operations`; detachments and posts become lists with an officer in charge |
| 37 | Monitoring with the Head of Operations | Partly | Attendance dashboard | Live board per post; escalations go to the Head of Operations |
| 38 | Top performing guards | New | Attendance data | Monthly score computed by a Query |
| 39 | Announcements | Built | **2.0:** banner, bell, urgent email, scheduled start | Audience by role, start and end dates, dashboard banner |
| 40 | Bulletin board | New | None | Announcements with categories |
| 41 | Donations | Limited | None | Voluntary deduction with consent; no direct payments in V2 |

## GCash QR: free vs. automatic (superseded)

*Replaced on 2026-10-09 by Borrow Load (load on credit, deducted on payroll), so no payment is collected in the app. Kept for the record.*

| Option | Cost | Confirmation |
| --- | --- | --- |
| Static GCash QR (V2) | Free: no API, no gateway fee | Manual: reference number + receipt, matched by IT in the GCash app |
| Payment gateway (PayMongo, Xendit, QR Ph) | Per-transaction fee + provider onboarding | Automatic via webhook |

For regular volume, use a GCash for Business account; personal accounts have monthly receiving limits. Check the current fees and limits with GCash before going live.

## Suggested order

- **2.0 Quick wins** (done): 28, 12, 10, 2, notifications + scheduler, 15, 39, 35
- **2.1 HR records** (201 file done; employment history and merit next): attachments, 18 + 9, 16, 17, 20, 22, 23, 21
- **2.2 Requests & payroll**: 27, 24, 7, 25, 29, 13, 26, 14, 19
- **2.3 Operations**: Operations role + Head of Operations (36, 37), 6, 4, 33, 5, 8, 30, 31, 32, 34, 40, 38, 41

The legal references are pointers for HR, not legal advice. Have the final wording reviewed.
