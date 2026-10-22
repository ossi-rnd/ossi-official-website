# 25. UI conventions: loading, status marks, the bell, dialogs

Small pieces every screen shares. Each one is built once, in a component or in `app.css`, so a page gets it without extra code.

## Buttons show they're working (`<x-ui.button>`, `resources/js/form-loading.js`)

While a button is busy, a spinner covers its label and clicks are ignored. Nothing to add on a page:

| Where the click goes | Who marks the button busy |
| --- | --- |
| A Livewire action (`wire:click`, or the submit button of a `wire:submit` form, even one outside it via `form="…"`) | Livewire 4 sets `data-loading` on that element for the whole request |
| A plain form that reloads the page (login, register, password, 2FA, account settings, logout) | `form-loading.js` sets `data-loading` + `aria-busy` on the submitter |
| A `wire:target` the button names (e.g. an upload still in flight) | the component adds `wire:loading.class="is-busy"` |

Why it's built this way:

- **The spinner covers the label** (`absolute inset-0`, label `invisible`), so the button keeps its exact size and nothing jumps.
- **Plain forms aren't `disabled`:** a disabled submit button drops its name/value from the request. `pointer-events: none` blocks the second click instead, and a second `submit` event is cancelled, so a double tap never logs in twice or sends two reset emails.
- **Only real submits:** the browser fires `submit` only after its own validation passes, and a form cancelled by `onsubmit="return confirm(…)"` arrives already cancelled. So an empty required field, or "Cancel" on a confirm, never shows a spinner.
- **The Back button** can restore a page exactly as it was left, mid-submit: `pageshow` clears the busy state.
- **Opt out** with `data-no-loading` on a form that never leaves the page (a file download).
- Buttons outside the component (row actions like Edit or Remove) just dim while busy (`button[data-loading]:not(.group)` in `app.css`).

Tests: `tests/Feature/ButtonLoadingTest.php`. Checked in a browser with the server slowed down: one login request sent, same width while spinning.

## Endorsed / not recommended (`<x-ui.verdict-dot>`)

A 16px solid dot with a white check (blue) or cross (red), drawn on its own 16-unit grid. Used in the leave endorsement trail and the Endorse/Review dialog.

Lesson: the first versions used a typed "✓" (thin, different on every device) and then the 24px outline icons shrunk to 10px (thin, loose in the circle). A mark drawn for its size is what looks crisp.

## Tabs answer at once (`partials/staff-kind-tabs`)

