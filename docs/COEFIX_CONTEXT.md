# COEFIX — Documento de Contexto de Trabajo

> Última actualización: 2026-05-06 (modo nominal + mejora flujo check-in)
> Rama activa: `develop`

---

## 1. Resumen General

Coefix es un sistema de gestión de asambleas de propiedad horizontal.
Opera en red local (LAN) y permite:

- Registrar la asistencia de propietarios a una asamblea (check-in).
- Gestionar representación por poderes (un inmueble o persona representa a otros).
- Calcular el quórum en tiempo real en dos modos:
  - **Modo coeficiente:** quórum por suma de coeficientes de copropiedad (default).
  - **Modo nominal:** quórum por conteo de votos de personas (1 persona = 1 voto + poderes).
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

### 5.1 Modo de quórum (`tipo_quorum`)

Cada evento tiene un campo `tipo_quorum` en la tabla `eventos`:

| Valor | Descripción |
|---|---|
| `coeficiente` | Quórum por suma de coeficientes de copropiedad (modo histórico, default) |
| `nominal` | Quórum por conteo de votos de personas (1 persona = 1 voto + poderes representados) |

`EventContext::tipoQuorum()` lee el campo desde BD en cada request, garantizando aislamiento entre eventos activos en distintos puestos.

Todos los módulos que dependen del modo usan `if ($this->tipoQuorum === 'nominal')` con early return, dejando el código de coeficiente intacto al final.

---

### 5.2 Check-in (`RegistroPantalla`)

**Modo coeficiente:**
1. El operador busca un inmueble por código (`updatedSearch` → `evento_padron`).
2. Si el inmueble es un poder de otro, se abre automáticamente la cabeza del grupo (`resolveCheckinTarget`).
3. Se crea o carga el `registros_checkin` del inmueble cabeza.
4. El operador digita el número de control; este queda "en cola" (`controlPendienteNumero`) pero NO se asigna todavía.
5. El operador rellena los datos del asistente y presiona **Guardar asistente** (`saveAsistente`).
6. Solo en ese momento se asigna el control y el estado pasa a `CHECKED_IN`.
7. Se guardan los snapshots: `coef_total_snapshot`, `cabeza_inmueble_snapshot`, `control_numero_snapshot`.

**Modo nominal:**
- La búsqueda consulta `evento_personas` (cédula o nombre).
- La selección llama `selectPersona` → crea/carga `registros_checkin` con `persona_id` (no `inmueble_base_id`).
- Datos del asistente se precargan desde `evento_personas`.
- `saveAsistente` guarda `coef_total_snapshot` = conteo de personas del grupo (1 propio + poderes).
- `cabeza_inmueble_snapshot` = nombre de la persona cabeza.

**Flujo post-guardado (ambos modos):**
- Al terminar `saveAsistente()` con éxito, `clearSelection()` limpia todo el formulario.
- Se muestra el modal de éxito ("¡Listo! Check-in cerrado").
- Al cerrar el modal ("Perfecto"), `closeCheckinMsg()` despacha `focus-field` → el cursor vuelve automáticamente al campo de búsqueda principal, listo para la siguiente persona.

**Búsqueda (coeficiente):**
- El campo principal acepta inmueble **o** propietario en el mismo input (query con `orWhere`).
- El resultado del dropdown muestra el nombre del inmueble como label y el propietario como sublabel.

**Reglas duras (ambos modos):**
- Solo la cabeza del grupo puede hacer check-in y recibir control.
- Un control `LIBRE` puede asignarse; uno `ASIGNADO` no.
- Si hay cambios sin guardar, se pide confirmación antes de cambiar de selección.

---

### 5.3 Poderes / Representación

**Modo coeficiente:**
- `representacion_grupos` — define el grupo: `cabeza_padron_id` es el dueño.
- `representacion_miembros` — inmuebles del grupo con `es_cabeza` flag.
- Un inmueble puede ser poder de otro solo si no está ya `CHECKED_IN` ni tiene control asignado.
- `addPoder` / `removePoder` usan `DB::transaction` con `lockForUpdate`.
- `separarCabeza` promueve el primer poder como nueva cabeza.

**Modo nominal:**
- `representacion_grupos_nominal` — `cabeza_persona_id` FK a `evento_personas`.
- `representacion_miembros_nominal` — `persona_id` FK a `evento_personas`.
- Mismas reglas de bloqueo que en coeficiente.
- `addPoderNominal` / `removePoderNominal` — métodos paralelos que usan las tablas nominales.

