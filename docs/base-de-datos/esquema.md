# Esquema de base de datos — Kampus

Este documento describe el modelo de datos del MVP de Kampus: el diagrama entidad-relación (DER), el diccionario de datos, los catálogos de valores y la relación de cada tabla con los requisitos del anteproyecto.

- **Motor:** PostgreSQL 16.
- **Uso:** es la especificación que deben implementar las migraciones de `database/migrations/`. Una vez fusionadas, las migraciones pasan a ser la fuente de verdad: si una cambia, este documento se actualiza en el mismo PR.
- **Tarea:** T-02 · Modelo entidad-relación y migraciones de base de datos (RF-01 a RF-21).

---

## 1. Diagrama entidad-relación

Se muestran solo las tablas del dominio. Las tablas internas de Laravel se listan en la sección 4.

```mermaid
erDiagram
    universities ||--o{ users : "agrupa"
    universities ||--o{ zones : "tiene campus"
    universities ||--o{ groups : "aloja"

    users ||--o| profiles : "tiene"
    users ||--o| user_locations : "tiene celda"
    zones ||--o{ profiles : "zona declarada"
    profiles ||--o{ photos : "tiene"
    profiles ||--o{ interest_profile : "elige"
    interests ||--o{ interest_profile : "es elegido"

    users ||--o{ swipes : "evalúa (swiper)"
    users ||--o{ swipes : "es evaluado (swiped)"
    users ||--o{ matches : "user_one"
    users ||--o{ matches : "user_two"
    matches ||--o{ messages : "contiene"
    users ||--o{ messages : "envía"

    users |o--o{ groups : "crea"
    groups ||--o{ group_members : "tiene"
    users ||--o{ group_members : "pertenece"
    groups ||--o{ group_activities : "publica"
    users |o--o{ group_activities : "crea"

    users ||--o{ blocks : "bloquea"
    users ||--o{ blocks : "es bloqueado"
    users |o--o{ reports : "reporta"
    users ||--o{ reports : "es reportado"
    users |o--o{ reports : "revisa"
    matches |o--o{ reports : "evidencia"

    universities {
        bigint id PK
        varchar name
        varchar short_name UK
        json email_domains
        boolean is_active
    }
    users {
        bigint id PK
        bigint university_id FK
        varchar name
        varchar email UK
        timestamp email_verified_at
        timestamp suspended_at
        varchar password
        varchar role
    }
    zones {
        bigint id PK
        varchar name
        varchar type
        bigint university_id FK
        varchar city
        numeric center_lat
        numeric center_lng
    }
    profiles {
        bigint id PK
        bigint user_id FK,UK
        varchar career
        smallint semester
        date birthdate
        varchar bio
        bigint zone_id FK
        boolean nearby_enabled
        json discovery_filters
    }
    user_locations {
        bigint id PK
        bigint user_id FK,UK
        varchar geohash
    }
    interests {
        bigint id PK
        varchar name UK
        varchar category
    }
    interest_profile {
        bigint interest_id PK,FK
        bigint profile_id PK,FK
    }
    photos {
        bigint id PK
        bigint profile_id FK
        varchar path
        smallint position
    }
    swipes {
        bigint id PK
        bigint swiper_id FK
        bigint swiped_id FK
        boolean liked
    }
    matches {
        bigint id PK
        bigint user_one_id FK
        bigint user_two_id FK
    }
    messages {
        bigint id PK
        bigint match_id FK
        bigint sender_id FK
        varchar type
        text body
        varchar attachment_path
        numeric latitude
        numeric longitude
        timestamp read_at
    }
    groups {
        bigint id PK
        bigint university_id FK
        bigint created_by FK
        varchar name
        text description
        varchar category
        varchar image_path
    }
    group_members {
        bigint group_id PK,FK
        bigint user_id PK,FK
        varchar role
    }
    group_activities {
        bigint id PK
        bigint group_id FK
        bigint created_by FK
        varchar title
        text description
        varchar location
        timestamp starts_at
    }
    blocks {
        bigint blocker_id PK,FK
        bigint blocked_id PK,FK
    }
    reports {
        bigint id PK
        bigint reporter_id FK
        bigint reported_id FK
        bigint match_id FK
        varchar reason
        text description
        varchar status
        bigint reviewed_by FK
        timestamp reviewed_at
    }
```

---

## 2. Diccionario de datos

Convenciones:

