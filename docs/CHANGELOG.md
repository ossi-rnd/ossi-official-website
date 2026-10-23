# Changelog

What changed, why, and where to read more. Newest first. Each entry was built the same way: understand the existing code → small change → Pest tests → PHPStan (level 8) and Pint → a check in a real browser (Playwright) on a **scratch copy** of the database → docs.

## 2026-10-23

**SQLite: web and worker can write together ("database is locked" fixed)**
- **What happened:** on the 5,000-guard test copy, "Generate all" stopped at 0 of 4,911, three times: `SQLSTATE[HY000]: General error: 5 database is locked`. The worker was writing payslips while the page saved its session on every 2-second poll. SQLite allows one writer, and by default a second writer gave up at once.
- **The fix, in `config/database.php`, not `.env`:**
  - `busy_timeout` 5000: a writer waits up to 5 s;
  - `journal_mode` WAL: readers and the writer don't block each other;
  - `synchronous` normal: safe with WAL;
  - `transaction_mode` IMMEDIATE: a transaction takes the write lock as it starts, so two readers can't deadlock upgrading.
- **It matters for the real pilot too:** SQLite with a worker. The pilot was backed up first and its worker restarted.
- **Checked:** the same click on the copy, with the page polling throughout, finished all **4,839** remaining (5,000 payslips on the run) in **81 s**, faster than the earlier 146 s thanks to WAL. All 982 tests pass.
- **New rule:** back up and copy databases with `scripts/pilot/backup.php` (`VACUUM INTO`), never a plain file copy, because WAL keeps recent writes in `*.sqlite-wal`.

**Speed at 5,000 employees (load test on a copy of the pilot)**
- **The test:** 5,000 sample guards with 13 days each (65,000 time logs) and a Processing run, on a separate copy (`database/loadtest.sqlite`, to delete after). The pilot was untouched.
- **What was measured:** each page rendered with every query timed. The test server has PHP's code cache off (`opcache.enable_cli=0`), so whole-page times there are slower than a real server.

| Page | Before | After | What changed |
|---|---|---|---|
| Payroll run (5,000 people) | 1,813 ms | **335 ms** | "Who has no daily rate" loaded every employee, their rates and position history. Now the database first narrows it to people with no personal rate in force (a personal rate always wins), so at 5,000 it checks almost no one. |
| Attendance Log | 541 ms | **237 ms** | New index on `time_logs.clock_out_at` (migration `2026_10_23_100007`). Finding open shifts no longer scans every time log, and the attendance alerts, which run the same lookup every 5 minutes, benefit too. |
| Employees | 101 ms | 101 ms | Already fine. |

- **"Generate all" for 5,000:** 146 s in the background (about 29 ms per payslip, 1,000 every ~28 s). Fine as a background job.
- **Database:** the index migration was run on dev and the pilot after backups. The test copy was migrated too.
- **Tests:** 192 payroll and attendance tests, and the full suite, pass.

**Payroll: "Generate all" in the background, with a progress bar**
- **Why:** the owner asked about 2,000+ employees. "Generate all" built every payslip inside one click: fine for a few hundred, but at thousands the page would hang and could time out partway.
- **Now:**
  - The click starts a background job (`StartPayslipGeneration` → `GenerateRunPayslipsJob`), which builds payslips in batches of 100.
  - The run page shows a live progress bar ("640 of 2,104 · 30%"), and it's safe to leave the page.
  - Only one generation per run at a time, and **Approve run** waits until it's done.
  - Whoever started it gets a bell: "2,091 generated. 13 skipped: no daily rate".
  - If it stops, clicking again carries on where it left off.
- **Not needed:** time windows. One background worker doesn't slow clock-ins or other pages.
- **Already fine:** release bells and emails were already sent in the background.
- **Still to do:** Gmail's ~500 emails a day will need a transactional mail service at that size (on Laravel Cloud).
- **Database, safe order:** migration `2026_10_23_100006` (progress columns on `payroll_runs`) was added and run on dev and the pilot after backups, before the code used it.
- **Checks:**
  - 4 new tests (982 in all);
  - an end-to-end run with a real database queue worker on a throwaway copy (24 of 24, done, bell sent);
  - the pilot's worker restarted to load the new job.

**Accounting sees the Attendance Log**
- **Why:** payroll is computed from attendance (days, minutes late, overtime), so Accounting can now open the **Attendance Log**, with the same detail as HR (the owner's choice): selfies, addresses, map links. Their dashboard also shows today's attendance.
- **Unchanged:** editing the attendance alert rules stays with HR.
- **Privacy notice:** "Who we share it with" now says Accounting sees the attendance records behind payroll, photograph and location included. The version is bumped to **2026-10-23.1** (config and `.env.example`), so everyone acknowledges it again. **The owner's `.env` pins its own `PRIVACY_NOTICE_VERSION` and must be updated to take effect.**
- **Tests:**
  - a new one: Accounting opens the log and can view a punch photo;
  - two old ones updated: a Developer, not Accounting, is now the role refused a photo, and the Accounting dashboard now shows attendance.
  - 978 pass.

**Late deduction: exact minutes only; brackets removed**
- **The owner's question:** do we need late brackets? Their idea was daily rate ÷ hours, rounded up to a whole hour once past grace.
- **What was chosen:** **exact minutes**. The deduction is the minutes late after the grace period × the hourly rate (daily rate ÷ 8 ÷ 60), which was already the rule whenever no bracket was set.
- **Why not round up to the hour:** 5 minutes late would cost an hour's pay, which deducts time actually worked (Labor Code Art. 113, "no work, no pay"). Habitual lateness is handled with a Notice to Explain instead.
- **Removed:**
  - the **Late Deduction** page and its menu item;
  - `LateDeductionRule`, `LateBracket`, the Save and Delete actions;
  - the brackets parameter in `PayslipInput`;
  - the `late_deduction_rules` table (migration `2026_10_23_100005`).
- **No payslip changes:** dev and the pilot had no brackets set, so nothing computes differently. Both were backed up first, then migrated, and the pilot was checked (200).
- **Texts updated:** the payslip's late-days note, the demo FAQ, and the docs.
- **Tests:** the calculator test now checks that 5 minutes late costs 5 minutes, 61 costs 61, and so on. 977 pass.

**Deployment Costs: a map pin, not a tick, on the picked deployment**
- The locked "Which deployment" line now shows a grey map pin. A blue ✓ there looked like a checkbox, now that every checkbox has one, and a deployment is a place.

**Checkboxes and radio buttons: one look everywhere**
- **The problem:** the browser's own checkbox ignored our colours and showed a glaring white square in dark mode ("I certify these are true").
- **The fix:** `resources/css/app.css` now draws every visible checkbox and radio itself:
  - **Unticked:** a soft border, white in light mode and dark in dark mode; the border darkens on hover.
  - **Ticked:** filled blue with a white tick (a white dot for radios). The "Urgent" announcement box stays amber.
  - **Also:** a focus ring for the keyboard, and dimmed when disabled.
- **Untouched:** hidden boxes behind styled chips or switches (`sr-only`).
- **Why the rules sit outside Tailwind's layers:** a utility left on an input (`dark:bg-gray-800`) could otherwise cover the tick.
- **Also:** `.dark { color-scheme: dark }`, so date pickers and scrollbars follow dark mode.
- **Checked in a real browser:** dark and light, unticked and ticked.

**Deployment Costs: choices first, for every guard**
- **The owner noticed:** a guard with no roster duties in the window went straight to an editable box ("OSSI Head Office (in-house) can type hahaha").
- **Now the choices are always:**
  1. their roster duties;
  2. **their usual post**;
  3. **another client's post…**, a searchable client list (a Satellite sees its own region's);
  4. **"Not on any list? Type it"**.
- Whatever is picked is locked with **Change**. The text box appears only after choosing "Type it", which is kept for true one-offs, so no valid claim is blocked.
- **Tests:** 987 pass, and the screen was checked in a real browser at phone width.

**Deployment Costs: a picked duty is locked**
- **The owner asked:** why can it still be edited when it's a choice? Edited text could stop matching the roster duty, or the relief the claim is linked to.
- **Now, three states** (`deploymentMode`):
  - **Choosing:** the guard's roster duties, plus a last option "Not on the roster? Type it".
  - **Picked:** shown locked, "✓ Eastwood City, Gate 2, Oct 23 · Change", like the guard above it. Filing from a relief on Relievers opens it already picked.
  - **Typed:** a text box, with "Pick from their roster instead". A guard with no duties in the window starts here, prefilled with their post.
- Sending with nothing chosen says "Pick one of their duties, or type it if it isn't on the roster."
- **Checked:** 987 tests pass, and a real browser at phone width.

**Deployment Costs: pick the deployment from the guard's roster**
- **Before:** after choosing the guard, Operations typed "Which deployment" (or kept their usual post).
- **Now:** the form lists **their duties from the roster in the filing window** (60 days, newest first): their posts and the reliefs they covered. One tap fills both the deployment and the date spent. A relief whose fare isn't claimed yet is linked to that relief, so it's claimed once, as when filing from Relievers. Typing stays available for anything not on the roster.
- **Bug caught by the browser check:** today's duty was left out. `duty_date` is stored with a time ("… 00:00:00"), so comparing it with "2026-10-23" dropped today. It now compares by date (`whereDate`), and the test covers today and tomorrow. Nowhere else in the code compares these dates that way.
- **Tests:** 987 pass, and the screen was checked in a browser at 1280 and 390 px.

**Deployment Costs: one hint fewer**
- **Removed:** the line "Within 60 days of the trip. Up to ₱20,000 per claim. The guard is told." under the receipt on File a claim, at the owner's request.
- **Unchanged:** both limits are still checked when the claim is sent, with an error that names them, and the guard is still told.

**Issue equipment: a clearer form, with search**
- **To:** a searchable list. Each person reads "Name · Guard, Makati CBD" or "Name · Office staff, Accounting", so typing a post or a role finds them too (`personNote`). Issuing from a request shows the person, fixed.
- **What:** an **An item / A radio** switch with icons, replacing the two radio buttons.
- **Item:** searchable now (radios already were). Once picked, a preview shows its photo, its kind, size chips, a **− 1 +** stepper and the total.
  - A new item clears the size picked for the last one.
  - The price shows only once a size is picked, since a size can cost more. A test caught the form showing the base price first.
  - Hints appear when no radio is in stock, or no item is meant for that person.
- **Dialog height:** the form has a minimum height, so an open list isn't cut off inside the dialog.
- **Checked in a real browser** at 1280 and 390 px. The throwaway database and photos were deleted afterwards.
- **Tests:** a new test drives the form (27 equipment and dark-mode tests pass).

**Uniforms: Operations hands over, Accounting sets the charge (final)**
- **The owner's question:** only Accounting should decide deductions, so why does the issuer type "Deduct per payslip"?
- **Chosen:** Operations (or Admin for office staff) approves and hands the uniform over, with no money fields. Accounting then sets how much each payslip takes, and that is final (`SetUniformCharge`, ability `manage-payroll`). The guard then authorizes the deduction on My Payslips, and nothing is taken before that.
- **Why this order:** the guard isn't kept waiting for a uniform they may need before their first duty, and the money stays with the people who see the whole payslip.
- **Accounting's side:** a bell for each uniform handed over, a "Uniform charges to set" card on Equipment, and the badge counts them.
- **Issuer's side:** their list shows "Charge not set yet (Accounting)" until it's done.
- **Tests:** 23 equipment tests (985 in all) pass.

**Shop: a cart icon on "Add to cart"**
- **Add to cart** and the phone bar's **Review cart** now show a trolley (the new `cart` icon). Elsewhere **+** means "create something new" (Add item, Add radio), so it doesn't fit here. The quantity stepper keeps its − / +.

**Sample pictures: proper line breaks**
- **Before:** "Guard polo (Type A)" broke into "Guard polo (Type" / "A)", because the old line break counted characters.
- **Now:** a name stays on one line when it fits. When it doesn't, it splits between words where the two lines come out most even, and the text shrinks if a line is still too wide. The text block is centred on the card.
- **On the pilot:** after a backup, running the seeder again redrew the 10 pictures under new file names, so browsers fetch them instead of showing the cached ones. The old files were deleted. Only the drawn sample pictures are ever redrawn, never a photo Admin uploaded.

**Shop: responsive cards (the owner: "it's not responsive")**
- **The cause:** the shop sits beside the cart inside a page capped at 64rem, so on a wide screen it is only about 46rem wide. The screen-size rule still put three cards across, too narrow for the stepper and the button.
- **Now:**
  - Columns follow the space the shop itself has (Tailwind container queries, `@container` with `@lg:` / `@4xl:`): one column on a phone, two beside the cart, three when there's room.
  - **Add to cart** has its own full-width row under the quantity stepper.
  - Uniforms are listed first.
  - On a phone or tablet, a bar fixed at the bottom of the screen shows "N items in your cart" and **Review cart** jumps to it, since the cart comes after every item there.
- **Checked in a real browser this time:** Playwright on a throwaway seeded copy, at 1440, 900 and 390 px wide, in dark and light. No sideways scroll at any width. The throwaway database and its photos were deleted afterwards.
- **Slip, caught by that check:** the first screenshots still showed one huge column, because I hadn't rebuilt the CSS after adding the new classes. Rebuilt, then checked again.

