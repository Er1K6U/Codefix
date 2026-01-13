<?php

namespace App\Livewire\Event;

use Livewire\Component;
use Illuminate\Support\Facades\Gate;
use App\Domain\Event\Models\Evento;
use App\Models\AuditLog;
use Livewire\WithFileUploads;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

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

    // ✅ Excels para importación
    public $baseExcelFile = null;         // Base Evento
    public $controlesExcelFile = null;    // Controles

    // ✅ Rutas guardadas (para mostrar en UI si quieres)
    public ?string $baseExcelPath = null;       // eventos/{id}/base_evento.xlsx
    public ?string $controlesExcelPath = null;  // eventos/{id}/controles.xlsx

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

            $this->baseExcelPath = $evento->base_excel_path ?? null;
            $this->controlesExcelPath = $evento->controles_excel_path ?? null;
        } else {
            Gate::authorize('eventos.crear');
        }
    }

    public function save()
    {
        $rules = [
            'titulo' => 'required|string|max:160',
            'descripcion' => 'nullable|string',
            'fecha_inicio' => 'required|date',
            'activo' => 'boolean',
            'imagenFile' => 'nullable|image|max:2048',

            // ✅ validar archivos Excel
            'baseExcelFile' => 'nullable|file|max:10240|mimes:xlsx,xls|mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel',
            'controlesExcelFile' => 'nullable|file|max:10240|mimes:xlsx,xls|mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel',
        ];

        $data = $this->validate($rules);

        // ✅ Generar slug AUTOMÁTICO y ÚNICO
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

        // ⚠️ Importante:
        // Ya NO guardamos excels en /imports con timestamp.
        // Los guardamos por evento en: storage/app/eventos/{evento_id}/base_evento.xlsx y controles.xlsx
        // y guardamos la ruta en DB (base_excel_path, controles_excel_path)

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

            // Guardar datos base primero
            $evento->fill($data);
            $evento->updated_by = auth()->id();
            $evento->save();

            // Carpeta por evento
            $dir = "eventos/{$evento->id}";

            // ✅ BASE Excel (si llega nuevo, borramos el anterior)
            if ($this->baseExcelFile) {
                if ($evento->base_excel_path) {
                    Storage::delete($evento->base_excel_path);
                }

                $stored = $this->baseExcelFile->storeAs($dir, 'base_evento.xlsx');
                $evento->base_excel_path = $stored;
                $this->baseExcelPath = $stored;
            }

            // ✅ Controles Excel
            if ($this->controlesExcelFile) {
                if ($evento->controles_excel_path) {
                    Storage::delete($evento->controles_excel_path);
                }

                $stored = $this->controlesExcelFile->storeAs($dir, 'controles.xlsx');
                $evento->controles_excel_path = $stored;
                $this->controlesExcelPath = $stored;
            }

            // Guardar cambios de rutas si hubo archivos
            if ($this->baseExcelFile || $this->controlesExcelFile) {
                $evento->save();
            }

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

            // Crear evento primero para obtener ID
            $evento = Evento::create($data + [
                'created_by' => auth()->id(),
            ]);

            $dir = "eventos/{$evento->id}";
            $cambio = false;

            if ($this->baseExcelFile) {
                $stored = $this->baseExcelFile->storeAs($dir, 'base_evento.xlsx');
                $evento->base_excel_path = $stored;
                $this->baseExcelPath = $stored;
                $cambio = true;
            }

            if ($this->controlesExcelFile) {
                $stored = $this->controlesExcelFile->storeAs($dir, 'controles.xlsx');
                $evento->controles_excel_path = $stored;
                $this->controlesExcelPath = $stored;
                $cambio = true;
            }

            if ($cambio) {
                $evento->save();
            }

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
