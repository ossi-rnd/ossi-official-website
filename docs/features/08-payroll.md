# 8. Payroll

## The pieces

```
config/payroll.php ──► RateScheduleResolver ──► RateSchedule (rates on the run's START date)
                                                     │
DB ──► PayslipInputsQuery (5 bulk queries) ──► PayslipInput per employee
                                                     │
                              PayslipCalculator (pure) ──► PayslipBreakdown
                                                     ▲
             PayslipRecord (frozen) ──► PayslipBreakdown::fromRecord()
                                                     │
                     ResolvePayslips: record if generated, else estimate
                         ▲                                ▲
               Payroll screen                     My Payslips
```

Both screens call `ResolvePayslips`, so "an employee's payslip page and the payroll screen show identical numbers" holds by construction.

## The formula (`PayslipCalculator`)

**Night differential** (Labor Code Art. 86) comes from attendance, not from a setting on the employee record. `AttendanceCalculator::nightMinutes()` counts each day's minutes between 10:00 PM and 6:00 AM, from the actual clock-in and clock-out, clipped to the scheduled shift. Clocking in early or staying late without approval earns none. Rotating, overnight and partial nights work without anyone ticking anything. An 18:00–06:00 post earns it on 8 of its 12 hours. The payslip saves `night_minutes` and shows e.g. "Night differential (10% · 16 hrs, 10 PM–6 AM)". The extra night premium on *overtime* hours isn't added yet. (Until 2026-10-09 an `is_night_shift` switch paid 10% of all basic pay; generated payslips keep the amount they were frozen with.)

```
minute rate    = daily rate / (8 × 60)
basic          = daily rate × days worked
night diff     = round(night minutes × minute rate × 0.10)   minutes worked 10 PM–6 AM inside the shift
overtime       = round(OT minutes × minute rate × 1.25)   OT capped per day at approved minutes
holiday        = Σ holiday days worked × daily rate × premium   (regular 1.00, special 0.30)
taxable gross  = basic + night diff + overtime + holiday
deductions     = SSS + PhilHealth + Pag-IBIG + tax + late + loan installments
net            = taxable gross − deductions
```

