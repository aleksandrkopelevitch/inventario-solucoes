<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent to the NEW address when somebody changes their e-mail on the profile. */
class ConfirmEmailChangeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public string $newEmail, public string $confirmUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Confirme seu novo e-mail no ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.confirm-email-change',
        );
    }
}
