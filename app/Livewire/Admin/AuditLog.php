<?php

namespace App\Livewire\Admin;

use App\Enums\AuditArea;
use App\Models\ActivityLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Audit Log')]
class AuditLog extends Component
{
    use WithPagination;

    #[Url(as: 'area', except: 'all')]
    public string $area = 'all';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'from', except: '')]
    public string $dateFrom = '';

    #[Url(as: 'to', except: '')]
    public string $dateTo = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, ActivityLog>
     */
    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        return ActivityLog::query()
            ->with('causer')
            ->when(AuditArea::tryFrom($this->area), fn ($query, AuditArea $area) => $query->where('log_name', $area))
            ->when($this->search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('description', 'like', "%{$this->search}%")
                ->orWhere('event', 'like', "%{$this->search}%")
                ->orWhereHas('causer', fn ($causer) => $causer->where('name', 'like', "%{$this->search}%"))))
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->dateFrom) === 1, fn ($query) => $query->whereDate('created_at', '>=', $this->dateFrom))
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->dateTo) === 1, fn ($query) => $query->whereDate('created_at', '<=', $this->dateTo))
            ->latest('id')
            ->paginate(30);
    }

    public function render(): View
    {
        return view('livewire.admin.audit-log', ['areas' => AuditArea::cases()]);
    }
}