Late: exactly the minutes late after the grace period, `round(late minutes × minute rate)`, where the minute rate is the daily rate ÷ 8 ÷ 60. Never rounded up to a block of time, which would deduct time worked (Labor Code Art. 113). The late-deduction brackets that could override this were removed on 2026-10-23 (the owner's choice). Tax: 0 for field staff (minimum wage, RA 9504), 2% for office staff.

**Worked example** (field guard, 13 days, 120 approved OT minutes, 30 min late, 1 regular holiday): net **₱7,787**. It's a unit test (`tests/Unit/PayslipCalculatorTest.php`) and a feature test with real rows.

## Effective-dated rates

`config/payroll.php` keys schedules by the date they take effect. A run uses the schedule in force on its **start date**, so reopening an old run gives the same figures. To change a rate, **add** a dated entry; never edit an old one.

## Run lifecycle (`AdvancePayrollRun`)

`draft → processing → approved → paid`

| Step | What happens |
| --- | --- |
| Create (draft) | Overlapping periods refused; employees don't see drafts |
| Start processing | Employees see estimates on My Payslips |
| Generate (one or "Generate all") | `GeneratePayslip` freezes every line in `payslip_records` |
| Approve | Refused **until the period has ended** (from the day after its last day: attendance, leave and overtime can still change) and while anyone who worked has no generated payslip. The locked button's tooltip gives the reason (`AdvancePayrollRun::approvalBlockedReason()`). Generating early to check the numbers is allowed |
| Mark paid | Stamps `paid_at` / `paid_by` on every payslip |

## Generate: why it's safe

- Run row locked (`lockForUpdate`) so generation can't interleave with approval.
- Unique `(payroll_run_id, user_id)`: a racing second request hits the index and gets a friendly error.
- Nobody gets a payslip for a period with no attendance.
- Loans: each payslip takes `min(installment, remaining balance)`; the balance drops and the loan switches off at zero. The loan lines are copied into the payslip JSON, so deleting a loan later doesn't alter history.
- **Authorized first (Labor Code Art. 113).** Accounting picks how each loan, cash advance, uniform, equipment or other deduction is authorized (`DeductionAuthorizationBasis`):
  - **Ask them in the app** (the default): the employee gets a bell and an email, and **My Payslips → Please review** shows the exact sentence, e.g. *"I authorize the company to deduct ₱500.00 from each payslip for my cash advance "Emergency cash advance" until ₱2,000.00 is paid (Labor Code Art. 113)."* Nothing is taken until they tick **I authorize this** (`AnswerDeductionAuthorization`). The sentence is saved word for word with the time (`authorization`, `authorized_at`), in English or Filipino as they read it. **I don't agree** takes nothing and tells Accounting.
  - **Signed on paper**: it applies from the next payslip; keep the paper.
  - **Government loan (SSS or Pag-IBIG)**: deducted by law, no authorization asked.

  `PayslipInputsQuery` uses `EmployeeDeduction::takenFromPay()` (switched on, not waiting). Accounting's list shows *Waiting for their authorization* or *They don't agree*, and how each one was authorized. Deductions added before 2026-10-17 keep working and say so (*"keep their signed paper"*). The cash bond (enrolled with the employment) and Borrow Load (authorized on its own request) don't ask.
- Every figure, plus the rate schedule date, is stored: later rate changes don't touch it.
- Money columns are `decimal(10,2)`, written as strings (`number_format(..., 2, '.', '')`).

## Scale

Run totals come from SQL `SUM()` over frozen records; live estimates are computed only for the 20 employees on the current page; CSV export streams in chunks. "Generate all" runs in the request with one transaction per employee: fine for a few hundred employees. Past that, move the loop into a queued job (`ShouldQueue`, one job per chunk) and show progress with `wire:poll`.

## Deliberate change

The old modal let accounting edit any figure before generating. Here figures always come from the calculator, so every peso is explainable. Manual adjustments, if needed, should be a separate audited "adjustment line" feature.

Tests: `tests/Unit/PayslipCalculatorTest.php`, `tests/Unit/RateScheduleResolverTest.php`, `tests/Feature/Payroll/PayrollTest.php`.

## Net pay never goes below ₱0 (carry-overs)

A guard who works one day earns ₱610, but ₱906 of contributions are due. The rule, agreed with the owner on 2026-10-09:

1. Deductions are taken **in this order** until the pay runs out: SSS → PhilHealth → Pag-IBIG → withholding tax → **carried over from earlier payslips** → late deduction → loans.
2. Whatever couldn't be taken is a **shortfall**, shown on the payslip in a yellow "Not deducted" box:
   - contributions, tax, late and earlier carry-overs are **carried to the next payslip** (`payroll_carryovers`), where they come off right after the contributions;
   - an unpaid **loan installment is not carried**. The loan balance only drops by what was actually deducted, so the rest simply stays owed.
3. Net pay is never negative, and a generated payslip never shows one.

| Piece | Where |
| --- | --- |
| The order and the cap | `PayslipCalculator::calculate()` (still pure, so unit tested without a database) |
| Owed amounts loaded for a run | `PayslipInputsQuery` → `PayrollCarryover::owedBefore($run)`: only from runs that started **earlier**, so generating an older run later never pulls in a newer shortfall |
| Settling and creating carry-overs | `GeneratePayslip::settleCarryovers()`, in the same transaction as the payslip. Every carry-over brought in is settled; any unpaid remainder becomes a new one from this run |
| Frozen on the payslip | `payslip_records.carryover_lines`, `shortfall_lines`, `carryover_deductions` (added by a new migration, so existing databases only need `php artisan migrate`) |

A period with **no** attendance is different: there is no payslip at all, so nothing is deducted or carried (the payslip shows "No earnings or deductions for this period").

## Adjustments: corrections without editing the figures

Accounting never types over a calculated amount. A correction is **its own line with a reason**, so every peso still traces back to attendance, the rate table, a loan, or a named and dated adjustment.

**Where:** Payroll → a run → the eye icon on an employee → **Adjustments** → *Add adjustment*.

| Category | Effect | Taxed (office staff) | Example |
| --- | --- | --- | --- |
| Correction | add or deduct | **yes**: it replaces pay that was earned | Nov 3 clock-in missed, confirmed by supervisor |
| Allowance | add | no | Transport allowance |
| Refund | add | no | Uniform deposit returned |
| Penalty | deduct | — | Lost radio (signed agreement, Labor Code Art. 113) |
| Other | add or deduct | no | Explain in the reason |

**Rules, enforced in the Actions (not only the form):**
- **Accounting has full control.** Adjustments apply to the payslip at once, at any amount (decided 2026-10-09). Super Admin isn't needed.
- **Optional second-person check, off by default.** Set `PAYROLL_ADJUSTMENT_APPROVAL_THRESHOLD` (e.g. `5000`) and adjustments of that size wait for someone with `approve-payroll-adjustments` (Super Admin) who isn't their creator. While one waits, that payslip can't be generated and "Generate all" skips it.
- **Only while the run is processing and before the payslip is generated.** After that the payslip is frozen; a later correction goes on the next payroll. When the change is in attendance, overtime, leave or the rate, the next run suggests it by itself (see *Corrections from earlier cut-offs* below).
- **Only for someone who worked that period.** Without attendance there is no payslip to adjust.
- **Deductions follow the never-below-₱0 rule.** They come after the late deduction and before loans; anything the pay can't cover is carried over like any other shortfall.
- **Every add, approve, reject and remove is audited**, with the amount, category, reason and status. Removing an adjustment keeps a full copy in the audit log.

## Corrections from earlier cut-offs (prior-period adjustments)

A frozen payslip is never rewritten, but pay can change after it: a day **recorded by hand** after the cut-off (a broken phone), **overtime or leave approved late**, a **back-dated daily rate**. Before this, that difference was simply never paid unless Accounting noticed and added an adjustment by hand.

**How it works:**
1. **Detection** (`DetectPriorPeriodCorrections`), for everyone who'll get a payslip on the open run: each of their payslips from cut-offs that ended in the last **90 days** (`payroll.prior_period.lookback_days`) is recomputed with today's data and compared with what was frozen. It runs automatically when a run **starts processing**, on **Generate all** (once for everyone), and on **Generate** for one person, and from **Check again** on the run page.
   - It compares **attendance pay only**: basic, holiday premium, overtime, night differential, less the late deduction due. `PayslipCalculator::attendancePay()` is the same method `calculate()` is built on, so the recomputation follows exactly the payslip's rules and rates (the schedule in force for that cut-off).
   - Contributions aren't recomputed; the correction is taxed as a **Correction** on the new payslip.
2. **Suggested, not applied.** A difference appears on the run page under **Corrections from earlier cut-offs**: employee, cut-off, what changed ("1 more day worked", "2 hrs more overtime", "daily rate ₱695.00 → ₱755.00"), and the signed amount. Money is never added without a person agreeing.
3. **Accept** (`AcceptPriorPeriodCorrection`) adds it to this run's payslip as a **Correction adjustment** through `AddPayrollAdjustment`, so every adjustment rule applies (taxable, audited, the optional second approval for large amounts). The payslip line reads *"Correction: Prior period September 16–30, 2026: 1 more day worked"*. **Dismiss** (`DismissPriorPeriodCorrection`) needs a reason ("paid in cash on Oct 3") and is audited. **Accept all** for many at once.
4. **The payslip waits.** Until it's decided, that person's payslip can't be generated (the message says why), and Generate all skips them, like a pending adjustment.
5. **Never twice, never lost.** The next check subtracts what was already decided (accepted, or dismissed) for that cut-off, so the same difference isn't suggested again. If an accepted correction's adjustment is **removed or rejected**, it counts as unpaid again and comes back. Overpaid works the same way, as a negative correction.

Data: `prior_period_corrections` (run, earlier run, signed amount, `differences` JSON, status suggested / accepted / dismissed, the adjustment, who decided and why). Migration `2026_10_16_100001`. Only people still on payroll with attendance on the open run are checked; for someone who resigned, add it to their final pay as an adjustment.

Tests: `tests/Feature/Payroll/PriorPeriodCorrectionsTest.php` (nothing changed → nothing suggested; a day recorded late is suggested and holds the payslip; accept → a taxable Correction on the payslip, not suggested again; dismiss is final; a removed adjustment makes it owed again; listed on the run page; detected when processing starts).

## Paid leave this cut-off (leave the law pays)

Approved leave that the law pays is **suggested** on the run (`DetectPaidLeave`), for Accounting to check and decide. It's never added silently: which days count for a guard on a roster (a rest day inside the leave, working or calendar days) is a judgment.

- **Paternity** (RA 8187, 7 days), **solo parent** (RA 11861, 7 working days a year), **VAWC** (RA 9262, 10 days), **special leave for women** (RA 9710, up to 2 months): the leave's days inside the cut-off × the daily rate on the cut-off's start. They're capped by what the law gives, less what earlier runs already took, so a leave across two cut-offs is split correctly. Accounting can pay **fewer days**. Accepting adds a **Paid leave** adjustment (taxable: it's pay) through `AddPayrollAdjustment`.
- **Maternity** (RA 11210): the employer **advances the SSS maternity benefit**, computed from the SSS salary credit (not the daily rate), and SSS reimburses it. The suggestion has no amount; Accounting enters it, and it's added as a non-taxable **Allowance** ("SSS maternity benefit advance").
- **Dismiss** needs a reason; a decided one is never suggested again on that run (`paid_leave_payments`, one per leave and run).
- **Payslips wait** until it's decided, like corrections. Someone with **no attendance** but accepted paid leave (e.g. paternity leave the whole cut-off) still gets a payslip: `User::scopeDueAPayslip()` (worked, or accepted paid leave) is used by Generate all and by approval's "everyone has a payslip" check.
- Detected when the run starts processing, before Generate all, before one payslip, and when the run page opens.

