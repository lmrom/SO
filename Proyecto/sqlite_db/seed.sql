-- Datos de ejemplo mínimos para pruebas locales
INSERT INTO Personas (id_persona, identificador, nombre, apellido, tipo_persona, foto_url, activo)
VALUES
    (0, 'A001', 'Ana',   'Lopez',   'ALUMNO',   NULL, 1),
    (1, 'P010', 'Pedro', 'Santos',  'PROFESOR', NULL, 1),
    (2, 'S100', 'Sara',  'Mendez',  'PERSONAL', NULL, 1);

INSERT INTO Puertas (nombre, ubicacion) VALUES
    ('Laboratorio', 'Edificio A - Piso 1'),
    ('Biblioteca',  'Edificio B - Planta Baja');

INSERT INTO Credenciales (id_persona, uid, activa) VALUES
    (0, 'UID-ANA-001', 1),
    (1, 'UID-PED-010', 1),
    (2, 'UID-SAR-100', 1);

INSERT INTO Accesos (id_persona, id_puerta, id_credencial, tipo, autorizado, observacion)
VALUES
    (0, 1, 1, 'ENTRADA', 1, 'Prueba OK'),
    (1, 2, 2, 'ENTRADA', 0, 'Tarjeta expirada'),
    (2, 1, 3, 'SALIDA',  1, 'Fin de turno');
