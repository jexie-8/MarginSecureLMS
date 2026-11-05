<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;

class MarginResetPasswordNotification extends ResetPassword
{
    protected function resetUrl($notifiable): string
    {
        return rtrim((string) config('margin.frontend_url'), '/').'/reset-password?token='.urlencode($this->token).'&email='.urlencode($notifiable->getEmailForPasswordReset());
    }
}