Code: `DetectPaidLeave`, `AcceptPaidLeave`, `DismissPaidLeave`, `PaidLeavePayment`, `Payroll\PaidLeaveSuggestions`, `AdjustmentCategory::PaidLeave`, `LeaveType::isPaidByLaw()` / `paidAsSssBenefit()`. Migration `2026_10_17_100009`. Tests: `tests/Feature/Payroll/PaidLeaveTest.php`.

## My Loans

**My Loans** (Personal menu, `/my-loans`, `MyLoansQuery`) shows each loan, cash advance and company deduction:
- the installment and the total;
- **what's left**, with a progress bar;
- its state (Being deducted, Waiting for your authorization, Paused, Paid off);
- **how it was authorized** ("You authorized it in the app", "You signed an authorization on paper", "Government loan, deducted by law"), with the exact sentence;
- **every payslip that took from it**, read from the payslips' own frozen loan lines (`deduction_id`), so the history always matches what was paid.

The **cash bond** is shown apart (held now, per payslip, collected), since it's kept rather than owed and refunded with the final pay. Roadmap item 13 is Built.

## Report a problem: employees flag what the system can't see (Payroll Concerns)

The corrections above catch what the system *knows* changed. Only the employee notices the rest: a day never recorded at all, overtime a supervisor allowed but nobody filed, a deduction they don't recognise. Before, they could only tell Accounting in person (no record) or misuse Complaints (HR, confidential).

