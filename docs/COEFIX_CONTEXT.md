# COEFIX — Documento de Contexto de Trabajo

> Última actualización: 2026-05-04 (cierre de tests + correcciones auth)
> Rama activa: `develop`

---

## 1. Resumen General

Coefix es un sistema de gestión de asambleas de propiedad horizontal.
Opera en red local (LAN) y permite:

- Registrar la asistencia de propietarios a una asamblea (check-in).
- Gestionar representación por poderes (un inmueble representa a otros).
- Calcular el quórum en tiempo real usando coeficientes de copropiedad.
- Emitir controles físicos numerados a cada asistente.
- Gestionar retiro y reingreso de asistentes durante la sesión.
- Exportar informes en Excel al cierre de la asamblea.

El sistema está diseñado para uso multi-puesto: varios operadores pueden
registrar simultáneamente desde distintas PCs de la red local.

---

## 2. Stack Técnico

| Componente | Versión / Detalle |
|---|---|
| PHP | 8.x |
| Laravel | 12.48.0 |
| Livewire | 3 (componentes reactivos sin JS manual) |
| TailwindCSS | CSS utilitario |
| MySQL | 8 (Laragon local) |
| Roles/Permisos | spatie/laravel-permission |
| Exportación Excel | maatwebsite/laravel-excel + PhpSpreadsheet |
| Tests | PHPUnit (23/23 tests pasan — suite verde) |

**Colores de identidad visual:**
- Azul petróleo: `#0F3D4C`
- Gris grafito: `#2E2E2E`
- Gris claro: `#E6E8EB`
- Verde técnico: `#4CAF50`

---

## 3. Rama Actual y Flujo de Trabajo

```
main       ← producción / releases estables
develop    ← rama de trabajo activa (HEAD actual)
```

- Todo el desarrollo ocurre en `develop`.
- Los PRs se hacen hacia `develop`.
- `main` solo recibe merges de releases revisadas.
- No hay CI/CD configurado.

---

## 4. Módulos Principales

| Módulo | Ruta | Componente Livewire | Middleware |
|---|---|---|---|
| Eventos (lista) | `/eventos` | `Event\Index` | auth, verified, usuario.activo |
| Eventos (crear/editar) | `/eventos/crear`, `/eventos/{id}/editar` | `Event\Form` | auth + gates |
| Check-in | `/checkin` | `Checkin\RegistroPantalla` | + evento.activo |
| Quórum | `/quorum` | `Quorum\Show` | + evento.activo |
| Retiro/Reingreso | `/controles/retiro` | `Controls\RetiroReingreso` | + evento.activo |
| Base Turning | `/base-turning` | `BaseTurning\Index` | + evento.activo + role:ADMIN |
| Informe Excel | `/informes/excel` | Closure (descarga directa) | + evento.activo + role:ADMIN |
| Admin Usuarios | `/admin/usuarios` | `Admin\Usuarios\Index` | + permission:usuarios.ver |

**Roles definidos:** `ADMIN`, `OPERADOR`, `CLIENTE`

---

## 5. Lógica Crítica

### 5.1 Check-in (`RegistroPantalla`)

1. El operador busca un inmueble por código (`updatedSearch`).
2. Si el inmueble es un poder de otro, se abre automáticamente la cabeza del grupo (`resolveCheckinTarget`).
3. Se crea o carga el `registros_checkin` del inmueble cabeza.
4. El operador digita el número de control; este queda "en cola" (`controlPendienteNumero`) pero NO se asigna todavía.
5. El operador rellena los datos del asistente y presiona **Guardar asistente** (`saveAsistente`).
6. Solo en ese momento se asigna el control y el estado pasa a `CHECKED_IN`.
7. Se guardan los snapshots: `coef_total_snapshot`, `cabeza_inmueble_snapshot`, `control_numero_snapshot`.

