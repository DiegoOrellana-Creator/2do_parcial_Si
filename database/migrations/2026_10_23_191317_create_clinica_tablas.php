<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Tablas Independientes
        Schema::create('rol', function (Blueprint $table) {
            $table->id('id_rol');
            $table->string('nombre', 50);
            $table->text('descripcion')->nullable();
        });

        Schema::create('permiso', function (Blueprint $table) {
            $table->id('id_permiso');
            $table->string('codigo', 50)->unique();
            $table->string('nombre', 100);
        });

        Schema::create('especialidad', function (Blueprint $table) {
            $table->id('id_especialidad');
            $table->string('nombre', 100);
            $table->text('descripcion')->nullable();
            $table->string('estado', 20)->default('Activo');
        });

        // 2. Relaciones (Usuario/Roles)
        Schema::create('rol_permiso', function (Blueprint $table) {
            $table->unsignedBigInteger('id_rol');
            $table->unsignedBigInteger('id_permiso');
            $table->foreign('id_rol')->references('id_rol')->on('rol')->onDelete('cascade');
            $table->foreign('id_permiso')->references('id_permiso')->on('permiso')->onDelete('cascade');
            $table->primary(['id_rol', 'id_permiso']);
        });

        Schema::create('usuario_rol', function (Blueprint $table) {
            $table->unsignedBigInteger('id_usuario');
            $table->unsignedBigInteger('id_rol');
            $table->timestamp('fecha_asignacion')->useCurrent();
            $table->string('estado', 20)->default('Activo');
            $table->foreign('id_usuario')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('id_rol')->references('id_rol')->on('rol')->onDelete('cascade');
            $table->primary(['id_usuario', 'id_rol']);
        });

        // 3. Subclases de Usuario
        Schema::create('preferencia_cuenta', function (Blueprint $table) {
            $table->unsignedBigInteger('id_usuario')->primary();
            $table->boolean('acepta_whatsapp')->default(true);
            $table->boolean('acepta_correo')->default(true);
            $table->text('privacidad')->nullable();
            $table->foreign('id_usuario')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::create('paciente', function (Blueprint $table) {
            $table->unsignedBigInteger('id_paciente')->primary();
            $table->string('nro_ficha', 50)->unique()->nullable();
            $table->integer('inasistencia')->default(0);
            $table->boolean('bloqueado')->default(false);
            $table->foreign('id_paciente')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::create('recepcionista', function (Blueprint $table) {
            $table->unsignedBigInteger('id_recepcionista')->primary();
            $table->string('codigo', 50)->unique()->nullable();
            $table->foreign('id_recepcionista')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::create('administrador', function (Blueprint $table) {
            $table->unsignedBigInteger('id_administrador')->primary();
            $table->string('codigo', 50)->unique()->nullable();
            $table->foreign('id_administrador')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::create('medico', function (Blueprint $table) {
            $table->unsignedBigInteger('id_medico')->primary();
            $table->string('matricula', 50)->unique()->nullable();
            $table->string('codigo', 50)->unique()->nullable();
            $table->foreign('id_medico')->references('id')->on('users')->onDelete('cascade');
        });

        // 4. Dependientes de Paciente
        Schema::create('contacto_emergencia', function (Blueprint $table) {
            $table->id('id_contacto');
            $table->unsignedBigInteger('id_paciente');
            $table->string('nombre', 100);
            $table->string('parentesco', 50)->nullable();
            $table->string('telefono', 20)->nullable();
            $table->foreign('id_paciente')->references('id_paciente')->on('paciente')->onDelete('cascade');
        });

        Schema::create('informacion_medica', function (Blueprint $table) {
            $table->unsignedBigInteger('id_paciente')->primary();
            $table->string('tipo_sangre', 10)->nullable();
            $table->text('alergias')->nullable();
            $table->text('enfermedades_cronicas')->nullable();
            $table->text('medicamentos')->nullable();
            $table->text('antecedentes')->nullable();
            $table->foreign('id_paciente')->references('id_paciente')->on('paciente')->onDelete('cascade');
        });

        Schema::create('bloqueo_paciente', function (Blueprint $table) {
            $table->id('id_bloqueo');
            $table->unsignedBigInteger('id_paciente');
            $table->timestamp('fecha_bloqueo')->useCurrent();
            $table->timestamp('fecha_desbloqueo')->nullable();
            $table->text('motivo')->nullable();
            $table->integer('inasistencias_acumuladas')->nullable();
            $table->foreign('id_paciente')->references('id_paciente')->on('paciente')->onDelete('cascade');
        });

        // 5. Dependientes de Medico
        Schema::create('especialidad_medico', function (Blueprint $table) {
            $table->unsignedBigInteger('id_medico');
            $table->unsignedBigInteger('id_especialidad');
            $table->foreign('id_medico')->references('id_medico')->on('medico')->onDelete('cascade');
            $table->foreign('id_especialidad')->references('id_especialidad')->on('especialidad')->onDelete('cascade');
            $table->primary(['id_medico', 'id_especialidad']);
        });

        Schema::create('horario_atencion', function (Blueprint $table) {
            $table->id('id_horario');
            $table->unsignedBigInteger('id_medico');
            $table->integer('dia_semana');
            $table->time('hora_inicio');
            $table->time('hora_final');
            $table->integer('duracion_cita')->nullable();
            $table->foreign('id_medico')->references('id_medico')->on('medico')->onDelete('cascade');
        });

        Schema::create('bloqueo_agenda', function (Blueprint $table) {
            $table->id('id_bloqueo');
            $table->unsignedBigInteger('id_medico');
            $table->date('fecha');
            $table->time('hora_inicio');
            $table->time('hora_final');
            $table->text('motivo')->nullable();
            $table->foreign('id_medico')->references('id_medico')->on('medico')->onDelete('cascade');
        });

        // 6. Sistema y Citas
        Schema::create('bitacora', function (Blueprint $table) {
            $table->id('id_bitacora');
            $table->unsignedBigInteger('id_usuario')->nullable();
            $table->timestamp('fecha_hora')->useCurrent();
            $table->string('accion', 100);
            $table->string('modulo', 100)->nullable();
            $table->integer('id_registro')->nullable();
            $table->text('detalle')->nullable();
            $table->string('ip', 45)->nullable();
            $table->foreign('id_usuario')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('notificacion', function (Blueprint $table) {
            $table->id('id_notificacion');
            $table->unsignedBigInteger('id_usuario');
            $table->string('tipo', 50)->nullable();
            $table->text('mensaje');
            $table->string('canal', 50)->nullable();
            $table->string('estado_envio', 20)->default('Pendiente');
            $table->timestamp('fecha_programada')->nullable();
            $table->timestamp('fecha_envio')->nullable();
            $table->boolean('leido')->default(false);
            $table->foreign('id_usuario')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::create('citas', function (Blueprint $table) {
            $table->id('id_cita');
            $table->string('codigo', 50)->unique();
            $table->unsignedBigInteger('id_paciente');
            $table->unsignedBigInteger('id_medico');
            $table->unsignedBigInteger('id_especialidad');
            $table->date('fecha_cita');
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->text('motivo')->nullable();
            $table->string('estado', 30)->default('Programada');
            $table->timestamp('fecha_creacion')->useCurrent();
            $table->text('motivo_cancelacion')->nullable();
            $table->timestamp('fecha_cancelacion')->nullable();
            $table->unsignedBigInteger('cancelado_por')->nullable();
            
            $table->foreign('id_paciente')->references('id_paciente')->on('paciente')->onDelete('cascade');
            $table->foreign('id_medico')->references('id_medico')->on('medico')->onDelete('cascade');
            $table->foreign('id_especialidad')->references('id_especialidad')->on('especialidad')->onDelete('cascade');
            $table->foreign('cancelado_por')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('historial_estado_cita', function (Blueprint $table) {
            $table->id('id_historial');
            $table->unsignedBigInteger('id_cita');
            $table->string('estado_anterior', 30)->nullable();
            $table->string('estado_nuevo', 30);
            $table->timestamp('fecha_hora')->useCurrent();
            $table->text('motivo')->nullable();
            $table->unsignedBigInteger('registrado_por')->nullable();
            
            $table->foreign('id_cita')->references('id_cita')->on('citas')->onDelete('cascade');
            $table->foreign('registrado_por')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('pago', function (Blueprint $table) {
            $table->id('id_pago');
            $table->unsignedBigInteger('id_cita');
            $table->decimal('monto', 10, 2);
            $table->string('metodo_de_pago', 50)->nullable();
            $table->string('id_transaccion', 100)->nullable();
            $table->string('estado_pago', 30)->default('Pendiente');
            $table->timestamp('fecha_pago')->nullable();
            
            $table->foreign('id_cita')->references('id_cita')->on('citas')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Esto permite deshacer las migraciones correctamente si hay errores
        Schema::dropIfExists('pago');
        Schema::dropIfExists('historial_estado_cita');
        Schema::dropIfExists('citas');
        Schema::dropIfExists('notificacion');
        Schema::dropIfExists('bitacora');
        Schema::dropIfExists('bloqueo_agenda');
        Schema::dropIfExists('horario_atencion');
        Schema::dropIfExists('especialidad_medico');
        Schema::dropIfExists('bloqueo_paciente');
        Schema::dropIfExists('informacion_medica');
        Schema::dropIfExists('contacto_emergencia');
        Schema::dropIfExists('medico');
        Schema::dropIfExists('administrador');
        Schema::dropIfExists('recepcionista');
        Schema::dropIfExists('paciente');
        Schema::dropIfExists('preferencia_cuenta');
        Schema::dropIfExists('usuario_rol');
        Schema::dropIfExists('rol_permiso');
        Schema::dropIfExists('especialidad');
        Schema::dropIfExists('permiso');
        Schema::dropIfExists('rol');
    }
};