---

### 5.4 `coef_total_snapshot` (dual-use)

| Modo | Significado | Fuente de cálculo |
|---|---|---|
| coeficiente | Suma de `coeficiente` de todos los miembros del grupo en `evento_padron` | `syncGrupoSnapshot()` |
| nominal | Conteo de personas del grupo (cabeza + poderes) en `representacion_miembros_nominal` | `syncGrupoNominalSnapshot()` |

- Se guarda en `registros_checkin.coef_total_snapshot` al hacer check-in.
- Se recalcula y sincroniza cada vez que se agrega o quita un poder.
- **Fuente de verdad del quórum:** el quórum se calcula sumando `coef_total_snapshot` de los registros activos, NO consultando el padrón/personas en vivo.

---

### 5.5 Quórum (`Quorum\Show`)

**Modo coeficiente:**
```
quorumActual   = SUM(coef_total_snapshot) WHERE estado = 'CHECKED_IN'
quorumRetirado = SUM(coef_total_snapshot) WHERE estado = 'RETIRADO'
quorumMax      = quorumActual + quorumRetirado
```

**Modo nominal:**
```
personasCheckin  = SUM(coef_total_snapshot) WHERE estado = 'CHECKED_IN' AND persona_id IS NOT NULL
personasRetiradas = SUM(coef_total_snapshot) WHERE estado = 'RETIRADO' AND persona_id IS NOT NULL
personasTotal    = COUNT(*) en evento_personas
quorumActual (%)  = personasCheckin / personasTotal * 100  ← solo para barra de progreso
```

- Los controles activos y retirados se cuentan con `DISTINCT control_id`.
- "Últimos llegados" se calcula por `checked_in_at DESC`.

---

### 5.6 Retiro y Reingreso (`RetiroReingreso`)

- **Retirar:** busca el control por número → verifica `ASIGNADO` → cambia estado a `RETIRADO`. En nominal muestra nombre/votos; en coeficiente muestra inmueble/coef.
- **Reingresar:** verifica estado `RETIRADO` → cambia a `CHECKED_IN` → registra `reingreso_at`.
- **Reemplazar control:** permite cambiar el control físico de un asistente. El viejo queda `LIBRE`, el nuevo queda `ASIGNADO`.
- **Consultar control:** solo lectura. En nominal muestra cédula + nombre; en coeficiente muestra inmueble + propietario.

---

### 5.7 Informes Excel (`InformeAsambleaExport`)

El archivo descargable tiene 6 hojas. Cada hoja lee `tipo_quorum` desde BD y bifurca el query y los headers:

| Hoja | Modo coeficiente | Modo nominal |
|---|---|---|
| Resumen | Totales de coef (presente, retirado, no asistió) | Totales de votos (presente, retirado, no asistió) |
| Asistencia | Por inmueble: control, código, inmueble, propietario, asistente, teléfono, correo, coef, estado, horas | Por persona: control, código, cédula, nombre, teléfono, correo, votos, estado, horas |
| Quórum | Por inmueble con coeficiente | Por persona con votos |
| Ausentes | Inmuebles sin check-in (no poderes) | Personas sin check-in (no poderes) |
| Poderes | Por grupo: coef propio, coef poderes, coef total | Por grupo: votos propios, votos poderes, votos totales |
| Poderes Detalle | Por cabeza: cada apoderado con su coeficiente | Por cabeza: cada apoderado con su nombre/cédula |

`columnFormats()` en cada hoja lee `tipo_quorum` desde BD y retorna `'0'` (entero) para nominal o `'0.00'` para coeficiente.

---

## 6. Cambios Recientes Importantes

### [2026-05-06] Modo nominal — `feat/modo-nominal-personas` + `fix/pulido-ux-nominal`

Implementación completa del modo quórum nominal (por personas/votos). Mergeado a `develop` limpio, sin regresión en modo coeficiente.

**Infraestructura agregada:**
- Migración `tipo_quorum` en `eventos` (`enum: coeficiente/nominal`).
- Tabla `evento_personas` (cédula, nombre, teléfono, correo — una fila por persona por evento).
- Columna `personas_excel_path` en `eventos`.
- Tablas `representacion_grupos_nominal` / `representacion_miembros_nominal` (estructura paralela a las de coeficiente).
- Columnas `persona_id` (FK a `evento_personas`) y soporte nominal en `registros_checkin`.