**Shop cards: size chips and a quantity stepper**
- **Before:** the dropdown and the narrow number box were cramped on a card (the owner's screenshot).
- **Now:** every size is a chip you tap, and the selected one is highlighted the same way as the shift-hours chips. Quantity has a **− 1 +** stepper (1 to 5), with **Add to cart** beside it.
- **How:** two small methods, `pickSize` and `pickQuantity`. The test now uses them.

**Sample items for the equipment shop (pilot)**
- `EquipmentSampleSeeder` adds 10 items whose names start with "SAMPLE ", each with a plain drawn picture:
  - for guards: polo, trousers (waist 28–40), combat boots (38–46), cap, raincoat, flashlight and nightstick;
  - for everyone: whistle and lanyard;
  - for office staff: polo and ID lace.
- The prices are my sample numbers, for looks only. Accounting can change them.
- Running it again adds nothing. Picture names are random, because the dev database and the pilot share the storage folder.
- The pilot was backed up first, then seeded (10 added). A test covers it.

**Equipment shop: browse, cart, one request**
- **My Equipment** now has a **Shop** tab: cards with photo, name, sizes and price; a cart (size and quantity per line, kept for the session); **Send request** sends the whole cart as one request. The issuers get one bell listing everything.
- **Who sees what:** Admin marks each item for **Guards**, **Office staff** or **Everyone**. Guards see only guard items and shared ones. The rule is enforced on request and on issue, and the issue form offers only matching items.
- **Photos:** Admin uploads one per item (JPG, PNG or WebP, up to 3 MB). It's kept private and shown only to signed-in staff; replacing it deletes the old file.
- **Database, safe order:** the new columns (`audience`, `photo_path`) were added, backed up and migrated on dev and the pilot before any code read them.
- **Tests:** 2 new (983 in all).
- **Bug caught by a test:** after "I received it", the page jumped to the Shop tab because nothing was waiting any more. It now stays on My items.
- **Not done this time:** a check in a real browser. The tests drive the pages, but I haven't looked at the cards' layout on a phone.

**Equipment: sizes per item, and Accounting sets the prices**
- **Sizes:** each item has its own sizes. Admin ticks standard ones (XS to 5XL, or All) or adds custom ones: `42`, `W32/L30`, `Free size`, several separated by commas, or a range like `38-45`. This replaces the "Comes in sizes (XS to 3XL)" tick; items that had it keep XS to 3XL.
- **Prices are Accounting's** (new ability `set-equipment-prices`; the owner's choice): the item's price, plus an optional price for any size that costs more. Admin sees prices but can't change them.
- **A uniform needs a price for every size before it can be issued.** Accounting gets a bell when one is missing, and their Equipment badge counts them.
- **Issued items keep their price:** a later price change applies only to new issues.
- **Database changes, done in the safe order this time:**
  1. added `sizes` (additive), backed up, and migrated dev and the pilot before any code read it;
  2. switched the code;
  3. dropped `has_sizes`, again after backups and on both databases.
- **Tests:** 3 new tests, 981 in all.

**Equipment: uniforms, radios and gear, with authority to deduct and accountability** ([34](features/34-equipment.md))
- **Who issues** (the owner's choice): Operations issues to the guards it deploys (a Satellite: its region's); Admin issues to office staff and keeps the item list and prices. New abilities: `issue-equipment` and `manage-equipment-items`.
- **Every uniform is charged:** its price becomes a deduction waiting for the employee's authority to deduct on My Payslips (Art. 113). Nothing is taken until they authorize it.
- **Company property** (radios, flashlights…): the employee confirms receipt and accountability, word for word in English or Filipino. Nothing is deducted.
- **Damage or loss** is charged only after due process, following DOLE's conditions for loss or damage deductions:
  - the employee has 5 days to explain;
  - their head (`Heads`) decides, with reasons;
  - the charge is the actual loss, with how it was arrived at;
  - each payslip takes at most 20% of a week's wages.
  
  A new deduction basis, "Loss or damage, after due process", is used for this; Accounting's own picker doesn't offer it.
- **Requests:** employees ask on **My Equipment**; the issuers are told and either issue the item or turn it down with a reason.
- **Radios** issued here update the masterlist.
- **Clearance and final pay:**
  - the resignation clearance now lists unreturned gear as well as radios ("Company property still with them");
  - final pay now collects any deduction with a balance left, so a uniform's unpaid balance is included. Before, it looked only at loans, cash advances and load, and those were the only ones with balances.
- **My defaults, to confirm:** 5 days to explain (as for a Notice to Explain), and a 6-day week for the 20% limit. **For the owner to check with DOLE or counsel:** charging guards for every uniform.
- **Tests:** 17 new (979 in all).
- **Slip, caught and fixed:** the menu code that reads the new tables was saved several minutes before the pilot was migrated, so pilot pages could fail in that window. The Radios lesson again. The pilot was then backed up, migrated and checked (login page 200).

**Dark mode: no more white flash on hover**
- **The bug:** the "Common hours" chips on the shift picker turned white when hovered in dark mode. They had a light hover (`hover:bg-gray-50`) and no dark one, written inside an Alpine `x-bind:class` string.
- **New check:** `DarkModeColorsTest` now fails on any light hover background (`hover:bg-white`, `-50`, `-100`, `-200`) without a `dark:hover:bg-…`, in `class="…"` and in class-only strings. See-through white on the photo lightbox is allowed, since that overlay is dark in both themes.
- **It found 12 more:**
  - the "Choose file" area;
  - the icon buttons on Holidays, Attendance History, Payroll and Contributions;
  - a Leave Requests button;
  - the Organization chips;
  - the avatar upload button.
- **The fix:** all of them now have a dark hover, and the chosen chip darkens slightly on hover. CSS rebuilt.

**Demo accounts: President and Vice President, no more "Executive"**
- **The accounts:** the seeded `executive@example.test` is now `president@example.test`, and there's a new `vp@example.test`. Neither has an employee record, matching the real accounts. The old account also carried the retired "President" position.
- **Docs updated:**
  - the study guide's account table, with each role's line matching the chain of heads;
  - every "Try it" step;
  - `docs/README.md` and CLAUDE.md;
  - the seeders' messages.
- **No real data touched:** the change only affects a fresh `migrate:fresh --seed`. The dev and pilot databases have no demo accounts.

**201 file: "Only what needs action" shows what it does**
- **The problem:** the owner ticked the box and nothing changed. That was correct: on a new 201 file nothing is on file yet, so every document needs action and the filter hides nothing.
- **The checkbox now shows its count:** "Only what needs action (20 of 21)".
- **It's offered only when it would change the list:** some documents are done and some aren't, or it's already ticked (so it can be turned off).
- **Test:** a new test checks both behaviours (`RequirementsTest`).

**Operation HQ and Accounting answer to the President or VP**
- **Owner's choice:** in a security agency, Operations and Accounting usually report to the President / GM, and HR administers leave rather than heading other departments.
- **The chain now:**
  - guard → Satellite + Operation HQ endorse, HR decides (no change);
  - HR, **Operation HQ, Accounting** → the President or VP;
  - Satellite → Operation HQ;
  - Admin and others → HR.
- **What changed for these two roles:** the President or VP now decide their leave and overtime, approve their time away, and issue their Notices to Explain and merits.
- **HR stays informed, as timekeeper:**
  - HR sees every non-HR staffer's leave, overtime and time away, view only (`Heads::constrainOfficeStaffSeenBy`).
  - HR is copied on attendance alerts for Operation HQ, Accounting and Satellites (`Heads::informed`, `Heads::toTell`).
- **Screens:** the tab hints now read "HR, Operation HQ and Accounting staff's are yours to decide" for the President or VP, and "Admin and other staff's are yours to decide" for HR.
- **Tests:** tests that used an Accounting clerk as "someone HR heads" now use an Admin staffer, and a new test covers Accounting and Operation HQ going to the President or VP. 960 pass.

**Tests for who is above whom (`ChainOfHeadsMatrixTest`)**
- **What it checks:** each head can act for their own people in **every** flow at once: deciding leave and overtime, approving time away, issuing a Notice to Explain, giving a merit. Nobody can act **upward** (Accounting → HR, a Satellite → Operation HQ), **sideways** (HR → another HR, Admin → Accounting, one Satellite → another), **across the chain** (HR → a Satellite, Operation HQ → Accounting; the President or VP only monitor non-HR staff), or **for themselves**.
- **Also covered:**
  - A guard's leave still climbs Satellite → Operation HQ → HR, each only at their own step, and a Satellite only for their own region.
  - The Action refuses an upward decision even without the screen.
  - A resigned head stops being told.
- **Fixed, found by the new tests:** the President, VP and Super Admin have no employee record, so `Heads` put them in the default group and counted HR as the President's "head". No screen could reach it, since they can't file requests or be picked for a notice. Now nobody heads them (`Heads::isTopOfChain`). 951 tests pass.

## 2026-10-22

**One chain of heads for everything (`App\Support\Heads`)**
- The owner's chain now drives every "who supervises whom":
  - **The chain:** a guard → Operation HQ + their region's Satellite; HR → President / VP; Satellite → Operation HQ; other office staff (Admin, Accounting, Operation HQ…) → HR.
  - **Before**, all office staff's leave, overtime and time away went to the President or VP. Now the **head decides**: HR decides Admin, Accounting and Operation HQ's leave and overtime; Operation HQ decides a Satellite's (no endorsing on to HR, as for a guard); the President or VP decides HR's. The President or VP still see every office staffer's requests and time away (they monitor), in a tab marked "HR staff's are yours to decide".
  - **Time away:** Office Away is open to HR and Operation HQ for the people they head. Only the head approves, and time-away alerts go to the head.
  - **Notices to Explain and merits:** a Satellite's are issued by Operation HQ, not HR. HR still reads them.
  - **Visibility:** Operation HQ, otherwise guards-only, now also sees the Satellites it heads (their records, requests and notices).
  - **Lists:** Leave / Overtime Requests show **Office staff / Guards** tabs to HR and Operation HQ too (guards' tab first for them), and each screen names the head: "Their head decides it: HR.", "Goes to HR automatically", "Location shared with HR".
- **Code:** one class answers "who heads this person" (`Heads::of`, `label`) and "does X head Y" (`isHeadOf`). `LeaveRouting`, `RoutedByStaffKind`, `ReviewLeaveRequest`, `ReviewOvertimeRequest`, `SubmitOvertimeRequest`, `ApproveAway`, `AwayMonitor`, `SendPatrolAlerts`, `CloseForgottenAways`, `NoticeToExplain::canIssue`, `MeritRecord::canGive` and `SendAttendanceAlerts` all use it. `NotifyExecutives` is gone. New test `HeadsTest`; tests that checked the old rule were updated to the new one (917 pass).
- **Slip, caught and fixed:** a helper script that couldn't find where to add an import still rewrote `NoticeToExplain.php`, and PowerShell garbled its "·" into "Â·". A test caught it ("No explanation Â· to decide"). The file was repaired exactly, and `git diff` confirmed only the intended lines changed.

**Attendance alerts go to the person's own head (the owner's chain)**
- A missed clock-out, and an office staffer who hasn't clocked in, now alert the person's actual head (`SendAttendanceAlerts::headsOf`):
  - **HR** → the President or VP
  - **Admin, Accounting, Operation HQ and other office staff** → HR
  - **Satellite Operation** → Operation HQ
  - **Guards** → both Operations (Operation HQ and their region's Satellite)
- The "not clocked in" summary is now one bell **per head**, naming only the people they head. Before, every office alert went to the President or VP.

**Attendance alerts: missed clock-outs and office staff not clocked in**
- Before, only a guard missing a rostered duty raised an alert (a no-show, to Operations). A forgotten clock-out showed only on the Attendance Log, and an office staffer who never clocked in told nobody.
- Now `app:send-attendance-alerts` runs every 5 minutes (`SendAttendanceAlerts`):
  - **Missed clock-out:** the employee is reminded first ("clock out now, or tell your supervisor the real time"). If it's still open later, their **head** is told: Operations (Operation HQ and the region's Satellite) for a guard, the President or VP for office staff.
  - **Office staff not clocked in** on a work day (not on approved leave, not a holiday): the employee is reminded, and the President or VP gets **one summary bell per batch**, not one per person.
  - Each alert goes once (`sent_alerts`).
- The times and office work days are **HR's rules in the app**: Attendance Log → **Alert rules** → Change (`SaveAttendanceAlertRules`, audited). The starting values are my defaults, to confirm: remind after 30 min, tell the head after 120 min, office clock-in reminder 30 min after the grace period, office days Mon–Fri. Office staff have no roster, so the work days are how a rest day is told from a missed day.
- Test: `AttendanceAlertsTest`.

**GitHub CI fixed: emails had no sender**
- The first CI run on GitHub failed: 36 of 897 tests stopped with "An email must have a From or a Sender header". On GitHub, `.env` is a copy of `.env.example`, whose `MAIL_FROM_ADDRESS=` was **blank**. A blank value isn't "missing", so the fallback in `config/mail.php` never applied. It passed on the office PC only because the real `.env` sets the address. Now `phpunit.xml` sets `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME` for tests, and `.env.example` has a placeholder address. I found it by running the committed code in a fresh clone, the same way CI does (`composer setup`, then `composer ci:check`).

**Ready for GitHub and a second machine**
- `CLAUDE.md` (project root) is now the project's memory for Claude on any machine. It covers what the system is, the owner's standing rules, "done means", the safety rules learned the hard way, the pilot, how to move to a laptop, and the open items. It used to be git-ignored; it's committed now.
- `.gitignore` also excludes, explicitly: every `*.sqlite` (the dev database, the pilot database and all backups), `database/pilot-backups/`, uploaded files in `storage/app/private`, and logs. A dry run of `git add` lists 959 files, with no databases, `.env`, uploads, logs, `vendor` or `node_modules`. The app key and the Gmail app password are only in `.env`.
- `composer setup` ran `php artisan key:generate` every time, which replaces an existing `APP_KEY`. On a machine with a real `.env`, that makes every encrypted field (government IDs, dates of birth, medical notes) unreadable and signs everyone out. It now generates a key only when `APP_KEY` is empty, and says "kept" otherwise.
- `.env.example` had the privacy notice pinned to `2026-10-13.1`; it's `2026-10-22.1` now. The README points to CLAUDE.md and warns: a copied database needs the same `APP_KEY`.

