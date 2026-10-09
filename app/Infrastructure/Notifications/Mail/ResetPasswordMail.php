<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class ResetPasswordMail extends Mailable
{
    /**
     * $token exists so the feature test can capture the plaintext without parsing the URL -
     * a test that regex-scrapes a rendered template fails for reasons that have nothing to
     * do with the behaviour under test.
     */
    public function __construct(
        public readonly string $displayName,
        public readonly string $resetUrl,
        public readonly string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your password');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.reset-password');
    }
}
