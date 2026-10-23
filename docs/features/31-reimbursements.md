# 31. Reimbursements (office staff only)

Roadmap item 25. Money an office staff member spent for the company out of pocket, such as a fare to SSS, a notarial fee or office supplies, is paid back on their next payslip. The money goes through payroll, so there is no petty-cash hand-off and every peso has a receipt, an approver and a payslip line.

## Who can file (`Reimbursement::canFile`)

- **Office staff on this payroll only**, and only while active.
- **Not guards:** their costs on duty go through Operations (overtime, deployment).
- **Not the President or Vice President:** they are paid outside payroll.
- **My Reimbursements** shows in the menu only for those who can file. Anyone else who opens `/reimbursements` gets a 403.

## Filing one (`FileReimbursement`)

**My Reimbursements → File a reimbursement**:

- **What for:** Transportation (fare, Grab, parking, toll), Meals on official business, Office supplies, Phone or load for work, Government or notarial fees, or Other. The purpose box shows an example for the chosen type.
- **Date spent:** not in the future, and within the **last 60 days** (a limit Accounting sets, see **Limits** below). File it while the receipt is fresh.
- **Amount:** ₱1 to **₱20,000** (a limit Accounting sets). Bigger purchases go through Accounting.
- **What it was for, and for whom:** required (at least 10 characters).
- **From an Office Away trip:** optional. It lists their own trips from the same 60 days, so the President or Vice President can see the fare matches a logged trip.
- **Receipt:** always required. It accepts a photo (JPG, PNG) or a PDF, up to 5 MB. The bytes are checked (`PrivateUploadStore`), and the file is kept privately and streamed after a check: the owner, or `view-reimbursements`.
- **Certification** (`Reimbursement::acknowledgementLines()`), required: the receipt is genuine and the money was spent on company business; it hasn't been paid back any other way and won't be claimed again; a false claim is dishonest and can lead to dismissal (Labor Code Art. 297). "I certify these are true" is saved word for word with the time, and the approver sees it.

The President or Vice President is told (bell + email). A request still waiting can be **cancelled** by the employee.

## Approving (`DecideReimbursement`, the President or Vice President: `approve-reimbursements`)

On **Reimbursements** (menu, with a badge for those waiting), **Open** shows the purpose, the trip and the receipt.

- **Approve**, or **Reject**. A rejection needs a reason, for example "Please attach the official receipt, not the order slip".
- Nobody decides their own request.
- The employee is told. On approval, **Accounting** is told it will be on the next payslip.
- **Accounting sees every request** (`view-reimbursements`), so the payroll has no surprises, but they don't decide them.

## Paid on the next payslip (`PayApprovedReimbursements`)

When a payslip is generated, `GeneratePayslip` turns every approved, unpaid reimbursement of that employee into a **Reimbursement** adjustment. This runs inside the payslip's own transaction, before the figures are computed.

- The adjustment is added already approved, since the President or Vice President approved the reimbursement.
- It is **not taxed** and never reduced.
- The payslip line reads *"Reimbursement: Transportation, Oct 14: Grab to SSS Pasig and back…"*.
- The reimbursement becomes **Paid** ("Paid on your Oct 16–31 payslip"). It is paid once only: a regenerated payslip doesn't add it again.

## Limits

**Reimbursements → Limits** (Accounting, `manage-payroll`; others see them). These are company policy, not law, and every change is audited (`SaveReimbursementLimits`):

- **Receipts from the last … days** (7–365; starting value 60).
- **Most per staff reimbursement** (starting value ₱20,000).
- **Most per guard's deployment claim** (starting value ₱20,000). Fares are usually a few hundred pesos, so a lower cap catches typos and padding.

They're stored as the `reimbursements` Setting (`Reimbursement::limits()`), with the starting values in config `payroll.reimbursements`. The forms read the same numbers, so their text always matches the rule.

## Deployment costs (roadmap 5)

Extra time worked on a deployment is an **overtime request** (date, hours, reason, the supervisor approves; payroll pays the smaller of logged and approved). What a deployment **cost** is handled here, in two ways:

- **Operations staff's own costs** (e.g. a fare to bring a reliever, meals during an emergency deployment): **My Reimbursements → "Deployment (relief, transfer, emergency post)"**, which also asks **Which deployment** (the post, who was relieved or moved). Same approval and payment as any reimbursement.
- **A guard's costs** (a guard sent to a far post pays their own fare): **Deployment Costs** (menu, `file-deployment-claims`: Operation HQ for any guard, a Satellite Operation for its region's; `Reimbursement::canFileFor`).
  - **Filing:** Operations picks the guard by name or employee number (suggestions first), answers **Which deployment** with one tap on a duty from **their roster in the filing window** (newest first, reliefs included). The tap also fills the date, and links an unclaimed relief's fare to that relief, once. A picked duty is **locked**, with **Change**, like the guard above it, so the claim always matches the roster. The other choices are **their usual post**, **another client's post** (a searchable list; a Satellite sees its own region's clients), and last **"Not on any list? Type it"**. Only after that last one is there a text box, for true one-offs such as an escort or an emergency trip. Every guard gets choices first, even one with no duties in the window. Then the date, amount, what it was for and the receipt. They certify: *"The receipt is genuine, and the guard spent this money on a deployment I ordered or confirmed"* (also in Filipino).
  - **The guard** is told (bell + email): *"A deployment cost claimed for you… Once the President or Vice President approves it, it's added to your next payslip, not taxed."* They see it on **My Reimbursements** (in their menu once they have one, without a File button): *"Filed for you by …"*.
  - **Approval** is the President or Vice President's, as for any reimbursement. Nobody approves one they filed. Operations is told the decision.
  - **Payment:** on the **guard's** next payslip, as a non-taxable *"Reimbursement: Deployment, …"* line.
  - **Cancelling** while it waits is for whoever filed it, not the guard.
  - **From a relief:** a reliever's fare is usually claimed from **Relievers → Claim the reliever's fare**, or from **Reliefs to claim** on this page. The form comes filled from the relief and stays linked to it, with one standing claim per relief ([18](18-relievers.md#the-relievers-fare-deployment-costs)).

  Stored as a reimbursement: `user_id` is the guard who's paid, `filed_by` is Operations, and `deployment_for` says which deployment (migration `2026_10_17_100007`). Tests: `tests/Feature/Payroll/DeploymentClaimsTest.php`.

## Data

- **Table:** `reimbursements`, with these columns: user, category, spent_on, amount, purpose, away_log_id, receipt (path, name, mime), status (pending, approved, paid, rejected, cancelled), decided_by / decided_at / decision_note, payroll_adjustment_id and paid_in_run_id.
- **Migration:** `2026_10_16_100006`.
- **Audited:** `reimbursement.filed`, `reimbursement.approved`, `reimbursement.rejected`, `reimbursement.cancelled` and `reimbursement.paid`.

**Tests:** `tests/Feature/Payroll/ReimbursementsTest.php`. They cover:

- office staff only, with a receipt;
- the 60-day, ₱20,000 and purpose rules;
- approval by the President or Vice President only, never their own, and a rejection needs a reason;
- paid on the next payslip, not taxed, once;
- cancel while waiting;
- the pages, and the private receipt.
