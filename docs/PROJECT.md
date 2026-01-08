# Coefix — Especificación y decisiones

## Propósito

Sistema local (LAN) para registro de asistentes a eventos/asambleas de propiedad horizontal,
controlando quórum por coeficientes en tiempo real.

## Identidad visual

-   Azul petróleo: #0F3D4C
-   Gris grafito: #2E2E2E
-   Gris claro: #E6E8EB
-   Verde técnico: #4CAF50
-   Blanco: #FFFFFF

## Stack

-   Laravel 11
-   Livewire 3 (UI por componentes)
-   TailwindCSS
-   MySQL 8 (Laragon)
-   Roles/Permisos: spatie/laravel-permission
-   Realtime (luego): Laravel Reverb

## Módulos (dominio)

-   Auth: usuarios, roles, permisos
-   Event: eventos/asambleas
-   Property: base master de inmuebles/propietarios
-   Registration: registro de ingreso/salida, anexos, control
-   Control: dispositivos (número + código)
-   Quorum: cálculo y tablero en vivo
-   Report: informes y exportaciones

## Reglas clave

-   Coeficiente: usar DECIMAL (nunca float).
-   Un inmueble anexo NO puede anexarse a dos registros dentro del mismo evento.
-   Un control NO puede estar asignado a dos registros activos.
-   Ingreso/salida no borra: cambia estado y registra hora.

## Roadmap (orden)

1. Estructura del proyecto (Domain + Livewire) ✅
2. Roles y permisos (Admin/Registrador/Consulta)
3. CRUD de eventos
4. Importar base master de inmuebles
5. Registro (ingreso/salida + anexos + control)
6. Tablero de quórum (realtime)
7. Listados + copiar
8. Reportes + export
