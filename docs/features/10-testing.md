# 10. Testing and quality gates

`composer test` = `config:clear` → Pint check → PHPStan (Larastan **level 8**) → Pest. CI runs the same on every push.

## Suite (185 tests, 462 assertions)

| Folder | What it proves |
| --- | --- |
| `tests/Unit/PayslipCalculatorTest.php` | Worked example = ₱7,787, holiday premiums, tax by tier, night diff, late brackets, loans. No Laravel boot: rates built by hand |
| `tests/Unit/AttendanceCalculatorTest.php` | Late/undertime/overtime, "start + grace + 5 = 5 late", overnight shifts |
| `tests/Unit/RateScheduleResolverTest.php` | Effective dating |
| `tests/Unit/ArchitectureTest.php` | House rules (below) |
| `tests/Feature/Auth` | Registration rule (every failure reason + audit), rate limits, login, deactivated users, verification, reset |
| `tests/Feature/Security` | Privacy gate and versioning, idle timeout, headers, deactivation, XSS escaping |
| `tests/Feature/Access` | Every role × every staff page; role removal blocks the next write |
| `tests/Feature/Attendance` | Punch rules, double tap, photo privacy, geocoder failure and throttle |
| `tests/Feature/Requests` | Leave/overtime flows, separation of duties, approved ≤ requested |
| `tests/Feature/Payroll` | Frozen payslips, OT cap, loans, lifecycle, identical numbers on both screens |
| `tests/Feature/PagesRenderTest.php` | Seeds the full demo and renders every page |

## Architecture rules (Pest `arch()`)

Actions final with `handle()`; Queries final with `handle()`; Data readonly and final; controllers invokable; Livewire classes extend `Component`; **no `DB` facade in Livewire**; calculators use no facades or models; notifications are queued; `php` and `security` presets; no `dd`/`dump`.

## Habits

- `tests/Pest.php` helpers: `employee()`, `staff(Role::Hr)`, `cameraPhoto()` (a real JPEG via GD), `payrollRun()`.
- `TestCase` fakes the photo disk, binds `FakeReverseGeocoder`, and disables Vite.
- Time is pinned with `$this->travelTo(...)`, so late-minute assertions are exact.
- Role-removal test calls `actingAs($hr->fresh())`, because every real request reloads the user.

## PHPStan notes

- Livewire `#[Computed]` methods are read as properties; declare them with `@property-read` on the class.
- `withSum()`/`withCount()` columns need `@property-read` on the model.
- `->values()->all()` is typed `array<int, T>`, not `list<T>`; wrap with `array_values()` where a `list` is promised.
- `$a?->b ?? $c`: the `?->` is redundant, `??` already tolerates null.
