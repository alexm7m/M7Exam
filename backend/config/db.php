<?php
/**
 * ========================================================
 * ARCHIVO DE CONEXIÓN PDO A BASE DE DATOS (MySQL / MariaDB)
 * ========================================================
 * Diseñado para Hosting Compartido (cPanel / Apache / Nginx / XAMPP)
 * Ruta sugerida: /config/db.php
 */

declare(strict_types=1);

// Evitar salida de errores PHP en pantalla en producción para no romper respuestas JSON
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Configuración de credenciales de la Base de Datos
// (Ajusta estos valores según los datos creados en tu cPanel -> MySQL Databases)
define('DB_HOST', 'database');
define('DB_NAME', 'mydatabase');
define('DB_USER', 'myuser');
define('DB_PASS', 'myuserpwd');
define('DB_PORT', 3306);
define('DB_CHARSET', 'utf8mb4');

/**
 * Obtiene la conexión singleton mediante mysqli
 * @return mysqli
 */
function getDBConnection(): mysqli
{
    static $mysqli = null;

    if ($mysqli === null) {
        // Reportar errores lanzando excepciones (equivalente a PDO::ERRMODE_EXCEPTION)
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        try {
            // Inicializar la instancia de MySQLi
            $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

            // Configurar el conjunto de caracteres por defecto
            $mysqli->set_charset(DB_CHARSET);

            // Asegurar zona horaria si es necesario
            // $mysqli->query("SET time_zone = '-06:00';");

        } catch (mysqli_sql_exception $e) {
            // En producción jamás expongas detalles sensibles en respuestas públicas
            jsonResponse([
                'success' => false,
                'error'   => 'Error crítico de conexión a la base de datos.',
                'detalle' => (ini_get('display_errors') === '1') ? $e->getMessage() : null
            ], 500);
            exit;
        }
    }

    return $mysqli;
}

/**
 * Envia una respuesta JSON estandarizada con cabeceras CORS
 * @param array $data Datos a serializar en JSON
 * @param int $statusCode Código HTTP (200, 400, 403, 404, 500)
 */
function jsonResponse(array $data, int $statusCode = 200): void
{
    // Limpiar cualquier buffer previo que pudiera corromper el JSON
    if (ob_get_level() > 0) {
        ob_clean();
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    // Cabeceras para permitir peticiones AJAX desde cualquier origen o el mismo dominio
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

    // Responder de inmediato a peticiones preflight OPTIONS
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        exit(0);
    }

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Normaliza el nombre del alumno para comparar de forma estricta (1 intento único)
 * Convierte a minúsculas, elimina tildes/espacios múltiples para evitar que "Juan Perez"
 * y "JUAN  PEREZ" burlen la validación de un solo intento.
 * @param string $nombre
 * @return string
 */
function normalizarNombre(string $nombre): string
{
    $nombre = trim($nombre);
    // Eliminar espacios dobles o triples
    $nombre = preg_replace('/\s+/', ' ', $nombre);
    // Convertir a minúsculas UTF-8
    return mb_strtoupper($nombre, 'UTF-8');
}
