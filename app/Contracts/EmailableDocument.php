<?php

namespace App\Contracts;

use App\Enums\DocumentEmailKind;
use App\Models\Customer;
use Illuminate\Mail\Mailable;

/**
 * A document that can be emailed to a customer as a PDF and logged in
 * document_emails. Implemented by Invoice, CreditNote and Statement, plus the
 * OrderDocket adapter so the existing docket email lands in the same log.
 *
 * App\Services\DocumentMailer is the only thing that should call these.
 */
interface EmailableDocument
{
    public function documentKind(): DocumentEmailKind;

    public function documentCustomer(): ?Customer;

    public function documentSubject(): string;

    public function documentFilename(): string;

    /** The PDF payload, already rendered. */
    public function documentPdfData(): string;

    /** The mailable used to send it. */
    public function documentMailable(): Mailable;

    /** Record a successful send (e.g. stamp emailed_at). */
    public function markEmailed(\Illuminate\Support\Carbon $at): void;

    /** Preferred recipient address, or null when none is on file. */
    public function documentRecipient(): ?string;
}
