# 7. Leave and overtime requests

| | Leave | Overtime |
| --- | --- | --- |
| Employee page | My Leave: type, dates, reason; cancel while pending | My Overtime: date, hours, reason (no supervisor to pick: it goes to Operations, or the President or Vice President for office staff) |
| Refused when | Overlaps a pending/approved leave; starts in the past (sick and emergency: up to 7 days back is fine) | A pending/approved request exists for that date |
| Staff page | Leave Requests (`monitor-requests`) | Overtime Requests (`monitor-requests`) |
| Who decides | **Guards:** HR or Admin (`review-field-leave`). **Office staff:** the President or Vice President (`review-office-leave`) | HR, Admin, Operation HQ, Satellite in its region (`review-overtime`) |
| Review | Approve / reject with a note | Approve with **approved minutes ≤ requested**, or reject |
| Actions | `SubmitLeaveRequest`, `CancelLeaveRequest`, `ReviewLeaveRequest` | `SubmitOvertimeRequest`, `ReviewOvertimeRequest` |

## Leave requirements (roadmap 24)

- **Leave under special laws**, each with what the law gives and the proof it needs (`LeaveType`):
  - **Maternity** (RA 11210): 105 days, 120 for a solo parent. Proof: a medical certificate of the pregnancy, or the birth certificate.
  - **Paternity** (RA 8187): 7 days. Proof: the child's birth certificate and the marriage certificate.
  - **Solo parent** (RA 11861): 7 working days a year. Proof: the Solo Parent ID.
  - **VAWC** (RA 9262): 10 days. Proof: a protection order or a certificate that a case is filed.
  - **Special leave for women** (RA 9710): up to 2 months. Proof: a medical certificate of the surgery.

  The form shows the entitlement and the proof. The day counts are **shown, not enforced**: the laws count days differently, so a longer request is flagged to the approver (*"This asks for more"*), who decides.
- **Pay:** the leave the law pays (maternity, paternity, solo parent, VAWC, special leave for women) is suggested on the payroll run for Accounting to accept ([08](08-payroll.md#paid-leave-this-cut-off-leave-the-law-pays)). Other leave doesn't change pay.
- **Sick leave of 2 days or more** needs a **medical certificate**.
- **Sick and emergency leave can be filed once back**, up to 7 days after it started. Everything else starts today or later.
- **HR sets both numbers in the app**: **Leave Requests → Rules → Change** (`manage-people`; others see them). They're stored as the `leave` Setting (`SaveLeaveRules`, `App\Support\LeaveRules`) and audited with the old and new values. The certificate rule allows 1–30 days, filing after allows 0–30. My Leave reads the same numbers. Starting values: 2 and 7 (`config/leave.php`).
- **Proof now or later** (`AttachLeaveDocument`, up to 5 files, photo or PDF, checked by bytes). Attached after filing, whoever acts next is told again. **The decider can't approve without it** (`ReviewLeaveRequest::approvalBlockedReason`): Approve is locked with *"Waiting for the document: …"*; rejecting is still possible. The list shows *Document needed / attached*.
- **Private** (Data Privacy Act: medical records are sensitive personal information). `leave_documents` sits on a private disk (`LEAVE_DOCUMENTS_DISK`) and is streamed by `ShowLeaveDocumentController` only to the employee and whoever decides (HR for guards, the President or Vice President for office staff; `LeaveRequest::documentsVisibleTo`). The endorsers see only that it's attached. The decider's views are audited (`leave.document_viewed`).
- **VAWC is confidential** (RA 9262 Sec. 44): it goes straight to HR (or the President or Vice President for office staff), skipping the Satellite and Operation HQ. It's hidden from everyone else's list (`LeaveRequest::scopeVisibleTo`), and the bell and email say only *"Confidential leave"*, never the type or reason.

Tests: `tests/Feature/Requests/LeaveRequirementsTest.php`.

## Rules worth noticing

- **Monitoring is not deciding.** Everyone with `monitor-requests` sees the lists. A row they can't decide shows why instead of a Review button: "Office staff leave is approved by the President or Vice President.", "Guards' leave is approved by HR.", "This employee is outside your region." Both Actions have a `blockedReason()`, which the list and the Action share.
- **Office or field** comes from the requester's HR profile (`is_office_staff`). An account with no profile counts as office staff.
- **Separation of duties:** nobody reviews their own request.
- **Row lock on review:** two supervisors clicking at once can't both decide; the second gets "already reviewed".
- **The decider hears about it.** Leave goes to whoever acts next (`LeaveRouting::notifyNext` → `LeaveAwaitingYou`: the region's Satellite, then Operation HQ, then HR; office staff's to the President or Vice President). Overtime: office staff's to the President and Vice President; a guard's to **Operations**, meaning Operation HQ and their region's Satellite Operation (`SubmitOvertimeRequest::operationsFor`, `RequestFiled`, bell and email). Since 2026-10-17 nobody picks a supervisor; older requests keep the one they were sent to (`supervisor_id`, now optional; lists show **Sent to**).
- **The requester hears the answer.** Approving or rejecting sends them `RequestDecided` (bell and email) with the reviewer's note; approved overtime says how much, and "you asked for 3 hours" when less was approved.
- **Ids are looked up through the owner** (`$user->leaveRequests()->findOrFail($id)`), so a forged id from someone else's request is simply not found (404), never cancelled.
- **Business failures throw `ValidationException`** from the Action. Livewire shows the message under the right field without any try/catch in the component.
- The post snapshot ("Detachment · Post") is copied onto the overtime request when filed, so later reassignments don't rewrite history.
- **Add extra time (Operations, HR, Admin: `review-overtime`).** For extra time Operations knows about, like a guard holding the post until the late night guard came, the **Add extra time** button on Overtime Requests records it for the guard: guard (a searchable list of active guards they may see, so a Satellite Operation only gets its region's), date worked (today or earlier), hours (¼ to 12) and a reason. It's saved **approved**, `filed_by` them, audited (`overtime.recorded`), and the guard is told: "Extra time added: Oct 18 · Teresa Ilagan added 2.5 hours of extra time for you: … Paid up to the time you clocked." Lists show **Added by Teresa Ilagan** in the Sent to column, on the guard's My Overtime too. Not for office staff (their overtime goes to the President or Vice President) or yourself. If the guard already asked for that day, it's refused with "review that request instead" (one per day, under a row lock). `RecordOvertime`; tests: `tests/Feature/Requests/RecordOvertimeTest.php`.
- A guard who **can't report for duty** on a given day uses **Report Absence**, not leave. That's for Operations to send a reliever, and it doesn't excuse the day. See [Relievers](18-relievers.md).

## How payroll uses overtime

Per day, payroll pays the **smaller** of logged overtime and the approved minutes. No approved request means 0. Extra time Operations added is approved overtime like any other, so it's paid the same way: never more than the guard actually clocked. See [Payroll](08-payroll.md).

Tests: `tests/Feature/Requests/LeaveAndOvertimeTest.php` and `tests/Feature/Access/OperationsRolesTest.php`.