**Reglas duras:**
- Solo la cabeza del grupo puede hacer check-in y recibir control.
- Un control `LIBRE` puede asignarse; uno `ASIGNADO` no.
- Si hay cambios sin guardar, se pide confirmación antes de cambiar de inmueble.

### 5.2 Poderes / Representación

- La tabla `representacion_grupos` define el grupo: `cabeza_padron_id` es el dueño.
- La tabla `representacion_miembros` tiene todos los inmuebles del grupo con `es_cabeza` flag.
- Un inmueble puede ser poder de otro solo si:
  - No está ya en otro grupo con poderes.
  - No tiene `CHECKED_IN` ni control asignado.
- Al agregar poder (`addPoder`): se usa `DB::transaction` con `lockForUpdate` para evitar carreras.
- Al quitar poder (`removePoder`): el inmueble separado recibe su propio grupo base y su registro se resetea a `EN_PROCESO`.
- La operación `separarCabeza` promueve el primer poder como nueva cabeza y saca a la cabeza actual como independiente.

### 5.3 `coef_total_snapshot`

- Se calcula como la suma de `coeficiente` de todos los miembros del grupo en `evento_padron`.
- Se guarda en `registros_checkin.coef_total_snapshot` al hacer check-in (`saveAsistente`).
- Se recalcula y sincroniza en `syncGrupoSnapshot` cada vez que se agrega o quita un poder.
- También se actualiza en registros con estado `CHECKED_IN` o `RETIRADO` para reflejar cambios tardíos.
- **Fuente de verdad del quórum:** el quórum se calcula sumando `coef_total_snapshot` de los registros activos, NO consultando el padrón en vivo.

### 5.4 Quórum (`Quorum\Show`)

```
quorumActual  = SUM(coef_total_snapshot) WHERE estado = 'CHECKED_IN'
quorumRetirado = SUM(coef_total_snapshot) WHERE estado = 'RETIRADO'
quorumMax     = quorumActual + quorumRetirado   ← máximo histórico de la sesión
```

- Los controles activos y retirados se cuentan con `DISTINCT control_id` (evita dobles).
- "Últimos llegados" se calcula por `checked_in_at DESC`.
- **Nota:** `quorumMax` crece con cada ciclo retiro/reingreso del mismo inmueble (ver Riesgos).

### 5.5 Retiro y Reingreso (`RetiroReingreso`)

- **Retirar:** busca el control por número → verifica que esté `ASIGNADO` → busca el registro asociado → cambia estado a `RETIRADO` → el control sigue `ASIGNADO` (ligado al registro para poder reingresar con el mismo número).
- **Reingresar:** verifica estado `RETIRADO` → cambia a `CHECKED_IN` → registra `reingreso_at` y `reingreso_by_user_id`.
- **Reemplazar control:** permite cambiar el control físico de un asistente (ej. control dañado). El control viejo queda `LIBRE` y el nuevo queda `ASIGNADO` al mismo registro.
- **Consultar control:** solo lectura — muestra quién tiene ese control.

### 5.6 Informes Excel (`InformeAsambleaExport`)

El archivo descargable tiene 6 hojas:

| Hoja | Contenido |
|---|---|
| Resumen | Totales: controles activos, coef presente, retirado, no asistió |
| Asistencia | Fila por asistente: control, código, inmueble, propietario, asistente, teléfono, correo, coef, estado, horas |
| Quórum | Lista de presentes y retirados con coeficiente |
| Ausentes | Inmuebles que no registraron check-in y no son poderes |
| Poderes | Resumen por grupo: cabeza, # poderes, coef propio, coef poderes, coef total |
| Poderes Detalle | Detalle expandido por cabeza: cada inmueble apoderado con su coeficiente |

**Lógica de ausentes:** un inmueble es "ausente" si no tiene registro `CHECKED_IN/RETIRADO`
Y no es un poder (`es_cabeza = 0`) en ningún grupo activo.

**Timezone:** `APP_TIMEZONE=America/Bogota`. Las horas se formatean con `Carbon::parse()`.
Las marcas de tiempo se almacenan usando `now()` con el timezone de la app.

