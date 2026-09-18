<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TenantAdminWelcome extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $tenantName,
        public readonly string $adminName,
        public readonly string $adminEmail,
        public readonly string $temporaryPassword,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Welcome to {$this->tenantName}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.tenant-admin-welcome');
    }
}
