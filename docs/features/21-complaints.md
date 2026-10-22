# 21. Complaints

Any employee can file a complaint with HR. It's **confidential**: only the employee who filed it and HR (`handle-complaints`: HR, Super Admin) ever see it.

## The flow

1. **Employee:** **My Complaints** → about (workplace conduct, harassment or discrimination, pay and benefits, safety at the post, schedule or assignment, other), subject, what happened (at least 20 characters), and optionally the person or party involved and the date. Then three points to acknowledge (`Complaint::acknowledgementLines()`): it's true as far as they know, and a knowingly false complaint can itself lead to discipline; HR keeps it confidential but may tell the person involved what is said, so they can give their side (due process); and no one may punish them for a complaint made in good faith. They tick "I've read these" → **Send to HR**. The lines are saved word for word with the time, and HR sees them on the case. A complaint opened by a just-cause resignation has none (the resignation has its own). They get a reference such as **CMP-0007**.
2. **HR** gets a bell alert: "New complaint CMP-0007". **Complaints** (badge = open cases) lists them as "Open and investigating" by default.
3. HR opens the case and moves it: **Open → Investigating → Resolved or Dismissed**. Closing it needs a **written outcome** (at least a sentence), which the employee reads on My Complaints.
4. The employee gets a bell alert each time the status changes.

## Subjects: pick a common one, or type it

Under **About** (the category), **Subject** is a list of common subjects for that category, plus **Other (type it)**. Picking Other shows a "Your subject" box. Changing the category starts the subject over. A category with no list ("Other") just shows the box.