---

## 6. Cambios Recientes Importantes

### [2026-05-04] Suite de tests en verde — 23/23 pasan
- Causa raíz de los fallos: FKs de SQLite requieren `PRAGMA foreign_keys = ON` y orden de creación correcto.
- Migración `add_imagen_to_eventos_table`: columna `imagen` se agrega con `nullable()` para compatibilidad SQLite.
- Migración `add_evento_id_to_representacion_miembros_table`: se adaptó para no duplicar la columna si ya existe (guard con `hasColumn`), compatible con SQLite in-memory de tests.
- Commits: `fix: corregir migracion de imagen en eventos` + `fix: adaptar migracion de representacion para sqlite`.
- Rama cerrada: `fix/tests-sqlite-foreign-keys` → mergeada a `develop`.

### [2026-05-04] Desactivación de `/register` y limpieza de tests Breeze
- La ruta `/register` no existe en Coefix (admins crean usuarios vía `/admin/usuarios`).
- `RegistrationTest` eliminado — no tiene sentido en el dominio del sistema.
- Tests restantes actualizados para reflejar el flujo real: `ExampleTest`, `AuthenticationTest`, `EmailVerificationTest`, `PasswordConfirmationTest`.
- Rama cerrada: `fix/tests-breeze-desactualizados` → mergeada a `develop`.

### [2026-05-04] Corrección de redirects de auth: `dashboard` → `eventos.index`
- Los controladores de auth generados por Breeze referenciaban `route('dashboard')`, que nunca fue definido en Coefix.
- Corregido en 4 controladores: `VerifyEmailController`, `ConfirmablePasswordController`, `EmailVerificationNotificationController`, `EmailVerificationPromptController`.
- Todos redirigen ahora a `route('eventos.index')`, la pantalla de entrada autenticada real.
- `RegisteredUserController` conserva la referencia rota pero es **dead code** — ninguna ruta apunta a él.

### [2026-05-04] Eliminación de dead code `columnExists()`
- Método privado en `RegistroPantalla.php` con SQL `SHOW COLUMNS FROM {$table}` (MySQL-only).
- No era llamado desde ningún lugar. Eliminado con seguridad.
- Commit: `chore: eliminar dead code columnExists`.

### [2026-05-03] Corrección de unique constraint en `representacion_miembros`
- El `UNIQUE(padron_id)` original impedía que el mismo inmueble perteneciera a grupos de distintos eventos.
- Se reemplazó por `UNIQUE(evento_id, padron_id)` — un inmueble solo puede estar en un grupo por evento.
- Se mantuvo `INDEX(padron_id)` independiente para soportar la FK `representacion_miembros_padron_id_foreign`.
- Migración: `2026_05_03_162630_fix_unique_padron_id_in_representacion_miembros_table` — batch 21.
- Commits: `fix: corregir unique de representacion miembros por evento` + `fix: mantener indice padron por foreign key`

### [2026-05-03] Middleware `evento.activo` en `/controles/retiro`
- La ruta `/controles/retiro` no declaraba `evento.activo`, inconsistente con el resto de rutas operativas.
- Se agregó `->middleware(['evento.activo'])` a la ruta en `routes/web.php`.
- Commit: `fix: agregar evento activo a ruta de retiro`

### [2026-05-03] Corrección de fecha segura en `RetiroReingreso`
- `max(now(), $registro->checked_in_at ?? now())` comparaba un `Carbon` con un `string` (DB::table retorna stdClass).
- Se reemplazó por `now()->max(\Carbon\Carbon::parse($registro->checked_in_at ?? now()))`.
- Commit: `fix: corregir fecha segura en retiro reingreso`

