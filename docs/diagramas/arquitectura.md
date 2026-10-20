# Arquitectura del sistema — Kampus

Kampus usa una arquitectura **cliente-servidor desacoplada**: la app y la API viven en repositorios distintos y se comunican por HTTPS (JSON) y WebSocket. Así cada parte se desarrolla, prueba y despliega por separado (RNF-09).

> **Nuevo en Sprint 2 — Módulo de Administración.** Cambio de alcance aprobado el 19 de octubre de 2026. Se agregan los usuarios **Moderador** (una universidad) y **Administrador** (todas), que usan un **panel de administración** dentro de la app y no las funciones sociales. En la API se agrega el módulo **Administración** (T-40 a T-43). Lo nuevo va marcado con **[Nuevo]** y, en los diagramas, en amarillo.

## 1. Diagrama de componentes

```mermaid
flowchart LR
    subgraph Usuarios
        EST(["Estudiante<br/>UTT · UAdeC · Tec Laguna"])
        MODR(["Moderador<br/>una universidad"])
        ADM(["Administrador<br/>todas las universidades"])
    end

    subgraph Cliente["Cliente · repo Frontend-Kampus"]
        APP["App Kampus<br/>Expo · React Native<br/>web y móvil"]
        PANEL["Panel de administración<br/>solo staff"]
    end

    subgraph Servidor["Servidor · repo backend-kampus"]
        API["API REST<br/>Laravel 13 · PHP 8.3<br/>Sanctum, tokens Bearer"]
        WS["Servidor WebSocket<br/>Laravel Reverb"]
        COLA["Cola de trabajos<br/>driver database"]
    end

    subgraph Datos["Persistencia"]
        DB[("PostgreSQL 16")]
        S3[("Almacenamiento S3<br/>Cloudflare R2 / MinIO<br/>bucket privado")]
    end

    subgraph Externos["Servicios externos"]
        MAIL["Correo transaccional<br/>Resend / Mailtrap"]
        LK["LiveKit<br/>videollamada 1 a 1"]
        TILES["Teselas de mapa<br/>OpenFreeMap"]
    end

    EST --> APP
    MODR --> PANEL
    ADM --> PANEL
    PANEL -->|"HTTPS · JSON · rol staff"| API
    APP -->|"HTTPS · JSON"| API
    APP <-->|"WebSocket · Echo"| WS
    APP -->|"URL firmada temporal"| S3
    APP <-->|"WebRTC"| LK
    APP -->|"MapLibre GL"| TILES
    API -->|"Eloquent"| DB
    API -->|"fotos e imágenes"| S3
    API -->|"token de sala"| LK
    API -->|"eventos de chat y match"| WS
    API -->|"trabajos en segundo plano"| COLA
    COLA -->|"correo de verificación"| MAIL

    classDef nuevo fill:#F5C518,stroke:#8A6D00,color:#121215
    class MODR,ADM,PANEL nuevo
```

| Componente | Tecnología | Responsabilidad | Requisitos |
|---|---|---|---|
| App Kampus | Expo / React Native (web y móvil) | Interfaz para estudiantes | RNF-01, RNF-08 |
| **[Nuevo]** Panel de administración | Mismas pantallas de Expo, con menú según el rol | Usuarios, reportes, grupos pendientes, grupos oficiales y gestión de staff (esta última solo para el administrador). El staff no ve Descubrir, Cerca ni Chat | RF-15, RF-22, RF-23, RF-24 |
| API REST | Laravel 13, PHP 8.3, Sanctum | Reglas de negocio, autenticación con tokens Bearer que expiran, validación. **[Nuevo]** Permisos por rol con *Policies* y filtro por universidad | RF-01 a RF-21, **[Nuevo]** RF-22 a RF-24, RNF-02 |
| Servidor WebSocket | Laravel Reverb + Echo | Mensajes de chat y avisos de match en tiempo real | RF-07, RF-08 |
| Cola de trabajos | Cola de Laravel (driver `database`) | Correo de verificación y tareas que no deben frenar la respuesta | RF-01 |
| Base de datos | PostgreSQL 16 | Datos del dominio; ver el DER en [`../base-de-datos/esquema.md`](../base-de-datos/esquema.md) | Todos |
| Almacenamiento | Bucket privado compatible con S3 | Fotos de perfil, de chat y de grupos, servidas con URL firmada temporal | RF-03, RF-09, RNF-03 |
| Correo | Resend (producción) / Mailtrap (pruebas) | Enlace de verificación institucional | RF-01 |
| Videollamada | LiveKit, detrás de un Adapter | Salas 1 a 1 solo entre usuarios con match | RF-10 |
| Mapa | MapLibre GL + teselas de OpenFreeMap | Vista "Cerca" con estilo oscuro propio | RF-19 |

## 2. Módulos de la API

