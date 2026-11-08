# Kampus — Backend (API)

API REST de **Kampus**, la aplicación web para la integración social de estudiantes universitarios de la UTT, la UAdeC y el Tecnológico de la Laguna.

Este repositorio contiene únicamente el backend. La app (Expo, web y móvil) vive en su propio repositorio y consume esta API.

## Stack

| Componente | Tecnología |
|---|---|
| Framework | Laravel 13 (PHP 8.3) |
| Autenticación | Laravel Sanctum (tokens para SPA) |
| Base de datos | PostgreSQL 16 |
| Tiempo real | Laravel Reverb + Echo (chat y notificaciones de match) |
| Archivos | Bucket compatible con S3 (Cloudflare R2 / MinIO) |
| Videollamada | LiveKit, detrás de una interfaz `VideoCallProvider` |
| Pruebas | PHPUnit · Formato: Laravel Pint |

## Documentación

| Documento | Contenido |
|---|---|
| [Esquema de base de datos](docs/base-de-datos/esquema.md) | Diagrama entidad-relación, diccionario de datos, catálogos de valores y trazabilidad con los requisitos |
| [Arquitectura](docs/diagramas/arquitectura.md) | Diagrama de componentes y de módulos de la API (Mermaid) |
| [Casos de uso](docs/diagramas/casos-de-uso.svg) | Diagrama UML de casos de uso; la fuente editable es [`casos-de-uso.puml`](docs/diagramas/casos-de-uso.puml) |
| [Documentación del proyecto](https://doc.kodev.online/) | Anteproyecto, Unidad 2, referencia de la API y changelog de los tres repositorios |

Lo que se agrega con el módulo de Administración (Sprint 2) está marcado con **[Nuevo]** en el esquema y en amarillo en los diagramas.

## Requisitos

- PHP 8.3 o superior
- Composer 2
- PostgreSQL 16 (o MySQL 8, ver la nota más abajo)

## Puesta en marcha

```bash
git clone https://github.com/WilbertDevMx/backend-kampus.git
cd backend-kampus
composer install
cp .env.example .env
php artisan key:generate
```

Crea la base de datos, ajusta las credenciales en `.env` y levanta el servidor:

```bash
php artisan migrate
php artisan serve
```

La API queda disponible en `http://localhost:8000/api`.

> **Nota sobre la base de datos:** el proyecto está configurado para PostgreSQL porque la vista "Cerca" trabaja con datos geográficos. Si el equipo decide usar MySQL, basta con cambiar `DB_CONNECTION=mysql` y `DB_PORT=3306` en `.env`, y el servicio de base de datos en `.github/workflows/ci.yml`.

## Flujo de trabajo con ramas

| Rama | Uso |
|---|---|
| `main` | Código estable y desplegable. Solo recibe merges desde `develop`. |
| `develop` | Rama de integración. Es la rama por defecto y el destino de todos los PR. |
| `feature/T-XX-descripcion` | Una rama por tarea del cronograma. Ej.: `feature/T-05-api-login`. |
| `fix/descripcion` | Corrección de errores. |

**Nadie hace push directo a `main` ni a `develop`.** Todo entra por Pull Request con la revisión de al menos otro integrante.

```bash
git switch develop
git pull
git switch -c feature/T-05-api-login
# ...trabajas y haces commits...
git push -u origin feature/T-05-api-login
gh pr create --base develop
```

## Convención de commits

```
tipo: descripción breve en presente

feat: agrega endpoint de registro con validación de dominio
fix: corrige expiración del token de Sanctum
test: agrega pruebas del algoritmo de descubrimiento
docs: actualiza el contrato de la API
refactor: extrae la consulta de descubrimiento a un Builder
chore: configura el pipeline de CI
```

Tipos válidos: `feat`, `fix`, `perf`, `revert`, `docs`, `refactor`, `test`, `chore`, `ci`, `build` y `style`. El CI rechaza un PR si alguno de sus commits no sigue el formato, y el tipo decide la versión que se publica (ver [Versiones y changelog](https://doc.kodev.online/docs/development/releases)).

## Definition of Done

Una tarea se considera terminada cuando:

1. El código fue revisado por otro integrante mediante PR.
2. Las pruebas del módulo pasan.
3. La funcionalidad se verificó manualmente.
4. La documentación de la API quedó actualizada.

## Comandos útiles

```bash
php artisan test                 # Ejecuta las pruebas
./vendor/bin/pint                # Corrige el formato del código
./vendor/bin/pint --test         # Solo revisa el formato (lo mismo que hace CI)
php artisan migrate:fresh --seed # Reinicia la base de datos con datos de prueba
php artisan kampus:create-admin  # Crea el primer administrador (correo institucional)
```

## Roles

Cada cuenta tiene un rol en `users.role`: `student`, `moderator` (una sola universidad) o `admin` (todas). El registro público siempre crea `student`, y el staff no usa las funciones sociales.

| Pieza | Dónde | Para qué |
|---|---|---|
| `App\Enums\UserRole` | `app/Enums` | Valores del rol y `isStaff()` |
| Middleware `role` | `bootstrap/app.php` | `role:student` en las rutas sociales (perfil, descubrimiento y swipe); `role:moderator,admin` en el panel. Responde `403` a los demás |
| `UserPolicy` y `User::manageableBy()` | `app/Policies`, `app/Models/User.php` | Un moderador solo actúa sobre estudiantes de su universidad; un administrador, sobre todos |
| `kampus:create-admin` | `app/Console/Commands` | El primer administrador; los demás los crea un administrador desde el panel (T-41) |
| `Admin\StaffController` | `app/Http/Controllers/Admin` | `/api/admin/staff`, solo para administradores: lista, alta y desactivación o reactivación de staff. Las cuentas se desactivan (`suspended_at`), no se borran, y el login rechaza las cuentas suspendidas |
| `ModerationAction` | `app/Models` | Bitácora inmutable `moderation_actions`: quién hizo qué, sobre quién y con qué motivo (obligatorio) |

## Despliegue (entorno de pruebas)

El entorno de pruebas (T-24) corre en **un solo servidor de AWS (EC2) con Docker**. Todo está en `compose.prod.yaml`:

| Servicio | Qué hace |
|---|---|
| `nginx` | Entrada pública con HTTPS. Manda `/app` al WebSocket y lo demás a PHP |
| `certbot` | Renueva el certificado de Let's Encrypt; Nginx toma el nuevo solo cada 6 horas |
| `app` | La API (PHP-FPM 8.3) |
| `queue` | La cola: correos de verificación y recuperación, y eventos como `match.created` |
| `scheduler` | Las tareas programadas de Laravel |
| `reverb` | El WebSocket (T-13); solo se ve dentro de la red de Docker |
| `db` | PostgreSQL 16, con los datos en el volumen `pgdata` |

Las imágenes se guardan en **S3** y los correos salen por **Resend**.

### Antes de desplegar (una sola vez)

1. **Servidor:** una instancia EC2 con Ubuntu 24.04 y al menos 2 GB de RAM (`t3.small` o `t4g.small`), con IP elástica. En su grupo de seguridad, abre solo 22 (SSH), 80 y 443.
2. **Dominio:** un registro DNS `A` del dominio de la API (por ejemplo `api.ejemplo.com`) a la IP elástica.
3. **S3:** un bucket **privado** (con "Block all public access" activado) y un usuario de IAM con permiso solo sobre ese bucket (`s3:GetObject`, `s3:PutObject` y `s3:DeleteObject`). Sus llaves van en `.env.production`; nunca uses las de la cuenta.
4. **Resend:** verifica el dominio (agrega los registros DNS que da Resend) y crea una API key con permiso **solo de envío**. Sin un dominio verificado, Resend solo entrega correos a la cuenta dueña, así que no llegarían los de verificación a los alumnos.

### Desplegar

En el servidor, con Docker instalado:

```bash
git clone https://github.com/WilbertDevMx/backend-kampus.git && cd backend-kampus
cp .env.production.example .env.production   # y llena los valores marcados con <...>
docker compose -f compose.prod.yaml --env-file .env.production run --rm app php artisan key:generate --show
docker compose -f compose.prod.yaml --env-file .env.production up -d --build db app
docker compose -f compose.prod.yaml --env-file .env.production exec app php artisan migrate --force
docker compose -f compose.prod.yaml --env-file .env.production exec app php artisan db:seed --class=CatalogSeeder --force
docker compose -f compose.prod.yaml --env-file .env.production exec app php artisan db:seed --class=ZoneSeeder --force
docker compose -f compose.prod.yaml --env-file .env.production up -d --build
docker compose -f compose.prod.yaml --env-file .env.production exec app php artisan kampus:create-admin
```

- La llave que imprime `key:generate --show` se copia en `APP_KEY` antes del `up`.
- La primera vez se levantan solo `db` y `app` para migrar: la cola y el scheduler fallan si arrancan antes de que existan las tablas.
- En producción **no** se corre `db:seed` sin `--class`: `DatabaseSeeder` crea un usuario de prueba.

**Certificado HTTPS (solo la primera vez).** Nginx arranca con un certificado autofirmado temporal. Con el DNS ya apuntando al servidor, saca el de Let's Encrypt y recarga Nginx:

```bash
docker compose -f compose.prod.yaml --env-file .env.production run --rm --entrypoint sh certbot -c 'certbot certonly --webroot -w /var/www/certbot -d "$API_DOMAIN" --email "$ACME_EMAIL" --agree-tos --no-eff-email'
docker compose -f compose.prod.yaml --env-file .env.production exec nginx kampus-reload
```

Desde ahí, `certbot` lo renueva solo y Nginx toma el nuevo cada 6 horas. Si el primer comando falla, revisa que el registro DNS ya apunte a la IP elástica y que el puerto 80 esté abierto.

Para actualizar: `git pull` y el mismo `up -d --build`, y luego `migrate --force`.

### Monitoreo básico

- `https://<dominio>/up` responde `200` mientras la API está arriba. Regístralo en un monitor externo gratuito (por ejemplo, UptimeRobot) para recibir un aviso si se cae.
- Logs: `docker compose -f compose.prod.yaml logs -f app queue reverb`. Los de Laravel, incluido `security.log`, quedan en el volumen `logs`.

## Integración continua

Cada push y cada Pull Request hacia `main` o `develop` ejecuta automáticamente:

1. Revisión de formato con Pint.
2. Verificación de que las migraciones corren contra PostgreSQL.
3. La suite de pruebas.

Además, en cada Pull Request se revisa la convención de commits (`commits.yml`). En el PR de `develop` a `main`, un bot comenta la versión que se publicará; al fusionarlo se crean el tag y el GitHub Release (`release.yml`). Ese PR se fusiona con **"Create a merge commit"**, no con squash.

## Privacidad de la ubicación

Kampus maneja datos de ubicación con reglas estrictas que el código debe respetar (RNF-11):

- El servidor **solo** almacena la zona declarada (campus/colonia) o una celda aproximada de ~1 km (geohash de precisión 6).
- **Nunca** se guarda la coordenada exacta de un usuario para el mapa.
- La ubicación exacta solo viaja dentro de un mensaje de chat que el emisor autorizó explícitamente.
- Al desactivar "Cerca de ti" o eliminar la cuenta, los datos de ubicación se borran.

## Equipo

| Integrante | Rol |
|---|---|
| Wilbert Omar Acosta Barrera | Scrum Master · Backend |
| Mario Humberto García Rodríguez | Backend |
| Luis Carlos Fernández Quiñones | Frontend |
| Marcelo Alessandro Sifuentes de la Cruz | Frontend |
| Jorge Alejandro Rentería Reyes | Product Owner · Documentación |
