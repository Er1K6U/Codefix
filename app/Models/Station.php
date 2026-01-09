<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Station extends Model
{
    protected $table = 'stations';

    protected $fillable = [
        'ip',
        'nombre',
        'active_event_id',
    ];
}
