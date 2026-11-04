<?php

namespace App\Notifications;

use App\Enums\GroupStatus;
use App\Models\Group;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Le avisa a quien creó un grupo que ya se revisó (T-42, RF-23): si se publicó o, si no, el motivo.
 */
class GroupReviewedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Group $group) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->greeting('Hola');

        if ($this->group->status === GroupStatus::Approved) {
            return $mail
                ->subject("Tu grupo \"{$this->group->name}\" ya está publicado")
                ->line("Revisamos tu grupo \"{$this->group->name}\" y ya aparece en la sección de grupos de Kampus.")
                ->line('Ahora otros estudiantes pueden unirse y tú puedes publicar actividades.')
                ->salutation('El equipo de Kampus');
        }

        return $mail
            ->subject("Tu grupo \"{$this->group->name}\" no se aprobó")
            ->line("Revisamos tu grupo \"{$this->group->name}\" y no se pudo publicar por este motivo:")
            ->line($this->group->rejection_reason ?? '')
            ->line('Puedes crear otro grupo que cumpla las reglas de la comunidad.')
            ->salutation('El equipo de Kampus');
    }
}