```mermaid
flowchart TB
    subgraph API["API REST · Laravel"]
        AUTH["Autenticación<br/>T-04, T-05"]
        PERFIL["Perfil, intereses y fotos<br/>T-07"]
        DESC["Descubrimiento y match<br/>T-09, T-10"]
        CHAT["Chat<br/>T-13, T-14, T-15"]
        GRUPOS["Grupos y actividades<br/>T-17"]
        ZONAS["Zonas y vista Cerca<br/>T-28, T-35"]
        VIDEO["Videollamada<br/>T-19"]
        MOD["Bloqueo, reporte y moderación<br/>T-21, T-37"]
        ROLES["Roles y permisos<br/>T-40"]
        ADMIN["Administración<br/>staff T-41 · grupos T-42 · usuarios T-43"]
    end

    AUTH --> PERFIL
    PERFIL --> DESC
    DESC --> CHAT
    DESC --> VIDEO
    ZONAS --> DESC
    MOD --> DESC
    MOD --> CHAT
    GRUPOS --> PERFIL
    AUTH --> ROLES
    ROLES --> DESC
    ROLES --> ADMIN
    ADMIN --> GRUPOS
    ADMIN --> MOD

    classDef nuevo fill:#F5C518,stroke:#8A6D00,color:#121215
    class ROLES,ADMIN nuevo
```

Las flechas indican de qué módulo depende cada uno. Por ejemplo, el chat solo existe entre usuarios que hicieron match, y el bloqueo saca al usuario bloqueado del descubrimiento y del chat.

**[Nuevo]** **Roles y permisos (T-40)** es la base del módulo de Administración. Define tres roles (`student`, `moderator` y `admin`) y aplica dos reglas:
- el descubrimiento solo muestra estudiantes;
- cada moderador solo ve y atiende su universidad.

Encima de ese módulo, **Administración** concentra tres funciones:
- la gestión de cuentas de staff (T-41);
- la aprobación de grupos (T-42);
- la consulta y suspensión de usuarios reportados (T-43).

Toda acción del staff queda en la bitácora `moderation_actions`, con motivo obligatorio.

## 3. Flujo de registro, verificación e inicio de sesión

Es el primer flujo del MVP (Sprint 1, T-04 y T-05).

```mermaid
sequenceDiagram
    autonumber
    actor E as Estudiante
    participant APP as App Kampus
    participant API as API Laravel
    participant DB as PostgreSQL
    participant MAIL as Correo

    E->>APP: Captura nombre, correo institucional y contraseña
    APP->>API: POST /api/register
    API->>DB: Busca la universidad por dominio del correo
    alt Dominio no aceptado
        API-->>APP: 422 "El dominio del correo no está permitido"
    else Dominio aceptado
        API->>DB: Crea el usuario sin verificar, con su universidad
        API->>MAIL: Envía el enlace firmado de verificación
        API-->>APP: 201 "Revisa tu correo"
    end

    E->>API: Abre el enlace del correo, GET /api/email/verify/{id}/{hash}
    API->>DB: Marca email_verified_at
    API-->>E: "Correo verificado correctamente"

    E->>APP: Inicia sesión
    APP->>API: POST /api/login
    alt 5 intentos fallidos con el mismo correo e IP
        API-->>APP: 429 Bloqueo temporal de 15 minutos
    else Credenciales incorrectas
        API-->>APP: 422 "Las credenciales no son correctas"
    else Correo sin verificar
        API-->>APP: 403 "Verifica tu correo institucional"
    else Correcto
        API->>DB: Guarda un token Sanctum que expira en 7 días
        API-->>APP: 201 token Bearer
    end
    APP->>API: Peticiones con Authorization: Bearer <token>
```

## 4. Despliegue previsto (T-24, Sprint 3)

- **Servidor:** VPS con Docker (Nginx, PHP-FPM, Reverb y PostgreSQL) y HTTPS obligatorio, porque cámara y micrófono lo exigen (riesgo R2).
- **CI:** GitHub Actions revisa formato (Pint), migraciones contra PostgreSQL y pruebas en cada PR hacia `develop` y `main`.

## 5. Decisiones que este diagrama refleja

| Tema | Decisión vigente | Nota |
|---|---|---|
| Base de datos | PostgreSQL 16 | El anteproyecto menciona MySQL en la sección 12.1; la decisión final es PostgreSQL (sección 12.3 y migraciones). |
| Frontend | Expo / React Native, que también corre en web | El anteproyecto dice "React 18 + Vite (SPA)"; el repositorio del frontend usa Expo. El equipo debe confirmarlo (ADR-001). |
| Ubicación | Zona declarada + celda geohash de precisión 6, sin PostGIS | Nunca se guarda la coordenada exacta de un usuario (RNF-11). |
| **[Nuevo]** Roles | Tres roles en `users.role`; un moderador tiene una sola universidad | El staff solo lo crea un administrador (comando `kampus:create-admin` para el primero) y usa correo institucional. Los permisos viven en *Policies*, no en el frontend. |
| Caché y colas | Driver `database`, sin Redis por ahora | Suficiente para 100 usuarios concurrentes (RNF-05); Redis entraría si Reverb necesita varios servidores. |
