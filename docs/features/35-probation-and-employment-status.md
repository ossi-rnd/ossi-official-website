# Probation, training and employment status

Built 2026-10-24, from the owner's questions: who approves a status change, can the system alert, what happens after 6 months, and can HR set the training length? The owner's answers: the **head evaluates, HR approves**; with **no decision by the end, Regular automatically**; **Resigned and Terminated only through their processes**.

## The law it follows (Labor Code Art. 296)

- Probation is at most **6 months** from the start of work.
- An employee allowed to keep working after probation is **regular by law**, whatever the record says.
- Failing probation is allowed only against **standards made known when hired**, with a written notice served before probation ends. It is a termination, never a "resignation": pressuring someone to resign is constructive dismissal. The system has no "force to resign".

## Rules HR sets (Employees → Probation & training rules)

`App\Support\ProbationRules`, saved by `SaveProbationRules` (HR / manage-people, audited `probation.rules_changed`). Starting values are in `config/hr.php`.

| Rule | Default | Range |
|---|---|---|
| Probation length | 6 months | 1–6 (never more than the law) |
| Head and HR asked to evaluate | 30 days before the end | 7–60 |
| Training length for Trainees | **30 days (Claude's default, to confirm)** | 1–180 |

Both count from the hire date. A shorter probation applies to everyone still on probation.

## The evaluation (employee page → Probation card)

1. **The head evaluates** (`EvaluateProbation`). The head is from `App\Support\Heads`: Operations for a guard, HR for most office staff, and so on; the Super Admin is backup. HR may also evaluate, for example someone not registered yet. The result is **Pass** or **Doesn't meet the standards**, with the reasons (a fail needs at least a few sentences, since the employee receives it in writing). It is only possible before the last day has passed. HR is told to approve.
2. **HR approves or sends it back** (`DecideProbationEvaluation`). When HR evaluates, it counts as approved at once. Nobody decides their own probation.
   - **Pass, approved:** Regular **at once**, and the employee is told (bell and email).
   - **Fail, approved:** only up to the last day. The employee gets the **written notice** now, with the reasons and the last day. If they aren't registered, the card says to hand it over on paper. Employment ends after the last day.
   - **Sent back:** the head sees HR's note and may evaluate again.
3. **The daily check** (`ApplyProbationEnds`, in `app:send-probation-alerts` at 07:00) runs for everyone still Probationary after their last day:
   - **an approved fail** → **Terminated** (`applied_at`), and the account closes. HR is told to start final pay and clearance.
   - **anything else** → **Regular** by law. An evaluation still waiting for HR is closed ("ended before HR approved it"). The employee and HR are told.

Table `probation_evaluations` (migration `2026_10_24_100001`): outcome, reason, ends_on, status pending / approved / returned, evaluated_by/at, decided_by/at, decision_note, applied_at.

## Alerts

- **Probation:** HR **and the head** get `ProbationEnding` the rules' days before, and on the day. Nobody is reminded once a decision is approved. The old weekly "ended N days ago" reminder only happens now if the daily check didn't run.
- **Training:** a Trainee whose training length is up. HR and the head get a bell and an email on the day, then once a week while they're still a Trainee (`SendTrainingAlerts`, `training.due`, `training.overdue.N`). Nothing changes by itself: HR moves them on, usually to Probationary.

## Employment status on the record

- The list on Add / Edit employee offers only **Trainee, Probationary, Regular, Project-based** (`EmployeeProfileForm::statusChoices()`, checked on the server too).
- **Resigned** comes only from an accepted resignation (`ApplySeparations`).
- **Terminated** comes from a Notice to Explain decided as **Dismissal** (`DecideNotice`, audited `nte.terminated`, the account closes) or an approved failed probation.
- A record already Resigned or Terminated keeps that status listed (read from the database), so it can still be edited.
- Every status change on the record tells the employee (existing `SaveEmployeeProfile`).

## Privacy notice

"Performance records" (probation evaluations, merits and demerits, Notices to Explain) are now listed. Supervisors are said to see what they keep on their staff. Version **2026-10-24.1**. The same change replaced a leftover "the Executive" with the office staff's head.

## Open

- **Training counts toward probation (decided 2026-10-24, for counsel to confirm).** Probation runs 6 months from the first day of work (Art. 296), and paid on-the-job training under OSSI is work, whatever the label. So probation counts from the hire date, training included. The Trainee note on the employee page and the rules card say so, with probation's last day. A pre-licensing course taken **before** OSSI engages someone is not employment: add them when they start work, and that day is the hire date. Why this side: if it's wrong, someone becomes Regular a little early. The other way round, a "failed" probation in month 7 would be an illegal dismissal.
- **Project-based end dates:** not built yet. Ending a project employee needs its own decision: how to record "end of project" (it isn't a dismissal).

Tests: `tests/Feature/People/ProbationEvaluationTest.php`, `ProbationAlertsTest.php`, the dismissal in `NoticesToExplainTest.php`.
