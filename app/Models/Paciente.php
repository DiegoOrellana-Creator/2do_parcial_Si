<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Tabla `paciente`: subtipo de `users`. Su clave primaria `id_paciente`
 * es a la vez la referencia a `users.id`.
 */
class Paciente extends Model
{
    protected $table = 'paciente';

    protected $primaryKey = 'id_paciente';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'id_paciente',
        'nro_ficha',
        'inasistencia',
        'bloqueado',
    ];

    protected function casts(): array
    {
        return [
            'inasistencia' => 'integer',
            'bloqueado' => 'boolean',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_paciente', 'id');
    }

    public function informacionMedica(): HasOne
    {
        return $this->hasOne(InformacionMedica::class, 'id_paciente', 'id_paciente');
    }

    public function contactosEmergencia(): HasMany
    {
        return $this->hasMany(ContactoEmergencia::class, 'id_paciente', 'id_paciente');
    }
}
