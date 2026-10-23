# Notifications: who is told what

Every action that affects **someone other than the person doing it** tells that person with a bell item, plus an email where it matters. Settings changes, and people acting on their own data, don't notify. Bells are written in the same request (`WritesBellImmediately`); emails go through the queue.

Last audited 2026-10-22, across all 172 Actions. The audit added the **New** rows, using the general `App\Notifications\UpdateNotice` (`tell()` / `tellProfile()`). These skip the person who made the change and anyone who no longer works here.

## Employees and guards

| Event | Told | Notice |
|---|---|---|
| Moved to another client, post or shift (Operations or HR) | the guard (+ email) | **New** UpdateNotice |
| A duty set or changed, a day off, the week filled on the roster | the guard (one bell per week when filled) | **New** UpdateNotice |
| Attendance recorded for them by hand | the guard, with the times, who and why | **New** UpdateNotice |
| Daily rate set | the employee (+ email) | **New** UpdateNotice |
| Employment status changed (e.g. Regular) | the employee (+ email) | **New** UpdateNotice |
| 13th month pay released | each employee paid, with their amount (+ email) | **New** UpdateNotice |
| A deduction paused, resumed or removed; a cash bond entry | the employee | **New** UpdateNotice |
| A change of position cancelled | the employee | **New** UpdateNotice |
| Training moved / called off (+ email), removed from it, graded | the trainee | **New** UpdateNotice |
| A radio issued to them | the guard | **New** UpdateNotice |
| Access changed (role) | the person (+ email) | **New** UpdateNotice |
| A company policy added or its text changed | everyone still working here | **New** UpdateNotice |
| Leave / overtime decided, overtime recorded for them | the employee | RequestDecided |
| Payslips ready, paid | everyone in the run | PayslipReady |
| Loan / deduction to authorize | the employee | DeductionAuthorizationNotice |
| Reimbursement decided, or filed for them by Operations | the employee | ReimbursementNotice |
| Load request decided | the employee | LoadRequestNotice |
| Payroll concern answered | the employee | PayrollConcernNotice |
| Merit / demerit given or voided | the employee | MeritNotice |
| Notice to Explain issued, hearing, decided | the employee | NoticeToExplainNotice |
| Promotion or change of position set | the employee | PositionChangeNotice |
| 201 document returned, due | the employee | RequirementNotice |
| Reliever assigned | the absent guard and the reliever | RelieverAssigned |
| Training request decided, added to a session | the guard | TrainingRequestNotice |
| Complaint updated, proof asked | the complainant | ComplaintNotice |
| Resignation accepted, completed | the employee | ResignationNotice |
| Final pay released | the employee | FinalPayNotice |
| Referral hired, not hired, no bonus | the referrer | ReferralNotice |
| Equipment issued (confirm; a uniform also asks the authority to deduct), request not approved, returned | the employee | UpdateNotice (+ DeductionAuthorizationNotice) |
| Equipment reported damaged or lost (explain within 5 days), decided (charged or not) | the employee, bell and email | UpdateNotice |
| Announcements | the audience chosen | AnnouncementPublished |

## Staff roles

| Event | Told | Notice |
|---|---|---|
| Someone registered | HR | **New** UpdateNotice |
| Large payroll adjustment approved or rejected by the Super Admin | the Accounting person who asked | **New** UpdateNotice |
| Named petty cash custodian (+ email) | the custodian | **New** UpdateNotice |
| Petty cash replenishment asked / released / returned | Accounting / the custodian | PettyCashNotice |
| Leave to endorse or decide | the next person in the chain | LeaveAwaitingYou |
| Overtime filed | Operations / HR / the President or VP | RequestFiled |
| Daily rate needed for a new hire | Accounting | DailyRateNeeded |
| Forgot to clock out (after the shift) | the employee, then their head: guard → Operation HQ + region's Satellite; HR → President / VP; Satellite → Operation HQ; other office staff → HR | **New** UpdateNotice (SendAttendanceAlerts) |
| Office staffer not clocked in on a work day | the employee, plus one summary per head (same chain) | **New** UpdateNotice (SendAttendanceAlerts) |
| Absence reported, no-show, guard arrived | Operations (the region's Satellite, then HQ) | AbsenceNoticeFiled, NoShowAlert |
| Patrol round missed, time away overdue | Operations / the President or VP | PatrolAlert |
| Other phone used to clock in | Operation HQ, the region's Satellite, HR | OtherPhoneUsed |
| Probation ending, 201 documents expiring | HR | ProbationEnding, RequirementNotice |
| Birthdays today | HR, and Operations for guards | BirthdayToday |
| Documents / radio NTC permits nearing expiry | Admin (+ Operation HQ for radios) | DocumentDueNotice, RadioPermitDue |
| Reimbursement filed, approved (to pay) | the President or VP, Accounting | ReimbursementNotice |
| A uniform saved without a price (for every size) | Accounting | UpdateNotice |
| A uniform handed over: set its payslip charge | Accounting | UpdateNotice |
| Equipment requested (one bell per cart, listing every item) | Operations (Operation HQ + the region's Satellite) for a guard, Admin for office staff | UpdateNotice |
| Equipment damage or loss explained | whoever reported it, and the employee's head | UpdateNotice |
| Payroll concern, complaint, resignation, referral, training request filed | the handlers | each one's notice |
| Final pay to sign, returned, released | the signers, Accounting | FinalPayNotice |
| Notice explained, demerit replied to | the issuer / giver | NoticeToExplainNotice, MeritNotice |

## Deliberately not notified

- Settings (shifts, holidays, rates per position, tax tables, clients, offices): they affect many people and show where they apply.
- The person's own actions (filing, cancelling, clocking in).
- Records of people who no longer work here.
