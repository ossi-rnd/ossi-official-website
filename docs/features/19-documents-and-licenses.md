# 19. Documents and licenses

Admin's library, under **Documents** (`manage-documents`: Admin, Super Admin), with three tabs:

| Tab | For | Due date |
| --- | --- | --- |
| Company documents | SEC registration, BIR 2303, certificates | Optional |
| **Licenses** | PNP-SOSIA License to Operate, business permit, firearms registration | **Required**: it's what the renewal alerts count down to |
| Client & legal | Service agreements and legal documents, optionally tied to a client | Optional |

## Uploading and renewing

- **Upload:** title, optional reference number, issue date, due date and notes, plus the file (PDF, JPG or PNG, up to 10 MB). Files are checked by their contents (`PrivateUploadStore`), stored on a **private** disk, and only streamed to `manage-documents` (`ShowCompanyDocumentController`). Every view is audited.
- **Renew** uploads the new copy with its new due date. The renewal is a **new row**, and the old one points at it (`replaced_by_id`), so nothing is overwritten and each license keeps its history. Lists show current copies only.
- **Delete** removes a document and its earlier copies, for an upload made by mistake. Use Renew for a new version.
- Badges: **Overdue N days** (red) and **Due in N days** (amber, within 30 days). The tab and the sidebar count those.

## Renewal alerts

`php artisan app:send-document-alerts`, scheduled daily at **07:10** (`routes/console.php`), sends alerts to Admin:

- 60, 30 and 7 days before the due date, and on the day (`config('documents.expiry_alert_days')`).
- Only the nearest threshold is sent, **once** per document and due date (claimed in `sent_alerts`), so running it twice sends nothing new, a missed week never sends two, and a renewal gets its own alerts.
- Bell always, and email too from 30 days out (`DocumentDueNotice`).

## Configuration

`config/documents.php`, with disk `COMPANY_DOCUMENTS_DISK`. When that's empty, it uses `HR_DOCUMENTS_DISK`, then `ATTENDANCE_PHOTO_DISK`. On Laravel Cloud it must be the bucket (see [Deployment](11-deployment.md)).

## Code

- `StoreCompanyDocument` (upload and renew), `DeleteCompanyDocument`, `SendDocumentExpiryAlerts`
- `CompanyDocument`, `DocumentCategory`
- `App\Livewire\Admin\CompanyDocuments`
- `App\Console\Commands\SendDocumentExpiryAlerts`, `DocumentDueNotice`

Tests: `tests/Feature/Operations/AdminModulesTest.php` (documents): the due date is required for licenses, renewal keeps the old copy, alerts fire once at the nearest threshold, Admin only.

## Try it

1. As `admin@example.test`: Documents → Licenses. The sample firearms registration is overdue and the License to Operate is due in 20 days.
2. Renew → upload any PDF with a new due date. The badge clears and "renewed copy" shows.
3. `php artisan app:send-document-alerts`: Admin's bell shows the due notices.
