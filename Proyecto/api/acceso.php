<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

const DB_PATH = '/var/www/html/audit.db';
const API_TOKEN = '';
const IDENTIFICADOR_SISTEMA = 'SYS-DENEGADO';
const WEB_ROOT = '/var/www/html';
const REVISION_MODE_FILE = WEB_ROOT . '/revision_mode.json';
const FACE_VERIFY_REMOTE_URL = 'http://10.76.102.19:5050/verify'; 
const FACE_VERIFY_REMOTE_TOKEN = ''; // opcional, mismo valor que en el server de laptop
const FACE_VERIFY_TIMEOUT_SEG = 8;

function responder(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function normalizarUid(string $uid): string {
    return preg_replace('/\s+/', '', trim($uid)) ?? '';
}

function extraerUidDesdeObservacion(string $observacion): string {
    if (preg_match('/UID:\s*([^\s|]+)/i', $observacion, $m) === 1) {
        return strtoupper(trim($m[1]));
    }
    return '';
}

function extraerFotoDesdeObservacion(string $observacion): ?string {
    if (preg_match('/Foto:\s*([^\s|]+)/iu', $observacion, $m) !== 1) {
        return null;
    }
    $ruta = trim($m[1]);
    if ($ruta === '' || str_contains($ruta, '..')) {
        return null;
    }
    if (!str_starts_with($ruta, 'fotos/')) {
        return null;
    }
    return '/' . ltrim($ruta, '/');
}

function normalizarRutaPublica(?string $ruta): ?string {
    if ($ruta === null) {
        return null;
    }
    $ruta = trim($ruta);
    if ($ruta === '' || str_contains($ruta, '..')) {
        return null;
    }
    if (str_starts_with($ruta, 'http://') || str_starts_with($ruta, 'https://')) {
        return $ruta;
    }
    if (str_starts_with($ruta, '/')) {
        return $ruta;
    }
    return '/' . ltrim($ruta, '/');
}

function quitarFotoEnObservacion(string $observacion): string {
    return preg_replace('/\s+\|\s+Foto:\s+.*$/u', '', $observacion) ?? $observacion;
}

function quitarRevisionEnObservacion(string $observacion): string {
    return preg_replace('/\s+\|\s+Revision:\s+.*$/u', '', $observacion) ?? $observacion;
}

function modoRevisionValido(string $modo): string {
    $modo = strtoupper(trim($modo));
    return in_array($modo, ['MANUAL', 'AUTO'], true) ? $modo : 'MANUAL';
}

function obtenerModoRevision(): string {
    if (!is_file(REVISION_MODE_FILE)) {
        return 'MANUAL';
    }
    $raw = file_get_contents(REVISION_MODE_FILE);
    if (!is_string($raw) || trim($raw) === '') {
        return 'MANUAL';
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return 'MANUAL';
    }
    return modoRevisionValido((string)($data['mode'] ?? 'MANUAL'));
}

function guardarModoRevision(string $modo): bool {
    $payload = json_encode([
        'mode' => modoRevisionValido($modo),
        'updated_at' => date('c')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    if (!is_string($payload)) {
        return false;
    }
    return file_put_contents(REVISION_MODE_FILE, $payload, LOCK_EX) !== false;
}

function resolverRutaLocal(?string $ruta): ?string {
    if ($ruta === null) {
        return null;
    }
    $ruta = trim($ruta);
    if ($ruta === '' || str_contains($ruta, '..')) {
        return null;
    }
    if (str_starts_with($ruta, 'http://') || str_starts_with($ruta, 'https://')) {
        return null;
    }
    if (!str_starts_with($ruta, '/')) {
        $ruta = '/' . $ruta;
    }
    return WEB_ROOT . $ruta;
}

function ejecutarComparacionFacial(string $fotoReferencia, string $fotoCapturada): array {
    $rutaRef = resolverRutaLocal($fotoReferencia);
    $rutaCap = resolverRutaLocal($fotoCapturada);

    if ($rutaRef === null || $rutaCap === null) {
        return ['ok' => false, 'motivo' => 'RUTA_FOTO_INVALIDA'];
    }
    if (!is_file($rutaRef)) {
        return ['ok' => false, 'motivo' => 'FOTO_REFERENCIA_NO_EXISTE'];
    }
    if (!is_file($rutaCap)) {
        return ['ok' => false, 'motivo' => 'FOTO_CAPTURADA_NO_EXISTE'];
    }
    if (FACE_VERIFY_REMOTE_URL === '') {
        return ['ok' => false, 'motivo' => 'FACE_VERIFY_REMOTE_URL_VACIA'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'motivo' => 'CURL_NO_DISPONIBLE'];
    }
    if (class_exists('CURLFile') === false) {
        return ['ok' => false, 'motivo' => 'CURLFILE_NO_DISPONIBLE'];
    }

    $payload = [
        'reference' => new CURLFile($rutaRef),
        'captured' => new CURLFile($rutaCap),
    ];
    if (FACE_VERIFY_REMOTE_TOKEN !== '') {
        $payload['token'] = FACE_VERIFY_REMOTE_TOKEN;
    }

    $ch = curl_init(FACE_VERIFY_REMOTE_URL);
    if ($ch === false) {
        return ['ok' => false, 'motivo' => 'NO_SE_PUDO_INICIAR_CURL'];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => FACE_VERIFY_TIMEOUT_SEG,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($errno !== 0 || !is_string($raw) || trim($raw) === '') {
        return ['ok' => false, 'motivo' => 'ERROR_CONEXION_FACE_REMOTO'];
    }
    if ($httpCode >= 400) {
        return ['ok' => false, 'motivo' => 'HTTP_FACE_REMOTO_' . $httpCode];
    }

    $json = json_decode($raw, true);
    if (!is_array($json) || !array_key_exists('ok', $json)) {
        return ['ok' => false, 'motivo' => 'RESPUESTA_FACE_ID_INVALIDA'];
    }
    if (!($json['ok'] ?? false)) {
        return ['ok' => false, 'motivo' => (string)($json['reason'] ?? 'FACE_ID_REMOTO_FALLO')];
    }

    $score = null;
    if (array_key_exists('score', $json)) {
        $score = (float)$json['score'];
    }
    $distance = isset($json['distance']) ? (float)$json['distance'] : null;
    if ($distance === null && $score !== null) {
        $distance = 1.0 - $score;
    }

    return [
        'ok' => true,
        'match' => (bool)($json['match'] ?? false),
        'distance' => $distance,
        'threshold' => isset($json['threshold']) ? (float)$json['threshold'] : null,
        'score' => $score,
    ];
}

function autoFaceDisponible(): bool {
    if (FACE_VERIFY_REMOTE_URL === '' || !function_exists('curl_init')) {
        return false;
    }
    $healthUrl = preg_replace('#/verify/?$#', '/health', FACE_VERIFY_REMOTE_URL);
    if (!is_string($healthUrl) || $healthUrl === '') {
        $healthUrl = FACE_VERIFY_REMOTE_URL;
    }
    $ch = curl_init($healthUrl);
    if ($ch === false) {
        return false;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($errno !== 0 || $httpCode >= 400 || !is_string($raw) || trim($raw) === '') {
        return false;
    }
    $json = json_decode($raw, true);
    return is_array($json) && (($json['ok'] ?? false) === true);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(405, [
        'ok' => false,
        'autorizado' => false,
        'registrado' => false,
        'motivo' => 'METODO_NO_PERMITIDO'
    ]);
}

$uid = strtoupper(normalizarUid((string)($_POST['uid'] ?? '')));
$idPuerta = (int)($_POST['id_puerta'] ?? 1);
$tipo = strtoupper(trim((string)($_POST['tipo'] ?? 'ENTRADA')));
$token = (string)($_POST['token'] ?? '');
$accion = strtoupper(trim((string)($_POST['accion'] ?? 'VALIDAR')));

if (API_TOKEN !== '' && !hash_equals(API_TOKEN, $token)) {
    responder(401, [
        'ok' => false,
        'autorizado' => false,
        'registrado' => false,
        'motivo' => 'TOKEN_INVALIDO'
    ]);
}

if ($accion === 'VALIDAR') {
    if ($uid === '') {
        responder(400, [
            'ok' => false,
            'autorizado' => false,
            'registrado' => false,
            'motivo' => 'UID_REQUERIDO'
        ]);
    }

    if (!in_array($tipo, ['ENTRADA', 'SALIDA'], true)) {
        responder(400, [
            'ok' => false,
            'autorizado' => false,
            'registrado' => false,
            'motivo' => 'TIPO_INVALIDO'
        ]);
    }
}

try {
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON;');

    if ($accion === 'OBTENER_MODO_REVISION') {
        $modo = obtenerModoRevision();
        $autoDisponible = autoFaceDisponible();
        if (!$autoDisponible && $modo === 'AUTO') {
            $modo = 'MANUAL';
            guardarModoRevision($modo);
        }
        responder(200, [
            'ok' => true,
            'modo_revision' => $modo,
            'auto_disponible' => $autoDisponible
        ]);
    }

    if ($accion === 'CAMBIAR_MODO_REVISION') {
        $autoDisponible = autoFaceDisponible();
        $modoNuevo = modoRevisionValido((string)($_POST['modo_revision'] ?? 'MANUAL'));
        if ($modoNuevo === 'AUTO' && !$autoDisponible) {
            responder(200, [
                'ok' => false,
                'motivo' => 'AUTO_FACE_ID_NO_DISPONIBLE',
                'modo_revision' => 'MANUAL',
                'auto_disponible' => false
            ]);
        }
        if (!guardarModoRevision($modoNuevo)) {
            responder(500, [
                'ok' => false,
                'motivo' => 'NO_SE_PUDO_GUARDAR_MODO'
            ]);
        }
        responder(200, [
            'ok' => true,
            'modo_revision' => $modoNuevo,
            'auto_disponible' => $autoDisponible
        ]);
    }

    if ($accion === 'ADJUNTAR_FOTO') {
        $idRegistro = (int)($_POST['id_registro'] ?? 0);
        $fotoUrl = trim((string)($_POST['foto_url'] ?? ''));
        $modoRevision = obtenerModoRevision();

        if ($idRegistro <= 0 || $fotoUrl === '') {
            responder(400, [
                'ok' => false,
                'autorizado' => false,
                'registrado' => false,
                'motivo' => 'PARAMETROS_FOTO_INVALIDOS'
            ]);
        }

        $stSel = $pdo->prepare('SELECT observacion FROM Accesos WHERE id_registro = :id_registro LIMIT 1');
        $stSel->execute([':id_registro' => $idRegistro]);
        $observacionActual = $stSel->fetchColumn();
        if ($observacionActual === false) {
            responder(404, [
                'ok' => false,
                'autorizado' => false,
                'registrado' => false,
                'motivo' => 'REGISTRO_NO_ENCONTRADO'
            ]);
        }

        $base = quitarFotoEnObservacion((string)$observacionActual);
        $observacionNueva = rtrim($base) . ' | Foto: ' . $fotoUrl;

        $stUpd = $pdo->prepare('UPDATE Accesos SET observacion = :observacion WHERE id_registro = :id_registro');
        $stUpd->execute([
            ':observacion' => $observacionNueva,
            ':id_registro' => $idRegistro
        ]);

        $autoRevision = [
            'intentada' => false,
            'resuelta' => false,
            'motivo' => 'MODO_MANUAL',
            'match' => null,
            'distance' => null,
            'threshold' => null
        ];

        if ($modoRevision === 'AUTO') {
            $autoRevision['intentada'] = true;
            $stAuto = $pdo->prepare(
                "SELECT
                    a.revision_estado,
                    a.observacion,
                    p.foto_url AS foto_referencia
                 FROM Accesos a
                 INNER JOIN Personas p ON p.id_persona = a.id_persona
                 WHERE a.id_registro = :id_registro
                 LIMIT 1"
            );
            $stAuto->execute([':id_registro' => $idRegistro]);
            $datoAuto = $stAuto->fetch(PDO::FETCH_ASSOC);

            if (!$datoAuto) {
                $autoRevision['motivo'] = 'REGISTRO_NO_ENCONTRADO';
            } else {
                $revisionEstadoActual = (string)($datoAuto['revision_estado'] ?? 'NO_REQUERIDA');
                $fotoReferencia = (string)($datoAuto['foto_referencia'] ?? '');
                $fotoCapturada = extraerFotoDesdeObservacion((string)($datoAuto['observacion'] ?? ''));

                if ($revisionEstadoActual !== 'PENDIENTE') {
                    $autoRevision['motivo'] = 'REGISTRO_NO_PENDIENTE';
                } elseif ($fotoReferencia === '' || $fotoCapturada === null) {
                    $autoRevision['motivo'] = 'FOTOS_INSUFICIENTES';
                } else {
                    $resultadoFace = ejecutarComparacionFacial($fotoReferencia, $fotoCapturada);
                    if (!($resultadoFace['ok'] ?? false)) {
                        $autoRevision['motivo'] = (string)($resultadoFace['motivo'] ?? 'FACE_ID_NO_DISPONIBLE');
                    } else {
                        $match = (bool)($resultadoFace['match'] ?? false);
                        $distance = $resultadoFace['distance'] ?? null;
                        $threshold = $resultadoFace['threshold'] ?? null;
                        $autoRevision['match'] = $match;
                        $autoRevision['distance'] = $distance;
                        $autoRevision['threshold'] = $threshold;
                        $autoRevision['resuelta'] = true;
                        $autoRevision['motivo'] = $match ? 'AUTO_FACE_MATCH' : 'AUTO_FACE_NO_MATCH';

                        $estadoFinal = $match ? 'APROBADA' : 'DENEGADA';
                        $autorizadoFinal = $match ? 1 : 0;
                        $comentario = $match
                            ? 'APROBADO automatico por Face ID'
                            : 'DENEGADO automatico por Face ID';

                        if ($distance !== null && $threshold !== null) {
                            $comentario .= sprintf(' (d=%.4f, t=%.4f)', (float)$distance, (float)$threshold);
                        }

                        $obsBaseAuto = quitarRevisionEnObservacion((string)($datoAuto['observacion'] ?? ''));
                        $obsNuevaAuto = rtrim($obsBaseAuto) . ' | Revision: ' . $comentario;

                        $stUpdAuto = $pdo->prepare(
                            "UPDATE Accesos
                             SET autorizado = :autorizado,
                                 revision_estado = :revision_estado,
                                 revision_comentario = :revision_comentario,
                                 revisado_por = 'AUTO_FACE_ID',
                                 fecha_revision = CURRENT_TIMESTAMP,
                                 observacion = :observacion
                             WHERE id_registro = :id_registro"
                        );
                        $stUpdAuto->execute([
                            ':autorizado' => $autorizadoFinal,
                            ':revision_estado' => $estadoFinal,
                            ':revision_comentario' => $comentario,
                            ':observacion' => $obsNuevaAuto,
                            ':id_registro' => $idRegistro
                        ]);
                    }
                }
            }
        }

        responder(200, [
            'ok' => true,
            'autorizado' => true,
            'registrado' => true,
            'motivo' => 'FOTO_ADJUNTADA',
            'id_registro' => $idRegistro,
            'foto_url' => normalizarRutaPublica($fotoUrl),
            'modo_revision' => $modoRevision,
            'auto_revision' => $autoRevision
        ]);
    }

    if ($accion === 'LISTAR_PENDIENTES') {
        $st = $pdo->prepare(
            "SELECT
                a.id_registro,
                a.fecha_hora,
                a.tipo,
                a.observacion,
                p.identificador,
                p.nombre,
                p.apellido,
                p.tipo_persona,
                p.foto_url,
                pr.nombre AS puerta,
                pr.ubicacion,
                c.uid
             FROM Accesos a
             INNER JOIN Personas p ON p.id_persona = a.id_persona
             INNER JOIN Puertas pr ON pr.id_puerta = a.id_puerta
             LEFT JOIN Credenciales c ON c.id_credencial = a.id_credencial
             WHERE a.revision_estado = 'PENDIENTE'
             ORDER BY a.id_registro ASC
             LIMIT 20"
        );
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $pendientes = [];
        foreach ($rows as $row) {
            $observacion = (string)($row['observacion'] ?? '');
            $uidFila = trim((string)($row['uid'] ?? ''));
            if ($uidFila === '') {
                $uidFila = extraerUidDesdeObservacion($observacion);
            }
            $pendientes[] = [
                'id_registro' => (int)$row['id_registro'],
                'fecha_hora' => (string)$row['fecha_hora'],
                'tipo' => (string)$row['tipo'],
                'uid' => strtoupper($uidFila),
                'identificador' => (string)$row['identificador'],
                'nombre' => trim((string)$row['nombre'] . ' ' . (string)$row['apellido']),
                'tipo_persona' => (string)$row['tipo_persona'],
                'puerta' => (string)$row['puerta'],
                'ubicacion' => (string)$row['ubicacion'],
                'foto_registrada' => normalizarRutaPublica((string)($row['foto_url'] ?? '')),
                'foto_capturada' => extraerFotoDesdeObservacion($observacion),
                'observacion' => $observacion,
            ];
        }

        responder(200, [
            'ok' => true,
            'pendientes' => $pendientes,
            'total' => count($pendientes),
            'modo_revision' => obtenerModoRevision(),
            'auto_disponible' => autoFaceDisponible()
        ]);
    }

    if ($accion === 'RESOLVER_REVISION') {
        $idRegistro = (int)($_POST['id_registro'] ?? 0);
        $decision = strtoupper(trim((string)($_POST['decision'] ?? '')));
        $revisor = trim((string)($_POST['revisor'] ?? 'OPERADOR'));
        if ($revisor === '') {
            $revisor = 'OPERADOR';
        }

        if ($idRegistro <= 0 || !in_array($decision, ['PERMITIR', 'DENEGAR'], true)) {
            responder(400, [
                'ok' => false,
                'motivo' => 'PARAMETROS_REVISION_INVALIDOS'
            ]);
        }

        $pdo->beginTransaction();

        $stSel = $pdo->prepare('SELECT revision_estado, autorizado, observacion FROM Accesos WHERE id_registro = :id_registro LIMIT 1');
        $stSel->execute([':id_registro' => $idRegistro]);
        $registro = $stSel->fetch(PDO::FETCH_ASSOC);

        if (!$registro) {
            $pdo->rollBack();
            responder(404, [
                'ok' => false,
                'motivo' => 'REGISTRO_NO_ENCONTRADO'
            ]);
        }

        $estadoActual = (string)$registro['revision_estado'];
        if ($estadoActual !== 'PENDIENTE') {
            $pdo->rollBack();
            responder(200, [
                'ok' => true,
                'motivo' => 'REVISION_YA_RESUELTA',
                'id_registro' => $idRegistro,
                'revision_estado' => $estadoActual,
                'autorizado' => ((int)$registro['autorizado'] === 1)
            ]);
        }

        $autorizadoFinal = $decision === 'PERMITIR' ? 1 : 0;
        $revisionEstado = $decision === 'PERMITIR' ? 'APROBADA' : 'DENEGADA';
        $comentarioRevision = $decision === 'PERMITIR'
            ? 'APROBADO despues de revision'
            : 'DENEGADO despues de revision';

        $observacionBase = quitarRevisionEnObservacion((string)($registro['observacion'] ?? ''));
        $observacionNueva = rtrim($observacionBase) . ' | Revision: ' . $comentarioRevision;

        $stUpd = $pdo->prepare(
            'UPDATE Accesos
             SET autorizado = :autorizado,
                 revision_estado = :revision_estado,
                 revision_comentario = :revision_comentario,
                 revisado_por = :revisado_por,
                 fecha_revision = CURRENT_TIMESTAMP,
                 observacion = :observacion
             WHERE id_registro = :id_registro'
        );
        $stUpd->execute([
            ':autorizado' => $autorizadoFinal,
            ':revision_estado' => $revisionEstado,
            ':revision_comentario' => $comentarioRevision,
            ':revisado_por' => $revisor,
            ':observacion' => $observacionNueva,
            ':id_registro' => $idRegistro,
        ]);

        $pdo->commit();

        responder(200, [
            'ok' => true,
            'motivo' => 'REVISION_RESUELTA',
            'id_registro' => $idRegistro,
            'revision_estado' => $revisionEstado,
            'autorizado' => $autorizadoFinal === 1,
            'comentario' => $comentarioRevision,
        ]);
    }

    if ($accion === 'ESTADO_REVISION') {
        $idRegistro = (int)($_POST['id_registro'] ?? 0);
        if ($idRegistro <= 0) {
            responder(400, [
                'ok' => false,
                'motivo' => 'ID_REGISTRO_INVALIDO'
            ]);
        }

        $st = $pdo->prepare(
            'SELECT id_registro, autorizado, revision_estado, revision_comentario, fecha_revision
             FROM Accesos WHERE id_registro = :id_registro LIMIT 1'
        );
        $st->execute([':id_registro' => $idRegistro]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            responder(404, [
                'ok' => false,
                'motivo' => 'REGISTRO_NO_ENCONTRADO'
            ]);
        }

        $revisionEstado = (string)$row['revision_estado'];
        $finalizada = in_array($revisionEstado, ['NO_REQUERIDA', 'APROBADA', 'DENEGADA'], true);

        responder(200, [
            'ok' => true,
            'id_registro' => (int)$row['id_registro'],
            'finalizada' => $finalizada,
            'autorizado_final' => ((int)$row['autorizado'] === 1),
            'revision_estado' => $revisionEstado,
            'motivo' => (string)($row['revision_comentario'] ?? ''),
            'fecha_revision' => (string)($row['fecha_revision'] ?? ''),
        ]);
    }

    // VALIDAR (default)
    $pdo->beginTransaction();

    $stPuerta = $pdo->prepare('SELECT id_puerta, activo FROM Puertas WHERE id_puerta = :id_puerta LIMIT 1');
    $stPuerta->execute([':id_puerta' => $idPuerta]);
    $puerta = $stPuerta->fetch(PDO::FETCH_ASSOC);

    if (!$puerta) {
        $pdo->rollBack();
        responder(400, [
            'ok' => false,
            'autorizado' => false,
            'registrado' => false,
            'motivo' => 'PUERTA_NO_EXISTE'
        ]);
    }

    $stSistemaIns = $pdo->prepare(
        "INSERT OR IGNORE INTO Personas (identificador, nombre, apellido, tipo_persona, activo)
         VALUES (:identificador, 'Sistema', 'Denegado', 'PERSONAL', 1)"
    );
    $stSistemaIns->execute([':identificador' => IDENTIFICADOR_SISTEMA]);

    $stSistemaSel = $pdo->prepare('SELECT id_persona FROM Personas WHERE identificador = :identificador LIMIT 1');
    $stSistemaSel->execute([':identificador' => IDENTIFICADOR_SISTEMA]);
    $idPersonaSistemaRaw = $stSistemaSel->fetchColumn();
    if ($idPersonaSistemaRaw === false) {
        throw new RuntimeException('No se pudo resolver persona del sistema');
    }
    $idPersonaSistema = (int)$idPersonaSistemaRaw;

    $st = $pdo->prepare(
        'SELECT
            c.id_credencial,
            c.uid,
            p.id_persona,
            p.nombre,
            p.apellido
         FROM Credenciales c
         INNER JOIN Personas p ON p.id_persona = c.id_persona
         WHERE c.uid = :uid
         LIMIT 1'
    );
    $st->execute([':uid' => $uid]);
    $fila = $st->fetch(PDO::FETCH_ASSOC);

    $uidRegistrado = $fila !== false;
    $puertaActiva = (int)$puerta['activo'] === 1;

    $requiereRevision = $uidRegistrado && $puertaActiva;
    $autorizadoPreliminar = $uidRegistrado && $puertaActiva;

    $motivo = 'UID_NO_REGISTRADO';
    if ($uidRegistrado && !$puertaActiva) {
        $motivo = 'PUERTA_INACTIVA';
    } elseif ($requiereRevision) {
        $motivo = 'REVISION_PENDIENTE';
    }

    $idPersonaRegistro = $uidRegistrado ? (int)$fila['id_persona'] : $idPersonaSistema;
    $idCredencialRegistro = $uidRegistrado ? (int)$fila['id_credencial'] : null;
    $revisionEstado = $requiereRevision ? 'PENDIENTE' : 'NO_REQUERIDA';

    $observacion = ($requiereRevision ? 'PERMITIDO_PRELIMINAR' : 'DENEGADO') .
                   ' | UID: ' . $uid .
                   ' | Motivo: ' . $motivo;

    $ins = $pdo->prepare(
        'INSERT INTO Accesos (
            id_persona,
            id_puerta,
            id_credencial,
            tipo,
            autorizado,
            revision_estado,
            observacion
         ) VALUES (
            :id_persona,
            :id_puerta,
            :id_credencial,
            :tipo,
            :autorizado,
            :revision_estado,
            :observacion
         )'
    );

    $ins->bindValue(':id_persona', $idPersonaRegistro, PDO::PARAM_INT);
    $ins->bindValue(':id_puerta', $idPuerta, PDO::PARAM_INT);
    if ($idCredencialRegistro === null) {
        $ins->bindValue(':id_credencial', null, PDO::PARAM_NULL);
    } else {
        $ins->bindValue(':id_credencial', $idCredencialRegistro, PDO::PARAM_INT);
    }
    $ins->bindValue(':tipo', $tipo, PDO::PARAM_STR);
    $ins->bindValue(':autorizado', $autorizadoPreliminar ? 1 : 0, PDO::PARAM_INT);
    $ins->bindValue(':revision_estado', $revisionEstado, PDO::PARAM_STR);
    $ins->bindValue(':observacion', $observacion, PDO::PARAM_STR);
    $ins->execute();

    $idRegistro = (int)$pdo->lastInsertId();

    $stFecha = $pdo->prepare('SELECT fecha_hora FROM Accesos WHERE id_registro = :id_registro LIMIT 1');
    $stFecha->execute([':id_registro' => $idRegistro]);
    $fechaHora = (string)$stFecha->fetchColumn();

    $pdo->commit();

    responder(200, [
        'ok' => true,
        'autorizado' => $autorizadoPreliminar,
        'autorizado_final' => $requiereRevision ? false : $autorizadoPreliminar,
        'decision_final' => !$requiereRevision,
        'requiere_revision' => $requiereRevision,
        'modo_revision' => obtenerModoRevision(),
        'registrado' => true,
        'motivo' => $motivo,
        'id_registro' => $idRegistro,
        'id_persona' => $idPersonaRegistro,
        'fecha_hora' => $fechaHora,
        'nombre' => $uidRegistrado ? trim((string)$fila['nombre'] . ' ' . (string)$fila['apellido']) : 'NO_REGISTRADO'
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    responder(500, [
        'ok' => false,
        'autorizado' => false,
        'registrado' => false,
        'motivo' => 'ERROR_INTERNO',
        'error' => $e->getMessage()
    ]);
}
