<?php

namespace App\Video;

/**
 * Proveedor de videollamadas (T-19, RF-10). Es la interfaz del patrón Adapter: el resto del sistema solo
 * conoce estas tres operaciones, y LiveKit queda detrás de {@see LiveKit\LiveKitProvider}. Para cambiar a
 * otro proveedor (Daily, Agora o LiveKit autoalojado) basta otra implementación y cambiar el binding en
 * `AppServiceProvider`.
 */
interface VideoCallProvider
{
    /**
     * Crea la sala si no existe, con un máximo de participantes. Si ya existe, no hace nada.
     *
     * @throws VideoCallUnavailable si el proveedor no está configurado o no responde.
     */
    public function openRoom(string $room, int $maxParticipants): void;

    /**
     * Credenciales para que una persona entre a la sala desde la app. El límite de participantes viaja también
     * en las credenciales, por si el proveedor solo lo hace cumplir desde ahí.
     *
     * @throws VideoCallUnavailable si el proveedor no está configurado.
     */
    public function accessFor(string $room, string $identity, string $name, int $maxParticipants): VideoCallAccess;

    /**
     * Cierra la sala y saca a quien siga conectado. Si la sala ya no existe, no hace nada.
     *
     * @throws VideoCallUnavailable si el proveedor no está configurado o no responde.
     */
    public function closeRoom(string $room): void;
}
