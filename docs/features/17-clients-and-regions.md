# 17. Clients by region

**Operation HQ, Admin** (or the Super Admin; since 2026-10-21, ability `manage-clients`, in the Management menu) files every client site by **where it is**: Region → Province → Client, for example **Region IV-A → Cavite → Metrobank – Imus**. HR then picks one as a guard's detachment. This is the deployment site, **never** a guard's home address (that stays in the profile's Contact section).

## The page

**Clients** (System, `configure-system`):

- One tab per region that has clients, plus **All regions**, each with a count. Rows are grouped by province.
- **Add client:** choose the region, then the province (the list depends on the region; NCR lists cities), then type the client name. Site address, contact person and contact number are optional.
- **Geofence:** paste the post's coordinates (Google Maps → right-click the site → click the numbers) and set a radius (default 150 m, 30–2,000). Guards can only clock in and out within it. A client without coordinates shows "No geofence yet". See [Duty Roster and anti-cheating](23-duty-roster.md).
- The same name can't be added twice in one province. Add the branch to the name: "Metrobank – Imus", "Metrobank – Dasmariñas".
- **Guards at a client:** click the client's name or its **N guards** count. A window lists everyone deployed there, sorted by post: name, employee number, post and position, shift, **today's attendance** and employment status, with **View** to open each record. Active staff come first.

## Today at the site (`ClientAttendanceTodayQuery`)

The guards window is a live check of whether every post is covered. It refreshes every minute while open.

| Today | When | Needs attention |
| --- | --- | --- |
| **On duty** (amber if late, with minutes) | Clocked in, not out | |
| Clocked out | In and out today | |
| **Absent · needs reliever** | Sent an absence notice; no reliever yet | Yes |
| Absent · covered | Notice with a reliever assigned | |
| On leave / Holiday | Approved leave, or a holiday | |
| Shift not started | Before the shift's start time | |
| Not in yet | Shift started, still within grace | |
| **No clock-in** | Past the grace minutes, no clock-in, no notice | Yes |
| **Absent · no clock-in** | The shift ended with no clock-in and no notice | Yes |

- Counts across the top: on duty, not in, absent, shift not started.
- A red alert says how many posts may be uncovered, with a link to **Relievers**.
- **Relievers here today** lists who is covering for whom at this site.
- A real clock-in always wins: a guard who sent a notice and came anyway shows On duty.
- **Night shifts:** until a 22:00–06:00 shift ends, "today" for that guard is the shift that started last night. At 03:00 they show On duty from last night's clock-in, not "Shift not started".
- Built in a few bulk queries (time logs, notices, leave, holidays) for all the site's guards at once, so a site with 50 guards is still one quick load.
- **Delete** is locked while guards are deployed there (the tooltip says how many). Move them first.

## Regions and provinces (`App\Enums\Region`)

All 18 regions, including **NIR** (Negros Island Region, RA 12000 of 2024). Sulu is listed under Region IX (Supreme Court, 2024). Each region's provinces are a fixed list in `Region::provinces()`, so "cavite" and "Cavite " can never split one province in two. If boundaries change, edit that list.

## Guards and clients

- `employee_profiles.client_id` points at the client, and `detachment` keeps the client's **name** as text. Every screen and report that already showed a detachment keeps working.
- **Renaming a client** updates `detachment` on every guard deployed there, in the same transaction (`SaveClient`).
- The employee form's **Detachment (client)** field lists every client as "Name · Province, Region".
- **Detachments typed before clients existed** are kept as they are, with a hint ("Currently "SM Megamall" … Pick its client"), until HR picks the client. Editing a phone number never erases them.
- The employee record shows **Detachment**, **Location** (Province, Region) and **Post**.

## Change deployment (Operations)

Holders of `deploy-guards` without full edit access (Satellite Operation) get **Change deployment** on a guard's record: client, post and shift, nothing else (`DeployEmployee`). A Satellite only sees its own region's clients in that list, and the Action refuses a client or a guard outside the region. See [Roles](02-roles-and-gates.md#regional-scope-satellite-operation).

## Code

- `App\Enums\Region`, `App\Models\Client`
- `SaveClient`, `DeleteClient`, `DeployEmployee`
- `App\Livewire\Admin\Clients`
- Migration `2026_10_10_100001_create_clients_table`
- Seeder: `ClientSeeder` (ten sample sites; it links guards whose typed detachment matches a client's name; safe to run again)

Tests: `tests/Feature/People/ClientsTest.php`.

## Try it

1. As `superadmin@example.test`: Clients → Add client → Region IV-A → Cavite → "Metrobank – General Trias".
2. As `hr@example.test`: open a guard → Edit record → Detachment (client) → pick it.
3. As `satellite@example.test`: Employees shows only guards at clients in the account's region. The demo sets Region IV-A, so that's the Cavite and Laguna clients. Open one → Change deployment.
4. As super admin: Roles & Access → Dennis Ocampo → Change roles → **Region covered** → pick another region (for example NCR) → Save. Signed in as the Satellite account again, the lists now show that region's guards.

## Setting the post's location

The Add / Edit client form fills **Post coordinates** three ways (`resources/js/site-picker.js`), all writing the same "latitude, longitude" text `Clients::save` always read:

- **Use my current location**: standing at the post, the phone's GPS fills it, with its precision ("GPS precise to ±12 m"; over 30 m it says the fix is rough: step outside and try again). The recommended way: create the client from the office, then set the point on the first visit to the site.
- **The map**: click to place the pin or drag it; **Full screen** (top-right of the map) opens it over the whole screen to place the pin precisely (the browser's full screen, or on an iPhone the map covering the screen; mouse-wheel zoom works there; **Done** or Esc returns to the form); the blue circle is the geofence, kept fully in view when the point or the geofence size changes, so its edge can be checked against the post.
- **Paste** from Google Maps, as before; the pin follows.

The map is Leaflet with OpenStreetMap tiles, loaded only when the form opens (`leaflet-loader.js`, shared with the away map). The pin is a drawn dot (Leaflet's image markers don't survive bundling). The person's own location is only used to fill the field; nothing about them is stored.

## OSSI offices (for office staff)

OSSI's own offices (head office, branches) are a separate list, **OSSI Offices** in the Management menu (`offices` table, `App\Models\Office`, ability `manage-offices`: Admin and Super Admin). They are **not clients**: clients feed guard deployment, the duty roster, area filters and the Organization chart's field side, and an office must never show up there.

- Same form as a client (Region → Province → name, address, and the location picker with geofence), via the shared partial `partials/site-location-fields`.
- HR picks an office on an **office staffer's** record (`employee_profiles.office_id`); guards never have one (cleared when someone becomes field staff).
- **Clock-in:** an office staffer with an office must be inside its circle to clock in or out (`RecordPunch`: "You're 3.0 km from OSSI Head Office. Clock in at the office."). One with no office set can still clock in anywhere, as before.
- The away map uses the staffer's office (`UpdateAwayLocation::postFor`), falling back to the old `OFFICE_LATITUDE` / `OFFICE_LONGITUDE` setting.
- An office with staff can't be deleted: move them first.