**Notification audit: everyone affected is now told**
- I checked all 172 Actions for "someone other than the actor is affected and never told". There were 20 gaps, and each now sends a bell (and an email where it matters) through one general notice, `UpdateNotice::tell()` / `tellProfile()`. It never notifies the person who made the change, or anyone who no longer works here.
  - **Guards:** moved to another client, post or shift; a duty set, changed or removed, or the week filled (one bell); attendance recorded for them by hand (times, by whom, why); a radio issued to them.
  - **Pay:** a new daily rate; 13th month released (each person's amount); a deduction paused, resumed or removed; a cash bond entry; employment status changed (e.g. Regular).
  - **Training:** a session moved or called off; removed from one; graded.
  - **Everyone:** a company policy added or its text changed; their access (role) changed.
  - **Staff:** HR when someone registers; the Accountant when the Super Admin approves or rejects their large adjustment; a new petty cash custodian; a cancelled change of position (to the employee).
- The full map of who is told what, by role, is in `docs/features/33-notifications.md`. Test: `UpdateNoticesTest`.

**Set the role of someone who hasn't registered yet**
- Roles belong to accounts, so Roles & Access listed only people who had registered: an office staffer HR had just added (e.g. "Admin", 261022CAV-8396 on the pilot) couldn't be given a role. Roles & Access now has a **Not registered yet** list (everyone HR added who hasn't created an account, office staff first) with **Set role**. The role waits on the record (`employee_profiles.pending_role` / `pending_region`, `SetPendingRole`, audited) and `ClaimEmployeeAccount` gives it to the account the moment they register. Same rules as for accounts: a Satellite needs its region, and Super Admin / President / VP never go to someone with an employee record. Migration `2026_10_22_100007` ran on dev and the pilot (backed up) before the code that uses it.

**Error pages are always full screen**
- A failed Livewire request (clicking a tab, saving a form) showed our error page inside Livewire's pop-up window, over a half-hidden page. `resources/js/error-pages.js` (`Livewire.interceptRequest` → `onError`) now replaces the whole page with it, so a 403, 404, 419, 500 or 503 looks the same whether it came from a page load or a click. An empty response still gets Livewire's "refresh the page?" prompt.
- The 500 behind this (Radios, 4:26 PM): the "Retired" → "Disposed" rename went live on the pilot a few minutes before its migration ran there, and a radio still marked "retired" broke the page until the migration ran at 4:30. Lesson: the pilot runs this folder live, so a change that needs a migration is now migrated on the pilot in the same step.

**Operation HQ sees guards only, everywhere**
- Operations looks after guards: their dashboard already counted guards only, and office staff's leave, overtime and time away go to the President or Vice President. Three places didn't follow that: the Employees filter still offered *Office staff (no client)*, the Attendance Log showed office staff's clock-ins, and an office staffer's record opened by its link. The one visibility rule (`User::scopeVisibleTo`, `EmployeeProfile::scopeVisibleTo`, `User::canSeeProfile`) now hides office staff from anyone without `view-office-staff`, so every page follows it. HR, Admin, Accounting and the President / VP are unchanged. Test: `OperationsSeeGuardsOnlyTest`.

**Four new features: birthdays, petty cash, the radio masterlist, referrals**
- **Birthday reminders.** A *Date of birth* on the employee record (encrypted, since it reveals age, and audited as "(changed)"; must be 18 or over). Each morning (`app:send-birthday-reminders`, 7:15) HR gets a bell for whoever has a birthday, and for a guard so do Operation HQ and the region's Satellite. The dashboard shows *Birthdays this month*. Only the day and month are shown, never the age. *Keep their birthday private* turns it off for anyone who objects. The privacy notice now lists date of birth as sensitive data and explains the reminder (version **2026-10-22.1**).
- **Petty cash** (imprest). Accounting sets up a fund (amount and custodian). The custodian logs each expense with its receipt, and the cash on hand is computed (fund − not yet replenished). They then *Ask to replenish*, and Accounting releases it (the fund is whole again) or returns it with what to fix. The President and VP can view. Audited under a new **Finance** area.
- **NTC radio masterlist** (*Radios*, Admin and Operation HQ): brand, model, serial, frequency, NTC permit and expiry, status, and where it is (post or guard). Permit alerts go out 60/30/7/0 days before expiry (`app:send-radio-permit-alerts`, 7:20; email from 30 days). The menu badge shows permits due. A resignation's clearance names any radio still with the person.
- **Referrals (hiring perks).** *Refer a Friend* for employees, and *Referrals* for HR, who marks each one hired (linked to the record) or not hired. The bonus and the days are HR settings, frozen at hiring. The bonus is paid on the referrer's payslip as a *Referral bonus* line once the hire has stayed (none if they left first).
- **My defaults, to confirm:** referral bonus ₱1,000 after 90 days (set the real ones in the app); "Running low" on a petty cash fund below 25%; birthday reminder at 7:15 and radio alerts at 7:20; and the referral bonus is treated as not taxable, as part of the ₱90,000 13th month and other benefits ceiling.
- The Radios menu icon is a handheld two-way radio (walkie-talkie), not an FM radio.
- The radio status "Retired" is now **Disposed** (sold, scrapped or returned to the supplier), which is clearer for staff and says it's no longer the agency's. Existing rows were renamed by migration `2026_10_22_100006`.
- Fixed while testing: a petty cash expense dated later today was refused as "in the future"; the check now compares days only.

**Security Manager is no longer a position**
- Office jobs now match the roles: Admin, HR, Accounting, IT, Operations Head, Satellite Operations Officer. Security Manager is gone from the Add employee list, the staff CSV import (which also stopped accepting the retired President and Vice President jobs: `Position::offered()`), and Daily Rates' rate per position, where a retired position appears only while someone still holds it. The enum case stays, so an older record still reads.

