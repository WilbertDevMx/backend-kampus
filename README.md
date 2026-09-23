# Kampus — Backend (API)

API REST de **Kampus**, la aplicación web para la integración social de estudiantes universitarios de la UTT, la UAdeC y el Tecnológico de la Laguna.

Este repositorio contiene únicamente el backend. La interfaz (SPA en React) vive en su propio repositorio y consume esta API.

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
```

## Integración continua

Cada push y cada Pull Request hacia `main` o `develop` ejecuta automáticamente:

1. Revisión de formato con Pint.
2. Verificación de que las migraciones corren contra PostgreSQL.
3. La suite de pruebas.

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
