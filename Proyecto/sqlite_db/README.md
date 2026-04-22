# Base de datos SQLite para control de accesos

Archivos:
- `init.sql`: crea tablas, vistas e índices + triggers de integridad.
- `seed.sql`: carga datos de ejemplo.

Cómo crear la base:
```bash
cd Proyecto/sqlite_db
sqlite3 gate.db ".read init.sql" ".read seed.sql"
```

Puntos clave del esquema:
- `Personas`: ALUMNO/PROFESOR/PERSONAL, `activo` controla si acepta accesos.
- `Credenciales`: una sola activa por persona (trigger `trg_credencial_unica_activa`).
- `Accesos`: guarda `tipo` ENTRADA/SALIDA y flag `autorizado`.
- Vistas: `vw_accesos_detalle` (join completo) y `vw_ultimo_acceso_por_persona`.
- Triggers: bloquean accesos de personas/credenciales inactivas y desactivan credenciales previas al activar una nueva.

Integración con `prueba.cpp`:
- `prueba.cpp` envia `uid + foto` por HTTP al endpoint PHP `api/acceso.php`.
- El endpoint resuelve credencial/persona, guarda la foto y registra el evento en `Accesos`.
- La puerta se manda por `id_puerta` (uso fijo recomendado: `1`, o el ID real de tu puerta).
- `prueba.cpp` solo lee JSON (`ok`, `autorizado`, `motivo`) y decide el LED.
- Token de API: define el mismo valor en `Proyecto/prueba.cpp` (`API_TOKEN`) y en `Proyecto/api/acceso.php` (`API_TOKEN`).

Notas:
- SQLite no tiene stored procedures; se sustituyen con triggers y vistas para la lógica embebida.
- Asegúrate de ejecutar `PRAGMA foreign_keys = ON;` si abres la DB desde tu aplicación. `init.sql` ya lo activa al crear.
