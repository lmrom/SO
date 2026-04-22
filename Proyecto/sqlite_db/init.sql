PRAGMA foreign_keys = ON;

-- Esquema principal
CREATE TABLE IF NOT EXISTS Personas (
    id_persona     INTEGER PRIMARY KEY AUTOINCREMENT,
    identificador  VARCHAR(32)  NOT NULL UNIQUE,   -- matrícula / nómina / ID externo
    nombre         VARCHAR(80)  NOT NULL,
    apellido       VARCHAR(80)  NOT NULL,
    tipo_persona   VARCHAR(10)  NOT NULL CHECK (tipo_persona IN ('ALUMNO','PROFESOR','PERSONAL')),
    foto_url       VARCHAR(255),
    activo         INTEGER NOT NULL DEFAULT 1 CHECK (activo IN (0,1)),
    creado_en      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS Puertas (
    id_puerta  INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre     VARCHAR(80)  NOT NULL,
    ubicacion  VARCHAR(120) NOT NULL,
    activo     INTEGER NOT NULL DEFAULT 1 CHECK (activo IN (0,1)),
    UNIQUE(nombre, ubicacion)
);

CREATE TABLE IF NOT EXISTS Credenciales (
    id_credencial INTEGER PRIMARY KEY AUTOINCREMENT,
    id_persona    INTEGER NOT NULL REFERENCES Personas(id_persona) ON DELETE CASCADE,
    uid           VARCHAR(32)    NOT NULL UNIQUE,  -- UID de la tarjeta RFID
    activa        INTEGER NOT NULL DEFAULT 1 CHECK (activa IN (0,1)),
    creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS Accesos (
    id_registro  INTEGER PRIMARY KEY AUTOINCREMENT,
    id_persona   INTEGER NOT NULL REFERENCES Personas(id_persona),
    id_puerta    INTEGER NOT NULL REFERENCES Puertas(id_puerta),
    id_credencial INTEGER REFERENCES Credenciales(id_credencial),
    tipo         VARCHAR(10)    NOT NULL CHECK (tipo IN ('ENTRADA','SALIDA')),
    fecha_hora   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    autorizado   INTEGER NOT NULL CHECK (autorizado IN (0,1)),
    revision_estado VARCHAR(16) NOT NULL DEFAULT 'NO_REQUERIDA'
        CHECK (revision_estado IN ('NO_REQUERIDA','PENDIENTE','APROBADA','DENEGADA')),
    revision_comentario VARCHAR(255),
    revisado_por  VARCHAR(80),
    fecha_revision DATETIME,
    observacion  VARCHAR(512)
);

-- Índices útiles
CREATE INDEX IF NOT EXISTS idx_accesos_persona_fecha ON Accesos(id_persona, fecha_hora DESC);
CREATE INDEX IF NOT EXISTS idx_accesos_puerta_fecha  ON Accesos(id_puerta, fecha_hora DESC);
CREATE INDEX IF NOT EXISTS idx_credenciales_persona  ON Credenciales(id_persona);
CREATE INDEX IF NOT EXISTS idx_accesos_revision       ON Accesos(revision_estado, fecha_hora DESC);

-- Vistas
CREATE VIEW IF NOT EXISTS vw_accesos_detalle AS
SELECT
    a.id_registro,
    a.fecha_hora,
    a.tipo,
    a.autorizado,
    a.revision_estado,
    a.revision_comentario,
    a.revisado_por,
    a.fecha_revision,
    a.observacion,
    p.id_persona,
    p.identificador,
    p.nombre,
    p.apellido,
    p.tipo_persona,
    p.foto_url AS foto_referencia,
    pr.id_puerta,
    pr.nombre  AS puerta,
    pr.ubicacion,
    c.uid
FROM Accesos a
JOIN Personas p   ON a.id_persona = p.id_persona
JOIN Puertas  pr  ON a.id_puerta   = pr.id_puerta
LEFT JOIN Credenciales c ON a.id_credencial = c.id_credencial;

CREATE VIEW IF NOT EXISTS vw_ultimo_acceso_por_persona AS
SELECT p.id_persona,
       p.nombre,
       p.apellido,
       MAX(a.fecha_hora) AS ultimo_acceso,
       SUM(a.autorizado = 0) AS rechazos
FROM Personas p
LEFT JOIN Accesos a ON a.id_persona = p.id_persona
GROUP BY p.id_persona, p.nombre, p.apellido;

-- Triggers

-- Al activar una credencial nueva, desactiva las demás de la misma persona.
CREATE TRIGGER IF NOT EXISTS trg_credencial_unica_activa
AFTER INSERT ON Credenciales
WHEN NEW.activa = 1
BEGIN
    UPDATE Credenciales
    SET activa = 0
    WHERE id_persona = NEW.id_persona AND id_credencial != NEW.id_credencial;
END;

CREATE TRIGGER IF NOT EXISTS trg_no_accesos_inactivos
BEFORE INSERT ON Accesos
BEGIN
    SELECT
    CASE
        WHEN (SELECT activo FROM Personas WHERE id_persona = NEW.id_persona) = 0
            THEN RAISE(ABORT, 'Persona inactiva')
        WHEN NEW.id_credencial IS NOT NULL
             AND (SELECT activa FROM Credenciales WHERE id_credencial = NEW.id_credencial) = 0
            THEN RAISE(ABORT, 'Credencial inactiva')
    END;
END;

-- Valida que la credencial pertenezca a la misma persona cuando se envía.
CREATE TRIGGER IF NOT EXISTS trg_credencial_corresponde_persona
BEFORE INSERT ON Accesos
WHEN NEW.id_credencial IS NOT NULL
BEGIN
    SELECT CASE
        WHEN (SELECT id_persona FROM Credenciales WHERE id_credencial = NEW.id_credencial) != NEW.id_persona
            THEN RAISE(ABORT, 'La credencial no corresponde a la persona')
    END;
END;

-- En SQLite no existen stored procedures; este archivo usa triggers y vistas para la lógica embebida.