### [2026-05-03] Eliminación de archivo zombie `RegistroPantallaFunciona.php`
- `app/Livewire/Checkin/RegistroPantallaFunciona.php` era una copia de 1.697 líneas del componente principal.
- Declaraba la misma clase `App\Livewire\Checkin\RegistroPantalla` en el mismo namespace — nunca cargada por PSR-4.
- Sin referencias en rutas, views ni imports. Eliminado con seguridad tras búsqueda exhaustiva.
- Commit: `chore: eliminar archivo zombie RegistroPantallaFunciona`

### [2026-05-03] Aplicación de migración pendiente `cabeza_inmueble_snapshot`
- La migración `2026_03_26_191339_change_cabeza_inmueble_snapshot_to_varchar` existía en el repo pero nunca se había ejecutado en esta BD.
- La columna `cabeza_inmueble_snapshot` era `bigint unsigned`; ahora es `varchar(100)`.
- Aplicada en batch 20 durante la sesión de correcciones.

### Corrección de timezone en export Excel
- `Carbon::parse($dt)->format('Y-m-d h:i:s A')` sin conversión explícita de timezone.
- Con `APP_TIMEZONE=America/Bogota` configurado, `now()` guarda en hora de Bogotá y el parse retorna correctamente.
- Commit: `fix: corrección de timezone en export Excel`

### Columnas asistente/teléfono/correo en hoja Asistencia
- La hoja Asistencia ahora incluye las columnas: Asistente, Teléfono, Correo.
- Se toman de `registros_checkin.asistente_nombre/telefono/correo`.
- Commit: `feat: mejora visual del informe Excel`

### Sincronización de snapshot al modificar poderes
- `syncGrupoSnapshot()` se llama tras `addPoder` y `removePoder`.
- Actualiza `coef_total_snapshot` y `cabeza_inmueble_snapshot` en registros con estado `CHECKED_IN` o `RETIRADO`.
- Garantiza que el quórum refleje cambios tardíos de representación.

### Mejora visual del informe Excel
- Estilos: fondo azul oscuro en títulos, fondos alternados, bordes finos en todas las tablas.
- Formato profesional con filas de totales en negrita.
- Columnas con ancho fijo optimizado.
- Commit: `feat: mejora visual del informe Excel (estilos, bordes, títulos y formato profesional)`

### Corrección de `cabeza_inmueble_snapshot` para inmuebles alfanuméricos
- La columna era `unsignedBigInteger` (solo números).
- Se cambió a `varchar(100)` para soportar identificadores como `AP-101`, `L-02B`, etc.
- Migración: `2026_03_26_191339_change_cabeza_inmueble_snapshot_to_varchar`
- Commit: `Corrige cabeza_inmueble_snapshot para inmuebles alfanumericos`

### Corrección de poderes en hoja Ausentes
- La hoja Ausentes ya no lista inmuebles que están representados como poderes.
- Filtro: `whereNotExists` sobre `representacion_miembros WHERE es_cabeza = 0`.
- Commit: `Modificacion en InformeAsambleaExport.php ya que en la hoja ausentes aun listaba los poderes`

---

## 7. Riesgos Detectados en Auditoría (2026-05-03)

### CRÍTICOS

#### R1 — ~~`AppServiceProvider` usa `is_active` en vez de `activo`~~ — FALSO POSITIVO (aclarado)
**Archivo:** `app/Providers/AppServiceProvider.php:23`
La auditoría inicial asumió que `is_active` era un typo de `activo`. **Hallazgo real:**
- `is_active` es una columna real, añadida en migración `2026_01_12_202804_add_is_active_to_eventos_table`.
- `activo` = evento habilitado/deshabilitado (default `true`). `is_active` = evento globalmente activo para CLI (default `false`).
- Los comandos `BackupEvento` e `ImportBaseEvento` usan `is_active` intencionalmente porque Artisan corre sin sesión.
- El objeto `$evento` del singleton nunca es leído por `EventContext::eventoId()` (usa sesión); es dead code pero no un bug.
**Acción:** ninguna. No hay bug.

