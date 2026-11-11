# WebSocket con Laravel Reverb

El backend usa Laravel Reverb como broadcaster y expone la autorización de canales privados en
`POST /api/broadcasting/auth`. La ruta requiere un token de Sanctum y una cuenta con correo verificado.
Este repositorio no instala dependencias de Node ni configura el cliente del frontend.

## Configuración

Configura estas variables en `.env` y usa el mismo `REVERB_APP_KEY` en el frontend. Genera credenciales
propias para cada entorno; no publiques `REVERB_APP_SECRET`.

```dotenv
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
REVERB_ALLOWED_ORIGINS=*
```

`REVERB_HOST`, `REVERB_PORT` y `REVERB_SCHEME` son la dirección que usa Laravel para enviar eventos.
`REVERB_SERVER_HOST` y `REVERB_SERVER_PORT` indican dónde escucha el servidor. En producción, configura
TLS y restringe los orígenes con `REVERB_ALLOWED_ORIGINS` (los hosts de la app, separados por comas).

`.env.example` trae `BROADCAST_CONNECTION=log`, para que el proyecto funcione sin el servidor de Reverb: los
eventos solo se escriben en el log. Cámbialo a `reverb` cuando quieras probar el tiempo real.

## Canales privados

| Canal de Echo | Autorización |
| --- | --- |
| `chat.{matchId}` | Solo participantes activos del match; se deniega si el usuario está suspendido o si cualquiera bloqueó al otro. |
| `user.{userId}` | Solo el usuario autenticado puede suscribirse a su propio canal, si no está suspendido. |

El cliente Echo no debe anteponer `private-` al nombre pasado a `Echo.private()`. Laravel añade ese prefijo
al nombre enviado al endpoint de autorización.

## Configuración de Echo en el frontend

Instala y configura `laravel-echo` y `pusher-js` en el repositorio frontend. Apunta la autorización al API
de este backend y adjunta el token de Sanctum:

```js
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
    wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: `${import.meta.env.VITE_API_URL}/api/broadcasting/auth`,
    auth: {
        headers: {
            Authorization: `Bearer ${accessToken}`,
        },
    },
});
```

Define `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME` y
`VITE_API_URL` en el entorno del frontend. La clave pública debe coincidir con `REVERB_APP_KEY`; nunca
expongas el secreto de Reverb en variables `VITE_`.

Los nombres para suscribirse son, por ejemplo, `Echo.private('chat.42')` y `Echo.private('user.7')`.
Al enviar un mensaje de texto por la API T-14, el backend publica `message.sent` en `chat.{matchId}`.
El payload contiene `message` con `id`, `match_id`, `sender_id`, `type`, `body`, `read_at` y `created_at`.
Con `broadcastAs`, Echo escucha el evento con punto inicial:

```js
Echo.private(`chat.${matchId}`).listen('.message.sent', ({ message }) => {
    // Agrega el mensaje recibido a la conversación.
});
```

Los eventos de match y de mensaje se entregan en los canales privados autorizados por el backend.

## Prueba manual

1. Configura las variables de Reverb en `.env` y asegúrate de tener usuarios verificados y un match
   existente entre sus IDs. El match no debe tener un bloqueo entre sus participantes.
2. En terminales separadas, inicia el API y Reverb:

   ```powershell
   php artisan serve --host=127.0.0.1 --port=8000
   php artisan reverb:start --host=0.0.0.0 --port=8080
   ```

3. En el frontend, conecta Echo con la configuración anterior y un token válido de Sanctum. La
   suscripción a `chat.{matchId}` o al canal propio `user.{userId}` debe obtener `200` en
   `POST /api/broadcasting/auth`.
4. Repite con el ID de otro usuario o de un match al que la cuenta no pertenezca; la autorización debe
   devolver `403`. Un usuario sin token debe recibir `401`; uno sin verificar, `403`.
5. Envía un mensaje de texto a un match autorizado y comprueba que Echo recibe `message.sent` con sus
   datos en `chat.{matchId}`. Una cuenta que no pertenezca a ese match no debe poder suscribirse al canal.
