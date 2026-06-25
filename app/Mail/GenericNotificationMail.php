<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class GenericNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $htmlBody,
        public ?string $textBody = null,
    ) {}

    public function build(): self
    {
        return $this->subject($this->subjectLine)
            ->html($this->htmlBody)
            ->when($this->textBody, fn ($m) => $m->text('emails.generic-text', ['text' => $this->textBody]));
    }
}
