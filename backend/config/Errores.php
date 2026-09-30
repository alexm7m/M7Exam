<?php
namespace config;

require_once __DIR__ . '/db.php';

class Errores {
   private static bool $modoDebug = false;

   public static function inicializar(bool $debug = false) {
      self::$modoDebug = $debug;

      set_exception_handler(function($exception) {
         error_log($exception); // Registrar en el log
         $mensaje = self::$modoDebug
               ? 'Excepción: ' . basename($exception->getFile()) . ':' . $exception->getLine() . ' - ' . $exception->getMessage()
               : 'Error interno del servidor.';
         jsonResponse([
            'success' => false,
            'error'   => $mensaje
         ], 500);
      });

      set_error_handler(function($errno, $errstr, $errfile, $errline) {
         error_log("[$errno] $errstr en $errfile:$errline");
         $mensaje = self::$modoDebug
               ? "Error [$errno]: $errstr en $errfile:$errline"
               : "Error interno del servidor.";
         jsonResponse([
            'success' => false,
            'error'   => $mensaje
         ], 500);
      });
   }
}

Errores::inicializar(true);

?>