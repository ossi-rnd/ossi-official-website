# 20. Guards' training

**Training** (`manage-training`: Admin, Super Admin) keeps training sessions (pre-licensing, refreshers, firearms, first aid) and who completed them.

## How it works

- **New training:** title, provider, first and last day, venue, **certificate valid until** (for example a yearly firearms refresher), and notes.
- **Attendees:** add guards one at a time (active field staff not already in that session), then mark each one **Enrolled**, **Completed** or **Did not complete**.
  - A result can't be marked before the training starts.
  - A guard can be in a session only once (a unique index backs this up).
- **Completed** trainings show on the guard's employee record (**Training** card), with the date and the certificate's validity.
- A session someone completed **can't be deleted**: it's part of their record.
- The list shows upcoming sessions first, then past ones, with "N / M completed".

Every change is audited (area **Training**).

## Guards ask for a training (My Training)

Guards often know first that they need a refresher, so they can ask (roadmap item 29, the training half).

- **My Training** (Personal menu, guards only; office staff get a 404 and ask HR): choose the training (**Firearms refresher**, **Training for license renewal**, **Pre-licensing course**, **First aid**, **Other**), an optional preferred start, and **why**. One open request per kind. Cancel while pending. The page also lists the sessions they're in or completed.
- **Admin is told** (bell and email), and the **Training** menu item shows how many are waiting. The **Training requests** card on Training lists them, oldest first, then the approved ones still waiting for a session.
- **Answer:** pick an upcoming session to **add them to it** (they're enrolled, as under Attendees), or **approve without a session** ("waiting for a session": the next time Admin adds them to any session, the request is linked and they're told), or **decline** with a note (required). A session that's over can't be chosen.
- **The guard is told** either way: "Training: you're in Firearms refresher – November · Nov 3, 2026 at Camp Crame", or why not.

Code: `FileTrainingRequest`, `CancelTrainingRequest`, `DecideTrainingRequest`, `EnrollTrainee` (links a waiting request), `TrainingRequest`, `TrainingRequestKind`, `TrainingRequestNotice`, `App\Livewire\Training\MyTraining`. Migration `2026_10_19_100003`. Tests: `tests/Feature/Operations/TrainingRequestTest.php`.

**Not built: inspection requests.** The roadmap item says "inspection / training request". Which inspection OSSI means (equipment or firearm, a post, or a scheduled check on guards) is still to be decided.

## Code

- `SaveTraining`, `DeleteTraining`, `EnrollTrainee`, `MarkTrainingResult`, `RemoveTrainee`
- `Training`, `TrainingAttendee`, `TrainingAttendance`
- `App\Livewire\Admin\Trainings`
- `EmployeeDetail::trainingRecords`

Tests: `tests/Feature/Operations/AdminModulesTest.php` (training).

## Try it

As `admin@example.test`: Training → **Annual firearms refresher** → Attendees. Four completed, one didn't. Open one of those guards under Employees: the Training card lists it.
