<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class VerifyEmailMail extends Mailable
{
    public function __construct(
        public readonly string $displayName,
        public readonly string $verificationUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm your email address');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.verify-email');
    }
}
