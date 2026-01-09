<?php

namespace App\Livewire\Event;

use Livewire\Component;
use Illuminate\Support\Facades\Gate;
use App\Domain\Event\Models\Evento;
use Illuminate\Support\Str;
use App\Models\AuditLog;
use Livewire\WithFileUploads;


class Form extends Component
{
    public ?int $idEvento = null;
    public string $titulo = '';
    public string $slug = '';
    public ?string $descripcion = null;
    public string $fecha_inicio = '';
    public bool $activo = true;
    use WithFileUploads;
    public $imagenFile = null;      // archivo temporal
    public ?string $imagenActual = null; // imagen guardada (modo editar)


    public function mount(?int $id = null): void
    {
        $this->idEvento = $id;

        if ($this->idEvento) {
            Gate::authorize('eventos.editar');

            $evento = Evento::findOrFail($this->idEvento);

            $this->titulo = $evento->titulo;
            $this->slug = $evento->slug;
            $this->descripcion = $evento->descripcion;
            $this->fecha_inicio = $evento->fecha_inicio?->format('Y-m-d') ?? '';
            $this->activo = (bool) $evento->activo;
            $this->imagenActual = $evento->imagen;
        } else {
            Gate::authorize('eventos.crear');
        }
    }

    public function updatedTitulo(): void
    {
        if ($this->slug === '') {
            $this->slug = Str::slug($this->titulo);
        }
    }

    public function save()
    {
        $rules = [
            'titulo' => 'required|string|max:160',
            'slug' => 'required|string|max:180|unique:eventos,slug,' . ($this->idEvento ?? 'NULL'),
            'descripcion' => 'nullable|string',
            'fecha_inicio' => 'required|date',
            'activo' => 'boolean',
            'imagenFile' => 'nullable|image|max:2048',
        ];

        $data = $this->validate($rules);
        if ($this->imagenFile) {
            $path = $this->imagenFile->store('eventos', 'public');
            $data['imagen'] = $path;
        }

        if ($this->idEvento) {
            Gate::authorize('eventos.editar');

            $evento = Evento::findOrFail($this->idEvento);

            // ✅ snapshot antes
            $antes = [
                'titulo' => $evento->titulo,
                'slug' => $evento->slug,
                'descripcion' => $evento->descripcion,
                'fecha_inicio' => optional($evento->fecha_inicio)->format('Y-m-d'),
                'activo' => (bool) $evento->activo,
            ];

            $evento->fill($data);
            $evento->updated_by = auth()->id();
            $evento->save();

            // ✅ snapshot después
            $despues = [
                'titulo' => $evento->titulo,
                'slug' => $evento->slug,
                'descripcion' => $evento->descripcion,
                'fecha_inicio' => optional($evento->fecha_inicio)->format('Y-m-d'),
                'activo' => (bool) $evento->activo,
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
