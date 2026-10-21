<?php
/**
 * ========================================================
 * API: VALIDAR ACCESO DEL ALUMNO A UN EXAMEN
 * ========================================================
 * Ruta sugerida: /api/validar_acceso.php
 * Método: POST
 * 
 * Recibe:
 * {
 *   "token": "PARCIAL-PROG-2026",
 *   "nombre_alumno": "Alex Macías Morales"
 * }
 * 
 * Reglas de Negocio Validadas:
 * 1. Campos obligatorios no vacíos.
 * 2. Existencia del examen por su token único y estado activo.
 * 3. Fecha de expiración: el examen no debe haber caducado (fecha_expiracion > NOW()).
 * 4. Único intento estricto: Si ya existe un intento registrado para ese (examen_id, nombre_normalizado),
 *    se bloquea el acceso con mensaje de error explicativo.
 * 5. Si todo es válido: Registra el intento en estado 'en_progreso' y entrega las preguntas
 *    SIN exponer las respuestas correctas al cliente.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/Errores.php';



use config\Errores;

$debug = false;
Errores::inicializar($debug);

// jsonResponse([
//     'success' => false,
//     'error'   => 'Antes del try todo bien.',
//     'detalle' => (ini_get('display_errors') === '1') ? $e->getMessage() : null
// ], 500);


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

// Obtener payload JSON o POST tradicional
$rawInput = file_get_contents('php://input');
$payload  = json_decode($rawInput, true);

if (!is_array($payload)) {
    $payload = $_POST;
}

$token        = strtoupper(trim((string)($payload['token'] ?? '')));
$nombreAlumno = trim((string)($payload['nombre_alumno'] ?? ''));

// 1. Validar campos obligatorios
if ($token === '' || $nombreAlumno === '') {
    jsonResponse([
        'success' => false,
        'error'   => 'El token del examen y el nombre completo del alumno son obligatorios.'
    ], 400);
}

if (mb_strlen($nombreAlumno, 'UTF-8') < 3) {
    jsonResponse([
        'success' => false,
        'error'   => 'Por favor ingresa tu nombre y apellido completo (mínimo 10 caracteres).'
    ], 400);
}

$nombreNormalizado = normalizarNombre($nombreAlumno);

$mysqli = getDBConnection();



try {
    $examen = null;
    $respuesta = null;
    // Comprobar si existe json, sino para crearlo
    if (!file_exists($token . '.json')) {
        // 2. Buscar el examen por token
        $stmtExamen = $mysqli->prepare('
            SELECT id, token, titulo, descripcion, fecha, hora_inicio, hora_fin, duracion_minutos, activo
            FROM examenes
            WHERE token = ?
            LIMIT 1
            ');
        $stmtExamen->bind_param('s', $token);
        $stmtExamen->execute();
        $examen = $stmtExamen->get_result()->fetch_assoc();
    } else {
        $respuesta = json_decode(file_get_contents($token . '.json'), true);
        $examen = $respuesta['examen'];
    }

    if (!$examen) {
        jsonResponse([
            'success' => false,
            'error'   => 'Token de examen inválido o no encontrado. Verifica con tu docente.'
        ], 404);
    }

    // jsonResponse(["success" => false, "examen" => $examen], 500);

    
    // if ((int)$examen['activo'] !== 1) {
    //     jsonResponse([
    //         'success' => false,
    //         'error'   => 'Este examen se encuentra actualmente desactivado por el profesor.'
    //         ], 403);
    //         }

    date_default_timezone_set('America/Mexico_City');
    
    // 3. Validar fecha y hora permitida
    $ahora = new DateTime('now');
    $ahoraString = $ahora->format('Y-m-d H:i:s');
    
    // Formato esperado de la DB: 'YYYY-MM-DD' para fecha y 'HH:MM:SS' para hora
    $inicioPermitido = new DateTime($examen['fecha'] . ' ' . $examen['hora_inicio']);
    $finPermitido    = new DateTime($examen['fecha'] . ' ' . $examen['hora_fin']);
    
    // Comprobar si aún no inicia el horario
    if ($ahora < $inicioPermitido) {
        jsonResponse([
            'success' => false,
            'error'   => sprintf('El examen aún no está disponible. Podrás ingresar a partir del %s.', $inicioPermitido->format('d/m/Y \a \l\a\s g:i a')),
            'fuera_de_horario' => true
            ], 403);
            }
            
    // Comprobar si ya expiró el horario
    if ($ahora > $finPermitido) {
        jsonResponse([
            'success' => false,
            'error'   => sprintf('El plazo para presentar este examen venció el %s.', $finPermitido->format('d/m/Y \a \l\a\s g:i a')),
            'expirado' => true,
            'fecha_limite' => $finPermitido->format('Y-m-d H:i:s')
            ], 403);
            }

    // 4. Validar Intento Único Estricto (1 intento por nombre y examen)
    $stmtIntento = $mysqli->prepare('
        SELECT id, estado, fecha_inicio, fecha_fin, calificacion_automatica
        FROM intentos
        WHERE examen_id = ? AND nombre_normalizado = ?
        LIMIT 1
    ');
    $stmtIntento->bind_param('is', $examen['id'], $nombreNormalizado);
    $stmtIntento->execute();
    $intentoExistente = $stmtIntento->get_result()->fetch_assoc();


    if ($intentoExistente) {
        if ($intentoExistente['estado'] === 'completado') {
            jsonResponse([
                'success' => false,
                'error'   => sprintf(
                    'Acceso denegado: El alumno "%s" ya ha presentado y finalizado este examen el %s. La política del examen permite un único intento estricto.',
                    htmlspecialchars($nombreAlumno, ENT_QUOTES, 'UTF-8'),
                    date('d/m/Y H:i', strtotime($intentoExistente['fecha_fin'] ?? $intentoExistente['fecha_inicio']))
                ),
                'ya_presentado' => true,
                'intento_id'    => (int)$intentoExistente['id']
            ], 403);
        } else {
            // Intento en progreso (se le permite continuar su sesión actual si recargó)
            $intentoId = (int)$intentoExistente['id'];
        }
    } else {
        // Crear nuevo intento en estado 'en_progreso'
        $ipOrigen = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $stmtNuevoIntento = $mysqli->prepare('
            INSERT INTO intentos (
                examen_id, nombre_normalizado, ip_origen, fecha_inicio, estado
            ) VALUES (
                ?, ?, ?, ?, "en_progreso"
            )
        ');
        $stmtNuevoIntento->bind_param('isss', $examen['id'], $nombreNormalizado, $ipOrigen, $ahoraString);
        $stmtNuevoIntento->execute();
        $intentoId = (int)$mysqli->insert_id;
    }

    // 5. Cargar las preguntas asociadas al examen
    // IMPORTANTE: NO seleccionamos la columna 'respuesta_correcta' para evitar trampas en el frontend
    if (!file_exists($token . '.json')) {
        $stmtPreguntas = $mysqli->prepare('
            SELECT 
                id,
                orden,
                enunciado,
                tipo,
                opciones,
                codigo_lenguaje,
                codigo_plantilla,
                puntaje
            FROM preguntas
            WHERE examen_id = ?
            ORDER BY orden ASC, id ASC
        ');
        $stmtPreguntas->bind_param('i', $examen['id']);
        $stmtPreguntas->execute();
        $preguntasRaw = $stmtPreguntas->get_result()->fetch_all(MYSQLI_ASSOC);

        $preguntas = [];
        $puntajeTotalExamen = 0;

        foreach ($preguntasRaw as $p) {
            $opcionesDecodificadas = null;
            if (!empty($p['opciones'])) {
                $opcionesDecodificadas = json_decode($p['opciones'], true);
            }

            $puntaje = $p['puntaje'];
            $puntajeTotalExamen += $puntaje;

            $preguntas[] = [
                'id'               => (int)$p['id'],
                'orden'            => (int)$p['orden'],
                'enunciado'        => $p['enunciado'],
                'tipo'             => $p['tipo'], // 'opcion_multiple' | 'codigo' | 'diagrama_flujo'
                'opciones'         => $opcionesDecodificadas,
                'codigo_lenguaje'  => $p['codigo_lenguaje'],
                'codigo_plantilla' => $p['codigo_plantilla'],
                'puntaje'          => $puntaje,
            ];
        }

        $respuesta = [
            'examen' => [
                'id'               => (int)$examen['id'],
                'token'            => $examen['token'],
                'titulo'           => $examen['titulo'],
                'descripcion'      => $examen['descripcion'],
                'fecha'            => $examen['fecha'],
                'hora_inicio'      => $examen['hora_inicio'],
                'hora_fin'         => $examen['hora_fin'],
                'duracion_minutos' => $examen['duracion_minutos'] ? (int)$examen['duracion_minutos'] : null,
                'total_preguntas'  => count($preguntas),
                'puntaje_total'    => $puntajeTotalExamen,
            ],
            'preguntas' => $preguntas
        ];

        file_put_contents($token . '.json', json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

        // 6. Retornar los datos del examen y preguntas listas para Vue 3 (con respuesta correcta)
    $rdata = [
        'success' => true,
        'mensaje' => 'Acceso autorizado al examen.',
        'intento' => [
            'id'            => $intentoId,
            'nombre_alumno' => $nombreNormalizado,
            'fecha_inicio'  => date('Y-m-d H:i:s'),
        ],
    ];

    $resp = array_merge($rdata, $respuesta);

    // 6. Retornar los datos del examen y preguntas listas para Vue 3
    jsonResponse($resp, 200);
    // } else {
    //     jsonResponse($resp, 200);
    // }

} catch (Exception $e) {
    // Throw new Exception($e->getMessage());
    jsonResponse([
        'success' => false,
        'error'   => 'Ocurrió un error inesperado al validar el acceso al examen.',
        // 'detalle' => (ini_get('display_errors') === '1') ? $e->getMessage() : null
        'detalle' => $e->getMessage()

    ], 500);
}
