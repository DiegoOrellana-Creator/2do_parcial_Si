# Clínica Santa Cruz — Sistema de citas médicas

Sistema web para gestionar las citas de la Clínica Santa Cruz: registro de pacientes y médicos,
agenda, cobro de consultas, notificaciones y reportes.

**Demo:** https://clinica-santa-cruz.onrender.com

## Funcionalidades

**Paciente**
- Consulta la disponibilidad médica y solicita citas en línea.
- Paga la consulta con tarjeta o con QR antes de que se genere la cita.
- Cancela o reprograma sus citas con al menos 24 horas de anticipación.
- Recibe avisos por correo y en la campanita (cita generada, cancelada, recordatorio…).

**Recepción**
- Gestiona pacientes, citas y la agenda de los médicos.
- Cobra en ventanilla (efectivo, QR bancario o tarjeta) y emite el comprobante.
- Registra la asistencia o inasistencia de los pacientes.

**Médico**
- Consulta su agenda y sus citas.
- Registra la asistencia o inasistencia de sus pacientes.

**Administración**
- Usuarios, roles y permisos.
- Reportes de citas e ingresos (exportables a CSV).
- Bitácora de todas las operaciones.
- Desbloqueo de pacientes (bloqueo automático a las 3 inasistencias) y corrección de inasistencias.

## Tecnologías

- PHP 8.2 y Laravel 12
- Blade y CSS propio (sin JavaScript)
- PostgreSQL
- Stripe (pagos de prueba) y códigos QR generados en el servidor
- Docker y Render (despliegue)

## Instalación local

1. Clonar el repositorio e instalar dependencias:

   ```bash
   composer install
   ```

2. Copiar la configuración y generar la clave:

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

3. Configurar PostgreSQL en `.env` (`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).

4. Crear las tablas:

   ```bash
   php artisan migrate
   ```

5. Iniciar el servidor y abrir http://127.0.0.1:8000:

   ```bash
   php artisan serve
   ```

Variables opcionales en `.env`: pasarela de pago (`PAGOS_PASARELA`, `STRIPE_SECRET`,
`STRIPE_WEBHOOK_SECRET`) y correo (`MAIL_*`). Sin ellas se usa la pasarela simulada.

## Pruebas

```bash
php artisan test
```

Más de 300 pruebas automáticas. Usan SQLite en memoria, nunca la base de datos real.

## Recordatorio diario

```bash
php artisan citas:recordatorios
```

Envía el recordatorio de las citas del día siguiente. Está programado a las 08:00; en local se
ejecuta con `php artisan schedule:work`.

## Despliegue

La aplicación se publica en Render con el `Dockerfile` del proyecto. Los scripts SQL para
actualizar la base de datos están en `database/scripts/`.

## Autor

Diego Orellana Gutiérrez
