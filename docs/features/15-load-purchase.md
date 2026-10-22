# 15. Borrow Load (mobile load on credit)

Employees get mobile load **on credit**: they request it, an Admin sends it from the company phone and approves, and the amount is **deducted from their next payslip**. Nothing is paid up front, and there's no GCash QR, reference number or receipt. (Until 2026-10-09 this was "Buy Load" with a GCash QR and manual payment checks; the owner replaced it with this.)

## The flow

1. **Employee:** **Borrow Load** → choose the network, mobile number and amount → tick **"I authorize the company to deduct ₱200 from my next payslip for this load (Labor Code Art. 113)."** → **Send request**. The tick is required: Art. 113 allows a deduction from pay only with the employee's written authorization. `SubmitLoadRequest` refuses without it and saves the exact sentence with the time (`deduction_authorization`, `authorized_at`). The page also shows how much approved load is still to be deducted.
2. **Admin:** **Load Requests** (the sidebar badge counts waiting ones). Send the load from the company phone, then click **Approve**. The approval box shows what's being approved and asks for **proof the load was sent**: a screenshot of the carrier's confirmation text or the e-load app. Then click **Approve and send receipt**. A screenshot is required. **Reject** asks for a reason instead.
3. **The employee's receipt** is that screenshot. It's **attached to their approval email** for their copy, and the request row shows **Receipt**. It's never a public link: only the employee, the Admins handling loads, and Accounting (for a disputed deduction) can open it (`ShowLoadProofController`). Files are checked by their contents (`PrivateUploadStore`), and if an approval fails, its upload is deleted.
4. **Approving creates a payroll deduction** of type **Borrowed Load** (`load`) for the full amount, in the same transaction. It appears on the payslip as "Borrowed load: ₱200 Smart to 0918 765 4321".
5. **The next generated payslip deducts it.** The deduction is balance-tracked like a loan, so once it's taken it switches itself off. If a payslip can't cover it (net pay never goes below ₱0), the balance stays and the next payslip takes it.
6. The employee is notified both ways: the bell always, and email on approval or rejection.

```
pending ──Approve (load sent)──▶ approved   → Load deduction on the next payslip
   ├──Reject (reason)──▶ rejected           → nothing deducted
   └──Cancel (employee)──▶ cancelled
```

`LoadRequestStatus::transitionsTo()` is the single list of allowed moves.

## Rules, and where they live

| Rule | Where | Why there |
| --- | --- | --- |
| Approve creates exactly one deduction | `ReviewLoadRequest`, row locked, move checked | Two Admins clicking at once can't both approve and charge twice |
| Nobody approves or rejects **their own** request | `ReviewLoadRequest` (and a locked button with a tooltip) | An Admin asking for load needs another Admin |
| Only employees **on payroll** can borrow | `SubmitLoadRequest` | The deduction needs a payslip to come from |
| Networks and amounts come from the configured lists | `config/load.php`, re-checked in the Action | A new promo is a config change, and a hand-crafted request can't pick ₱75 |
| At most 3 waiting requests per person | `SubmitLoadRequest`, under a lock on the **user** row | Two tabs count each other's request |
| Cancel only while waiting | `CancelLoadRequest` | After approval the load is sent and the deduction exists |
| Load isn't in Accounting's manual "Add deduction" list | `DeductionType::manual()` | Load deductions only come from approved requests, so each one traces back to a request |

## Code

- `SubmitLoadRequest`, `ReviewLoadRequest` (approve or reject), `CancelLoadRequest`
- `LoadRequest` (`deduction()`, `deductionLabel()`)
- `DeductionType::Load`
- `App\Livewire\Load\BuyLoad` (the Borrow Load page), `App\Livewire\Admin\LoadRequests`
- `LoadRequestNotice`

Migration `2026_10_09_100009` converted old requests: "load sent" became approved, with no deduction, since those were paid by GCash; "payment confirmed" went back to waiting. It also dropped the GCash columns and setting.

Tests: `tests/Feature/Load/LoadRequestsTest.php` covers the request, the required receipt (emailed as an attachment, viewable by the employee and Accounting but not by other employees), approve → deduction → next payslip takes ₱300 and switches it off, reject, no double charge, no self-approval, the limits, cancelling, and access.

## Try it

1. `php artisan migrate:fresh --seed`: Maria has one approved (₱100, on her next payslip), one waiting and one rejected request.
2. As Maria (`employee@example.test`): Borrow Load → Smart, any number, ₱200 → Send request.
3. As `admin@example.test`: Load Requests → Approve. Then generate Maria's payslip in Payroll: "Borrowed load: ₱200 Smart…" is among her deductions.