- Todas las tablas, salvo `interest_profile`, tienen `created_at` y `updated_at` (`timestamp`, nulos permitidos), que Laravel llena solo. No se repiten en cada tabla.
- **PK** = llave primaria · **FK** = llave foránea · **UK** = valor único.
- `bigint` autoincremental = `id()` de Laravel (secuencia de PostgreSQL).
- Las columnas de tipo `varchar` sin longitud indicada miden 255 caracteres.
- Al borrar el registro padre, cada FK hace una de tres cosas: **CASCADE** (borra también los hijos), **SET NULL** (deja la columna en nulo) o **RESTRICT** (impide el borrado).

### 2.1 `universities` — Instituciones participantes

| Columna | Tipo | Nulo | Llave | Restricción / valor por defecto | Descripción |
|---|---|---|---|---|---|
| id | bigint | No | PK | Autoincremental | Identificador de la institución. |
| name | varchar(255) | No | | | Nombre oficial (p. ej. "Universidad Tecnológica de Torreón"). |
| short_name | varchar(20) | No | UK | | Siglas (UTT, UAdeC, ITL). |
| email_domains | json | No | | Arreglo de cadenas | Dominios de correo institucional aceptados en el registro (RF-01). |
| is_active | boolean | No | | `true` | Permite desactivar una institución sin borrarla. |

### 2.2 `users` — Cuentas de usuario

| Columna | Tipo | Nulo | Llave | Restricción / valor por defecto | Descripción |
|---|---|---|---|---|---|
| id | bigint | No | PK | Autoincremental | Identificador del usuario. |
| university_id | bigint | Sí | FK → universities.id | RESTRICT | Institución del alumno, deducida del dominio de su correo. |
| name | varchar(255) | No | | | Nombre visible del alumno. |
| email | varchar(255) | No | UK | | Correo institucional; sirve para iniciar sesión. |
| email_verified_at | timestamp | Sí | | | Fecha de verificación del correo. Mientras sea nulo la cuenta está inactiva (RF-01). |
| suspended_at | timestamp | Sí | | | Fecha de suspensión por moderación. Si tiene valor, la cuenta no puede iniciar sesión (RF-15). |
| password | varchar(255) | No | | Hash bcrypt | Contraseña cifrada (RNF-02). |
| role | varchar(20) | No | Índice | `'student'` | Rol de la cuenta. Valores en la sección 3. |
| remember_token | varchar(100) | Sí | | | Token de "recordarme" de Laravel. |

### 2.3 `zones` — Catálogo de zonas (campus y colonias)

| Columna | Tipo | Nulo | Llave | Restricción / valor por defecto | Descripción |
|---|---|---|---|---|---|
| id | bigint | No | PK | Autoincremental | Identificador de la zona. |
| name | varchar(255) | No | UK compuesta (name, city) | | Nombre del campus o de la colonia. |
| type | varchar(20) | No | Índice | | Tipo de zona: `campus` o `colonia`. |
| university_id | bigint | Sí | FK → universities.id | SET NULL | Institución a la que pertenece el campus; nulo en colonias. |
| city | varchar(60) | No | UK compuesta (name, city) | | Ciudad (Torreón, Gómez Palacio, Lerdo…). |
| center_lat | numeric(9,6) | No | | | Latitud del centro de la zona. Es un punto público de referencia, no la ubicación de un usuario. |
| center_lng | numeric(9,6) | No | | | Longitud del centro de la zona. |

### 2.4 `profiles` — Perfil del alumno

| Columna | Tipo | Nulo | Llave | Restricción / valor por defecto | Descripción |
|---|---|---|---|---|---|
| id | bigint | No | PK | Autoincremental | Identificador del perfil. |
| user_id | bigint | No | FK → users.id, UK | CASCADE | Dueño del perfil. Un usuario tiene como máximo un perfil. |
| career | varchar(120) | Sí | | | Carrera o programa académico (RF-03). |
| semester | smallint | Sí | | 1 a 12, validado en la aplicación | Semestre o cuatrimestre actual. |
| birthdate | date | Sí | | | Fecha de nacimiento, para validar la mayoría de edad. |
| bio | varchar(300) | Sí | | Máx. 300 caracteres | "Sobre mí" (RF-03). |
| zone_id | bigint | Sí | FK → zones.id | SET NULL | Zona declarada: campus o colonia (RF-18). |
| nearby_enabled | boolean | No | | `false` | Indica si el alumno activó "Cerca de ti" (RF-18, RF-19). |
| discovery_filters | json | Sí | | | Filtros de descubrimiento guardados: universidades, carrera e intereses (RF-06). |

### 2.5 `user_locations` — Celda aproximada del alumno

