## Qué hace este PR

<!-- Descripción breve del cambio y por qué es necesario. -->

## Tarea relacionada

<!-- Ej.: T-05 · RF-02 · Closes #12 -->

- Tarea:
- Requisito:

## Cómo probarlo

<!-- Pasos para que quien revisa pueda verificarlo. -->

1.
2.

## Definition of Done

- [ ] El código fue revisado por otro integrante del equipo.
- [ ] Las pruebas del módulo pasan (`php artisan test`).
- [ ] El formato de código pasa (`./vendor/bin/pint --test`).
- [ ] La funcionalidad se verificó manualmente.
- [ ] La documentación de la API (OpenAPI) quedó actualizada si cambiaron endpoints.
- [ ] No se subieron credenciales, archivos `.env` ni datos personales.

## Seguridad

- [ ] Las rutas nuevas de usuario usan `auth:sanctum` y `verified`, y solo dejan tocar datos propios (o tienen una Policy).
- [ ] Toda entrada se valida en el servidor (de preferencia con un `FormRequest`) y las respuestas usan un `API Resource` sin campos internos.
- [ ] Las contraseñas usan `Password::defaults()`.
- [ ] Las imágenes que suben los usuarios pasan por `ImageSanitizer` (sin EXIF/GPS) y se guardan en el disco privado.
- [ ] No se guardan coordenadas exactas de usuarios (RNF-11) ni datos que los requisitos no piden.
- [ ] Los endpoints sensibles tienen límite de peticiones propio si el general no basta.

## Notas para quien revisa

<!-- Decisiones de diseño, dudas abiertas, deuda técnica que dejas anotada. -->
