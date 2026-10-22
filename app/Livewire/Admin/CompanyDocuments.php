<?php

namespace App\Livewire\Admin;

use App\Actions\Documents\DeleteCompanyDocument;
use App\Actions\Documents\StoreCompanyDocument;
use App\Enums\Ability;
use App\Enums\DocumentCategory;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\Client;
use App\Models\CompanyDocument;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Admin's library: company documents, licenses (with due dates) and client /
 * legal documents, one tab each. Renew uploads a new copy and keeps the old.
 *
 * @property-read Collection<int, CompanyDocument> $documents
 * @property-read array<string, int> $dueCounts
 * @property-read Collection<int, Client> $clients
 */
#[Title('Documents')]
class CompanyDocuments extends Component
{
    use InteractsWithUser, WithFileUploads;

    #[Url(as: 'tab', except: 'company')]
    public string $tab = 'company';

    public bool $formOpen = false;

    /** The document being renewed, or null for a new upload. */
    public ?int $renewingId = null;

    public string $title = '';

    public string $clientId = '';

    public string $referenceNumber = '';

    public string $issuedOn = '';

    public string $expiresOn = '';

    public string $notes = '';

    /** @var UploadedFile|null */
    public $file = null;

    private const FIELDS = ['renewingId', 'title', 'clientId', 'referenceNumber', 'issuedOn', 'expiresOn', 'notes', 'file'];

    public function selectTab(string $tab): void
    {
        $this->tab = DocumentCategory::tryFrom($tab)->value ?? 'company';
    }

    public function create(): void
    {
        $this->authorize(Ability::ManageDocuments->value);

        $this->reset(self::FIELDS);
        $this->resetErrorBag();
        $this->formOpen = true;
    }

    public function renew(int $id): void
    {
        $this->authorize(Ability::ManageDocuments->value);

        $document = CompanyDocument::query()->current()->findOrFail($id);
        $this->reset(self::FIELDS);
        $this->renewingId = $document->id;
        $this->title = $document->title;
        $this->clientId = (string) $document->client_id;
        $this->referenceNumber = (string) $document->reference_number;
        $this->notes = (string) $document->notes;
        $this->resetErrorBag();
        $this->formOpen = true;
    }

    public function cancel(): void
    {
        $this->reset(['formOpen', ...self::FIELDS]);
    }

    public function save(StoreCompanyDocument $store): void
    {
        $this->authorize(Ability::ManageDocuments->value);

        $category = $this->category();

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'clientId' => ['nullable', 'integer', 'exists:clients,id'],
            'referenceNumber' => ['nullable', 'string', 'max:100'],
            'issuedOn' => ['nullable', 'date'],
            // A license without a due date can't be tracked: required on that tab.
            'expiresOn' => [$category === DocumentCategory::License ? 'required' : 'nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.(int) config('documents.max_kilobytes')],
        ], ['expiresOn.required' => 'Enter the due date: that\'s what the renewal alerts count down to.'], [
            'expiresOn' => 'due date', 'issuedOn' => 'issue date', 'referenceNumber' => 'reference number',
        ]);

        /** @var UploadedFile $file */
        $file = $this->file;

        $store->handle([
            'category' => $category,
            'title' => $validated['title'],
            'client' => filled($validated['clientId']) ? Client::query()->find((int) $validated['clientId']) : null,
            'reference_number' => filled($validated['referenceNumber']) ? trim($validated['referenceNumber']) : null,
            'issued_on' => filled($validated['issuedOn']) ? CarbonImmutable::parse($validated['issuedOn']) : null,
            'expires_on' => filled($validated['expiresOn']) ? CarbonImmutable::parse($validated['expiresOn']) : null,
            'notes' => filled($validated['notes']) ? trim($validated['notes']) : null,
        ], $file, $this->currentUser(), $this->renewingId !== null ? CompanyDocument::query()->findOrFail($this->renewingId) : null);

        $renewed = $this->renewingId !== null;
        $this->cancel();
        unset($this->documents, $this->dueCounts);
        $this->dispatch('notify', message: $renewed ? 'Renewal uploaded. The old copy is kept in its history.' : 'Document uploaded.');
    }

    public function delete(int $id, DeleteCompanyDocument $delete): void
    {
        $this->authorize(Ability::ManageDocuments->value);

        $delete->handle(CompanyDocument::query()->current()->findOrFail($id), $this->currentUser());
        unset($this->documents, $this->dueCounts);
        $this->dispatch('notify', message: 'Document deleted.');
    }

    private function category(): DocumentCategory
    {
        return DocumentCategory::tryFrom($this->tab) ?? DocumentCategory::Company;
    }

    /**
     * Current copies on this tab. Licenses by due date (soonest first); the rest by title.
     *
     * @return Collection<int, CompanyDocument>
     */
    #[Computed]
    public function documents(): Collection
    {
        return CompanyDocument::query()
            ->current()
            ->where('category', $this->category())
            ->with(['client', 'uploader'])
            ->withCount(['earlierCopies'])
            ->when($this->category() === DocumentCategory::License,
                fn ($query) => $query->orderByRaw('expires_on is null')->orderBy('expires_on'),
                fn ($query) => $query->orderBy('title'))
            ->get();
    }

    /**
     * Due within 30 days or overdue, per tab: the badge on each tab.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function dueCounts(): array
    {
        return CompanyDocument::query()
            ->current()
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', today()->addDays(CompanyDocument::DUE_SOON_DAYS)->toDateString())
            ->toBase()
            ->selectRaw('category, count(*) as total')
            ->groupBy('category')
            ->pluck('total', 'category')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * @return Collection<int, Client>
     */
    #[Computed]
    public function clients(): Collection
    {
        return Client::query()->orderBy('name')->get();
    }

    public function render(): View
    {
        return view('livewire.admin.company-documents', [
            'category' => $this->category(),
            'renewing' => $this->renewingId !== null ? CompanyDocument::query()->find($this->renewingId) : null,
        ]);
    }
}