| Columna | Tipo | Nulo | Llave | Restricción / valor por defecto | Descripción |
|---|---|---|---|---|---|
| id | bigint | No | PK | Autoincremental | Identificador del registro. |
| user_id | bigint | No | FK → users.id, UK | CASCADE | Alumno al que pertenece la celda. |
| geohash | varchar(6) | No | Índice | Precisión 6 (~1 km) | Celda geohash calculada en el navegador. **Nunca se guarda latitud ni longitud exactas** (RNF-11). |

Esta fila solo existe mientras "Cerca de ti" está activo. Al desactivarlo se borra.

### 2.6 `interests` — Catálogo de intereses

| Columna | Tipo | Nulo | Llave | Restricción / valor por defecto | Descripción |
|---|---|---|---|---|---|
| id | bigint | No | PK | Autoincremental | Identificador del interés. |
| name | varchar(60) | No | UK | | Nombre del interés (Fútbol, Programación, Anime…). |
| category | varchar(30) | No | | | Categoría: `deportes`, `cultura`, `entretenimiento`, `academico` o `social`. |

### 2.7 `interest_profile` — Intereses elegidos por cada perfil (tabla pivote)

| Columna | Tipo | Nulo | Llave | Restricción / valor por defecto | Descripción |
|---|---|---|---|---|---|
| interest_id | bigint | No | PK compuesta, FK → interests.id | CASCADE | Interés elegido. |
| profile_id | bigint | No | PK compuesta, FK → profiles.id | CASCADE | Perfil que lo eligió. Tiene índice propio para consultar los intereses de un perfil. |

RF-03 pide un mínimo de 3 intereses por perfil. Esa regla se valida en la aplicación.

### 2.8 `photos` — Fotos del perfil

| Columna | Tipo | Nulo | Llave | Restricción / valor por defecto | Descripción |
|---|---|---|---|---|---|
| id | bigint | No | PK | Autoincremental | Identificador de la foto. |
| profile_id | bigint | No | FK → profiles.id, UK compuesta (profile_id, position) | CASCADE | Perfil al que pertenece. |
| path | varchar(255) | No | | | Ruta del archivo en el bucket privado; se sirve con URL firmada temporal (RNF-03). |
| position | smallint | No | UK compuesta (profile_id, position) | 1 a 5, validado en la aplicación | Orden de la foto. La 1 es la principal. Máximo 5 fotos (RF-03). |

### 2.9 `swipes` — Evaluaciones de descubrimiento

| Columna | Tipo | Nulo | Llave | Restricción / valor por defecto | Descripción |
|---|---|---|---|---|---|
| id | bigint | No | PK | Autoincremental | Identificador del swipe. |
| swiper_id | bigint | No | FK → users.id, UK compuesta (swiper_id, swiped_id) | CASCADE | Quien evalúa. |
| swiped_id | bigint | No | FK → users.id, UK compuesta (swiper_id, swiped_id) | CASCADE | Quien es evaluado. |
| liked | boolean | No | Índice compuesto (swiped_id, liked) | | `true` = "Me interesa", `false` = "Pasar". |

El par único evita evaluar dos veces el mismo perfil (RF-05). El índice `(swiped_id, liked)` sirve para detectar el "me interesa" mutuo que genera un match (RF-07).

### 2.10 `matches` — Coincidencias mutuas

| Columna | Tipo | Nulo | Llave | Restricción / valor por defecto | Descripción |
|---|---|---|---|---|---|
| id | bigint | No | PK | Autoincremental | Identificador del match. |
| user_one_id | bigint | No | FK → users.id, UK compuesta (user_one_id, user_two_id) | CASCADE | El usuario con el **id menor** del par. |
| user_two_id | bigint | No | FK → users.id, UK compuesta (user_one_id, user_two_id); índice | CASCADE | El usuario con el **id mayor** del par. |

La aplicación siempre guarda el par ordenado (`user_one_id < user_two_id`). Así un mismo par no puede generar dos matches (RF-07).

### 2.11 `messages` — Mensajes del chat

| Columna | Tipo | Nulo | Llave | Restricción / valor por defecto | Descripción |
|---|---|---|---|---|---|
| id | bigint | No | PK | Autoincremental | Identificador del mensaje. También ordena la conversación. |
| match_id | bigint | No | FK → matches.id; índice compuesto (match_id, id) | CASCADE | Conversación a la que pertenece. Solo existe chat entre usuarios con match (RF-08). |
| sender_id | bigint | No | FK → users.id | CASCADE | Quien envía el mensaje. |
| type | varchar(20) | No | | `'text'` | Tipo de mensaje: `text`, `image` o `location`. |
| body | text | Sí | | | Texto del mensaje. Nulo en imágenes y ubicaciones. |
| attachment_path | varchar(255) | Sí | | | Ruta de la imagen en el bucket privado (RF-09). |
| latitude | numeric(9,6) | Sí | | Solo si `type = 'location'` | Latitud compartida a propósito por el emisor (RF-20). |
| longitude | numeric(9,6) | Sí | | Solo si `type = 'location'` | Longitud compartida a propósito por el emisor (RF-20). |
| read_at | timestamp | Sí | | | Fecha en que el receptor leyó el mensaje. |

