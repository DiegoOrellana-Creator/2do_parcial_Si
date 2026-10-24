<?php

use App\Http\Controllers\AgendaController;
use App\Http\Controllers\BitacoraController;
use App\Http\Controllers\CitaController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DisponibilidadController;
use App\Http\Controllers\EspecialidadController;
use App\Http\Controllers\HorarioController;
use App\Http\Controllers\MedicoController;
use App\Http\Controllers\MisCitasController;
use App\Http\Controllers\PacienteController;
use App\Http\Controllers\ProfileController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::middleware(['auth', 'verified'])->group(function () {
    // Inicio: el personal ve el panel de la clínica; médicos y pacientes, su propia página de inicio.
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Agenda: el personal con «agenda.ver» consulta cualquier médico; un médico, solo la suya
    // (ver AgendaController).
    Route::get('/agenda', [AgendaController::class, 'index'])->name('agenda.index');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Módulos de gestión: solo para el personal de la clínica y, además, cada acción exige
// su permiso (ver el método middleware() de cada controlador).
Route::middleware(['auth', 'verified', 'perfil:' . User::PERFIL_PERSONAL])->group(function () {
    Route::resource('pacientes', PacienteController::class);
    Route::resource('especialidades', EspecialidadController::class)
        ->parameters(['especialidades' => 'especialidad']);
    Route::resource('medicos', MedicoController::class)
        ->parameters(['medicos' => 'medico']);
    Route::resource('horarios', HorarioController::class)
        ->except(['show'])
        ->parameters(['horarios' => 'horario']);
    Route::get('disponibilidad', [DisponibilidadController::class, 'index'])->name('disponibilidad.index');
    Route::resource('citas', CitaController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->parameters(['citas' => 'cita']);
    Route::patch('citas/{cita}/cancelar', [CitaController::class, 'cancelar'])->name('citas.cancelar');
    // Solo lectura y solo para el administrador (Gate «bitacora.ver»).
    Route::get('bitacora', [BitacoraController::class, 'index'])->name('bitacora.index');
});

// Funciones del paciente: solo cuentas con perfil paciente, y siempre sobre su propia ficha.
Route::middleware(['auth', 'verified', 'perfil:' . User::PERFIL_PACIENTE])->group(function () {
    Route::get('mi-disponibilidad', [MisCitasController::class, 'disponibilidad'])->name('mis-citas.disponibilidad');
    Route::get('mis-citas', [MisCitasController::class, 'index'])->name('mis-citas.index');
    Route::get('mis-citas/solicitar', [MisCitasController::class, 'create'])->name('mis-citas.create');
    Route::post('mis-citas', [MisCitasController::class, 'store'])->name('mis-citas.store');
    Route::get('mis-citas/{cita}', [MisCitasController::class, 'show'])->name('mis-citas.show');
});

require __DIR__.'/auth.php';