- **HR keeps the list in the app:** Complaints → Rules → Common subjects → **Manage**: add (category + subject), **Rename**, **Switch off** / on, no duplicates in a category, all audited (`SaveComplaintSubject`). Never deleted.
- **A complaint copies the words** it was filed with (`subject`), and keeps the link (`complaint_subject_id`) so HR can see which problems keep coming back: each subject shows how many complaints came in with it in the **last 90 days**, counting only cases that person handles (HR never counts HR staff's).
- **The starting list is a suggestion**, mine, for HR to change: Workplace conduct (shouted at or insulted, treated unfairly by a supervisor, a co-worker not doing their part, asked to do something against the rules), Harassment (sexual harassment, bullying or threats, discrimination), Pay and benefits (missing pay or days, wrong deduction, overtime not paid, salary paid late, SSS/PhilHealth/Pag-IBIG not remitted), Safety at the post (no relief guard, equipment or firearm not working, threat or danger at the post, no water, shelter or restroom), Schedule or assignment (schedule changed without notice, too many shifts in a row, reassigned without a reason).

Code: `ComplaintSubject`, `SaveComplaintSubject`; `FileComplaint` takes `picked:` (refused if it's from another category or switched off). Migration `2026_10_20_100002`. Tests: `tests/Feature/Operations/ComplaintSubjectsTest.php`.

## Proof: welcome, never required

Roadmap item 31. Proof helps HR investigate, but **a complaint never needs it to be filed**. Many real complaints (harassment, verbal abuse, threats) leave nothing on paper. The Safe Spaces Act (RA 11313) and the anti-sexual-harassment law (RA 7877) expect the employer to receive and investigate them anyway, and the "a false complaint can lead to discipline" line the employee acknowledges already guards against abuse.

- **When filing:** a "Proof, if you have any" box: up to **5** photos, screenshots or PDFs (checked by their bytes), and **Who saw it** for when there's nothing on paper. It says plainly: "Not required: you can send this without it."
- **HR asks for proof** from the case: **Ask for proof** → "What would help" (e.g. "A screenshot of the messages, if you kept them") → the employee gets a bell notice (no contents) and sees an amber box on My Complaints with HR's note and **Add proof**. Once they add a file, the request counts as answered and HR is told ("Proof added to CMP-0007").
- The employee can **add proof later** on any open case, not only when asked. A closed case takes none.
- **Private:** files live on a private disk (`config/complaints.php` → `COMPLAINT_FILES_DISK`, falling back to `ATTENDANCE_PHOTO_DISK`, so the bucket on Laravel Cloud). Only the complainant and the case's handler (HR, or the President or Vice President for HR staff's) open them (`Complaint::filesVisibleTo`); every handler view is audited (`complaint.proof_viewed`). The audit log says proof was added or asked for, never what it is.
- The 5-file cap is a storage limit picked to match leave documents (`complaints.max_files`), not a policy number.

Code: `AttachComplaintFile`, `RequestComplaintProof`, `ComplaintFile`, `ShowComplaintFileController`; `FileComplaint` takes `witnesses:` and `proof:`. Migration `2026_10_19_100004`. Tests: `tests/Feature/Operations/ComplaintProofTest.php`.

## "I need help now"

The first thing on the form is a checkbox: **I need help now** ("You're being threatened, or you don't feel safe"). Ticked, it turns red and adds "In danger right now? Call 911 first, then send this."

- The handler (HR, or the President or Vice President for HR staff's) is alerted **by bell and email** at once: *"Urgent: Maria Santos asks for help (CMP-0005)"*. The email still carries no contents, only who, the reference and a link to the case. Ordinary complaints stay bell only.
- On HR's list it's **first**, with a red **Asks for help** badge, while the case is open or investigating. The case opens with a red banner: "Maria Santos asked for help now. Reach them as soon as you can (their contact number), then move the case to Investigating."
- The employee sees **Asked for help** on it in My Complaints.
- It's never counted against the weekly limit.

## One complaint a week (HR's number)

The roadmap asked for "1 complaint per week" (item 32). It's company policy, so **HR sets the number in the app**: Complaints → **Rules** → Change (0 = no limit, up to 10; `SaveComplaintRules`, audited; read through `App\Support\ComplaintRules`, starting value `complaints.per_week` = 1).

- It counts the employee's complaints in the last **7 days**, and never counts **asking for help**, **harassment**, or a complaint **opened by a resignation** for a just cause. Those can be sent any time: a limit must never stand between someone and help, and RA 11313 requires harassment complaints to be received.
- **The balance is shown before writing**, like a token count, under "I need help now": *"You can file 1 complaint this week (up to 1 in 7 days). Harassment, and asking for help, don't count."* Once used: *"You've used this week's complaint. Next one possible Oct 27, 9:37 AM. You can still add to an open one below…"* (with a warning dot). With no limit (0), there's no counter. It's a rolling 7 days: a token comes back 7 days after the complaint that used it, not on a fixed weekday.
- If they send anyway, the form says the same in a message: *"You filed CMP-0004 on Oct 20. You can file another from Oct 27, 9:22 AM, or add to an open one on this page. Harassment, and asking for help, can be sent any time."*
- One count for both: `ComplaintRules::allowanceFor()` gives the limit, what's used, what's left and when the next frees up; the form shows it and `FileComplaint` checks it.

Code: `FileComplaint` (`asksForHelp:`, `exemptFromLimit:`), `ComplaintRules`, `SaveComplaintRules`, `ComplaintNotice` (mail for help requests). Migration `2026_10_20_100001`. Tests: `tests/Feature/Operations/ComplaintHelpAndLimitTest.php`.

## Opened by a resignation for a just cause

A resignation that ticks an Art. 300(b) just cause (serious insult, inhuman treatment, a crime against them or their family, or the like) accuses the company or someone acting for it. So `FileResignation` also opens a complaint for the employee: Workplace conduct, *"Just cause given for resigning: …"*, with the letter as the details, for the same handler. It's linked by `resignation_id`. HR's case shows "Opened from their resignation for a just cause" with a link back. The resignation shows the case, and closing it needs the written outcome like any other complaint. Cases open from a link (`/complaint-cases?open=`), and the "New complaint" bell goes straight to the case.

## Confidentiality, in code

- Only `handle-complaints` opens HR's page. Admin, Accounting and the rest get 403. Employees see only their own complaints.
- The **audit log** records that a complaint was filed or updated (reference and status), **never** what it says.
- Notifications are bell only and carry no details ("filed a complaint (Safety at the post)").
- An HR member can't handle a complaint they filed themselves.

## Code

- `FileComplaint`, `UpdateComplaint`
- `Complaint`, `ComplaintStatus`, `ComplaintCategory`
- `App\Livewire\Complaints\MyComplaints` (everyone), `App\Livewire\Admin\Complaints` (HR)
- `ComplaintNotice`

Tests: `tests/Feature/Operations/AdminModulesTest.php` (complaints): HR only, the outcome is required to close, the employee is notified and sees it, other employees don't, and the audit log has no contents.

## Try it

1. As Maria: My Complaints → file one.
2. As `hr@example.test`: Complaints → Open → Resolved → write the outcome → Save.
3. As Maria: the outcome shows under the complaint.
