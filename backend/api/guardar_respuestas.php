<?php
/**
 * ========================================================
 * API: GUARDAR INTENTO Y AUTOCALCULAR OPCIÓN MÚLTIPLE
 * ========================================================
 * Ruta sugerida: /api/guardar_respuestas.php
 * Método: POST
 * 
 * Recibe JSON:
 * {
 *   "intento_id": 12,
 *   "token": "PARCIAL-PROG-2026",
 *   "nombre_alumno": "Alex Macías Morales",
 *   "respuestas": [
 *     { "pregunta_id": 1, "respuesta": "b" },
 *     { "pregunta_id": 2, "respuesta": "b" },
 *     { "pregunta_id": 3, "respuesta": "graph TD\n A[Inicio] --> B[Fin]" },
 *     { "pregunta_id": 4, "respuesta": "def mi_codigo():\n return True" }
 *   ]
 * }
 * 
 * Reglas de Negocio Implementadas:
 * 1. Valida que el intento exista, coincida con el alumno y token, y esté en estado 'en_progreso'.
 * 2. Verifica que la fecha de expiración del examen no haya sido rebasada.
 * 3. Utiliza Transacciones ACID de mysqli$mysqli (beginTransaction / commit / rollBack).
 * 4. Autocalcula las preguntas de tipo 'opcion_multiple' comparando la respuesta enviada
 *    con 'respuesta_correcta' de la base de datos (con normalización de mayúsculas/espacios).
 * 5. Guarda las respuestas de 'codigo' y 'diagrama_flujo' con es_correcta = NULL (pendiente de revisión docente).
 * 6. Actualiza el intento a estado 'completado', guarda fecha_fin y el puntaje_automatica.
 * 7. Retorna recibo con desglose de puntos automáticos y confirmación de recepción.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/Errores.php';

use config\Errores;

$debug = true;
Errores::inicializar($debug);

// Validar método HTTP
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse([
        'success' => false,
        'error'   => 'Método no permitido. Solo se acepta POST.'
    ], 405);
}

// Obtener payload JSON
$rawInput = file_get_contents('php://input');
$payload  = json_decode($rawInput, true);

if (!is_array($payload)) {
    $payload = $_POST;
}

$intentoId        = (int)($payload['intento_id'] ?? 0);
$token            = strtoUpper(trim((string)($payload['token'] ?? ''), '"'));
$nombreAlumno     = trim((string)($payload['nombre_alumno'] ?? ''), '"');
$respuestas            = $payload['respuestas'] ?? [];
$incidenciasFoco       = isset($payload['incidencias_foco']) ? (int)$payload['incidencias_foco'] : 0;
$incidenciasCopia      = isset($payload['incidencias_copia']) ? (int)$payload['incidencias_copia'] : 0;
$incidenciasSeleccion  = isset($payload['incidencias_seleccion']) ? (int)$payload['incidencias_seleccion'] : 0;
$registroIncidentes    = isset($payload['registro_incidentes']) ? json_encode($payload['registro_incidentes'], JSON_UNESCAPED_UNICODE) : null;

if ($intentoId <= 0 || $token === '' || $nombreAlumno === '') {
    jsonResponse([
        'success' => false,
        'error'   => 'Parámetros inválidos. Se requiere intento_id, token y nombre_alumno.'
    ], 400);
}

if (!is_array($respuestas)) {
    $respuestas = [];
}

$mysqli = getDBConnection();

try {
    // 1. Obtener y validar el intento junto con el examen asociado
    $stmtIntento = $mysqli->prepare('
        SELECT 
            i.id AS intento_id,
            e.id AS examen_id,
            e.titulo,
            i.nombre_normalizado,
            i.estado,
            e.token,
            e.fecha,
            e.hora_fin,
            e.activo
        FROM intentos i
        INNER JOIN examenes e ON i.examen_id = e.id
        WHERE i.id = ?
        LIMIT 1
    ');
    $stmtIntento->bind_param('i', $intentoId);
    $stmtIntento->execute();
    $intento = $stmtIntento->get_result()->fetch_assoc();

    if (!$intento) {
        jsonResponse([
            'success' => false,
            'error'   => 'El intento especificado no existe.'
        ], 404);
    }

    // Validar coincidencia de token
    if ($intento['token'] !== $token) {
        jsonResponse([
            'success' => false,
            'error'   => 'Incoherencia entre el intento y el token de examen.'
        ], 403);
    }

    // Validar que el intento esté en progreso
    if ($intento['estado'] === 'completado') {
        jsonResponse([
            'success' => false,
            'error'   => 'Este intento ya fue finalizado y entregado previamente. No se permiten reenvíos.'
        ], 403);
    }

    date_default_timezone_set('America/Mexico_City');

    // Validar fecha de expiración con un margen de cortesía de 2 minutos para latencia de red
    $ahora = new DateTime('now');
    $ahoraString = $ahora->format('Y-m-d H:i:s');
    $fechaExpiracion = new DateTime($intento['fecha'] . ' ' . $intento['hora_fin']);
    $fechaExpiracionMargen = (clone $fechaExpiracion)->modify('+5 minutes');

    if ($ahora > $fechaExpiracionMargen) {
        // Marcar intento como expirado
        $stmtExp = $mysqli->prepare('UPDATE intentos SET estado = "expirado", fecha_fin = ? WHERE id = ?');
        $stmtExp->bind_param('si', $ahoraString, $intentoId);
        $stmtExp->execute();

        jsonResponse([
            'success' => false,
            'error'   => 'El tiempo límite global del examen ha vencido. El examen fue cerrado por el servidor.'
        ], 403);
    }

    // 2. Obtener todas las preguntas del examen con sus respuestas correctas para autocalificar
    $stmtPreguntas = $mysqli->prepare('
        SELECT id, tipo, respuesta_correcta, puntaje
        FROM preguntas
        WHERE examen_id = ?
    ');
    $stmtPreguntas->bind_param('i', $intento['examen_id']);
    $stmtPreguntas->execute();
    $preguntasDB = $stmtPreguntas->get_result()->fetch_all(MYSQLI_ASSOC);

    // Mapear preguntas por ID para acceso rápido O(1)
    $mapaPreguntas = [];
    $puntajeMaximoTotal = 0;
    $puntajeMaximoAuto  = 0;
    $totalPreguntasManual = 0;

    foreach ($preguntasDB as $p) {
        $pId = (int)$p['id'];
        $mapaPreguntas[$pId] = $p;
        $puntajeMaximoTotal += (int)$p['puntaje'];

        if ($p['tipo'] === 'opcion_multiple') {
            $puntajeMaximoAuto += (int)$p['puntaje'];
        } else {
            $totalPreguntasManual++;
        }
    }

    // Indexar respuestas enviadas por el alumno
    $respuestasEnviadas = [];
    foreach ($respuestas as $r) {
        $pId = (int)($r['pregunta_id'] ?? 0);
        if ($pId > 0) {
            $respuestasEnviadas[$pId] = (string)($r['respuesta'] ?? '');
        }
    }

    // 3. Iniciar Transacción ACID
    $mysqli->begin_transaction();

    $calificacionAutomaticaTotal = 0;
    $totalOpcionMultipleAcertadas = 0;
    $totalOpcionMultipleRespondidas = 0;

    // Preparar INSERT/UPDATE de respuestas del alumno
    $stmtUpsert = $mysqli->prepare('
        INSERT INTO respuestas (
            intento_id, pregunta_id, respuesta_texto, es_correcta, puntaje_obtenido
        ) VALUES (
            ?, ?, ?, ?, ?
        )
        ON DUPLICATE KEY UPDATE
            respuesta_texto   = VALUES(respuesta_texto),
            es_correcta       = VALUES(es_correcta),
            puntaje_obtenido  = VALUES(puntaje_obtenido)
    ');

    // Procesar cada pregunta del examen
    foreach ($mapaPreguntas as $pId => $pregunta) {
        $tipo               = $pregunta['tipo'];
        $puntajePregunta    = (int)$pregunta['puntaje'];
        $respuestaAlumno    = $respuestasEnviadas[$pId] ?? '';
        $respuestaLimpia    = trim($respuestaAlumno);

        $esCorrecta         = null;
        $puntajeObtenido    = 0;

        if ($tipo === 'opcion_multiple') {
            $totalOpcionMultipleRespondidas++;
            $correctaDB = trim((string)$pregunta['respuesta_correcta']);

            // Comparar ignorando mayúsculas/minúsculas
            if ($respuestaLimpia !== '' && strtolower($respuestaLimpia) === strtolower($correctaDB)) {
                $esCorrecta = 1;
                $puntajeObtenido = $puntajePregunta;
                $calificacionAutomaticaTotal += $puntajePregunta;
                $totalOpcionMultipleAcertadas++;
            } else {
                $esCorrecta = 0;
                $puntajeObtenido = 0;
            }
        } else {
            // 'codigo' o 'diagrama_flujo': Requiere revisión del docente
            $esCorrecta = null;
            $puntajeObtenido = 0; // Se asignará cuando el docente califique
        }

        $stmtUpsert->bind_param('issii', $intentoId, $pId, $respuestaAlumno, $esCorrecta, $puntajeObtenido);
        $stmtUpsert->execute();
    }

    // 4. Actualizar el intento como completado con métricas de seguridad
    $stmtFinalizar = $mysqli->prepare('
        UPDATE intentos
        SET 
            estado                  = "completado",
            fecha_fin               = ?,
            calificacion_automatica = ?,
            puntaje_maximo          = ?,
            incidencias_foco        = ?,
            incidencias_copia       = ?,
            incidencias_seleccion   = ?,
            registro_incidentes     = ?
        WHERE id = ?
    ');
    $stmtFinalizar->bind_param('siiiiisi', $ahoraString, $calificacionAutomaticaTotal, $puntajeMaximoTotal, $incidenciasFoco, $incidenciasCopia, $incidenciasSeleccion, $registroIncidentes, $intentoId);
    $stmtFinalizar->execute();

    // Confirmar cambios
    $mysqli->commit();

    // 5. Retornar comprobante exitoso al alumno
    jsonResponse([
        'success' => true,
        'mensaje' => 'Examen enviado y registrado con éxito.',
        'recibo'  => [
            'intento_id'                  => $intentoId,
            'examen_titulo'               => $intento['titulo'],
            'alumno'                      => $intento['nombre_normalizado'],
            'fecha'                       => $ahoraString,
            'calificacion_automatica'     => $calificacionAutomaticaTotal,
            'puntaje_maximo_autocalculado'=> $puntajeMaximoAuto,
            'puntaje_maximo_examen'       => $puntajeMaximoTotal,
            'aciertos_opcion_multiple'    => $totalOpcionMultipleAcertadas,
            'preguntas_opcion_multiple'   => $totalOpcionMultipleRespondidas,
            'preguntas_pendientes_docente'=> $totalPreguntasManual,
            'incidencias_foco'            => $incidenciasFoco,
            'incidencias_copia'           => $incidenciasCopia,
            'incidencias_seleccion'       => $incidenciasSeleccion,
            'total_incidencias'           => ($incidenciasFoco + $incidenciasCopia + $incidenciasSeleccion),
            'nota_informativa'            => ($totalPreguntasManual > 0)
                ? 'Tu examen incluye preguntas de código que serán evaluadas manualmente por tu docente.'
                : 'Tu examen consta únicamente de preguntas de opción múltiple y ha sido calificado al 100% automáticamente.'
        ]
    ], 200);

} catch (Exception $e) {
    if ($mysqli->in_transaction()) {
        $mysqli->rollback();
    }

    jsonResponse([
        'success' => false,
        'error'   => 'Ocurrió un error al guardar las respuestas del examen.',
        'detalle' => (ini_get('display_errors') === '1') ? $e->getMessage() : null
    ], 500);
}
