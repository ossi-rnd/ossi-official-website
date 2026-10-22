<?php

namespace App\Enums;

enum PayrollRunStatus: string
{
    case Draft = 'draft';
    case Processing = 'processing';
    case Approved = 'approved';
    case Paid = 'paid';

    /**
     * The lifecycle only moves forward: draft → processing → approved → paid.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::Draft => self::Processing,
            self::Processing => self::Approved,
            self::Approved => self::Paid,
            self::Paid => null,
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function canGeneratePayslips(): bool
    {
        return $this === self::Processing;
    }

    /**
     * Draft or processing: the run is still being prepared, so its payslips
     * (and the loans they will take) can still change. Approved and paid runs are closed.
     */
    public function isOpen(): bool
    {
        return $this === self::Draft || $this === self::Processing;
    }

    public function isVisibleToEmployees(): bool
    {
        return $this !== self::Draft;
    }
}
