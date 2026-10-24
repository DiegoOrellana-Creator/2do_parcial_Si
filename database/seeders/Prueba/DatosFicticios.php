<?php

namespace Database\Seeders\Prueba;

/**
 * Catálogos ficticios para la población de prueba de la Clínica Santa Cruz.
 * Ningún nombre, documento, teléfono o correo corresponde a una persona real:
 * los correos usan el dominio reservado .test y los CI empiezan por 99.
 */
final class DatosFicticios
{
    public const NOMBRES_FEMENINOS = [
        'Ana', 'María', 'Lucía', 'Valeria', 'Camila', 'Daniela', 'Sofía', 'Gabriela', 'Paola', 'Carla',
        'Fernanda', 'Andrea', 'Natalia', 'Patricia', 'Rosa', 'Elena', 'Claudia', 'Mónica', 'Verónica', 'Silvia',
        'Jimena', 'Mariana', 'Laura', 'Teresa', 'Carolina', 'Adriana', 'Lorena', 'Beatriz', 'Isabel', 'Noelia',
    ];

    public const NOMBRES_MASCULINOS = [
        'Jorge', 'Luis', 'Carlos', 'Andrés', 'Roberto', 'Daniel', 'Miguel', 'José', 'Fernando', 'Ricardo',
        'Diego', 'Javier', 'Sergio', 'Marcelo', 'Gonzalo', 'Rodrigo', 'Pablo', 'Mauricio', 'Héctor', 'Raúl',
        'Alejandro', 'Óscar', 'Eduardo', 'Gustavo', 'Hugo', 'Iván', 'Julio', 'Mario', 'Nicolás', 'Víctor',
    ];

    public const APELLIDOS = [
        'Rojas', 'Vaca', 'Suárez', 'Antelo', 'Justiniano', 'Montaño', 'Salvatierra', 'Rivero', 'Chávez', 'Pinto',
        'Gutiérrez', 'Flores', 'Mendoza', 'Herrera', 'Méndez', 'Vargas', 'Peña', 'Saucedo', 'Áñez', 'Ortiz',
        'Moreno', 'Parada', 'Roca', 'Terrazas', 'Cuéllar', 'Arteaga', 'Landívar', 'Durán', 'Becerra', 'Aguilera',
        'Zambrana', 'Soliz', 'Ribera', 'Cabrera', 'Paz', 'Quiroga', 'Medina', 'Castro', 'Rocha', 'Ibáñez',
    ];

    public const CALLES = [
        'Av. Los Tajibos', 'Calle Las Palmas', 'Av. Cristo Redentor', 'Calle Los Cusis', 'Av. Banzer',
        'Calle Ñuflo de Chávez', 'Av. Paraguá', 'Calle Los Pinos', 'Av. Alemana', 'Calle Moldes',
        'Av. Santos Dumont', 'Calle Velasco', 'Av. Busch', 'Calle Sucre', 'Av. Mutualista',
    ];

    public const ZONAS = ['Equipetrol', 'Plan 3000', 'Villa 1ro de Mayo', 'Zona Norte', 'Centro', 'Urbarí', 'Las Palmas', 'Los Lotes'];

    /**
     * Especialidades: [nombre, descripción, precio de consulta de prueba en Bs].
     */
    public const ESPECIALIDADES = [
        ['Medicina General', 'Atención primaria, diagnóstico inicial y derivación.', 100],
        ['Pediatría', 'Atención médica de niños y adolescentes.', 150],
        ['Cardiología', 'Diagnóstico y tratamiento de enfermedades del corazón.', 250],
        ['Dermatología', 'Enfermedades de la piel, el cabello y las uñas.', 200],
        ['Ginecología y Obstetricia', 'Salud femenina, embarazo y parto.', 200],
        ['Traumatología y Ortopedia', 'Lesiones y enfermedades del sistema musculoesquelético.', 220],
        ['Neurología', 'Trastornos del sistema nervioso.', 250],
        ['Oftalmología', 'Salud visual y enfermedades de los ojos.', 180],
        ['Otorrinolaringología', 'Enfermedades de oído, nariz y garganta.', 180],
        ['Psiquiatría', 'Diagnóstico y tratamiento de trastornos mentales.', 230],
        ['Psicología Clínica', 'Evaluación y terapia psicológica.', 150],
        ['Endocrinología', 'Trastornos hormonales y metabólicos.', 220],
        ['Gastroenterología', 'Enfermedades del aparato digestivo.', 230],
        ['Neumología', 'Enfermedades del aparato respiratorio.', 220],
        ['Nefrología', 'Enfermedades de los riñones.', 230],
        ['Urología', 'Aparato urinario y sistema reproductor masculino.', 220],
        ['Reumatología', 'Enfermedades de articulaciones y tejido conectivo.', 220],
        ['Oncología', 'Diagnóstico y tratamiento del cáncer.', 300],
        ['Hematología', 'Enfermedades de la sangre.', 250],
        ['Infectología', 'Enfermedades infecciosas.', 220],
        ['Medicina Interna', 'Atención integral del adulto.', 180],
        ['Geriatría', 'Atención de adultos mayores.', 180],
        ['Nutrición y Dietética', 'Evaluación nutricional y planes de alimentación.', 120],
        ['Fisioterapia y Rehabilitación', 'Recuperación funcional y rehabilitación física.', 120],
        ['Odontología', 'Salud bucodental.', 150],
    ];

    public const MOTIVOS_CITA = [
        'Control de rutina', 'Consulta por dolor persistente', 'Revisión de resultados de laboratorio',
        'Control de tratamiento', 'Primera consulta', 'Seguimiento postoperatorio', 'Evaluación preventiva',
        'Consulta por malestar general', 'Renovación de receta', 'Control anual',
    ];

    public const MOTIVOS_CANCELACION = [
        'El paciente no puede asistir por motivos laborales.',
        'El paciente solicitó cambiar de médico.',
        'Viaje imprevisto del paciente.',
        'El médico no estará disponible.',
        'El paciente se recuperó y ya no necesita la consulta.',
    ];

    public const ALERGIAS = [null, null, null, 'Penicilina', 'Ibuprofeno', 'Polen', 'Mariscos', 'Látex', 'Ácaros del polvo'];

    public const ENFERMEDADES_CRONICAS = [null, null, null, 'Hipertensión arterial', 'Diabetes tipo 2', 'Asma', 'Hipotiroidismo', 'Gastritis crónica'];

    public const MEDICAMENTOS = [null, null, 'Losartán 50 mg', 'Metformina 850 mg', 'Salbutamol inhalador', 'Levotiroxina 50 mcg', 'Omeprazol 20 mg'];

    public const ANTECEDENTES = [null, null, 'Apendicectomía', 'Fractura de brazo derecho', 'Antecedentes familiares de diabetes', 'Cesárea', 'Ninguno relevante'];

    public const PARENTESCOS = ['Madre', 'Padre', 'Esposo', 'Esposa', 'Hermano', 'Hermana', 'Hijo', 'Hija', 'Tío', 'Tía'];

    public const TIPOS_SANGRE = ['O+', 'O+', 'O+', 'A+', 'A+', 'B+', 'AB+', 'O-', 'A-', 'B-'];

    public const MOTIVOS_BLOQUEO_AGENDA = [
        'Congreso médico', 'Capacitación interna', 'Permiso personal', 'Cirugía programada', 'Reunión de servicio',
    ];
}
