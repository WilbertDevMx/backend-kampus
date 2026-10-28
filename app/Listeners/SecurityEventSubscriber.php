<?php

namespace App\Listeners;

use App\Support\Security\MasksEmail;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Registra en el canal `security` los eventos de autenticación que conviene auditar.
 */
class SecurityEventSubscriber
{
    public function handleLockout(Lockout $event): void
    {
        Log::channel('security')->warning('Bloqueo por intentos fallidos de inicio de sesión.', [
            'email' => MasksEmail::mask((string) $event->request->input('email')),
            'ip' => $event->request->ip(),
        ]);
    }

    public function handleRegistered(Registered $event): void
    {
        Log::channel('security')->info('Cuenta registrada.', [
            'user_id' => $event->user->getAuthIdentifier(),
        ]);
    }

    public function handleVerified(Verified $event): void
    {
        Log::channel('security')->info('Correo verificado.', [
            'user_id' => $event->user->getAuthIdentifier(),
        ]);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Lockout::class => 'handleLockout',
            Registered::class => 'handleRegistered',
            Verified::class => 'handleVerified',
        ];
    }
}
