# 4. Audit log

## Design

- Table `activity_log`: `log_name` (area enum), `event` (`leave.approved`…), `description`, polymorphic `subject`, `causer_id`, `properties` JSON (`{"old": …, "new": …}`), `ip_address`, `created_at`.
- `App\Models\ActivityLog` is **append-only**: no `updated_at`, and `updating`/`deleting` events throw. An audit trail that can be edited proves nothing.
- `App\Services\AuditLogger::record()` writes one entry. `AuditLogger::diff()` keeps only changed keys and masks sensitive ones.

## Written inside the same transaction

```php
DB::transaction(function () use (...) {
    $locked->forceFill([...])->save();
    $this->audit->record(AuditArea::Leave, 'leave.approved', ..., $locked, $changes, causer: $reviewer);
});
```

The change and its audit row commit together or not at all. It is deliberately **not** queued: a lost audit row is worse than a few milliseconds.

Exception: a refused registration is recorded *after* the transaction, because nothing else is saved.

## What is recorded

Registration (succeeded / failed + reason), privacy acknowledgement, role changes, employee record created/updated, leave approved/rejected/cancelled, overtime approved/rejected, payroll run created/advanced/paid, payslip generated, deductions added/paused/deleted, shifts and holidays saved/deleted (late brackets were until 2026-10-23, when they were removed).

The Audit Log page (`view-system-logs`) filters by area, text and date, and shows old values struck through next to new ones.

Tests: audit assertions sit next to each feature's tests (search `ActivityLog::query()` in `tests/`).
