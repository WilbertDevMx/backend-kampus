<?php

namespace App\Sms;

use Illuminate\Notifications\Notification;

/**
 * Canal `sms` de las notificaciones de Laravel. La notificación define `toSms()` y el número sale de la ruta
 * `sms` (`Notification::route('sms', $phone)`). Se registra en `AppServiceProvider`.
 */
class SmsChannel
{
    public function __construct(private readonly SmsSender $sender) {}

    public function send(object $notifiable, Notification $notification): void
    {
        $to = $notifiable->routeNotificationFor('sms', $notification);

        if (! is_string($to) || $to === '' || ! method_exists($notification, 'toSms')) {
            return;
        }

        $this->sender->send($to, $notification->toSms($notifiable));
    }
}