**Comando Artisan:**
- `ImportBaseNominal` — importa personas desde Excel al evento; ignora duplicados por cédula.

**Módulos adaptados (todos con bifurcación `tipo_quorum`, coeficiente intacto):**
- `Event\Form` — upload de archivo de personas (nominal) + trigger de importación correcto.
- `Checkin\RegistroPantalla` — búsqueda, selección, poderes nominales, snapshots.
- `Quorum\Show` — KPIs de votos, barra de progreso, feed de llegadas nominales.
- `BaseTurning\Index` — tabla con cédula/nombre/votos, subtotales nominales.
- `Controls\RetiroReingreso` — modales con nombre/votos (nominal) vs inmueble/coef (coeficiente).
- `InformeAsambleaExport` — las 6 hojas con columnas y formatos correctos para nominal.

**Commits:**
```
d8dc301  feat: agregar padron nominal e importacion base
2abc10e  feat: agregar representacion base para modo nominal
a883b24  feat: habilitar checkin y carga base para modo nominal
4e1e960  feat: adaptar quorum y base turning al modo nominal
f9e4083  fix: corregir agregacion nominal en quorum
70b35c0  feat: adaptar retiro y controles al modo nominal
270685a  feat: adaptar informes excel al modo nominal
1db9cb2  fix: pulir textos y labels del modo nominal
```

**Pulido UX (rama `fix/pulido-ux-nominal`):**
- Modal "control ocupado" muestra nombre/cédula de la persona en nominal (antes mostraba "0").
- Dropdown de búsqueda de poderes muestra `votos: 1` en nominal (antes `coef: 1.0000`).
- Todos los textos hardcoded de "inmueble" en la pantalla de check-in ajustados a "persona" en nominal.

### [2026-05-06] Mejora de flujo en check-in — `fix/checkin-flujo-busqueda-y-limpieza`

Dos mejoras pequeñas de UX en `Checkin\RegistroPantalla`, sin tocar quórum, informes ni retiro.

- **Limpieza automática post-check-in:** `saveAsistente()` ahora llama `clearSelection()` al terminar, dejando el formulario en blanco inmediatamente después del guardado exitoso.
- **Foco al buscador tras cerrar modal:** nuevo método `closeCheckinMsg()` reemplaza el `$set('checkinMsg', null)` del botón "Perfecto". Al cerrar el modal despacha `focus-field` → el cursor queda en `#checkinSearch` listo para la siguiente búsqueda.
- **Búsqueda coeficiente por inmueble o propietario:** `updatedSearch()` amplía el `WHERE` con `orWhere('propietario')`. El dropdown muestra el propietario como sublabel debajo del inmueble.

Commits: `20706d2 fix: mejorar foco y busqueda en checkin`

---

### [2026-05-04] Suite de tests en verde — 23/23 pasan
- Causa raíz: FKs de SQLite requieren `PRAGMA foreign_keys = ON` y orden de creación correcto.
- Migración `add_imagen_to_eventos_table`: columna `imagen` se agrega con `nullable()`.
- Migración `add_evento_id_to_representacion_miembros_table`: guard con `hasColumn`.
- Ramas cerradas: `fix/tests-sqlite-foreign-keys` + `fix/tests-breeze-desactualizados`.

### [2026-05-04] Desactivación de `/register` y limpieza de tests Breeze
- La ruta `/register` fue desactivada intencionalmente — usuarios se crean vía `/admin/usuarios`.
- `RegistrationTest` eliminado. Tests restantes actualizados para flujo real.

### [2026-05-04] Corrección de redirects de auth: `dashboard` → `eventos.index`
- 4 controladores Breeze corregidos para redirigir a `route('eventos.index')`.

### [2026-05-04] Eliminación de dead code `columnExists()`
- Método privado con SQL `SHOW COLUMNS FROM {$table}` (MySQL-only), sin llamadas. Eliminado.

### [2026-05-03] Corrección de unique constraint en `representacion_miembros`
- `UNIQUE(padron_id)` → `UNIQUE(evento_id, padron_id)`. Se mantiene `INDEX(padron_id)` para FK.

### [2026-05-03] Middleware `evento.activo` en `/controles/retiro`
- Ruta `/controles/retiro` no declaraba `evento.activo`. Corregido en `routes/web.php`.