1. **The employee** (My Payslips → a payslip → *Something wrong on this payslip? Report a problem*) picks what's wrong, writes the details with dates, and can attach a **photo or PDF** (a DTR, a supervisor's note). The list of choices: a missing day, overtime, late deduction, a loan or deduction, the daily rate, contributions or tax, something else; each with an example (`PayrollConcernCategory`). The same kind of problem on the same payslip can't be filed twice while open. `FilePayrollConcern`.
2. **Accounting is told at once** (bell + email, `PayrollConcernNotice`). **Payroll Concerns** in the menu (`manage-payroll`, badge = open; "Waiting for you · to answer" on the dashboard) lists them; opening one shows the details, the attachment, and a link to that payroll run.
3. **Accounting answers** (`AnswerPayrollConcern`), always with a reply the employee reads:
   - **Pay it:** on a payslip still being processed (a processing run where they worked and the payslip isn't generated yet), add or deduct an amount. It's a **Correction** adjustment through `AddPayrollAdjustment` (taxable, audited, the optional second approval), with the reply as the reason on the payslip.
   - **Explain:** resolved, nothing changes ("Oct 10 was your day off on the roster").
   - **Not valid:** with the reason.
   If no payslip is open yet, the dialog says so; answer it when the next run starts processing, or explain it now.
4. **The employee is told** (bell + email), and the answer shows under that payslip: *"Resolved · Confirmed with Sgt. Reyes: Sep 22 added. · Added ₱695.00 on your October 1–15 payslip."*

The attachment is on the private disk (`payroll.concerns.disk`, like load receipts), checked by its first bytes (`PrivateUploadStore`), and streamed only to the employee and Accounting (`ShowPayrollConcernAttachmentController`). Everything is audited (`concern.filed`, `concern.answered`).

Data: `payroll_concerns` (migration `2026_10_16_100002`). Tests: `tests/Feature/Payroll/PayrollConcernsTest.php`.

**Code:** `AddPayrollAdjustment`, `ReviewPayrollAdjustment`, `RemovePayrollAdjustment` (actions); `PayrollAdjustment` (model); `App\Livewire\Payroll\PayslipAdjustments` (the panel, its own component, which refreshes the payroll page through an `adjustments-changed` event); the calculator gets approved adjustments as `AdjustmentLine`s. Tests: `tests/Feature/Payroll/AdjustmentsTest.php`.

## Government contributions and tax (2026 rules)

From the `2026-01-01` schedule in `config/payroll.php`, SSS, PhilHealth, Pag-IBIG and withholding tax are **computed from the government rules**. The fixed amounts of the original spec stay in the `1970-01-01` schedule, so older payslips still reproduce. Checked on 2026-10-09; have Accounting compare them with the circulars before the first live payroll.

| | Rule | Base |
| --- | --- | --- |
| **SSS** (RA 11199) | 15% of the Monthly Salary Credit: **5% employee**, 10% employer, + EC ₱10 (MSC < ₱15,000) or ₱30. MSC ₱5,000–₱35,000 in ₱500 steps (₱5,250–5,749.99 → ₱5,500) | all taxable pay |
| **PhilHealth** (RA 11223) | 5%, split **2.5% / 2.5%**; floor ₱10,000, ceiling ₱100,000 | basic pay |
| **Pag-IBIG** (HDMF Circular 460) | **2%** employee (1% at ₱1,500 or less), 2% employer, on up to ₱10,000, so at most ₱200 a month each | basic pay |
| **Withholding** (RR 11-2018) | BIR **semi-monthly** table on taxable pay **minus** the employee's three contributions: 0% to ₱10,417, then 15% / 20% / 25% / 30% / 35% | taxable pay |
| **Minimum wage earners** | Anyone whose daily rate is **at or below** the region's minimum wage pays **no** withholding tax (basic, OT, holiday pay, night differential). `payroll.minimum_wages`: NCR ₱695 (NCR-26), **₱755 from 2026-09-26 (NCR-28)** | |

**Two cutoffs, one month.** The contributions are monthly; payroll runs twice a month:

- **1st–15th:** half of the monthly amount, estimated as if the month continues like this cutoff (base × 2).
- **16th–end** (any run ending on the month's last day): the monthly amount on the **actual** month (both cutoffs) **minus** what the first cutoff was due. So the month always adds up to the law, whatever the first estimate was.
- The month's last payslip can't be generated before the first one (if the employee worked then), because it balances against it. "Generate all" skips those people.
- Each payslip freezes `contributions`: bases, MSC, **employee and employer shares** and EC. The CSV export lists them per employee for the remittances.

**Rates are the employer's decision.** OSSI is a private company and sets its own rates, so the system shows no minimum-wage warnings on rates (removed 2026-10-09 at the owner's request). The minimum wage is still used for **tax**: anyone paid at or below it is a minimum wage earner and exempt from withholding (Tax & Contributions keeps the wage orders). An employee without any rate gets an amber banner on the open run, and their payslip can't be generated.

**Code:** `App\Services\Payroll\StatutoryContributions` (the formulas, pure), `PayslipCalculator::statutoryDue()` (cutoff logic), `PayslipInputsQuery::monthToDate()`, `GeneratePayslip::blockingEarlierRun()`. Tests: `tests/Unit/StatutoryContributionsTest.php` (brackets, floors, caps, tax table) and `tests/Feature/Payroll/PayrollTest.php` (worked example ₱7,957.25, month balancing, ordering, minimum wage, CSV).

## Daily rates and rules are maintained by Accounting

**Rates (Accounting).** OSSI is a private company, so pay is a company decision above the legal minimum. Each employee is paid, in this order (`EffectiveRatesQuery`, the one place that decides it):

1. their **personal rate**, if Accounting set one (an exception, e.g. a senior shift-in-charge);
2. else their **position's company rate** (**Daily Rates → Rate per position**, `position_rates`, `SetPositionRate`), e.g. Security Guard ₱755, Shift-in-Charge ₱800, Office Staff ₱900;
3. else **no rate**. From 2026 the schedule's company default is **₱0** (`config/payroll.php`; the 1970 schedule keeps ₱610/₱900 so old payslips reproduce). A newly onboarded employee shows **Not set** everywhere until Accounting sets their position's rate or a personal one. Until then:
   - `GeneratePayslip` refuses their payslip, so nobody is paid on a made-up rate and ₱0 pay doesn't run up SSS/PhilHealth carry-overs;
   - **Generate all** skips them and says how many;
   - the open run lists them in an amber *no daily rate yet* banner, linking to Daily Rates.

   Tests pin the old ₱610/₱900 in `tests/TestCase.php` for the worked examples; `zeroCompanyDefault()` switches to the real ₱0.

Position rates are dated like personal ones. A position rate can't start on or before the last generated payslip (`FrozenPayrollUntilQuery`). The **Rate per position** tab shows each position's rate today, its next change, and how many employees are paid it. The **Employees** tab lists every active employee with the rate paid today and where it comes from (*Personal rate* / *Position rate* / *Company default*), the next personal change, filterable to *No rate yet* (neither personal nor position rate). The sidebar badge counts employees without a rate. **Change rate** opens the history and a form with amount, **effective-from date** and note (`employee_rates`). HR sees the same rate read-only on the employee record. A raise is a new row, so each payroll uses the rate in force when its period starts, and the history stays readable. A change that would contradict an already generated payslip must start after that period. Anyone without a rate yet falls back to the schedule's company default (₱610 field / ₱900 office), and the payroll run names everyone paid **below the minimum wage**. The run's **Daily rate** column shows it too. `SetEmployeeRate` requires `manage-payroll`, so HR can't change pay.

**Rules (Accounting).** **Tax & Contributions** (`/tax-settings`, `manage-payroll`) shows the SSS, PhilHealth, Pag-IBIG and BIR rules in force and the minimum wages. **New version** saves the rules from a date (`statutory_rule_sets`), and **Add minimum wage** adds a wage order (`minimum_wages`). The config file is only the built-in default until a version exists. Rates are typed as percentages and stored as fractions. No version or wage can start on or before the last day of any generated payslip (`FrozenPayrollUntilQuery`), so a frozen payslip and its working stay true. Every save and delete is audited.

**"How it's computed (PH law)" tab.** Tax & Contributions has a second tab (`?tab=law`) that explains each deduction in plain language with its legal basis (RA 11199, RA 11223, RA 9679 / HDMF Circular 460, NIRC as amended by RA 10963, RR 11-2018, RA 9504), the values in force today, and the BIR table. A calculator takes a daily rate, days worked, other taxable pay and a date, and runs the real `PayslipCalculator` for both cutoffs of a month, so what it shows is exactly what payroll would deduct. It also lists what isn't modelled yet (13th month pay and the ₱90,000 exemption, de minimis, year-end annualization). Test: `TaxSettingsTest` (₱755 × 26 days → SSS ₱975, PhilHealth ₱490.75, Pag-IBIG ₱200, no tax).

**Checking the numbers (Accounting).**
- The run's **Contributions & tax** tab lists every employee on the page: daily rate, taxable pay, SSS MSC, employee and employer shares (+ EC), the amount taxed after contributions, and the tax or "MWE · exempt", with page totals. The CSV export has the same columns for the whole run.
- Each payslip has a collapsed **"How contributions and tax were computed"** panel. It shows the month basis, the MSC bracket, "half of ₱X" or "month total − already deducted", the employer shares, and the tax bracket used. It's built from what the payslip saved, so a frozen payslip shows the rules it was computed with.

**Loans on a final payslip are read-only.** Loans and company deductions belong to the employee and apply to future payslips, so they're managed from a payslip that can still change (open run, not yet generated). On a generated or paid payslip the list is read-only and **Add** is locked with a tooltip saying where to manage them; `Payroll::ensureLoansEditable()` enforces the same rule on the server. The minimum-wage banner shows only while a run is open (`PayrollRunStatus::isOpen()`): on an approved or paid run there is nothing left to fix.

## Sample payroll data (`PayrollSampleSeeder`)

`php artisan migrate:fresh --seed` runs it through `DemoSeeder`. It can also be run on its own on a database that already has data: `php artisan db:seed --class=PayrollSampleSeeder`. It only adds what's missing and never touches an approved or paid run. Everything goes through the real Actions, so the numbers are real calculations.

- **Daily rates per position:** guards ₱695 (₱755 from Sep 26, NCR-28), shift-in-charge ₱800, office ₱900–1,200, President ₱2,500, Operations Head ₱1,500, Satellite Operations Officer ₱1,100. These are demo values; Accounting sets the real ones.
- **Deductions:**
  - Loans: SSS salary loan, Pag-IBIG multi-purpose loan, company emergency loan.
  - Two cash advances (these pay off fully within the two cut-offs).
  - Recurring: uniform, radio rental, cooperative share.
  - Borrowed load: ₱300 and ₱100.
- **Adjustments:** transport and meal allowances, a lost-ID penalty, and a missed clock-in correction.
- **Runs:** the cut-off before last is generated, approved and **paid**. The last cut-off is generated and **approved** (ready to mark paid). The current cut-off is **processing**, so employees see estimates.