#### R2 — ~~Suite de tests completamente rota~~ — ✅ CORREGIDO (2026-05-04)
Causa raíz identificada: FKs de SQLite y migraciones no compatibles con SQLite in-memory.
Migraciones corregidas; suite ahora en **23/23 tests pasando**.
Ramas cerradas: `fix/tests-sqlite-foreign-keys` + `fix/tests-breeze-desactualizados`.

#### R3 — ~~`RegistroPantallaFunciona.php` es un archivo zombie~~ — ✅ CORREGIDO
Archivo eliminado. Commit: `chore: eliminar archivo zombie RegistroPantallaFunciona`.

### ALTOS

#### R4 — ~~Registro público abierto~~ — ✅ CORREGIDO (2026-05-04)
La ruta `/register` nunca existió en `auth.php` de Coefix — el riesgo era aparente, no real.
`RegistrationTest` eliminado para reflejar el estado correcto del sistema.
Usuarios se crean exclusivamente vía `/admin/usuarios` (requiere `permission:usuarios.ver`).

#### R5 — ~~Unique constraint en `representacion_miembros.padron_id` (solo)~~ — ✅ CORREGIDO
Confirmado y corregido. El `UNIQUE(padron_id)` fue reemplazado por `UNIQUE(evento_id, padron_id)`.
Se mantiene `INDEX(padron_id)` para soporte de FK. Migración aplicada en batch 21.

### MEDIOS

#### R6 — ~~Rutas Livewire duplicadas~~ — ✅ CORREGIDO
`setUpdateRoute` y `setScriptRoute` definidos únicamente en `routes/web.php`.
Definición duplicada en `AppServiceProvider::boot()` eliminada.

#### R7 — ~~`max(now(), $registro->checked_in_at)` — tipos mixtos~~ — ✅ CORREGIDO
Reemplazado por `now()->max(\Carbon\Carbon::parse($registro->checked_in_at ?? now()))`.
Commit: `fix: corregir fecha segura en retiro reingreso`.

#### R8 — ~~`columnExists()` es dead code con SQL MySQL-only~~ — ✅ CORREGIDO (2026-05-04)
Método eliminado de `RegistroPantalla.php`. Commit: `chore: eliminar dead code columnExists`.

#### R9 — ~~Ruta `/controles/retiro` sin middleware `evento.activo`~~ — ✅ CORREGIDO
Middleware agregado en `routes/web.php`. Commit: `fix: agregar evento activo a ruta de retiro`.

#### R10 — `Artisan::call()` síncrono en request web
Al crear un evento con archivos Excel, las importaciones se corren dentro del request HTTP.
Puede causar timeout con archivos grandes.

### BAJOS

#### R11 — `APP_DEBUG=true` en `.env`
Si el `.env` actual se usa en producción, expone trazas de stack ante errores.

#### R12 — `station_id` siempre null en `registros_checkin`
El campo existe en la tabla pero `saveAsistente()` nunca lo escribe.

#### R13 — ~~`quorumMax` crece con ciclos retiro/reingreso~~ — FALSO POSITIVO
`quorumMax = actual + retirado` es el máximo histórico de presencia simultánea, no un contador absoluto.
Un mismo inmueble solo tiene un registro activo por sesión; el estado cambia entre `CHECKED_IN` y `RETIRADO`
pero no se duplica. El valor nunca puede superar el coeficiente total del padrón.

#### R14 — `down()` de migración cabeza_inmueble_snapshot revierta a tipo incorrecto
Un rollback de esa migración rompería todos los snapshots alfanuméricos.

---

## 8. Próximas Tareas Sugeridas (en orden)