### [2026-05-03] Corrección de fecha segura en `RetiroReingreso`
- `max(now(), $registro->checked_in_at)` → `now()->max(\Carbon\Carbon::parse(...))`.

### [2026-05-03] Eliminación de archivo zombie `RegistroPantallaFunciona.php`
- 1.697 líneas duplicadas, misma clase, nunca cargada por PSR-4. Eliminada.

### Otras mejoras anteriores (2026-03 / 2026-05)
- `syncGrupoSnapshot()` recalcula snapshot al agregar/quitar poderes.
- Hoja Asistencia incluye columnas Asistente, Teléfono, Correo.
- Estilos profesionales en informe Excel (fondos, bordes, anchos, totales en negrita).
- `cabeza_inmueble_snapshot` cambiado de `bigint` a `varchar(100)` para inmuebles alfanuméricos.
- Hoja Ausentes excluye poderes (`es_cabeza = 0`).
- Timezone `America/Bogota` correcto en export.

---

## 7. Riesgos Detectados en Auditoría

### CRÍTICOS

#### R1 — ~~`AppServiceProvider` usa `is_active` en vez de `activo`~~ — FALSO POSITIVO
`is_active` es columna real para CLI (BackupEvento, ImportBaseEvento). No hay bug.

#### R2 — ~~Suite de tests completamente rota~~ — ✅ CORREGIDO (2026-05-04)
23/23 tests pasando.

#### R3 — ~~`RegistroPantallaFunciona.php` es un archivo zombie~~ — ✅ CORREGIDO

### ALTOS

#### R4 — ~~Registro público abierto~~ — ✅ CORREGIDO (2026-05-04)
Ruta `/register` desactivada. `RegistrationTest` eliminado.

#### R5 — ~~Unique constraint en `representacion_miembros.padron_id`~~ — ✅ CORREGIDO
`UNIQUE(evento_id, padron_id)`. Migración aplicada en batch 21.

### MEDIOS

#### R6 — ~~Rutas Livewire duplicadas~~ — ✅ CORREGIDO

#### R7 — ~~`max(now(), $registro->checked_in_at)` — tipos mixtos~~ — ✅ CORREGIDO

#### R8 — ~~`columnExists()` dead code con SQL MySQL-only~~ — ✅ CORREGIDO (2026-05-04)

#### R9 — ~~Ruta `/controles/retiro` sin middleware `evento.activo`~~ — ✅ CORREGIDO

#### R10 — `Artisan::call()` síncrono en request web
Al crear un evento con archivos Excel, las importaciones corren dentro del request HTTP.
Puede causar timeout con archivos grandes. Pendiente mover a Job asíncrono.

### BAJOS

#### R11 — `APP_DEBUG=true` en `.env`
Si el `.env` actual se usa en producción, expone trazas de stack ante errores.

#### R12 — `station_id` siempre null en `registros_checkin`
El campo existe en la tabla pero `saveAsistente()` nunca lo escribe.

#### R13 — ~~`quorumMax` crece con ciclos retiro/reingreso~~ — FALSO POSITIVO
`quorumMax = actual + retirado` es el máximo histórico. Un mismo registro solo tiene un estado activo. Comportamiento correcto.

#### R14 — `down()` de migración `cabeza_inmueble_snapshot` revierte a tipo incorrecto
Un rollback rompería todos los snapshots alfanuméricos. Sin urgencia mientras no haya rollback en producción.

---

## 8. Próximas Tareas Sugeridas (en orden)