**Government IDs: digits only, in the official format**
- SSS (10 digits, 00-0000000-0), PhilHealth PIN (12, 00-000000000-0), Pag-IBIG MID (12, 0000-0000-0000) and TIN (9, 000-000-000, plus a 3- or 5-digit branch code if the card shows one). The fields drop letters and add the dashes as HR types (`resources/js/government-id.js`), open the number keypad on phones, and show the pattern as a placeholder. On save, `App\Support\GovernmentId` tidies spaces and dashes, and refuses letters or a wrong length with a plain message. Blank is still allowed. Older records with a number in another format must be corrected the next time they're edited.
- **Clean input everywhere else that needed it** (an audit of every form field):
  - Phones: the employee's own contact number must be a Philippine mobile (it feeds load requests). The emergency contact and the client's contact number take a mobile or a landline with its area code. Mobiles are saved as `0917 123 4567` however they were typed (+63, dashes). The fields keep only digits, spaces, `+ ( ) -`, and open the phone keypad (`PhilippineFormats::readableMobile` / `phoneNumber`).
  - Names (employee, emergency contact, client contact person, Account Settings): letters only, plus `. , ' -` (ñ and accents are fine). Stray spaces are tidied on save (`PhilippineFormats::NAME_PATTERN` / `name()`).
  - Employee number: the ID-card field adds the dashes as HR types (`employee-number.js` was still shaping the old 260627-PAM-0950 form, and wasn't used). On Register it's capitalised as typed, and a number typed without its dashes now still matches (`EmployeeNumber::normalize`). Older numbers are untouched.
  - Placeholders added (address, names, phones), and a minimum of 0 on the Tax Settings rates and peso amounts and 2000-2100 on the 13th-month year.
- Guide: five quiz questions used `''` as an apostrophe, which breaks the quiz's JavaScript. They now use `\'`.

**Readable hover in dark mode**
- Hovering a "ghost" button (e.g. **Set date** on the 201 checklist) turned its text dark gray on a dark background, nearly invisible. The shared button style now hovers to white in dark mode, and 14 other spots with the same problem (back links, tab buttons, the away monitor's arrows, Attendance History and clock-panel toggles, announcements) got a light dark-mode hover. `DarkModeColorsTest` now also fails if a view darkens text on hover without a dark-mode hover.
- **Slip, caught and fixed the same day:** the first pass of that sweep matched quoted text across two separate strings and inserted the class inside code in 7 views (the employee page's status badge, My Payslips, Payroll, Patrols, the away monitor's Today control, plus two sentences), so the employee page briefly showed an error on the pilot. Every insertion was listed and repaired by exact replacement, the sweep was redone on real class lists only (`class="…"` and strings made purely of class names), and the test checks the same way. Full suite (827) passes, including the page-render tests.

**My Punch Log grouped by shift** ([06](features/06-attendance.md))
- An employee's Punch Log was one row per punch. Now it's one row per shift under day headings, like the Attendance Log supervisors see: clock-in and clock-out side by side, each with its selfie (tap to enlarge), time, place and map link, plus lateness / leaving early in words; cards on a phone. Hours and status aren't repeated (they're on Attendance History). An old shift with no clock-out says "No clock-out recorded" instead of "Not clocked out yet". Tested, including in a browser at laptop and phone sizes.

**Full-screen map for placing a post or office**
- The location map on the client and office forms has a **Full screen** button: the map fills the screen (browser full screen; on an iPhone it covers the screen), with mouse-wheel zoom, so the pin can be placed precisely; **Done** or Esc goes back to the form with the coordinates filled. Tested on laptop and phone sizes.

**OSSI offices for office staff** ([17](features/17-clients-and-regions.md#ossi-offices-for-office-staff))
- New **OSSI Offices** page (Management menu; Admin and Super Admin): head office and branches, with the same location picker as clients (GPS at the site, map pin, geofence circle). Kept apart from clients so offices never appear in guard deployment, the roster or the field side of the Organization chart.
- HR picks an **Office** on an office staffer's record (where guards pick a client site). Office staff with an office must now **clock in inside its circle**, like guards at their post; with no office set they can still clock in anywhere. The away map uses their office.
- Migration `2026_10_22_100001` (dev backup `database-before-offices.sqlite`; pilot backed up first). The employee form's hint now says Operation HQ or Admin adds clients. Tested: who can manage offices, offices kept out of client lists, assigning and clearing an office, clock-in refused 3 km away and accepted at the office, the away map, delete refused with staff, and the page in a browser.

## 2026-10-21

**Set a client's post from the site, or on a map** ([17](features/17-clients-and-regions.md#setting-the-posts-location))
- Add / Edit client now has **Use my current location** (the phone's GPS at the post, with its precision and a warning when rough) and a **map with a draggable pin and the geofence circle** (click or drag to adjust; the circle stays fully in view). Pasting from Google Maps still works and moves the pin. Same saved format as before. Leaflet loads only with the form (shared `leaflet-loader.js`).
- Tested in a browser: GPS fill, drag, paste far away (the map follows), geofence size change (circle refitted), editing an existing client (pin and circle on open), and saving a point picked on the map.

**One role per person on Roles & Access** ([02](features/02-roles-and-gates.md))
- The Change roles window used checkboxes (several roles at once). Now it's **one** role (radio buttons, new `x-ui.radio`) or "No extra role" for a regular employee; "Employee" is no longer a choice: it stays automatic for anyone with an employee record. Accounts that had two job roles show a warning, and saving keeps the one picked. **What each role can open is unchanged.**
- Role descriptions: Operation HQ now says it sets up clients. Checkboxes and radios no longer shrink beside a long description (Developer and Employee looked bigger and different).

**Operation HQ sets up clients** ([17](features/17-clients-and-regions.md), [02](features/02-roles-and-gates.md))
- Clients were Super Admin only. Now **Operation HQ** and **Admin** add and edit them too (Admin added on request the same day) (new ability `manage-clients`; the Super Admin keeps it), so the order is: Operation HQ adds the client and its location, HR onboards the guard to it, Operations runs the roster and relievers. Satellite Operation, below Operation HQ, doesn't. Clients moved from the System menu to **Management**, next to Duty Roster. Tested: Operation HQ adds a client; Satellite Operation and HR are refused.

**Other IDs on the employee record** ([05](features/05-people.md#other-ids))
- Under Government IDs, HR can add any other ID as rows of name and number (**Add another ID**, remove per row, up to 10), with suggested names such as Security Guard License (LESP), Driver's License, Passport, UMID. Encrypted at rest and kept out of the audit log like SSS/TIN; masked on the employee page, in full on the employee's My Profile.
- Migration `2026_10_21_100005` (dev backup `database-before-other-ids.sqlite`; pilot backed up first). Privacy notice: new lines for other identification and its basis (same pending version `2026-10-21.1`). Tested: saving, blank rows dropped, a name without a number refused, encryption, masking, and adding/removing rows in a browser.

**Photo viewers close only with ✕ or Esc**
- The big-photo views (selfies in the Attendance Log and the Punch Log, patrol scan photos) closed on any tap outside the photo, easy to do by accident on a phone. Now only ✕ (bigger tap target) or Esc closes them, like the forms. The patrol photo had no ✕ at all; it has one now. All three are announced as dialogs to screen readers. The ✕ is a light circle that brightens on hover (its old black-on-black hover couldn't be seen) and shows a ring when reached with the keyboard. Tested on a phone-sized screen: a tap outside leaves it open, ✕ and Esc close it.

**Faster pages (Lighthouse Performance was 61)** ([25](features/25-ui-conventions.md#heavy-libraries-load-on-demand), [11](features/11-deployment.md))
- `app.js` loaded on every page was 568 KB: Chart.js, Leaflet and jsQR were all in it. They now load only when a chart, a map or the fallback QR reader needs them: `app.js` is 89 KB (28 KB compressed). Nothing looks or works differently; charts and the away map were checked in a browser.
- Pilot only: `scripts\pilot\router.php` makes PHP's built-in server compress (gzip) pages, Livewire responses and the built CSS/JS, and lets browsers cache the built files for a year (their names change with their content). The dashboard's HTML went from 292 KB to 25 KB on the wire; a second visit downloads no scripts or styles. Photos and downloads are untouched; a `../` path can't reach outside `public\build`. Production (Laravel Cloud) compresses and caches on its own.

**Locations open in Google Maps**
- A punch's coordinates (Attendance Log Details, My Attendance's Punch Log) opened OpenStreetMap; they now open Google Maps (`TimeLogPunch::mapUrl`, the official `maps/search/?api=1&query=` form): satellite and 3D view, Street View, and the Maps app on a phone. Away-from-post locations already used Google Maps and now use the same link form.
- Privacy notice: the transfer paragraph now says that opening a location on a map sends its coordinates (only) to Google Maps. Same notice version as today's phone line (`2026-10-21.1`).

**Phone brand and model on punches**
- The phone shown in the Attendance Log, alerts and the employee page now names the brand and model when the browser gives it: "Samsung SM-A156E · Chrome" instead of "Android · Chrome". Chrome, Samsung Internet and Edge on Android give the model (asked for separately, since Chrome stopped sending it by default); iPhones and Firefox don't, and stay "iPhone · Safari". The brand is worked out from the model code (Samsung, OPPO, realme, vivo, Xiaomi, Huawei, …). Tested in a browser posing as a Samsung A15. Phone naming by Operations/HR was left out on purpose for now.

**One company phone per guard (flagged, not blocked)** ([06](features/06-attendance.md))
- A guard's first clock-in registers their phone. A clock-in or clock-out from another phone still counts, is marked **Other phone** in the Attendance Log (new tab with a count), and rings the bell for Operation HQ, HR and the region's Satellite Operation, once a day per phone; every one is in the audit log. Office staff's phones are recorded but never flagged.
- How a phone is recognised: a website can't read the IMEI, so the app keeps a random code in an encrypted, HttpOnly cookie on the phone (only its hash is stored) and notes the phone type ("Android · Chrome"). Clearing the browser's data or switching browsers looks like a new phone.
- New permission **Manage guard phones** (Operation HQ, HR, Super Admin; a Satellite Operation for its region): **Make this their phone** from a flagged punch, and **Forget phone** on the employee page (lost phone, or a new one handed out).
- Privacy notice: new line about the phone; notice version default `2026-10-21.1`. Migration `2026_10_21_100004` (dev backup `database-before-guard-phones.sqlite`; pilot backed up first). Tested end to end in a browser (phone A registered, phone B flagged, bell sent).

**Clock-in record now matches the photo** ([06](features/06-attendance.md))
- The address saved with a punch could differ from the one printed on its selfie (photo: Saint Peter Street, Baesa; record: Road 18 Extension, Project 8). Two causes in the camera screen: the address was looked up at most every 15 s so it could belong to an earlier, rougher GPS fix, and Submit sent the live GPS/address instead of what Capture saw.
- Now Capture takes one snapshot (position, accuracy, and an address looked up for exactly that position, fetched on the spot if needed: the button says "Getting the address…"), prints it on the photo, and Submit sends that same snapshot. The live preview shows an address only while it belongs to where the phone is.
- Verified in a browser with a fake camera: capture at spot A, move the phone 100 m and get spot B's address, submit: the punch sent is spot A with spot A's address. Punches already saved keep their address; the photo shows the right one.

**Dark mode: readable blue and red** ([25](features/25-ui-conventions.md#dark-mode-coloured-text-uses-the-400-shade))
- Measured: in dark mode `blue-600` links were 3.4:1 on the cards and `red-600` 3.7:1, under WCAG's 4.5. Every coloured text now has a dark colour from the -400 shade (blue-400: 6.7:1) and a -300 hover: 190 class lists in 70 views, plus the active tab underline and the urgent-announcement icon. Light mode is unchanged.
- Checked in the browser on six busy pages (no coloured text under 4.5:1), and a new test (`DarkModeColorsTest`) fails if a view adds coloured text without a dark colour.

**Attendance Log: one row per shift, grouped by day** ([06](features/06-attendance.md))
- Was one row per punch (a clock-in and its clock-out far apart). Now each shift is one row: clock-in and clock-out side by side with their selfies, hours worked, lateness / leaving early / overtime in words, and the status. Days have headings (Today, Yesterday, then the date). **Details** shows both selfies, addresses, map links and GPS accuracy. On a phone, rows become cards.
- New views: **On duty now**, **Late**, **Not clocked out** (with counts). Whether an open shift is on duty or not clocked out comes from the shift's end, so night shifts past midnight aren't flagged; no new setting. Entries Operations made by hand are now listed too (the old log only showed punches).
- Grouped rather than coloured per punch type: colour stays for warnings.
- **Tooltips** (`x-ui.tooltip`) are now `hidden` rather than `invisible` until shown: an invisible one near the screen's edge made phone pages scroll sideways.

**Lateness reads in hours and minutes**
- "314 min late" is now "5 hr 14 min late" (`App\Support\Duration::minutes`: 60 → "1 hr", 45 → "45 min"; short form "5h 14m" for the duty roster's small cells). Used on My Attendance, the Attendance Log, the duty roster, a client's attendance today, and the payslip's Late line and its per-day table.

**"Daily rate needed" notice reads better**
- The bell broke the employee number at its dash ("261021NCR-" / "7534") and repeated the name from the title. Now: "Employee no. 261021NCR-7534, just added by HR." on one line, then "Set their daily rate before their first payroll." (`DailyRateNeeded`; the bell already shows one fact per line). The pilot's two existing notices were reworded in place (backup first).

**Pilot address is now https://ossi.tail4ab8aa.ts.net** ([11](features/11-deployment.md#troubleshooting))
- The machine was renamed `admin-w10-pc3` → `ossi` in the Tailscale admin console (free). The new address refused connections because the Funnel was still set up under the old name: `tailscale funnel reset`, `` updated in `pilot-env.ps1`, pilot restarted. Checked: the login page loads and its links use the new address. The old link no longer works.

**Test accounts for pilot testers without a real email** ([01](features/01-authentication.md#test-accounts-pilot-testers-without-a-real-email))
- On **Add employee**, HR can tick **Test account (no real email)**. The email is made from the employee number, e.g. `260627PAM-0950@test.invalid` (`.invalid` never delivers), and shown on the form as the number fills in.
- Registering with it confirms the email at once (no link to click), and no mail is ever sent to that address (`User::routeNotificationForMail()` returns null). In-app notices still work.
- It's an on/off **switch** (new `x-ui.switch`: a real checkbox with `role="switch"` drawn as a sliding track, so `wire:model`, the keyboard and screen readers work), not a checkbox.
- The email keeps the number's **capitals** (``261021NCR-7534@test.invalid``, the province code as on the ID card); testers can type it in any case to register or sign in. The pilot's one test record was updated. **Update:** the account keeps the capitals too (the header and Roles & Access showed `261021ncr-7534`): sign-in and the "email already used" check now match in any case, and Account settings doesn't treat a change of letter case as a new email. The pilot's registered tester was updated (backup first). An existing record also keeps its test-account setting whatever the browser sends (it used to lowercase the email if the switch was forced off).
- A **Test account** badge on Employees and the employee page; the choice is made when adding and an existing record keeps it.
- Migration `2026_10_21_100003` (`employee_profiles.is_test_account`); dev backup `database-before-test-accounts.sqlite`, pilot backed up first.

**Board of Directors: the President and Vice President only; the checkbox is gone** ([05](features/05-people.md))
- The "Member of the Board of Directors" checkbox on the employee form was confusing (who should be ticked when the owner is the President?). The Board box on the Organization chart now always shows the President and Vice President, taken from their roles, and nothing else.
- Migration `2026_10_21_100002` drops `employee_profiles.is_board_member` (dev backup `database-before-drop-board-flag.sqlite`, nobody was ticked; pilot backed up first, empty since the reset).
- **Pilot reset:** the pilot database was wiped at the user's request (backup `pilot-backups/pilot-2026-10-21-1005.sqlite`: 3 accounts, no employee data). `migrate:fresh` is refused in production mode (kept), so the file was replaced with an empty one and migrated. The Super Admin is created with `app:create-super-admin` through `pilot-artisan.ps1`.
- If other directors need showing later, the plan is a separate "Board members" list (names and titles), not a tick on employee records.

**Super Admin, President and Vice President have no employee number** ([02](features/02-roles-and-gates.md), [11](features/11-deployment.md#first-account))
- Company decision: these three are accounts **without an employee record**: no employee number, not on payroll, attendance or headcount, no "needs a daily rate" (the pilot's Super Admin was showing up there). `Role::withoutEmployeeRecord()`; the personal attendance and pay pages are closed to them, as for the President and VP before.
- **Creating them:** `app:create-super-admin email "Name"` asks for a password only; `--role=president` / `--role=vice-president` create the leadership accounts the same way (they can't register like staff).
- **Roles & Access** refuses to give these three roles to someone with an employee record: a person on payroll who also needs one gets a second account.
- **Organization chart:** the President and Vice President boxes come from the roles (no longer from a position), and **both are on the Board** automatically, plus employees HR ticks. President and Vice President are no longer offered as positions on the employee form.
- **My Profile** for these accounts no longer says "HR hasn't set up your employee record yet" (that was meant for new staff). It shows a plain note instead: "This is a Super Admin account, not an employee: it has no employee number, and isn't paid or clocked in through the system. It can still open and manage every part of the system." For the President or Vice President, the last line reads "It still has all its President (or Vice President) access and approvals." The first wording ("isn't on payroll or attendance") sounded like the account couldn't open those pages, so it was reworded.
- Migration `2026_10_21_100001` (dev and pilot, after backups `database-before-no-employee-records.sqlite` and a pilot backup): removed the HR records of Grace Park and Ramon Lacson (dev) and OSSI-SA (pilot); none had attendance, payslips, rates or 201 files. Seeders updated.

**Pilot: back online after a restart, and it won't get stuck that way again** ([11](features/11-deployment.md#troubleshooting))
- The laptop restarted at 7:33 AM. The pilot's processes came back by themselves (auto-start at sign-in), but the public address showed "This site can't be reached": Tailscale sat at "starting" because its tray app hadn't opened. Opening it fixed it.
- Now: Tailscale runs in **unattended mode** (no tray app needed), and `start-pilot.ps1` opens Tailscale and waits for it to connect before turning the address on, with a clear message if it can't.
- The sidebar now reads **OSSI · Oriental Sentinel Services Inc.** (was "Attendance & Payroll").

**Show / hide password** ([25](features/25-ui-conventions.md))
- Every password field (sign in, register, reset, confirm, Account Settings) has an eye button on the right: shows the password as text, and hides it again. It starts hidden; each field has its own button; it works by keyboard, and screen readers hear "Show password" / "Hide password". One change in the shared `x-ui.input`, so new password fields get it too.

## 2026-10-20

**No more "Executive": the role is now President and Vice President** ([02](features/02-roles-and-gates.md))
- The company doesn't use the term "Executive". The one role is now two, **President** and **Vice President**, with **exactly the same access** (`Role::leadership()`), so either one can approve office staff's leave, overtime and reimbursements, handle HR staff's complaints and resignations, sign final pay, and see the Organization page. A test checks the two can never drift apart.
- Every screen, message, notification and email says "the President or Vice President" (Filipino: "Presidente o Bise Presidente"). Checked by opening all 79 menu pages as a President, the Super Admin and HR: the word appears nowhere.
- **Final pay:** the last signature is now "President or Vice President": either one signs and releases it.
- Migration `2026_10_20_100003` (dev and pilot, after backups `database-before-president-roles.sqlite` and a pilot backup): the Executive role became **President**, or **Vice President** for someone whose position is Vice President (Ramon Lacson → President); announcement audiences and final-pay signatures moved too. Past audit-log entries keep their wording (history). Internal names users never see (`for_executive`, `LeaveStage::Executive`, `NotifyExecutives`) were left as they are. The demo login `executive@example.test` is unchanged.

**Error pages redone, and 401, 500 and 503 added** ([25](features/25-ui-conventions.md#error-pages-resourcesviewserrors))
- One layout for all, simple and modern: no card; a big faded gray status number as the picture, a soft glow and faint grid behind it, the OSSI seal in a slim header, one blue button for the way forward plus a quiet "Go back", dark mode, and a help line. (Was: bare text with the number in blue.)
- The main button says **"Take me home"** (was "Go to the dashboard"): guards have no dashboard and a visitor may be signed out. `/home` sends each to the right place (sign-in, My Attendance or the Dashboard); tested.
- **Big line, small line:** each page has a short, friendly headline (2–4 words, large and bold, tucked into the faded number) and one quiet line under it, e.g. 404 "**Nothing here.**" · "This page moved, or the link has a typo." Was a medium title plus a long sentence.
- **429** counts down from `Retry-After` and unlocks "Try again" at 0. **403** shows the app's own reason when there is one. **500** (new) says it isn't the user's fault and shows the time for IT, never the error. **503** (new, during `php artisan down`) rechecks every 30 seconds. **401** (new) sends to sign-in. Before, 500 and 503 showed Laravel's default pages.

**Dashboard: the Super Admin's numbers no longer squeeze together** ([25](features/25-ui-conventions.md#numbers-in-a-row-x-uimetric-strip-x-uimetric))
- With every job's numbers (11 "At a glance", 8 "Waiting for you") in one row each, tiles were ~90px wide and labels read "Peop…", "Oper…". Now more than 6 wrap into even rows (4 a row on a laptop, 6 on a wide screen), and the two sections stack when there are many.
- Words like "None yet" or "Processing" are smaller in the same height, so they don't wrap; a number with no line under it keeps the space; the job ("PEOPLE", "PAYROLL") sits on its own small line above the label. Checked at 1672 px, 1280 px and on a phone, light and dark; HR's few tiles look as before.

**A one-week pilot on the laptop, at a fixed address** ([11](features/11-deployment.md#laptop-pilot-one-week))
- The app runs 24/7 from this laptop at **https://ossi.tail4ab8aa.ts.net** (Tailscale Funnel: free, fixed, HTTPS; replaces the random `trycloudflare.com` address). Cloudflare's fixed tunnels need an owned domain, which OSSI doesn't have yet.
- **Separate from development:** its own database (`database/pilot.sqlite`: no demo data, only shifts and holidays), production mode (no debug pages, strict passwords, destructive commands blocked), set by `scripts/pilot/pilot-env.ps1` as environment variables. `.env` and the dev database are untouched; the mail password still comes from `.env` only.
- `scripts/pilot/`: `setup-pilot.ps1` (once), `start-pilot.ps1` / `stop-pilot.ps1` (web server, queue worker, scheduler, each restarted if it stops), `backup-pilot.ps1` (consistent copy while running, keeps 14), `pilot-artisan.ps1` (artisan against the pilot), `install-autostart.ps1` / `uninstall-autostart.ps1` (start at sign-in, backup daily at 2 AM).
- App change: in production, Vite's `public/hot` is ignored (`AppServiceProvider`), so running `npm run dev` for development never leaves pilot users with an unstyled page.

**Complaint subjects: pick a common one, or type it** ([21](features/21-complaints.md#subjects-pick-a-common-one-or-type-it))
- Under **About**, **Subject** is now a list of common subjects for that category plus **Other (type it)**. Quicker on a phone, and HR sees which problems keep coming back (each subject's count for the last 90 days).
- **HR keeps the list** (Complaints → Rules → Common subjects → Manage): add, rename, switch off; audited; never deleted. A complaint copies its subject's words, so renaming one doesn't change past cases.
- The starting list (19 subjects across 5 categories) is my suggestion for HR to review, not OSSI's own. New `complaint_subjects` table and `complaints.complaint_subject_id` (migration `2026_10_20_100002`, run on the dev DB after a backup `database-before-complaint-subjects.sqlite`).

**Complaints: "I need help now", and a weekly limit HR sets** ([21](features/21-complaints.md), roadmap 31 and 32 now Built)
- **I need help now**, first on My Complaints: HR (or the Executive for HR staff's) is alerted by bell **and email** at once ("Urgent: … asks for help (CMP-0005)", still without the contents). The case is first on HR's list with a red **Asks for help** badge and opens with a red banner and the employee's contact number. The form reminds them to call 911 first if they're in danger.
- **One complaint a week:** HR sets the number under Complaints → **Rules** (starting at 1, the roadmap's number; 0 = no limit; audited). Asking for help, harassment and resignation cases are never limited. Over the limit, the form says when the next one is possible.
- **Complaint balance shown up front:** My Complaints says "You can file 1 complaint this week" before anyone writes, and once it's used, when the next one is possible. The counter and the check share one count (`ComplaintRules::allowanceFor`).
- `complaints.asks_for_help` (migration `2026_10_20_100001`, run on the dev DB after a backup `database-before-complaint-help.sqlite`). `.env.example` now lists `LEAVE_DOCUMENTS_DISK` and `COMPLAINT_FILES_DISK` (both optional).

**Complaints: proof, welcome but never required** ([21](features/21-complaints.md#proof-welcome-never-required), roadmap 31)
- My Complaints has a "Proof, if you have any" box: up to 5 photos, screenshots or PDFs, and **Who saw it**. It's optional: harassment and threats often leave nothing on paper, and RA 11313 / RA 7877 expect such complaints to be investigated anyway.
- HR can **Ask for proof** on a case, saying what would help. The employee is told (bell, no contents), sees HR's note, and adds files later; HR is told when they do. Proof can also be added to any open case unasked.
- Files are private: only the complainant and the case's handler open them, every handler view audited. New `complaint_files` table and `complaints.witnesses` / `proof_requested_at` / `proof_request_note` (migration `2026_10_19_100004`, run on the dev DB after a backup `database-before-complaint-proof.sqlite`). New `config/complaints.php` (`COMPLAINT_FILES_DISK`).

**Fixed a broken migration**
- `2026_10_08_100006_create_leave_and_overtime_requests_tables.php` line 39 had become `->timestamp('reviewed_at')-nullable>()` (an accidental edit on Oct 19, 6:17 PM). It didn't affect the running app (the migration ran long ago), but every test and any fresh deploy would have failed. Restored to `->nullable()` with the owner's OK.

## 2026-10-19

**Guards can ask for a training** ([20](features/20-training.md#guards-ask-for-a-training-my-training), roadmap 29)
- New **My Training** page for guards: ask for a firearms refresher, training for license renewal, a pre-licensing course, first aid or other, with a reason and an optional preferred start; cancel while pending; see their sessions.
- Admin is told and answers on **Training → Training requests**: add them to an upcoming session, approve and add them later (linked automatically when Admin enrolls them), or decline with a note. The guard is told either way. The Training menu item shows a badge.
- New `training_requests` table (migration `2026_10_19_100003`, run on the dev DB after a backup `database-before-training-requests.sqlite`).
- The **inspection** half of roadmap item 29 is not built yet: OSSI to say which kind of inspection is meant.

**Privacy notice wording is now tested** ([01](features/01-authentication.md))
- `config/privacy.php` said `tests/Feature/Privacy/PrivacyNoticeTest.php` kept the notice worded as an *acknowledgement*, never *consent* (RA 10173 sec. 16(a); consent given to keep a job isn't freely given). That file didn't exist. It does now: the button and the "not a consent form" line are there, no "I consent / I agree / I accept" anywhere, and every sentence mentioning consent says it isn't relied on. Checked that a "You consent to…" line would fail it.

**Operations can add a guard's extra time** ([07](features/07-requests.md))
- New **Add extra time** button on Overtime Requests (Operations, HR, Admin). For extra time they know about, like a guard holding the post until the late night guard came, so the guard doesn't have to file it: guard, date worked, hours, reason.
- Saved **approved** and marked **Added by** them (`overtime_requests.filed_by`, migration `2026_10_19_100002`, run on the dev DB after a backup `database-before-overtime-filed-by.sqlite`), audited, and the guard is told by bell and email ("Extra time added").
- Payroll is unchanged: it still pays the smaller of approved and clocked overtime, so this can't pay time not worked.
- With Deployment Costs (fares, with a reason) this covers the item "For Operations: additional time worked, reason, and cost of deployment".

**Employee numbers are generated, like the OSSI ID card's control number** ([05](features/05-people.md#the-employee-form-number-hire-type-and-client-site))
- HR no longer types the number. It's made from the **hire date** (YYMMDD) + **province of birth** code + 4 random digits + `-RH` for a rehire, e.g. `260627PAM-0950`: the same rule as the ID card app (`OSSI_ID_GEN_R_SOFTWARE`), so the app's number and the printed card's agree. The form shows `260627PAM-····` until it's saved. Hire date and province of birth are required for a new record and moved up beside the number.
- The format is now the card's: no dash between the date and the letters (`260627PAM-0950`, was `260627-PAM-0950`), and the 6 digits must be a real date.
- **Already has an ID card:** HR can enter the card's number instead; hire date and province are filled from it, and a mismatch is refused. Editing keeps the number (it never changes by itself).
- The 4 digits are picked from the free ones for that date and province (`EmployeeNumber::generate`), tested with 9,999 taken.
- Fixed in the shared dropdown: a value the server sets (the province from a card number) now shows (`custom-select.js` watches its Livewire property).
- Existing numbers aren't changed. `resources/js/employee-number.js` (the typing formatter) is no longer used by the form.

**Employee number letters = province of birth, with a dropdown** ([05](features/05-people.md#the-employee-form-number-hire-type-and-client-site))
- The `AAA` in `000000-AAA-0000` is now the **province of birth** code: the official ISO 3166-2:PH codes for the 82 provinces, plus NCR for Metro Manila (`App\Support\Provinces`, 83 entries, tested for unique 3-letter codes).
- **Province of birth** dropdown next to the number on Add / Edit employee. Picking it fills the letters; typing a code picks the province. Nothing new is stored: the number holds it (`EmployeeNumber::provinceCode()`).
- New or changed numbers must use a real code (the form, the staff import and `app:create-super-admin`). Existing numbers with other letters stay valid until changed. Tests now use real codes (PAM, NCR, CAV…).
- Full name and Email now sit side by side under the number.
- The Province of birth list is **searchable**: a search box at the top of the list ("del n" → the six "del Norte" provinces; a code like "pam" puts Pampanga first). Typing on the closed list starts the search; ↑ ↓ Enter choose, Escape closes. It's a new `searchable` option on the shared `x-ui.select` (`resources/js/custom-select.js`), off by default, so other lists are unchanged.

**Board seat, job and app access kept apart; "Executive" is no longer a position** ([05](features/05-people.md#organization-chart-the-executive))
- New **Member of the Board of Directors** checkbox on office staff's employee record (`employee_profiles.is_board_member`). The Organization chart's Board box now lists those ticked, plus the President (always a director by law), instead of everyone with the Executive role.
- The **Executive position** is removed: it wasn't a job. The jobs are President and Vice President; the **Executive role** stays as app access.
- Migration `2026_10_19_100001` (run on the dev DB after a backup, `database-before-board-member.sqlite`): Ramon Lacson's position became **President** (with his position history and the ₱2,500 company rate), and everyone with the Executive role was ticked as a Board member, so the chart looks the same. Seeders updated.

**Employees: one search, All · Guards · Office staff tabs, and an Assignment column** ([05](features/05-people.md#lists-by-client-site-employees-and-attendance-log))
- One search box still finds everyone. New tabs under the header (**All · Guards · Office staff**, with counts) narrow the list, combine with the other filters and stay in the URL (`?kind=`).
- **Operations** now sees guards only on Employees, with no tabs, matching the dashboard (`view-office-staff`). Before, the list showed them office staff too.
- The **Detachment · Post** column is now **Assignment**: a guard's detachment · post, an office employee's position (e.g. "HR Staff"), or "Office staff" if no position is set yet.

**Organization chart: the President is on the Board too** ([05](features/05-people.md#organization-chart-the-executive))
- The Board of Directors and the officers are different: the Board governs and elects the President and Vice President. Under the Revised Corporation Code (Sec. 24), the **President must be a director**.
- Before, giving someone the President position *moved* them out of the Board box. Now the Board lists everyone with the Executive role **plus the President**, so the President appears in both.
- A Vice President is on the Board only if they also hold the Executive role. The page's description says so.

## 2026-10-17

**Overtime goes to Operations automatically** ([07](features/07-requests.md))
- The **Supervisor** field is gone from My Overtime. A guard's request now goes to **Operations**: Operation HQ and their region's Satellite Operation are told (bell + email), not another region's. Office staff's still goes to the Executive. The card says where: *"Goes to Operations automatically."*
- **Who may decide is unchanged** (by role): Operations, HR or Admin for guards; the Executive for office staff.
- `overtime_requests.supervisor_id` is now optional (migration `2026_10_17_100010`, run on the dev DB after a backup); older requests keep the supervisor they were sent to. Lists show **Sent to** (`OvertimeRequest::sentToLabel()`: that name, else Operations or Executive).

**Clock In button readable on hover in dark mode**
- **Clock In** and **Scan a checkpoint** turn white in dark mode, but hovering applied the light theme's dark-gray hover under dark-gray text, so the label vanished. Both now add `dark:hover:bg-gray-200`, like the shared `x-ui.button` "dark" variant.

**Paid leave on the payroll, My Loans, and Not covered** ([08](features/08-payroll.md#paid-leave-this-cut-off-leave-the-law-pays), [18](features/18-relievers.md#not-covered-the-post-went-without-a-guard))
- **Paid leave this cut-off** (Payroll, on a processing run): approved leave the law pays is suggested for Accounting to check, never added silently.
  - **Paternity, solo parent, VAWC, special leave for women:** the days in the cut-off × the daily rate, capped by what the law gives across cut-offs. Accounting can pay fewer days (a rest day inside the leave). Accepting adds a taxable **Paid leave** adjustment.
  - **Maternity:** the employer advances the SSS benefit (computed from the SSS salary credit), so Accounting enters the amount; it's added as a non-taxable allowance.
  - Dismissing needs a reason. Payslips wait until it's decided.
  - Someone with **no attendance** but accepted paid leave still gets a payslip (`User::scopeDueAPayslip`, used by Generate all and approval). `AddPayrollAdjustment` allows it only for paid leave.
  - New: `paid_leave_payments` (migration `2026_10_17_100009`, run on the dev DB after a backup), `DetectPaidLeave`, `AcceptPaidLeave`, `DismissPaidLeave`, `PaidLeavePayment`, `Payroll\PaidLeaveSuggestions`, `AdjustmentCategory::PaidLeave`.
- **My Loans** (Personal menu): each loan and cash advance with what's left (progress bar), its state, how it was authorized (in the employee's words: "You authorized it in the app") and every payslip that took from it, read from the payslips' frozen loan lines. The cash bond is shown apart. Roadmap item 13 is **Built**.
- **Not covered:** an absence still open after its day becomes **Not covered** (`app:close-uncovered-absences`, daily 00:15): red badge, own tab on Relievers, kept and audited. The first run closed 19 old no-shows from Oct 14 on the dev DB (after a backup).
- The study guide gains a **Patterns & lessons** chapter: the reusable decisions and the mistakes made along the way, for whoever builds next.

**Leave rules are set in the app** ([07](features/07-requests.md#leave-requirements-roadmap-24))
- The two leave rules were set in the server file:
  - from how many days sick leave needs a medical certificate (2);
  - how many days after it started sick or emergency leave can still be filed (7).
- HR now sets them on **Leave Requests → Rules → Change** (`manage-people`); everyone else on that page sees them.
- Saved as the `leave` Setting (`SaveLeaveRules`, `App\Support\LeaveRules`) and audited with the old and new values. Allowed: 1–30 days for the certificate rule, 0–30 for filing after (0 means "only from today on").
- My Leave's hints and checks read the same numbers, so they always match. `.env.example` no longer lists them; the leave documents' disk stays a server setting.

**Reimbursement limits are set in the app** ([31](features/31-reimbursements.md))
- The receipt window (60 days) and the per-claim cap (₱20,000) were fixed in the code; ₱20,000 was only a starting value, not law or OSSI policy.
- **Reimbursements → Limits**: Accounting (`manage-payroll`) sets them in the app; the Executive and others see them. There are three:
  - how old a receipt can be;
  - the most per staff reimbursement;
  - a **separate cap for a guard's deployment claim** (fares are usually a few hundred pesos, so a lower cap catches a typo like ₱2,800 for ₱280).
- Saved as the `reimbursements` Setting (`SaveReimbursementLimits`, `Reimbursement::limits()`), and every change is in the audit log with the old and new values. Allowed: 7–365 days, ₱100–₱1,000,000.
- The forms read the same numbers, so their text ("Within 60 days of the trip. Up to ₱20,000 per claim.") always matches the rule. Until Accounting changes them, the starting values (config `payroll.reimbursements`) apply: 60 days, ₱20,000, ₱20,000.
- `.env.example` lists the leave settings with what they do.

**Relievers and Deployment Costs, linked** ([18](features/18-relievers.md#the-relievers-fare-deployment-costs))
- **Claim the reliever's fare** on a done relief (Relievers) opens Deployment Costs with the form filled from the relief: the reliever, *"Relief at Makati CBD · Roving, Oct 11, for Romeo Ocampo"*, and the date. Operations adds the amount and receipt.
- **Reliefs to claim** on Deployment Costs lists recent reliefs (last 60 days) with no fare claimed, each with **Claim fare**.
- The claim is **linked to the relief** (`reimbursements.absence_notice_id`, migration `2026_10_17_100008`, run on the dev DB after a backup); the relief shows *"Fare claimed ₱280.00 · Waiting for approval"*.
- **Once per relief:** the relief row is locked while filing, and a second standing claim is refused. A rejected or cancelled one can be filed again.
- The two menu items stay separate, since they serve different moments: covering a post now, and the paperwork after.

**Deployment costs** ([31](features/31-reimbursements.md#deployment-costs-roadmap-5))
- **Extra time worked** on a deployment stays an **overtime request** (date, hours, reason, approved; payroll pays the smaller of logged and approved).
- **A guard's deployment costs** (a fare to a far post, a ride to a relief):
  - Operations files them on the new **Deployment Costs** page (Operation HQ for any guard, a Satellite for its region's): the guard, which deployment (their post by default), the date, amount, purpose and receipt, with Operations' certification.
  - The guard is told, and sees it on My Reimbursements ("Filed for you by …").
  - The Executive approves it like any reimbursement (never one they filed), and Operations is told the decision.
  - It's paid on the **guard's** next payslip, not taxed. Only the filer can cancel it while it waits.
- **Operations staff's own costs:** a new **"Deployment (relief, transfer, emergency post)"** reimbursement category that asks which deployment.
- New: ability `file-deployment-claims`, `reimbursements.filed_by` and `deployment_for` (migration `2026_10_17_100007`, run on the dev DB after a backup), `Admin\DeploymentClaims` (`/deployment-claims`). Roadmap item 5 is **Built**.

**Leave requirements** ([07](features/07-requests.md#leave-requirements-roadmap-24))
- **Leave under special laws** added to My Leave, each with what the law gives and the proof it needs:
  - maternity (RA 11210)
  - paternity (RA 8187)
  - solo parent (RA 11861)
  - VAWC (RA 9262)
  - special leave for women (RA 9710)

  A request longer than the law gives is flagged to the approver, not refused (the laws count days differently).
- The sick-leave hint is two short lines, each with its key point first: *"2 days or more: attach a medical certificate. It's needed before your leave is approved."* and *"Already back at work? You can still file, up to 7 days after you got sick, and attach the certificate then."*
- **Sick leave of 2 days or more needs a medical certificate.** Sick and emergency leave can now be **filed once back**, up to 7 days after it started; before, the form refused any past date.
- **Proof now or later**: photos or PDFs, up to 5, checked by their bytes. Attached later from My Leave, whoever acts next is told again. **Approve stays locked** until the proof is attached (*"Waiting for the document: …"*); rejecting is still possible.
- **Private**: only the employee and whoever decides (HR for guards, the Executive for office staff) can open the documents; endorsers see only that one is attached, and views are audited.
- **VAWC is confidential**: it goes straight to the decider, skipping Operations, and is hidden from everyone else's list. The bell and email say only "Confidential leave".
- New: `leave_documents` (migration `2026_10_17_100006`, run on the dev DB after a backup), `LeaveDocument`, `AttachLeaveDocument`, `ShowLeaveDocumentController` (`/leave-documents/{document}`), `config/leave.php`. Roadmap item 24 is **Built**.

**Loans need the employee's authorization; Filipino acknowledgements; two small fixes** ([08](features/08-payroll.md))
- **Loans, cash advances, uniform, equipment, other: authorized before they're taken** (Labor Code Art. 113). Adding one, Accounting picks the basis:
  - **Ask them in the app** (the default): the employee is told (bell + email), and **My Payslips → Please review** shows the exact sentence. Nothing is deducted until they tick **I authorize this**; their words are saved with the time. **I don't agree** takes nothing and tells Accounting.
  - **Signed on paper.**
  - **Government loan (SSS or Pag-IBIG): deducted by law**, no authorization asked.
  - Accounting's list shows *Waiting for their authorization* / *They don't agree* and how each one was authorized. Existing deductions keep working and are marked as added before this (keep their paper).
  - New: `DeductionAuthorizationBasis`, `AnswerDeductionAuthorization`, `DeductionAuthorizationNotice`, `Payroll\DeductionAuthorizations`, `EmployeeDeduction::takenFromPay()` (used by `PayslipInputsQuery`). Migration `2026_10_17_100005`, run on the dev DB after a backup. The sample seeders mark their loans as signed on paper (SSS and Pag-IBIG ones by law).
- **Acknowledgements in Filipino.** An **English · Filipino** switch on the resignation, complaint, reimbursement and Borrow Load acknowledgements and on the loan authorization. The record keeps the lines in the language that was read.
  - The wording moved to `lang/en/acknowledgements.php` and `lang/fil/acknowledgements.php`; the English is unchanged.
  - A test checks both files have the same lines and placeholders.
  - **Have HR (or counsel) read the Filipino before go-live.**
- **The 13th Month fold-out** (per-person cut-offs) uses the app's chevron, not the browser's ▸.
- **The payroll disclaimer** ("subject to change with prior notice…") is now in the **Payslip ready** bell message, so it's read before the figures. That notice is bell-only (no email), so there was no email to add it to.

**Acknowledge before sending: Borrow Load, complaints, reimbursements** ([15](features/15-load-purchase.md), [21](features/21-complaints.md), [31](features/31-reimbursements.md))
- Each form was checked for what it commits the employee to. Leave, overtime, absence, Office Away and Report a problem waive nothing, so they stay as they are: more checkboxes would only train people to tick without reading.
- **Borrow Load (a legal gap, now closed):** the amount came off the payslip with no written authorization, which Labor Code Art. 113 requires. A required tick now reads *"I authorize the company to deduct ₱100 from my next payslip for this load (Labor Code Art. 113)."* It replaces the blue "will be deducted" note. `SubmitLoadRequest` refuses without it and saves the sentence with the time (`deduction_authorization`, `authorized_at`).
- **Complaints:** three points before sending:
  1. It's true as far as they know; a knowingly false complaint can itself lead to discipline.
  2. HR keeps it confidential but may tell the person involved what is said, so they can give their side (due process).
  3. No one may punish them for a complaint made in good faith.
- **Reimbursements:** "I certify these are true", covering three points:
  1. The receipt is genuine, and the money was spent on company business.
  2. It wasn't paid back another way and won't be claimed again.
  3. A false claim can lead to dismissal (Art. 297).
- Complaints and reimbursements are saved word for word with the time (`acknowledgement`, `acknowledged_at`), and the handler sees them ("They read and acknowledged 3 points · …").
- All use one pair of partials (`partials.acknowledgement` for the form, `partials.acknowledged` for the handler), now shared with resignations.
- Migration `2026_10_17_100004`, run on the dev DB after a backup.

**Resignation: a just cause opens a complaint, a sample letter, and why people leave** ([26](features/26-resignation.md), [21](features/21-complaints.md))
- **A just cause opens a complaint case.** Ticking an Art. 300(b) item accuses the company or someone acting for it. Before, HR only settled the date; now filing also opens a complaint (`FileComplaint`, category Workplace conduct). The subject is e.g. *"Just cause given for resigning: Inhuman and unbearable treatment"* and the details are the letter. It goes to the same handler (HR, or the Executive for HR staff), and closing it needs a written outcome.
  - Linked both ways (`complaints.resignation_id`, migration `2026_10_17_100003`, run on the dev DB after a backup).
  - HR's resignation shows "Complaint CMP-0004 was opened about what happened · Open the case". The case says "Opened from their resignation for a just cause… See the resignation".
  - The employee's resignation shows the case and its status, with a link to My Complaints, and the success message says it was opened.
  - Complaints now open from a link (`/complaint-cases?open=`), and the "New complaint" bell goes straight to the case.
- **"Use a sample letter"** (step 3) fills a plain letter from the reasons ticked, their position and the last day ("To HR: I am resigning from my position as Security Guard. My last day of work will be Monday, November 16, 2026…"). Replacing what they wrote asks first. For a just cause, it leaves *"What happened: [when, where, who, and what was said or done]"*, and the form won't send until the [brackets] are replaced. `Resignation::sampleLetter()`.
- **"Why people leave"** on HR's Resignations page: each reason ticked in the last 12 months (withdrawn ones not counted), most common first, with a bar and the split between guards and office staff, plus the "Others" in their own words. Only the resignations that person handles (`ResignationReasonsQuery`).

**Fold-outs use the app's chevron**
- "Read the law: Labor Code Art. 300" and HR's "They read and acknowledged…" showed the browser's ▸ triangle. They now use the gray chevron after the text, turning up when open, like "How contributions and tax were computed" on the payslip.

**What's owed always comes out of final pay** ([27](features/27-final-pay-and-cash-bond.md))
- The optional "Your final pay" checkbox is gone from the resignation form. Without it ticked, debts were only shown and had to be collected separately.
- The written authorization that Labor Code Art. 113 asks for is now one of the required "Before you send" lines: *"I authorize the company to deduct what I still owe (unpaid loans, borrowed load, unreturned property) from my final pay and cash bond (Labor Code Art. 113)."* Filing therefore always records the consent (`deduction_consent_at`), and the final-pay statement deducts what's owed (the bond first, never below ₱0, the rest a receivable).
- HR's "Record a resignation" (paper letter) keeps its box: tick it only with their signed authorization.

**Resignation: acknowledge before sending** ([26](features/26-resignation.md))
- A fifth step, **Before you send**, lists what happens next in six plain sentences:
  1. It's of their own free will.
  2. 30 days' notice, or a waiver; leaving earlier can mean damages (Art. 300(a)).
  3. It can be taken back only until accepted.
  4. Return company property and hand over the post.
  5. Final pay within 30 days and a Certificate of Employment within 3 days (DOLE Labor Advisory No. 06, s. 2020).
  6. The account closes the day after the last day.
- **"I've read these, and I understand them"** is required to send.
- Short and specific on purpose: a long terms-and-conditions page gets ticked without being read.
- **With a just cause** the wording changes: not "of my own free will" but "what I say in my letter about what happened is true, and HR may ask me about it", and there's no notice line.
- **Saved word for word with the time** (`acknowledgement`, `acknowledged_at`; migration `2026_10_17_100002`, run on the dev DB after a backup), so the record shows exactly what they agreed to even if the wording changes later. HR's detail shows "They read and acknowledged 6 points · Oct 17, 2026 10:13 AM" with the list. A paper letter HR enters has none (the paper is the record). Text in `Resignation::acknowledgementLines()`.

**Resignation form: clearer layout, just cause behind one question** ([26](features/26-resignation.md))
- **Four numbered steps:** 1 Why you're leaving, 2 Your last day, 3 Your letter, 4 Your final pay, then Send. The card says only "Your letter goes to HR only. You can take it back until it's accepted.", where Art. 300 used to appear three times.
- **Reasons are tiles** (the whole box ticks; ticked ones turn light blue), two columns on a phone, three on the full page, two in HR's dialog.
- **The just causes are folded behind one question:** "The company, or someone acting for it, did something to me that makes me leave at once" (*"Most resignations aren't this."*). Only then do the four Art. 300(b) items show. Unticking it clears any ticked. It's a serious claim that skips the 30 days, so it shouldn't be one stray tap away. State in `ChoosesResignationReasons`, shared by both forms.
- **"Read the law: Labor Code Art. 300"** is now a small link under the reasons (the text opens below it).
- HR's dialog and the records read in the third person ("Serious insult on their honor and person", `ResignationReason::labelForOthers()`); the employee's form keeps "my".

**Resignation reasons: a checklist, "Others", and Art. 300 on the form** ([26](features/26-resignation.md))
- The single "Main reason" dropdown is now a **checklist** (tick all that apply, nothing pre-ticked) on the employee's form and on HR's "Record a resignation". "Others" asks for the reason in words.
- **Just causes are the law's own list**: a separate group, "Just cause: leaving without the 30 days' notice", with Art. 300(b)(1) to (4) (serious insult, inhuman and unbearable treatment, a crime or offense against them or their family, another cause like these), each labelled with its paragraph. Ticking one removes the 30-day rule and the waiver; the last-day hint says it can be today. HR's view says "Just cause (Art. 300(b)(2)): no notice needed".
- **"What the law says: Labor Code Art. 300"** opens the article's text under the checklist. (It's Article 300 of the Labor Code, P.D. 442, formerly Art. 285, renumbered by DOLE Department Advisory No. 01, s. 2015; not an RA.)
- Data: `reasons` (JSON) added (migration `2026_10_17_100001`, existing rows get their one reason; run on the dev DB after a backup). `reason` stays as the main one: a just cause if any, else the first ticked. The old single "Just cause" value isn't offered any more but still reads on older records.

## 2026-10-16

**Clock in/out: front or back camera** ([06](features/06-attendance.md))
- The punch camera was front-only. On a phone with two cameras, a **Back camera / Front camera** button on the preview now switches between them. It still opens on the front (the selfie). The current camera is stopped before the other opens, Capture waits until it plays, and only the front preview is mirrored (the saved photo never is). Icon `arrow-path` added.

**Reimbursement form: the limits line in plain words**
- "Receipts from the last 60 days, up to ₱20,000 each" read like a note about the file. It now says what it means: *"File within 60 days of spending it. Up to ₱20,000 per request; bigger purchases go through Accounting."*

**"Choose file" hover in dark mode**
- On the reimbursement receipt and the Report a problem attachment, the button turned near-white on hover in dark mode, with its light text unreadable. It now darkens slightly instead (`dark:hover:file:bg-gray-700`).

**Reimbursements (office staff only)** ([31](features/31-reimbursements.md))
- **My Reimbursements** (office staff on this payroll; not guards, not the Executive): what for (transportation, meals, supplies, phone/load, fees, other), the date (last 60 days), the amount (up to ₱20,000), what it was for and for whom, optionally the Office Away trip, and the **receipt** (photo or PDF, always). Cancel while it waits.
- **Reimbursements** (menu, badge): the **Executive** approves or rejects with a reason, never their own. Accounting sees every request and is told once one is approved.
- **Paid on the next payslip** automatically: `GeneratePayslip` adds each approved one as a non-taxable **Reimbursement** line (category added to adjustments), once, and marks it Paid.
- New: `reimbursements` (migration `2026_10_16_100006`, run on the dev DB after a backup), `FileReimbursement`, `DecideReimbursement`, `CancelReimbursement`, `PayApprovedReimbursements`, `ReimbursementNotice`, abilities `approve-reimbursements` and `view-reimbursements`, `/reimbursements`, `/reimbursement-requests`. Roadmap item 25 is **Built**.

**Log out: neutral, red only on hover**
- In the sidebar and the name menu, "Log out" was always red, which on the dark theme read like an error. Signing out loses nothing, so it's now the same gray as the items above it, turning red only when hovered.

**Promotions and position history** ([30](features/30-promotions.md))
- 201 file → **Position history** → **Promote or change position** (HR): the new position (for their staff type), from a **cut-off start (the 1st or 16th)**, and why. The form shows what the position pays then. Ahead: on the record on its day (`app:apply-position-changes`, daily 00:05) and can be cancelled until then. Back-dated (up to 90 days): the cut-offs already paid are **suggested as corrections** on the next payroll.
- **Pay now uses the position held on each cut-off's start** (`EffectiveRatesQuery` with `PositionChange::positionsOn`). Before, it used the current position for any date, so a promotion would have re-priced earlier cut-offs (and triggered false back-pay suggestions).
- The employee is told ("Congratulations: promoted to Shift-in-Charge"), and Accounting: the new rate, or that a personal rate still wins and should be reviewed. A position edited on the employee form is recorded in the history too.
- New: `position_changes` (migration `2026_10_16_100005`, run on the dev DB after a backup), `ChangePosition`, `CancelPositionChange`, `ApplyPositionChanges` + command, `PositionChangeNotice`, `People\PositionHistory`. Roadmap item 22 is **Built**.

**Notices to Explain (the twin-notice rule)** ([29](features/29-notices-to-explain.md))
- **Notices to Explain** (menu, `issue-notices-to-explain`): issue one to someone you supervise. Guards: Operation HQ or their region's Satellite. Office staff: HR. HR staff: the Executive. It needs the specific acts, the rule, the possible penalty (reprimand, suspension, dismissal), and at least 5 calendar days to explain; anything shorter is refused.
- The employee reads it on **My Notices** (in their menu once they have one), marks it received (not agreeing), and explains in writing, optionally asking for a hearing. Whoever issued it is told; a hearing can be set (when, where).
- **The decision** (second notice) opens only once they've explained or the days have passed: cleared, written reprimand, suspension (days, from), or dismissal, always with reasons, optionally with a demerit. The employee is told and marks it received; a dismissal tells HR to update the record. Every step audited; listed on the 201 file with **Issue one**.
- New: `notices_to_explain` (migration `2026_10_16_100004`, run on the dev DB after a backup), `IssueNoticeToExplain`, `ExplainNotice`, `AcknowledgeNotice`, `ScheduleNoticeHearing`, `DecideNotice`, `NoticeToExplainNotice`, `NteOutcome`, `/notices-to-explain`, `/my-notices`. Roadmap item 30 is **Built**.

**Merits and demerits** ([28](features/28-merits-and-demerits.md))
- **Merits & Demerits** (menu, `give-merits`): give one to someone you supervise. Guards: Operation HQ, their region's Satellite Operation, or HR. Office staff: HR. HR staff: the Executive. Pick a type (its points are copied) or "Other…" (1–20 points), the date, and what happened. The list shows only your people.
- **Types and points** (HR, `manage-merit-types`): starts with 4 merits (+2 to +10) and 5 demerits (−1 to −5); switch off, never delete.
- The employee is told (bell + email) and, for a demerit, can **add their side** on My Profile within 5 days, once; whoever gave it is told. A mistake is **taken back** with a reason: struck through, no longer counted, never deleted.
- **12-month totals** (merit points, demerit points, net) on My Profile and the 201 file, and "+5 / −1 pts" on the Employees list (Operations: guards only). Nothing touches pay (Art. 113). Serious offences: the Notice to Explain, next.
- Menu icon: a **balance scale** (weighing good against bad), not a checkmark, which in this app means "done / approved".
- **Picking the employee** (both Give a merit or demerit and Issue a notice, `PeopleToActOnQuery`): search **by name or employee number**. Before typing, it shows **suggestions**: the people at the site of your last record, or else the first of the people you supervise. Before, the box was empty until you typed 2 letters of a name.
- No "+" on the new buttons and links (Give a merit or demerit, Issue a notice, Add a type, Give one, Issue one): the labels already say what they do, and plain text matches the app's other buttons.
- New: `merit_types`, `merit_records` (migration `2026_10_16_100003`, run on the dev DB after a backup), `GiveMerit`, `ReplyToDemerit`, `VoidMerit`, `SaveMeritType`, `MeritNotice`, `MeritTotalsQuery`, `People\MeritHistory`, `/merits`. Roadmap item 21 is **Built**.

**Report a problem on a payslip (Payroll Concerns)** ([08](features/08-payroll.md#report-a-problem-employees-flag-what-the-system-cant-see-payroll-concerns))
- Under each payslip on My Payslips: *Something wrong on this payslip? Report a problem*. The employee picks what's wrong (missing day, overtime, late, a deduction, the rate, contributions, other; each with an example), writes it with dates, and can attach a photo or PDF. One open report per kind per payslip.
- Accounting is told at once (bell + email) and answers on **Payroll Concerns** (new menu item with a badge; "to answer" on the dashboard): **Pay it** on a payslip still being processed (a taxable Correction adjustment, the reply as its reason), **Explain**, or **Not valid**. The employee is told, and the answer shows under that payslip with the amount and which payslip.
- The attachment is private (checked by its first bytes, random name, streamed only to the employee and Accounting). Audited.
- New: `payroll_concerns` (migration `2026_10_16_100002`, run on the dev DB after a backup), `FilePayrollConcern`, `AnswerPayrollConcern`, `PayrollConcernNotice`, `PayrollConcernCategory` / `PayrollConcernStatus`, `/payroll-concerns`. Roadmap item 14 is **Built**.

**Corrections from earlier cut-offs: pay that changed after it was paid** ([08](features/08-payroll.md#corrections-from-earlier-cut-offs-prior-period-adjustments))
- A day recorded by hand after the cut-off, overtime or leave approved late, a back-dated rate: before, the difference was never paid unless Accounting noticed. Now each open run recomputes everyone's paid cut-offs from the last 90 days and lists every difference under **Corrections from earlier cut-offs** (who, which cut-off, what changed, the amount).
- Accounting **accepts** (a taxable Correction adjustment on this payslip, through the usual adjustment rules) or **dismisses** with a reason. That person's payslip waits until it's decided; Generate all skips them. Nothing is suggested twice, and a removed adjustment makes it owed again.
- Runs when a run starts processing, on Generate / Generate all, and from **Check again**. Compares attendance pay only (`PayslipCalculator::attendancePay()`, which `calculate()` now uses too); contributions aren't recomputed.
- New: `prior_period_corrections` (migration `2026_10_16_100001`, run on the dev DB after a backup), `DetectPriorPeriodCorrections`, `AcceptPriorPeriodCorrection`, `DismissPriorPeriodCorrection`, `App\Livewire\Payroll\PriorPeriodCorrections`, `payroll.prior_period.lookback_days`. Roadmap item 14 is now Partly: the employee's "Report a problem" is next.

**Final pay: only Accounting and the Executive** ([27](features/27-final-pay-and-cash-bond.md))
- Company decision: final pay amounts are for Accounting and the Executive only. Before, Operation HQ, Admin and HR signed too, so they saw everyone's pay. Now Accounting prepares and signs, and the Executive signs last, which releases it. HR, Operation HQ and Admin no longer have the Final Pay menu or page (403); they confirm their part on the resignation's **clearance** instead.
- `FinalPay::SIGNERS = [Accounting, Executive]`; `sign-final-pay` is Accounting, Executive, Super Admin. Statements already mid-signing simply wait for Accounting, then the Executive. Earlier signatures by others no longer count or show.
- `FinalPaySampleSeeder` signs in the new order; Ferdinand Ramos's sample now waits for Accounting.

**Stepping out: the note asks what's useful for the reason** ([24](features/24-patrols-and-away.md))
- One label, "Note (where, who asked)", became a question per reason: "Where, and what for" (Official business), "Which site, and what for", "Which office, and what for", "Where (no need to say what for)" (Appointment), "Where" (Personal); for guards "Who asked, and what for", "Who called you", "Which posts" (optional ones say so). The example changes with it.
- Privacy: the old example "Dentist at Robinsons Galleria" nudged people to write health information (sensitive personal information, RA 10173). The Appointment note now asks only where.
- `AwayReason::noteLabel()` / `noteExample()`; the view no longer keeps its own list.

**Stepping out: they say their own time, nothing suggested** ([24](features/24-patrols-and-away.md))
- Showing "Official business (usually 2 hrs)" made 2 hours the plan, even for a 20-minute errand, and since "running late" only started after it, the extra time was invisible. Now the reasons show no hours, and "How long will you be out?" starts on *Choose your time* and is required. They're held to the time they chose ("You're expected back by then. Back early is fine.").
- The usual lengths stay in `AwayReason::usualMinutes()` only as a fallback for rows recorded without a time. So the "Usual: 3 hrs" text added earlier today is gone again.

**Stepping out: reasons and lengths that fit a Manila office** ([24](features/24-patrols-and-away.md))
- New office reason **Government office** (usually 3 hrs, note required): SSS, PhilHealth, Pag-IBIG, BIR, DOLE, PNP-SOSIA. It used to fall under Official business (2 hrs) and show as late almost every time.
- **Appointment** is usually **2 hrs** (was 1). The usual time is the deadline people are held to, and travel plus waiting was rarely under an hour. This applies to guards too.
- "How long" now says the actual time, **"Usual: 3 hrs"**, following the reason picked; the note's example changes with the reason too.
- Under it: *Out for more than 4 hrs? File it as leave on My Leave instead.*, for **personal reasons only** (Appointment, Personal). A long official trip is still work. Leave is whole days; there's no half-day leave yet.
- New office reason **Client site visit** (usually 3 hrs, note required: "Makati CBD, inspecting Gate 2"), for Operations and HR going to a post or detachment.
- `AwayReason::needsNote()` and `isPersonal()` replace the lists kept in `LeavePost` and the view.

**Probation: weekly reminder once it's past, and overdue marks** ([13](features/13-v2-quick-wins.md))
- Before, HR got two alerts (30 days before, and on the day), and nothing more if they missed them. Now, while someone is still Probationary past 6 months, HR is reminded **every week** ("Probation ended 14 days ago: Name"), citing Labor Code Art. 296: an employee allowed to keep working after probation becomes regular by law. It stops as soon as the status changes.
- A late first alert (scheduler down on the day) says "ended 3 days ago" instead of "ends today".
- **Employees list:** an amber dot and "Probation ended Apr 8" under the status. **HR's dashboard:** the Probation ending tile says "3 already past 6 months".
- How: one alert name per overdue week (`probation.overdue.1`, `.2`…), each claimed once in `sent_alerts`, so no new table or "last sent" column. `EmployeeProfile::probationOverdueSince()` / `scopeProbationOverdue()`.

## 2026-10-15

**Organization chart: Board of Directors on top** ([05](features/05-people.md#organization-chart-the-executive))
- The top box is now **Board of Directors**, drawn as a hexagon (`.org-hexagon`), with **President**, then **Vice President**, below it one under the other, as on the company's chart (they were side by side). It holds the same people as before: the Executive role, minus whoever is President or Vice President by position. The role keeps its name elsewhere, because the President and VP hold it too.

**Leave Requests: no verdict icon in the Status column**
- The check or cross before each endorsement only repeated its text. It's now plain: "Endorsed · Oct 14, 8:21 AM" in gray, or "Not recommended" in red (only those words) and then the date. The Review dialog now does the same (it kept the icons at first): the endorser on one line, then "Endorsed" or a red "Not recommended", the time and the note.

**Custom duty times on the Duty Roster** ([23](features/23-duty-roster.md))
- The duty dialog's list ends with **Custom time…**: type the hours (common hours, Start / End, the length and "ends the next day", grace), like HR's employee form. It starts from the duty already picked. The hours are saved as a shift through `ShiftForTimes`, so the same hours reuse one shift instead of piling up copies.
- HR's "Shift hours" box became a component (`<x-shift-hours start= end= grace=>`) that both screens use, so they can't drift apart.

**Indent guides on People by area**
- Like VS Code's file tree: when a region or province is open, a thin gray line runs from under its arrow down past its provinces or clients, so it's clear what belongs to what. It darkens while hovered, and has a dark-mode shade. Pure CSS (`before:` on a wrapper around the children), with no extra markup per row.

**Dark mode survives page changes**
- Choosing dark or light mode no longer resets when you open another page. `wire:navigate` swaps in the next page's `<html>` without the `dark` class and doesn't re-run the head script. The theme is now applied again on every swap (`livewire:navigating` → `onSwap`, in `layouts/base.blade.php`), before Alpine starts, so the toggle shows the right state with no flash. The Back button is covered too.

**Final pay sample data**
- `FinalPaySampleSeeder` adds four resigned guards, one at each stage: ready to prepare, draft, signing without consent, and released with a receivable. All go through the real Actions ([27](features/27-final-pay-and-cash-bond.md)).
- Final Pay screen: an unprepared statement now says when it can be prepared and by whom, instead of an empty footer. A released one shows its date, and signature cards show the time on its own line.

**Two decisions settled**
- The Executive can't resign through the system (company decision): `FileResignation` refuses them, and HR's paper-letter list leaves them out ([26](features/26-resignation.md)).
- The Executive's **Employees** tile still counts everyone employed (36), but the hint now explains why that's more than the Headcount (31): "31 clock in · 4 no account yet". The rest is the Executive, who's paid outside payroll and never clocks in. Counting only people who clock in would have hidden the ones still to sign up ([09](features/09-dashboard-and-self-service.md)).

**Final pay and the cash bond** ([27](features/27-final-pay-and-cash-bond.md))
- Company decisions: leave isn't converted to cash; a ₱100-per-cut-off cash bond (₱200 a month), refunded in final pay; five sign (Operation HQ, Admin, HR, Accounting, then the Executive last, which releases it); what's owed is deducted only with written consent, never below zero, and the rest is a receivable.
- The statement: + pro-rated 13th month + cash bond refund ± Accounting's adjustments − loans, advances and load owed (with consent). The last salary is shown but not added, because the regular payroll pays it, with a warning if the last cut-off has no payslip yet.
- On release: the bond is refunded and stops, loans are settled as far as covered, "Final pay released" is ticked on the clearance, and the employee is emailed. December's 13th month won't pay it again.
- Consent checkbox on the resignation form (and on HR's paper-letter form). New guards are enrolled in the cash bond when they register; Accounting enrolls the rest once from **Cash Bonds**, and enters what each paid in before.
- New: `final_pays`, `cash_bond_entries`, `resignations.deduction_consent_at` (migration `2026_10_15_100002`), `prepare-final-pay` / `sign-final-pay`, `app:enroll-cash-bonds`, deduction type `cash_bond`.

**Resignation and clearance** ([26](features/26-resignation.md))
- The employee files from My Profile: reason (or "Others"), letter, last day. Labor Code Art. 300: 30 days' notice, or a just cause; shorter asks HR to waive it. They can withdraw while pending.
- HR accepts the last day (the Executive for HR staff's own); the employee is told with the final-pay date, and Operations for a guard. Clearance checklist with who and when (firearm and equipment for guards; what's still owed on loans shown). The day after the last day the status becomes Resigned and the account closes; it completes once everything is cleared.
- New: `resignations` table (migration `2026_10_15_100001`), `handle-resignations` / `handle-hr-resignations`, `app:apply-separations` (daily 00:10).

**A dashboard for every staff role** ([09](features/09-dashboard-and-self-service.md))
- HR and Accounting now land on the dashboard too (they had none: `/home` sent them to My Attendance). Developers and guards still go to My Attendance.
- Each section has its own Ability, so each role sees its part, and someone with two roles sees both:
  - **Waiting for you** (everyone): the sidebar's counts as cards (leave to decide, relievers, complaints, load requests, daily rates…), from `Navigation::for()`, so the two can never disagree.
  - **At a glance** (per job, `DashboardHighlightsQuery`): Executive: employees and clients; HR: probation ending, hired this month, no account yet; Accounting: the latest payroll run; Operations: no-shows today; Admin: training in the next 14 days.
  - **Today** (`supervise-attendance`): the counts, charts and DTR, now filtered by the Client site bar.
  - **People by area** (`view-people`): region → province → client (open/close), with headcount and, for those who see attendance, in / late / not in yet / on leave and a "% in" bar; office staff and "not deployed yet" rows; each row links to Employees filtered to it. Accounting sees the headcount only.
- Numbers shown as one card per group split by thin lines (`<x-ui.metric-strip>` / `<x-ui.metric>`, see [25](features/25-ui-conventions.md)): icon and label, a plain number, a coloured line under it (blue = needs you, gray = information). Today's numbers show their share ("16% of 31") and filter the DTR when clicked.
- Operations count guards only, Operation HQ and Satellite Operation alike: every guard in every region, no office staff, in their numbers, charts, areas and DTR (new `view-office-staff`, `User::scopeOnDashboardOf`). A Satellite still opens records in its own region only (other regions' names are plain text). The headcount says what it covers: "whole company", "all guards", or the area picked.
- The weekly stacked bars are now an attendance line chart laid out like a finance chart: today's "% in" and its change from yesterday first, 7D / 14D / 30D tabs, a smooth line with a hover label. The Today ring uses the same "expected" (people not on leave).
- Accounting's dashboard: a Payroll summary (net pay, deductions, change from the run before) and **Runs to finish**, a tracker of each open run (draft → processing → approved → paid, pay day, payslips still to generate), plus a Next pay day tile. The charts stay on the Payroll page, so the two pages don't repeat each other. The Payroll page's totals moved to `PayrollOverviewQuery`, which both pages read, so they can't disagree.
- Softer text everywhere: primary text is charcoal (`gray-800`) instead of near-black, and off-white (`gray-100`) instead of pure white in dark mode ([25](features/25-ui-conventions.md#text-colours-never-pure-black-or-pure-white)).
- No extra query for the areas: `TodayRosterQuery` rows now carry office / region / province / client, and `WorkforceByAreaQuery` counts them.

## 2026-10-14

**Employees and Attendance Log by client site** ([05](features/05-people.md))
- A "Client site" bar under each list's header: Region → Province → Client (each pick clears the ones after it), plus "Office staff (no client)" and Clear. By where people are deployed, not their home address; the Attendance Log goes by the person's own client. Kept in the URL (`?region=r4a&province=Cavite`), so a filtered list can be bookmarked or shared. Only places with clients are offered; a Satellite Operation sees its own region only. One trait for both pages: `App\Livewire\Concerns\FiltersByArea` and `partials/area-filter.blade.php`.

**HR staff's own requests go to the Executive only** ([07](features/07-requests.md), [02](features/02-roles-and-gates.md))
- Leave and overtime already did (HR counts as office staff); now tested for HR specifically.
- Complaints: one filed by someone in HR goes to the Executive (new `handle-hr-complaints`: Executive, Super Admin). Other HR staff don't see it, can't open or change it, and it isn't in their badge. Stored on the complaint (`for_executive`, migration `2026_10_14_100001`, existing HR complaints back-filled), so a later role change doesn't hand it to HR. The Executive gets Complaints in the menu, showing only those. The employee's screens say "the Executive" instead of "HR".

**Important notices reach the bell** ([13](features/13-v2-quick-wins.md), [07](features/07-requests.md))
- New: leave and overtime decisions to the requester (`RequestDecided`, bell + email); "Payslip ready" / "Pay released" to everyone in a run (`PayslipReady`, bell only); "Time away never ended" to the supervisor (`CloseForgottenAways` → `PatrolAlert`).
- Why a late "168 min past their time" never arrived: the **scheduler** and **queue worker** weren't running locally. The rule was fine; `composer dev` runs both.
- Guard rail: an architecture test fails if any notification (except the two sign-in emails) leaves out the `'database'` (bell) channel, so a future notice can't live only in an inbox.
- Clicking a notice opens its page on the address you're using: the link's host is dropped (the queue worker writes APP_URL's), so it works through a Cloudflare tunnel too.
- **The bell no longer waits for the queue worker.** Every notice writes its bell item in the same request (`App\Support\WritesBellImmediately`: `viaConnections()` → `database` on `sync`); only the email is queued. Found when Patrick Villareal's leave sat in `jobs` with no worker running, so the Executive's bell stayed empty. An architecture test requires the trait on every bell notice.
- Request notices read better: the title is what and who ("Leave to decide: Patrick Villareal"); the body is one fact per line ("Vacation · Oct 15 – Oct 17, 2026 (3 days)", "Reason: …", each endorsement), which the bell keeps (`whitespace-pre-line`). Before, the dates were in both and the title wrapped mid-date.
- A guard's overtime request now pings the **supervisor they chose** (bell + email, "Overtime request: …"). Before, only office staff's overtime notified anyone (the Executive); a guard's waited silently in the list.
- New **Announcements** page for everyone (`/news`, Personal menu; publishers keep Management → Announcements): the last 90 days meant for your roles, Urgent / Ended tags. An announcement in the bell opens it with that one highlighted; before, it went to `/home`, which only reloaded the dashboard you were on.

**Organization chart for the Executive** ([05](features/05-people.md#organization-chart-the-executive), [02](features/02-roles-and-gates.md))
- Overview → Organization (`view-organization`): headquarters boxes filled by role, President / Vice President by **position** (new `Position` cases), and the field side region → province → client → guards.

**Dialog flash fixed** ([25](features/25-ui-conventions.md#dialogs-and-the-backdrop-x-uimodal-appcss))
- Switching options inside an open dialog drew the backdrop twice. Found by measuring a pixel (118 → 63), fixed in CSS; the first attempt failed because Chrome never matches `:not(:only-child)` on view-transition snapshots.

**Employee form** ([05](features/05-people.md#the-employee-form-number-hire-type-and-client-site))
- Newly hired / Rehired switch drives the `-RH`; Region → Province → Detachment under one "Client site" heading (nothing new stored); columns renamed **Name** / **Employee No.** (and CSV exports).
- Sample data only: every employee number on the dev database set to a random `000000-AAA-0000-RH` (the seeder is unchanged).

**Endorsement marks** ([25](features/25-ui-conventions.md#endorsed--not-recommended-x-uiverdict-dot))
- `<x-ui.verdict-dot>`: a solid blue check / red cross drawn for 16px, replacing a typed ✓ and then a shrunk icon.

## 2026-10-13

**No-show alerts** ([18](features/18-relievers.md#no-shows-a-guard-who-didnt-report-detectnoshows))
- Every 5 minutes: a rostered guard past their grace period with no clock-in, notice or leave becomes a **no-show** on the Relievers board; Operation HQ and the region's Satellite are alerted, with who is still at the post to hold it; escalated to HQ after 30 minutes; closes itself if the guard turns up.
- Relievers: nearest first (site → province → region), tap-to-call numbers, nobody unrested (8 hours). A late reliever gets an hour before counting as a no-show (found by a failing test).

**Loading buttons** ([25](features/25-ui-conventions.md#buttons-show-theyre-working-x-uibutton-resourcesjsform-loadingjs))
- Every button shows a spinner while busy and ignores a second click; plain forms (login, register…) included.

**Notification bell** ([13](features/13-v2-quick-wins.md))
- Live over WebSockets (Laravel Reverb): the badge, the item and a pop-up arrive with no request, so the idle timeout still works. Badge redesigned.

**Patrols** ([24](features/24-patrols-and-away.md), [23](features/23-duty-roster.md))
- Duty Roster: "Type of duty" cards (Stays at the post / Roving patrol) showing the client's checkpoints and round time.
- Scanner: refusals in red, a pause before re-reading, and no more hanging on "Saving the scan…" after a server error; a sticker from another client names both sites.
- Clocking in closes any time away left open from an earlier duty.

**Earlier on 2026-10-12 and 10-13** (see each guide): Office Away for office staff, time away with the person's own return time and "need more time", history by day and 90-day GPS retention, the guard leave chain (Satellite → Operation HQ → HR), office staff requests to the Executive only, manual shift hours, the Trainee status, QR anti-cheat (pinned checkpoints, suspicious rounds, new stickers).

## Data changes on the dev database

Each was preceded by a backup in `database/` (`database-before-*.sqlite`): the QR test site and its clean-up, Parking B2 moves, the no-show migration, the test notification, the random employee numbers.