```
[x] R1  — AppServiceProvider is_active: FALSO POSITIVO, no hay acción (aclarado 2026-05-03)
[x] R2  — Suite de tests rota: migraciones SQLite corregidas, 23/23 pasan (hecho 2026-05-04)
[x] R3  — Eliminar RegistroPantallaFunciona.php (hecho 2026-05-03)
[x] R4  — Registro público: ruta /register nunca existió; RegistrationTest eliminado (hecho 2026-05-04)
[x] R5  — Corregir unique constraint padron_id (hecho 2026-05-03)
[x] R6  — Rutas Livewire duplicadas en AppServiceProvider eliminadas (hecho)
[x] R7  — Corregir max(now(), string) en RetiroReingreso (hecho 2026-05-03)
[x] R8  — Eliminar columnExists() dead code (hecho 2026-05-04)
[x] R9  — Agregar middleware evento.activo a /controles/retiro (hecho 2026-05-03)
[x] R13 — quorumMax: FALSO POSITIVO — comportamiento correcto por diseño

[ ] 1.  Limpiar RegisteredUserController (dead code — tiene route('dashboard') roto pero sin ruta activa)
[ ] 2.  Mover importación Excel a un Job asíncrono (R10)
[ ] 3.  Escribir tests de dominio: ControlService, addPoder/removePoder, quórum, retiro/reingreso
[ ] 4.  Cambiar APP_DEBUG=false en producción (R11)
[ ] 5.  Resolver station_id siempre null en registros_checkin (R12)
[ ] 6.  Revisar down() de migración cabeza_inmueble_snapshot (R14)
```

---

## 9. Reglas de Trabajo

1. **Un cambio a la vez.** No mezclar refactors con fixes ni features con correcciones de bugs.

2. **Validar antes de commit.**
   - `php -l` en el archivo modificado.
   - `php artisan route:list` si se tocaron rutas.
   - Prueba manual del flujo afectado.

3. **No tocar lógica si el cambio es visual.**
   Si el cambio es CSS/Blade, no tocar PHP de backend. Si el cambio es lógica, no reorganizar el Blade.

4. **No mezclar dependencias con fixes.**
   No actualizar `composer.json` o `package.json` en el mismo commit que un fix de negocio.

5. **Documentar qué snapshot se toca.**
   Cualquier cambio que afecte `coef_total_snapshot`, `cabeza_inmueble_snapshot` o `control_numero_snapshot` debe describirse explícitamente en el commit.

6. **No modificar migraciones existentes.**
   Siempre crear una migración nueva para cambios de schema. Las existentes son historia.

7. **Los tests deben pasar antes de cualquier merge a `main`.**
   Mientras la suite esté rota, al menos ejecutar `php -l` y prueba manual del flujo completo.

---

## 10. Estructura de Carpetas Relevante

```
app/
  Domain/Event/Models/Evento.php      ← modelo principal de eventos
  Livewire/
    Checkin/RegistroPantalla.php      ← componente principal de check-in
    Quorum/Show.php                   ← tablero de quórum
    Controls/RetiroReingreso.php      ← retiro, reingreso, reemplazo
    BaseTurning/Index.php             ← listado para sistema de turnos
    Event/Form.php                    ← crear/editar evento + importar Excel
    Event/Index.php                   ← lista de eventos + activar puesto
    Admin/Usuarios/Index.php          ← gestión de usuarios (ADMIN)
  Services/ControlService.php         ← asignación y liberación de controles
  Support/EventContext.php            ← contexto de evento activo por sesión
  Exports/InformeAsambleaExport.php   ← 6 hojas Excel del informe
  Http/Middleware/
    EnsureEventoActivo.php            ← requiere evento activo en sesión
    EnsureUserIsActive.php            ← bloquea usuarios desactivados
    SetEventContext.php               ← inicializa EventContext al inicio del request
  Providers/AppServiceProvider.php    ← gates, singleton EventContext, Livewire routes
database/migrations/                  ← 26 migraciones (nunca modificar existentes)
routes/web.php                        ← todas las rutas autenticadas
docs/
  PROJECT.md                          ← especificación original e identidad visual
  COEFIX_CONTEXT.md                   ← este archivo
```

---

*Este documento debe actualizarse cada vez que se completen tareas de la lista o se detecten nuevos riesgos.*