```
[x] R1  — AppServiceProvider is_active: FALSO POSITIVO (aclarado 2026-05-03)
[x] R2  — Suite de tests rota: 23/23 pasan (hecho 2026-05-04)
[x] R3  — Eliminar RegistroPantallaFunciona.php (hecho 2026-05-03)
[x] R4  — Registro público: ruta /register desactivada (hecho 2026-05-04)
[x] R5  — Corregir unique constraint padron_id (hecho 2026-05-03)
[x] R6  — Rutas Livewire duplicadas eliminadas (hecho)
[x] R7  — Corregir max(now(), string) en RetiroReingreso (hecho 2026-05-03)
[x] R8  — Eliminar columnExists() dead code (hecho 2026-05-04)
[x] R9  — Agregar middleware evento.activo a /controles/retiro (hecho 2026-05-03)
[x] R13 — quorumMax: FALSO POSITIVO — comportamiento correcto por diseño
[x]      — Modo nominal completo implementado y mergeado (hecho 2026-05-06)
[x]      — Mejora flujo check-in: limpieza, foco y búsqueda por propietario (hecho 2026-05-06)

--- Siguiente bloque sugerido: UX / UI ---

[ ] UX-1  Revisar y mejorar la experiencia visual general:
          pantallas de check-in, quórum, base turning — coherencia entre modos.
[ ] UX-2  Revisar otros flujos post-acción: retiro/reingreso, reemplazo de control
          (misma mejora de foco y limpieza que ya se hizo en check-in).
[ ] UX-3  Revisar responsividad y usabilidad en pantallas pequeñas (tablets en puesto).

--- Siguiente bloque sugerido: Rendimiento / Optimización local ---

[ ] PERF-1  Mover importación Excel a Job asíncrono con feedback (R10).
[ ] PERF-2  Revisar queries N+1 en Base Turning y check-in con eventos grandes.
[ ] PERF-3  Evaluar índices en registros_checkin para queries frecuentes de quórum.

--- Pendientes menores ---

[ ] 1.  Limpiar RegisteredUserController (dead code — tiene route('dashboard') roto, sin ruta activa)
[ ] 2.  Cambiar APP_DEBUG=false en producción (R11)
[ ] 3.  Resolver station_id siempre null en registros_checkin (R12)
[ ] 4.  Revisar down() de migración cabeza_inmueble_snapshot (R14)
[ ] 5.  Escribir tests de dominio: ControlService, addPoder/removePoder, quórum nominal/coef
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
   Suite verde obligatoria. Ejecutar `php artisan test --no-coverage` antes de cada merge.

8. **Todos los cambios por modo van detrás de `tipo_quorum`.**
   Nunca mezclar lógica de coeficiente y nominal en la misma rama. El modo coeficiente es el modo base y nunca debe romperse.

---

## 10. Estructura de Carpetas Relevante

```
app/
  Console/Commands/
    ImportBaseNominal.php             ← importa personas desde Excel (modo nominal)
  Domain/Event/Models/Evento.php      ← modelo principal de eventos
  Livewire/
    Checkin/RegistroPantalla.php      ← componente principal de check-in (coef + nominal)
    Quorum/Show.php                   ← tablero de quórum (coef + nominal)
    Controls/RetiroReingreso.php      ← retiro, reingreso, reemplazo (coef + nominal)
    BaseTurning/Index.php             ← listado para sistema de turnos (coef + nominal)
    Event/Form.php                    ← crear/editar evento + importar Excel (coef + nominal)
    Event/Index.php                   ← lista de eventos + activar puesto
    Admin/Usuarios/Index.php          ← gestión de usuarios (ADMIN)
  Services/ControlService.php         ← asignación y liberación de controles
  Support/EventContext.php            ← contexto de evento activo por sesión (eventoId + tipoQuorum)
  Exports/InformeAsambleaExport.php   ← 6 hojas Excel (coef + nominal, bifurcación por tipo_quorum)
  Http/Middleware/
    EnsureEventoActivo.php            ← requiere evento activo en sesión
    EnsureUserIsActive.php            ← bloquea usuarios desactivados
    SetEventContext.php               ← inicializa EventContext al inicio del request
  Providers/AppServiceProvider.php    ← gates, singleton EventContext, Livewire routes
database/migrations/                  ← 32 migraciones (nunca modificar existentes)
routes/web.php                        ← todas las rutas autenticadas
docs/
  PROJECT.md                          ← especificación original e identidad visual
  COEFIX_CONTEXT.md                   ← este archivo

Tablas principales:
  eventos                             ← tipo_quorum: 'coeficiente' | 'nominal'
  evento_padron                       ← padrón coeficiente (inmueble, propietario, coeficiente)
  evento_personas                     ← padrón nominal (cedula, nombre, telefono, correo)
  representacion_grupos               ← grupos de poderes coeficiente
  representacion_miembros             ← miembros de grupos coeficiente
  representacion_grupos_nominal       ← grupos de poderes nominal
  representacion_miembros_nominal     ← miembros de grupos nominal
  registros_checkin                   ← registro unificado (persona_id NULL en coef, inmueble_base_id NULL en nominal)
  controles                           ← controles físicos numerados
```

---

*Este documento debe actualizarse cada vez que se completen tareas de la lista o se detecten nuevos riesgos.*
