<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class PlanInviteMail extends Mailable
{
    /**
     * $token exists so a feature test can capture the plaintext without parsing the URL - a test
     * that regex-scrapes a rendered template fails for reasons that have nothing to do with the
     * behaviour under test. ResetPasswordMail carries it for the same reason.
     */
    public function __construct(
        public readonly string $inviterName,
        public readonly string $planTitle,
        public readonly string $inviteUrl,
        public readonly string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'You have been invited to a plan on Tandem');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.plan-invite');
    }
}
