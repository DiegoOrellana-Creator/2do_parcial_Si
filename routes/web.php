<?php

use App\Http\Controllers\AgendaController;
use App\Http\Controllers\BitacoraController;
use App\Http\Controllers\BloqueoAgendaController;
use App\Http\Controllers\CitaController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DisponibilidadController;
use App\Http\Controllers\EspecialidadController;
use App\Http\Controllers\HorarioController;
use App\Http\Controllers\MedicoController;
use App\Http\Controllers\MisCitasController;
use App\Http\Controllers\MisConsultasController;
use App\Http\Controllers\PacienteController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RolController;
use App\Http\Controllers\UsuarioController;
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
});

// Módulos de gestión: solo para el personal de la clínica y, además, cada acción exige
// su permiso (ver el método middleware() de cada controlador).
Route::middleware(['auth', 'verified', 'perfil:' . User::PERFIL_PERSONAL])->group(function () {
    // Las cuentas nunca se borran: pacientes y médicos no tienen ruta de eliminación; se desactivan.
    Route::resource('pacientes', PacienteController::class)->except(['destroy']);
    Route::resource('especialidades', EspecialidadController::class)
        ->parameters(['especialidades' => 'especialidad']);
    Route::resource('medicos', MedicoController::class)
        ->except(['destroy'])
        ->parameters(['medicos' => 'medico']);
    Route::resource('horarios', HorarioController::class)
        ->except(['show'])
        ->parameters(['horarios' => 'horario']);
    Route::get('disponibilidad', [DisponibilidadController::class, 'index'])->name('disponibilidad.index');
    Route::resource('citas', CitaController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->parameters(['citas' => 'cita']);
    Route::get('agenda/bloqueos', [BloqueoAgendaController::class, 'index'])->name('agenda.bloqueos.index');
    Route::get('agenda/bloqueos/registrar', [BloqueoAgendaController::class, 'create'])->name('agenda.bloqueos.create');
    Route::post('agenda/bloqueos', [BloqueoAgendaController::class, 'store'])->name('agenda.bloqueos.store');
    Route::delete('agenda/bloqueos/{bloqueo}', [BloqueoAgendaController::class, 'destroy'])->name('agenda.bloqueos.destroy');
    Route::patch('citas/{cita}/cancelar', [CitaController::class, 'cancelar'])->name('citas.cancelar');
    Route::patch('citas/{cita}/confirmar', [CitaController::class, 'confirmar'])->name('citas.confirmar');
    Route::patch('citas/{cita}/atendida', [CitaController::class, 'atendida'])->name('citas.atendida');
    Route::patch('citas/{cita}/inasistencia', [CitaController::class, 'inasistencia'])->name('citas.inasistencia');
    Route::patch('pacientes/{paciente}/desbloquear', [PacienteController::class, 'desbloquear'])->name('pacientes.desbloquear');
    Route::get('pacientes/{paciente}/citas', [PacienteController::class, 'citas'])->name('pacientes.citas');
    Route::get('citas/{cita}/reprogramar', [CitaController::class, 'reprogramar'])->name('citas.reprogramar');
    Route::patch('citas/{cita}/reprogramar', [CitaController::class, 'guardarReprogramacion'])->name('citas.reprogramar.guardar');
    // Solo para el administrador (Gate «usuarios.gestionar»). Las cuentas no se borran.
    Route::resource('usuarios', UsuarioController::class)
        ->except(['show', 'destroy'])
        ->parameters(['usuarios' => 'usuario']);
    // Solo para el administrador (Gate «roles.gestionar»). Los roles no se borran.
    Route::resource('roles', RolController::class)
        ->except(['show', 'destroy'])
        ->parameters(['roles' => 'rol']);
    // Solo lectura y solo para el administrador (Gate «bitacora.ver»).
    Route::get('bitacora', [BitacoraController::class, 'index'])->name('bitacora.index');
});

// «Mis citas» del médico: solo consulta y solo sobre sus propias citas.
Route::middleware(['auth', 'verified', 'perfil:' . User::PERFIL_MEDICO])->group(function () {
    Route::get('mis-consultas', [MisConsultasController::class, 'index'])->name('consultas.index');
    Route::get('mis-consultas/{cita}', [MisConsultasController::class, 'show'])->name('consultas.show');
});

// Funciones del paciente: solo cuentas con perfil paciente, y siempre sobre su propia ficha.
Route::middleware(['auth', 'verified', 'perfil:' . User::PERFIL_PACIENTE])->group(function () {
    Route::get('mi-disponibilidad', [MisCitasController::class, 'disponibilidad'])->name('mis-citas.disponibilidad');
    Route::get('mis-citas', [MisCitasController::class, 'index'])->name('mis-citas.index');
    Route::get('mis-citas/solicitar', [MisCitasController::class, 'create'])->name('mis-citas.create');
    Route::post('mis-citas', [MisCitasController::class, 'store'])->name('mis-citas.store');
    Route::get('mis-citas/{cita}', [MisCitasController::class, 'show'])->name('mis-citas.show');
    Route::patch('mis-citas/{cita}/cancelar', [MisCitasController::class, 'cancelar'])->name('mis-citas.cancelar');
    Route::get('mis-citas/{cita}/reprogramar', [MisCitasController::class, 'reprogramar'])->name('mis-citas.reprogramar');
    Route::patch('mis-citas/{cita}/reprogramar', [MisCitasController::class, 'guardarReprogramacion'])->name('mis-citas.reprogramar.guardar');
});

require __DIR__.'/auth.php';
