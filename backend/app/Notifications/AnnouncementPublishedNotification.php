<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AnnouncementPublishedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $title, public string $body) {}

    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Margin LMS: '.$this->title)->greeting('Hello '.$notifiable->name.',')->line($this->body)->action('Open Margin LMS', (string) config('margin.frontend_url'));
    }
}