`latitude` y `longitude` de esta tabla son el **único lugar** del sistema donde existe una coordenada exacta, y solo cuando el emisor confirmó que quiere compartirla. El índice `(match_id, id)` permite paginar el historial (RF-08).

### 2.12 `groups` — Grupos y clubes

| Columna | Tipo | Nulo | Llave | Restricción / valor por defecto | Descripción |
|---|---|---|---|---|---|
| id | bigint | No | PK | Autoincremental | Identificador del grupo. |
| university_id | bigint | Sí | FK → universities.id; índice compuesto (university_id, category) | SET NULL | Institución del grupo; nulo si es interinstitucional. |
| created_by | bigint | Sí | FK → users.id | SET NULL | Alumno que creó el grupo. |
| name | varchar(255) | No | | | Nombre del grupo (RF-12). |
| description | text | Sí | | | Descripción del grupo. |
| category | varchar(20) | No | Índice compuesto (university_id, category) | | Categoría: `deportivo`, `cultural`, `academico` o `social` (RF-11). |
| image_path | varchar(255) | Sí | | | Ruta de la imagen del grupo en el bucket. |

### 2.13 `group_members` — Integrantes de cada grupo

| Columna | Tipo | Nulo | Llave | Restricción / valor por defecto | Descripción |
|---|---|---|---|---|---|
| group_id | bigint | No | PK compuesta, FK → groups.id | CASCADE | Grupo. |
| user_id | bigint | No | PK compuesta, FK → users.id; índice | CASCADE | Alumno integrante. |
| role | varchar(20) | No | | `'member'` | `admin` (puede publicar actividades y editar el grupo) o `member`. |

### 2.14 `group_activities` — Actividades publicadas por los grupos

| Columna | Tipo | Nulo | Llave | Restricción / valor por defecto | Descripción |
|---|---|---|---|---|---|
| id | bigint | No | PK | Autoincremental | Identificador de la actividad. |
| group_id | bigint | No | FK → groups.id; índice compuesto (group_id, starts_at) | CASCADE | Grupo que la publica. |
| created_by | bigint | Sí | FK → users.id | SET NULL | Administrador del grupo que la publicó. |
| title | varchar(255) | No | | | Título de la actividad (RF-12). |
| description | text | Sí | | | Descripción. |
| location | varchar(255) | Sí | | | Lugar en texto libre (p. ej. "Cancha 2, UTT"). |
| starts_at | timestamp | No | Índice compuesto (group_id, starts_at) | | Fecha y hora de inicio. |

### 2.15 `blocks` — Bloqueos entre usuarios

| Columna | Tipo | Nulo | Llave | Restricción / valor por defecto | Descripción |
|---|---|---|---|---|---|
| blocker_id | bigint | No | PK compuesta, FK → users.id | CASCADE | Quien bloquea. |
| blocked_id | bigint | No | PK compuesta, FK → users.id; índice | CASCADE | Quien es bloqueado. Desaparece del descubrimiento, los matches y el chat de ambos (RF-13). |

### 2.16 `reports` — Reportes de usuarios

| Columna | Tipo | Nulo | Llave | Restricción / valor por defecto | Descripción |
|---|---|---|---|---|---|
| id | bigint | No | PK | Autoincremental | Identificador del reporte. |
| reporter_id | bigint | Sí | FK → users.id | SET NULL | Quien reporta. Si elimina su cuenta, el reporte se conserva. |
| reported_id | bigint | No | FK → users.id | CASCADE | Usuario reportado. |
| match_id | bigint | Sí | FK → matches.id | SET NULL | Conversación que sirve como evidencia (RF-14). |
| reason | varchar(30) | No | | | Motivo del catálogo. Valores en la sección 3. |
| description | text | Sí | | | Descripción opcional del reporte. |
| status | varchar(20) | No | Índice | `'pending'` | Estado del reporte en moderación (RF-15). |
| reviewed_by | bigint | Sí | FK → users.id | SET NULL | Administrador que lo revisó. |
| reviewed_at | timestamp | Sí | | | Fecha de la revisión. |

---

## 3. Catálogos de valores

PostgreSQL no restringe estos valores con `CHECK`. La aplicación los valida, idealmente con un `enum` de PHP por cada catálogo.

