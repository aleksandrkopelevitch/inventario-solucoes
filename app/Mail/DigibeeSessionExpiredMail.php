<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Tells the admins somebody tried to read the Digibee logs while the server's session was gone. */
class DigibeeSessionExpiredMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public ?string $expiredAt) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'A sessão da Digibee do ' . config('app.name') . ' expirou');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.digibee-session-expired', with: [
            'renewUrl' => route('digibee-session.index'),
        ]);
    }
}
