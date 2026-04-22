<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

const DB_PATH = '/var/www/html/audit.db';
const API_TOKEN = ''; // opcional: si no quieres token, dejalo vacio
const IDENTIFICADOR_SISTEMA = 'SYS-DENEGADO';

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

    if ($accion === 'ADJUNTAR_FOTO') {
        $idRegistro = (int)($_POST['id_registro'] ?? 0);
        $fotoUrl = trim((string)($_POST['foto_url'] ?? ''));

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

        responder(200, [
            'ok' => true,
            'autorizado' => true,
            'registrado' => true,
            'motivo' => 'FOTO_ADJUNTADA',
            'id_registro' => $idRegistro,
            'foto_url' => normalizarRutaPublica($fotoUrl)
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
            'total' => count($pendientes)
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
