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
use App\Http\Controllers\NotificacionController;
use App\Http\Controllers\PacienteController;
use App\Http\Controllers\PagoController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\RolController;
use App\Http\Controllers\UsuarioController;
use App\Models\Cita;
use App\Models\User;
use Illuminate\Support\Facades\Route;

// {cobro}: una cita o una reserva pendiente de pago (las reservas no son citas todavía y {cita} no
// las encuentra). Una reserva anulada ya no se puede cobrar.
Route::bind('cobro', fn (string $valor) => Cita::conReservas()->where('estado', '<>', Cita::RESERVA_ANULADA)->findOrFail($valor));

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
    // Avisos del propio usuario (campanita), para todos los perfiles.
    Route::get('notificaciones', [NotificacionController::class, 'index'])->name('notificaciones.index');
    Route::patch('notificaciones/leidas', [NotificacionController::class, 'marcarLeidas'])->name('notificaciones.leidas');
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
    Route::patch('citas/{cita}/corregir-inasistencia', [CitaController::class, 'corregirInasistencia'])->name('citas.corregir_inasistencia');
    Route::get('citas/{cita}/comprobante', [PagoController::class, 'comprobanteVentanilla'])->name('citas.comprobante')->middleware('can:citas.ver');
    Route::middleware('can:pagos.registrar')->group(function () {
        // Cobro de una reserva (la cita se genera al pagarla) o de una cita ya registrada.
        Route::get('citas/reservas/{cobro}/cobro', [PagoController::class, 'cobroVentanilla'])->name('citas.cobro');
        Route::post('citas/reservas/{cobro}/anular', [PagoController::class, 'anularReservaVentanilla'])->name('citas.reserva.anular');
        Route::post('citas/{cobro}/pago', [PagoController::class, 'ventanilla'])->name('citas.pago');
        Route::post('citas/{cobro}/pago-qr', [PagoController::class, 'generarQrVentanilla'])->name('citas.pago.qr.generar');
        Route::get('citas/pagos/{pago}/qr', [PagoController::class, 'qrVentanilla'])->name('citas.pago.qr');
        Route::post('citas/pagos/{pago}/cancelar', [PagoController::class, 'cancelarQrVentanilla'])->name('citas.pago.qr.cancelar');
        // Vuelta de la pasarela tras cobrar con tarjeta desde la ventanilla.
        Route::get('citas/pagos/{pago}/retorno', [PagoController::class, 'retornoVentanilla'])->name('citas.pago.retorno');
        Route::get('citas/pagos/{pago}/cancelado', [PagoController::class, 'canceladoVentanilla'])->name('citas.pago.cancelado');
    });
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
    // Solo consulta y solo para el administrador (Gate «reportes.ver»).
    Route::get('reportes', [ReporteController::class, 'index'])->name('reportes.index');
    Route::get('reportes/exportar', [ReporteController::class, 'exportar'])->name('reportes.exportar');
    // Solo lectura y solo para el administrador (Gate «bitacora.ver»).
    Route::get('bitacora', [BitacoraController::class, 'index'])->name('bitacora.index');
});

// «Mis citas» del médico: solo consulta y solo sobre sus propias citas.
Route::middleware(['auth', 'verified', 'perfil:' . User::PERFIL_MEDICO])->group(function () {
    Route::get('mis-consultas', [MisConsultasController::class, 'index'])->name('consultas.index');
    Route::get('mis-consultas/{cita}', [MisConsultasController::class, 'show'])->name('consultas.show');
    // El médico registra la asistencia de sus propias citas (atendida o no asistió).
    Route::patch('mis-consultas/{cita}/atendida', [MisConsultasController::class, 'atendida'])->name('consultas.atendida');
    Route::patch('mis-consultas/{cita}/inasistencia', [MisConsultasController::class, 'inasistencia'])->name('consultas.inasistencia');
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
    // Pago en línea: se abre la pasarela y se vuelve a la cita.
    // La solicitud se paga antes de generar la cita.
    Route::get('mis-citas/reservas/{cobro}', [PagoController::class, 'reservaPaciente'])->name('mis-citas.reserva');
    Route::post('mis-citas/reservas/{cobro}/anular', [PagoController::class, 'anularReservaPaciente'])->name('mis-citas.reserva.anular');
    Route::post('mis-citas/{cobro}/pagar', [PagoController::class, 'pagar'])->name('mis-citas.pagar');
    Route::post('mis-citas/{cobro}/pagar-qr', [PagoController::class, 'pagarConQr'])->name('mis-citas.pagar.qr');
    Route::get('mis-citas/pagos/{pago}/qr', [PagoController::class, 'qrPaciente'])->name('mis-citas.pago.qr');
    Route::get('mis-citas/{cita}/comprobante', [PagoController::class, 'comprobantePaciente'])->name('mis-citas.comprobante');
    Route::get('mis-citas/pagos/{pago}/retorno', [PagoController::class, 'retorno'])->name('mis-citas.pago.retorno');
    Route::get('mis-citas/pagos/{pago}/cancelado', [PagoController::class, 'cancelado'])->name('mis-citas.pago.cancelado');
});

require __DIR__.'/auth.php';

// Páginas públicas firmadas: se abren en el celular desde el QR, sin sesión iniciada.
Route::middleware('signed')->group(function () {
    // Página de pago de prueba (solo con PAGOS_PASARELA=simulada).
    Route::get('pagos/simulado/{pago}', [PagoController::class, 'simulado'])->name('pagos.simulado');
    Route::post('pagos/simulado/{pago}', [PagoController::class, 'completarSimulado'])->name('pagos.simulado.completar');
    Route::get('pagos/qr/{pago}/listo', [PagoController::class, 'qrListo'])->name('pagos.qr.listo');
    Route::get('pagos/qr/{pago}/cancelado', [PagoController::class, 'qrCancelado'])->name('pagos.qr.cancelado');
});

// Avisos de Stripe (sin sesión ni CSRF; se verifican con STRIPE_WEBHOOK_SECRET).
Route::post('pagos/stripe/webhook', [PagoController::class, 'webhookStripe'])->name('pagos.stripe.webhook');
