-- -- Creación de la Base de Datos y Selección
CREATE DATABASE IF NOT EXISTS `mydatabase`
  CHARACTER SET utf8mb4 
  COLLATE utf8mb4_unicode_ci;

USE `mydatabase`;

-- --------------------------------------------------------
-- 1. Tabla: examenes (Mapea ExamenDB)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `examenes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `token` VARCHAR(100) NOT NULL UNIQUE,
  `titulo` VARCHAR(255) NOT NULL,
  `descripcion` TEXT NULL,
  `fecha` DATE NOT NULL,
  `hora_inicio` TIME NULL DEFAULT NULL,
  `hora_fin` TIME NULL DEFAULT NULL,
  `duracion_minutos` INT NULL DEFAULT NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `creado_en` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_token_activo` (`token`, `activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 2. Tabla: preguntas (Mapea PreguntaDB)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `preguntas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `examen_id` INT NOT NULL,
  `orden` INT NOT NULL DEFAULT 1,
  `enunciado` TEXT NOT NULL,
  `tipo` ENUM('opcion_multiple', 'codigo') NOT NULL,
  `opciones` JSON NULL COMMENT 'Lista de objetos: [{"id": "a", "texto": "..."}, ...]',
  `respuesta_correcta` VARCHAR(100) NULL COMMENT 'ID de la opción correcta (ej: a, b, c)',
  `codigo_lenguaje` VARCHAR(50) NULL DEFAULT NULL,
  `codigo_plantilla` TEXT NULL,
  `puntaje` INT NOT NULL DEFAULT 5,
  `creado_en` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`examen_id`) REFERENCES `examenes`(`id`) ON DELETE CASCADE,
  INDEX `idx_examen_orden` (`examen_id`, `orden`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 3. Tabla: intentos (Mapea IntentoDB)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `intentos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `examen_id` INT NOT NULL,
  `nombre_normalizado` VARCHAR(255) NOT NULL,
  `ip_origen` VARCHAR(45) NOT NULL DEFAULT '0.0.0.0',
  `fecha_inicio` DATETIME NOT NULL,
  `fecha_fin` DATETIME NULL DEFAULT NULL,
  `estado` ENUM('en_progreso', 'completado', 'expirado') NOT NULL DEFAULT 'en_progreso',
  `calificacion_automatica` INT NOT NULL DEFAULT 0,
  `puntaje_maximo` INT NOT NULL DEFAULT 0,
  `incidencias_foco` INT NOT NULL DEFAULT 0,
  `incidencias_copia` INT NOT NULL DEFAULT 0,
  `incidencias_seleccion` INT NOT NULL DEFAULT 0,
  `registro_incidentes` JSON NULL COMMENT 'Bitácora cronológica: [{"id":"...", "timestamp":"...", "tipo":"...", "detalle":"..."}]',
  `creado_en` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`examen_id`) REFERENCES `examenes`(`id`) ON DELETE CASCADE,
  INDEX `idx_intento_alumno` (`examen_id`, `nombre_normalizado`),
  INDEX `idx_estado` (`estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 4. Tabla: respuestas (Mapea RespuestaAlumnoDB)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `respuestas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `intento_id` INT NOT NULL,
  `pregunta_id` INT NOT NULL,
  `respuesta_texto` TEXT NOT NULL,
  `es_correcta` TINYINT(1) NULL DEFAULT NULL COMMENT '1=Correcta, 0=Incorrecta, NULL=Pendiente revisión docente',
  `puntaje_obtenido` INT NOT NULL DEFAULT 0,
  `guardado_en` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`intento_id`) REFERENCES `intentos`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`pregunta_id`) REFERENCES `preguntas`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `uniq_intento_pregunta` (`intento_id`, `pregunta_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
