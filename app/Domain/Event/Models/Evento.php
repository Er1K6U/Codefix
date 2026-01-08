<?php

namespace App\Domain\Event\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Evento extends Model
{
    protected $table = 'eventos';

    protected $fillable = [
        'titulo',
        'slug',
        'descripcion',
        'fecha_inicio',
        'fecha_fin',
        'activo',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'activo' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $evento) {
            // Si no escriben slug, lo generamos desde el título
            if (blank($evento->slug) && filled($evento->titulo)) {
                $evento->slug = Str::slug($evento->titulo);
            }
        });
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'updated_by');
    }
}