A tab that waits for the server feels broken over a slow line (the tunnel). The Office staff / Guards tabs on Leave and Overtime Requests move the underline in the browser on click (Alpine `shown`, colours only via `x-bind:class` object syntax so the old tab's are removed), slide a thin bar under the tabs, and dim the list (`wire:loading.class`, `wire:target="showGroup,statusFilter"`) until the new list arrives. `showGroup()` is a method rather than `$set`, so it can be a `wire:target`. Measured: ~150 ms locally, 1.2 s+ with 600 ms latency; the feedback is there within a frame either way.

## Text colours: never pure black or pure white

Primary text (headings, names, numbers) is a soft charcoal, `text-gray-800`, and in dark mode an off-white, `dark:text-gray-100`; pure black (`gray-900`) and pure white glared and made a page feel heavy. Below that: body `gray-700` / `gray-600` (dark `gray-300`), secondary labels and notes `gray-500` (dark `gray-400`), faint hints `gray-400`. White stays only on coloured fills (a blue button's label). Changed app-wide on 2026-10-15 (`text-gray-900` → `text-gray-800`, `dark:text-white` → `dark:text-gray-100`); new markup follows the same steps.

## Numbers in a row (`<x-ui.metric-strip>`, `<x-ui.metric>`)

One card, numbers side by side, each with a small gray icon and label, the number, and a line under it. Used on the dashboard for "Waiting for you", "At a glance" and Today.

- **Text is never coloured.** Icon and label gray, the number dark, the line under it gray with its key part a shade darker ("**39%** of 31"). Colour is only a small graphic in the `tone` (`blue` = needs you, `green` / `amber` / `red` = attendance, `gray` = none): a thin bar when `progress` (0–100) is given (today's share of the headcount; the headcount itself is a full gray bar so the row lines up), otherwise a small dot before the line. A dot is a warning (amber / red), so it only appears where it sets one number apart: "Waiting for you" has none, since everything there needs you anyway. Five coloured sentences in a row read like a rainbow; bars and dots don't.
- **Link, button or plain:** `href` makes a link; `wire:click` (no href) a button with `aria-pressed` from `active` (Today's numbers filter the DTR); neither, plain text.
- **Dividers:** on a laptop the strip is one row and each number but the first has a short line on its left that stops before the card's edges (`before:inset-y-5`). On a phone (2 columns; an odd last one spans the row) or tablet (up to 3) they wrap, so each gets full 1px grid lines instead: its right/bottom shadow, with the grid pulled 1px past the card so the outer ones are clipped.
- **Many numbers (more than 6) wrap, never squeeze.** A Super Admin sees every job's numbers (11 "At a glance", 8 "Waiting"). In one row each was ~90px and every label read "Peop…". Now: even rows of at most **4 on a laptop, 6 on a wide screen** (11 → 4 + 4 + 3, or 6 + 5), with grid lines between rows. And when "Waiting for you" + "At a glance" have more than 8 between them, they **stack** (each full width) instead of sharing a row.
- **Every number lines up:** a word value ("None yet", "Processing", "Oct 5") is smaller (`text-lg`) in the same height as a number (`text-3xl`), so it doesn't wrap onto two lines; a number with no line under it keeps an empty one. The job ("PEOPLE", "PAYROLL"), for someone with several, is its own small line above the label (`group`), not a "People · " that cut the label short; the full label is also a hover tooltip.
- Icons are drawn at `stroke-width="2"` (the icon component's default is 1.5): at 16px the thinner line looked frail.

## Password fields (`<x-ui.input type="password">`)

Every password field gets an eye button inside it on the right: **Show password** turns the dots into text, **Hide password** turns them back. It starts hidden, each field has its own button (`aria-controls`), `aria-pressed` says which way it is, and the label changes for screen readers. It's in the shared input, so no page adds it by hand. Typing on a phone is error-prone; seeing what you typed saves failed sign-ins (and the rate limit).

## Error pages (`resources/views/errors/`)

One layout for all of them, simple and modern: **no card**. The big status number *is* the picture (gray, fading downward, `bg-clip-text`; never coloured text), with a soft blue glow and a faint grid fading out behind it (clipped, so a phone never scrolls sideways). The OSSI seal sits in a slim header. Then one plain title, one sentence, **one blue button** for the way forward and a quiet text button for the other, and a help line at the bottom. Light or dark as the user chose. They use no session, login or database, so they still show when those are what broke. (The first version, a card with an icon, read as "a box inside a box".)

| Page | Big line · small line | Buttons | Special |
|---|---|---|---|
| 401 | **Sign in first.** · You need to be signed in to see this. | Sign in | |
| 403 | **Not for your role.** · Ask your Super Admin if you need access. | Go back · Take me home | The small line is the app's own reason when there is one ("This employee is outside your region.") |
| 404 | **Nothing here.** · This page moved, or the link has a typo. | Go back · Take me home | |
| 419 | **This page expired.** · Go back, refresh, and try again. | Sign in again · Go back and refresh | |
| 429 | **Slow down a little.** · Too many tries. Wait 43 seconds. | Take me home · Try again | Counts down from `Retry-After`; Try again unlocks at 0 |
| 500 | **Something broke.** · Not your fault. We're on it, so try again shortly. | Take me home · Try again | Shows the time for IT to find in the logs; never the error itself |
| 503 | **Back in a few minutes.** · We're updating the app. This page checks by itself. | Check now | Rechecks every 30 s; tells guards to note the time if they were clocking in |

**"Take me home", not "Go to the dashboard":** guards have no dashboard, and a visitor may not be signed in. The button goes to `/home`, which sends each person to the right place: not signed in → sign-in (and back afterwards); a guard or employee → My Attendance; staff → the Dashboard. The page itself doesn't try to tell who's signed in: a 404 or a server error often never starts the session, so it would guess wrong.

Tests: `tests/Feature/Security/ErrorPagesTest.php`.

## The notification badge

On the bell's top-right shoulder, cut out from it by a ring in the header's colour (`ring-2 ring-white`, `dark:ring-gray-900`): a circle up to 9, a pill for "9+", tabular digits so it doesn't shift, `pointer-events-none` so it never blocks the click. Positions were tried in screenshots at 1, 3 and 9+: further out it floated away, further in it covered the bell.

## Dialogs and the backdrop (`<x-ui.modal>`, `app.css`)

`wire:transition` names the backdrop and the panel, and Livewire runs the browser's View Transitions API whenever it adds, removes or updates them. The open and close animations apply only when one snapshot exists (`:only-child`); updates while open are instant.

**The flash bug (fixed):** switching Field / Office staff inside an open dialog flashed the page darker for a quarter of a second. During an update the browser shows the old and the new snapshot of the backdrop at once: two 60% layers. How it was found:

1. A sidebar pixel through the backdrop measured **118** at rest and **63** mid-switch, which is exactly two layers.
2. Hiding each layer in turn showed it was the **old backdrop snapshot**.
3. The first fix (`::view-transition-old(...):not(:only-child)`) did nothing: Chrome matches `:only-child` on these snapshots but **never `:not(:only-child)`**. `display: none` is ignored on them too.

The fix: the old snapshot is always `opacity: 0`, and the close keyframes start explicitly `from { opacity: 1 }`, so a real close still fades out (measured 118 → 188 → 255).

## Grouping a form's fields

Say a thing once for the group, not on every field: the employee form's **Client site** heading covers Region, Province and Detachment (a "Client site" tag beside each label was tried first and read as clutter). See [People](05-people.md#the-employee-form-number-hire-type-and-client-site).

## On/off switch (`x-ui.switch`)

For a setting that is on or off (e.g. **Test account** on Add employee), use `<x-ui.switch wire:model.live="..." label="..." description="..." />` rather than a checkbox: a bordered row, text on the left, a sliding track on the right (blue when on). It is a real checkbox with `role="switch"`, so `wire:model`, Space/Tab and screen readers work. Checkboxes stay for lists where several items are ticked.

## Heavy libraries load on demand

`app.js` is what every page loads, so it stays small (89 KB, 28 KB compressed). Chart.js (`charts.js`), Leaflet with its CSS (`away-map.js`) and jsQR (`patrol-scanner.js`, only the fallback when the browser has no BarcodeDetector) are dynamic `import()`s: each is its own file, loaded the first time a chart, a map or a fallback scan needs it, and cached after. A component that may be removed while its library loads checks it is still on the page before drawing. Before 2026-10-21 everything was in one 568 KB `app.js`. New heavy dependencies follow the same pattern.

## Durations (`App\Support\Duration`)

Show minutes the way people read them: `Duration::minutes(314)` → "5 hr 14 min", 60 → "1 hr", 45 → "45 min". Pass `short: true` ("5h 14m") only for tight table cells. Settings where HR types minutes (grace, late brackets) stay in minutes.

## Dark mode: coloured text uses the -400 shade

Measured (WCAG, normal text needs 4.5:1): on our dark backgrounds (gray-950 page, gray-900 cards, gray-800 menus) the 500-700 shades fail, e.g. `blue-600` on a card is 3.4 and `red-600` 3.7, while every **-400** passes (`blue-400` 6.7). So any class list that colours text, or its hover, with a 500-700 shade also says `dark:text-{colour}-400` and `dark:hover:text-{colour}-300`:

`class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300"`

The active-tab underline uses `dark:border-blue-400` too. Checkboxes are the exception (there `text-blue-600` is the box's fill). `tests/Unit/DarkModeColorsTest.php` fails if a view adds coloured text without its dark colour.

## Closing windows

Forms (`x-ui.modal`), the clock-in camera and photo viewers close only with ✕, Cancel or Esc, never with a tap outside: on a phone that tap is too easy by accident. Small menus (the bell, the account menu, dropdowns, the phone sidebar) do close on a tap outside, as menus usually do.

Hover text follows the same rule: `hover:text-gray-700/800/900` (right on white) needs a `dark:hover:text-…` (e.g. `dark:hover:text-gray-100`), or it vanishes on dark cards. The ghost button hovers to white in dark mode. Tested by `DarkModeColorsTest`.
