<?php

namespace App\Livewire\Concerns;

use App\Support\Acknowledgements;

/**
 * The language an acknowledgement is read in (English or Filipino), switched on
 * the form (partials.acknowledgement). The record keeps the lines in that language.
 */
trait ChoosesAcknowledgementLanguage
{
    public string $ackLocale = 'en';

    public function updatedAckLocale(): void
    {
        $this->ackLocale = Acknowledgements::locale($this->ackLocale);
    }
}