| Tabla.columna | Valores | Origen |
|---|---|---|
| users.role | `student`, `admin` | RF-15 (administrador de moderación) |
| zones.type | `campus`, `colonia` | RF-18 |
| interests.category | `deportes`, `cultura`, `entretenimiento`, `academico`, `social` | Anteproyecto, sección 15.1 |
| messages.type | `text`, `image`, `location` | RF-08, RF-09, RF-20 |
| groups.category | `deportivo`, `cultural`, `academico`, `social` | Anteproyecto, sección 15.1 |
| group_members.role | `admin`, `member` | RF-12 |
| reports.reason | `acoso`, `perfil_falso`, `contenido_inapropiado`, `spam`, `otro` | Anteproyecto, sección 15.1 |
| reports.status | `pending`, `reviewed`, `dismissed` | RF-15 |

**Datos iniciales.** `CatalogSeeder` debe sembrar las 3 instituciones con sus dominios y los intereses de la sección 2.6, y poder ejecutarse varias veces sin duplicar nada (usando `upsert`). El catálogo de zonas (campus y colonias de la Comarca Lagunera) se siembra en T-28.

---

## 4. Tablas internas de Laravel

Existen en la base de datos, pero no forman parte del dominio:

| Tabla | Uso |
|---|---|
| `personal_access_tokens` | Tokens de Sanctum para la API, con expiración (RF-02). |
| `password_reset_tokens` | Tokens de recuperación de contraseña. |
| `sessions` | Sesiones de Laravel. |
| `cache`, `cache_locks` | Caché en base de datos; incluye el contador de intentos fallidos de inicio de sesión (RF-02). |
| `jobs`, `job_batches`, `failed_jobs` | Cola de trabajos, como el envío del correo de verificación. |

---

## 5. Trazabilidad con los requisitos

| Requisito | Tablas |
|---|---|
| RF-01 Registro con correo institucional | `universities`, `users` |
| RF-02 Inicio y cierre de sesión | `users`, `personal_access_tokens`, `cache` |
| RF-03 Gestión de perfil | `profiles`, `photos`, `interests`, `interest_profile` |
| RF-05 Descubrimiento tipo swipe | `swipes`, `profiles` |
| RF-06 Filtros de descubrimiento | `profiles.discovery_filters` |
| RF-07 Generación de match | `swipes`, `matches` |
| RF-08 / RF-09 Chat e imágenes | `messages` |
| RF-11 / RF-12 Grupos | `groups`, `group_members`, `group_activities` |
| RF-13 Bloqueo | `blocks` |
| RF-14 / RF-15 Reporte y moderación | `reports`, `users.suspended_at`, `users.role` |
| RF-18 Zona del usuario | `zones`, `profiles.zone_id`, `profiles.nearby_enabled`, `user_locations` |
| RF-19 Vista "Cerca" | `zones`, `user_locations` |
| RF-20 Ubicación en el chat | `messages` (type `location`) |
| RF-21 Perfil expandible | `profiles`, `photos`, `interest_profile`, `group_members` |
| RNF-11 Privacidad de ubicación | `user_locations.geohash`; ninguna otra tabla guarda coordenadas de usuarios |

---

## 6. Pendientes para revisar en equipo

1. **RF-04 (aceptación de aviso de privacidad y normas) no tiene dónde guardarse.** Propuesta: agregar a `users` las columnas `privacy_accepted_at` y `rules_accepted_at` (`timestamp`, nulo permitido). Así queda evidencia de cuándo aceptó cada documento.
2. **RF-16 (eliminación de cuenta en máximo 72 horas).** Los `CASCADE` ya borran perfil, fotos, swipes, matches, mensajes, bloqueos y membresías al borrar el usuario. Falta decidir si el borrado es inmediato o diferido. Si es diferido, hay que agregar `users.deletion_requested_at` y una tarea programada que borre la cuenta y los archivos del bucket.
3. **Dominios de correo duplicados.** El registro de T-04 (rama `garica`) valida contra `config('auth.allowed_email_domains')`, pero los dominios ya viven en `universities.email_domains`. Conviene validar contra la tabla y, al registrar, llenar `users.university_id`. Así hay una sola fuente de verdad.
4. **Nombre y apellido.** `users` tiene un solo campo `name`, y la pantalla de registro del frontend pide nombre y apellido por separado. Hay que acordar si se separan en dos columnas o si el frontend los une antes de enviarlos.
5. **RF-17 (notificaciones en la app)** es deseable y no tiene tabla. Si se implementa, la tabla `notifications` de Laravel (`php artisan make:notifications-table`) lo cubre sin diseño adicional.
