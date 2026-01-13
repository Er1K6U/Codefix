<?php

namespace App\Livewire\Event;

use Livewire\Component;
use Illuminate\Support\Facades\Gate;
use App\Domain\Event\Models\Evento;
use App\Models\AuditLog;
use Livewire\WithFileUploads;
use Illuminate\Support\Str;

class Form extends Component
{
    use WithFileUploads;

    public ?int $idEvento = null;

    public string $titulo = '';
    public ?string $descripcion = null;
    public string $fecha_inicio = '';
    public bool $activo = true;

    // ✅ Imagen (ya existía)
    public $imagenFile = null;            // archivo temporal
    public ?string $imagenActual = null;  // imagen guardada (modo editar)

    // ✅ NUEVO: Excels para importación (se guardan en storage/app/imports)
    public $baseExcelFile = null;         // Base Evento (base_evento.xlsx)
    public $controlesExcelFile = null;    // Controles (Controles.xlsx)

    // ✅ NUEVO: rutas guardadas (para verlas luego si quieres mostrar en UI)
    public ?string $baseExcelPath = null;       // imports/xxxx.xlsx
    public ?string $controlesExcelPath = null;  // imports/xxxx.xlsx

    public function mount(?int $id = null): void
    {
        $this->idEvento = $id;

        if ($this->idEvento) {
            Gate::authorize('eventos.editar');

            $evento = Evento::findOrFail($this->idEvento);

            $this->titulo = $evento->titulo;
            $this->descripcion = $evento->descripcion;
            $this->fecha_inicio = $evento->fecha_inicio?->format('Y-m-d') ?? '';
            $this->activo = (bool) $evento->activo;
            $this->imagenActual = $evento->imagen;

            // Si en tu tabla eventos NO existen aún estas columnas, no pasa nada.
            // Las dejamos aquí para futuro (solo si existen).
            $this->baseExcelPath = $evento->base_excel_path ?? null;
            $this->controlesExcelPath = $evento->controles_excel_path ?? null;
        } else {
            Gate::authorize('eventos.crear');
        }
    }

    public function save()
    {
        // ✅ Validación SIN slug
        $rules = [
            'titulo' => 'required|string|max:160',
            'descripcion' => 'nullable|string',
            'fecha_inicio' => 'required|date',
            'activo' => 'boolean',
            'imagenFile' => 'nullable|image|max:2048',

            // ✅ NUEVO: validar archivos Excel
            // (mimes y mimetypes para cubrir Windows/Mac)
            'baseExcelFile' => 'nullable|file|max:10240|mimes:xlsx,xls|mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel',
            'controlesExcelFile' => 'nullable|file|max:10240|mimes:xlsx,xls|mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel',
        ];

        $data = $this->validate($rules);

        // ✅ Generar slug AUTOMÁTICO y ÚNICO (pero ya no lo mostramos en el formulario)
        $slugBase = Str::slug($this->titulo);
        $slug = $slugBase;

        $n = 2;
        while (
            Evento::where('slug', $slug)
                ->when($this->idEvento, fn($q) => $q->where('id', '!=', $this->idEvento))
                ->exists()
        ) {
            $slug = $slugBase . '-' . $n;
            $n++;
        }
        $data['slug'] = $slug;

        // ✅ Imagen
        if ($this->imagenFile) {
            $path = $this->imagenFile->store('eventos', 'public');
            $data['imagen'] = $path;
        }

        // ✅ NUEVO: Guardar Excels en storage/app/imports
        // OJO: esto NO importa aún, solo guarda archivos.
        if ($this->baseExcelFile) {
            $stored = $this->baseExcelFile->storeAs(
                'imports',
                'base_evento_' . now()->format('Ymd_His') . '.xlsx'
            );
            $this->baseExcelPath = $stored; // e.g. imports/base_evento_20260112_235959.xlsx

            // Si la columna existe más adelante, la guardaremos también en $data
            $data['base_excel_path'] = $stored;
        }

        if ($this->controlesExcelFile) {
            $stored = $this->controlesExcelFile->storeAs(
                'imports',
                'controles_' . now()->format('Ymd_His') . '.xlsx'
            );
            $this->controlesExcelPath = $stored; // e.g. imports/controles_20260112_235959.xlsx

            // Si la columna existe más adelante, la guardaremos también en $data
            $data['controles_excel_path'] = $stored;
        }

        // ✅ Guardar en DB + auditoría
        if ($this->idEvento) {
            Gate::authorize('eventos.editar');

            $evento = Evento::findOrFail($this->idEvento);

            $antes = [
                'titulo' => $evento->titulo,
                'slug' => $evento->slug,
                'descripcion' => $evento->descripcion,
                'fecha_inicio' => optional($evento->fecha_inicio)->format('Y-m-d'),
                'activo' => (bool) $evento->activo,
                'imagen' => $evento->imagen,
                'base_excel_path' => $evento->base_excel_path ?? null,
                'controles_excel_path' => $evento->controles_excel_path ?? null,
            ];

            $evento->fill($data);
            $evento->updated_by = auth()->id();
            $evento->save();

            $despues = [
                'titulo' => $evento->titulo,
                'slug' => $evento->slug,
                'descripcion' => $evento->descripcion,
                'fecha_inicio' => optional($evento->fecha_inicio)->format('Y-m-d'),
                'activo' => (bool) $evento->activo,
                'imagen' => $evento->imagen,
                'base_excel_path' => $evento->base_excel_path ?? null,
                'controles_excel_path' => $evento->controles_excel_path ?? null,
            ];

            AuditLog::create([
                'modulo' => 'eventos',
                'accion' => 'updated',
                'subject_type' => Evento::class,
                'subject_id' => $evento->id,
                'user_id' => auth()->id(),
                'meta' => [
                    'antes' => $antes,
                    'despues' => $despues,
                ],
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            session()->flash('ok', 'Evento actualizado correctamente.');
        } else {
            Gate::authorize('eventos.crear');

            $evento = Evento::create($data + [
                'created_by' => auth()->id(),
            ]);

            AuditLog::create([
                'modulo' => 'eventos',
                'accion' => 'created',
                'subject_type' => Evento::class,
                'subject_id' => $evento->id,
                'user_id' => auth()->id(),
                'meta' => [
                    'data' => [
                        'titulo' => $evento->titulo,
                        'slug' => $evento->slug,
                        'descripcion' => $evento->descripcion,
                        'fecha_inicio' => optional($evento->fecha_inicio)->format('Y-m-d'),
                        'activo' => (bool) $evento->activo,
                        'imagen' => $evento->imagen,
                        'base_excel_path' => $evento->base_excel_path ?? null,
                        'controles_excel_path' => $evento->controles_excel_path ?? null,
                    ],
                ],
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            session()->flash('ok', 'Evento creado correctamente.');
        }

        return redirect()->route('eventos.index');
    }

    public function render()
    {
        return view('livewire.event.form')->layout('layouts.app');
    }
}
