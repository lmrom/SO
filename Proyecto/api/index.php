<?php
declare(strict_types=1);

const DB_PATH = '/var/www/html/audit.db';

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function extraerUidDesdeObservacion(?string $observacion): string {
    if (!$observacion) {
        return '-';
    }
    if (preg_match('/UID:\s*([^\s|]+)/i', $observacion, $m) === 1) {
        return strtoupper(trim($m[1]));
    }
    return '-';
}

function extraerFotoDesdeObservacion(?string $observacion): ?string {
    if (!$observacion) {
        return null;
    }
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
    $abs = '/var/www/html/' . ltrim($ruta, '/');
    if (!is_file($abs)) {
        return null;
    }
    return '/' . ltrim($ruta, '/');
}

function normalizarFotoReferencia(?string $ruta): ?string {
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

$estado = strtoupper(trim((string)($_GET['estado'] ?? 'ALL')));
if (!in_array($estado, ['ALL', 'AUTORIZADO', 'DENEGADO', 'PENDIENTE'], true)) {
    $estado = 'ALL';
}
$q = trim((string)($_GET['q'] ?? ''));
$limite = (int)($_GET['limite'] ?? 50);
if ($limite < 10) {
    $limite = 10;
}
if ($limite > 200) {
    $limite = 200;
}

$errores = [];
$stats = [
    'total_hoy' => 0,
    'autorizados_hoy' => 0,
    'denegados_hoy' => 0,
    'pendientes_hoy' => 0,
    'ultimo_evento' => '-',
];
$accesos = [];

try {
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON;');

    $stats['total_hoy'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM Accesos WHERE date(fecha_hora, 'localtime') = date('now', 'localtime')"
    )->fetchColumn();

    $stats['autorizados_hoy'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM Accesos
         WHERE autorizado = 1
           AND revision_estado IN ('NO_REQUERIDA','APROBADA')
           AND date(fecha_hora, 'localtime') = date('now', 'localtime')"
    )->fetchColumn();

    $stats['denegados_hoy'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM Accesos
         WHERE autorizado = 0
           AND date(fecha_hora, 'localtime') = date('now', 'localtime')"
    )->fetchColumn();

    $stats['pendientes_hoy'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM Accesos
         WHERE revision_estado = 'PENDIENTE'
           AND date(fecha_hora, 'localtime') = date('now', 'localtime')"
    )->fetchColumn();

    $ultimo = $pdo->query("SELECT fecha_hora FROM Accesos ORDER BY id_registro DESC LIMIT 1")->fetchColumn();
    if (is_string($ultimo) && $ultimo !== '') {
        $stats['ultimo_evento'] = $ultimo;
    }

    $sql = "
        SELECT
            v.id_registro,
            v.fecha_hora,
            v.tipo,
            v.autorizado,
            v.revision_estado,
            v.revision_comentario,
            v.observacion,
            v.identificador,
            v.nombre,
            v.apellido,
            v.tipo_persona,
            v.foto_referencia,
            v.puerta,
            v.ubicacion,
            v.uid
        FROM vw_accesos_detalle v
        WHERE
            (
                :estado = 'ALL'
                OR (:estado = 'AUTORIZADO' AND v.autorizado = 1 AND v.revision_estado IN ('NO_REQUERIDA','APROBADA'))
                OR (:estado = 'DENEGADO' AND v.autorizado = 0)
                OR (:estado = 'PENDIENTE' AND v.revision_estado = 'PENDIENTE')
            )
            AND (
                :q = ''
                OR UPPER(COALESCE(v.uid, '')) LIKE '%' || UPPER(:q) || '%'
                OR UPPER(v.identificador) LIKE '%' || UPPER(:q) || '%'
                OR UPPER(v.nombre || ' ' || v.apellido) LIKE '%' || UPPER(:q) || '%'
                OR UPPER(COALESCE(v.observacion, '')) LIKE '%' || UPPER(:q) || '%'
            )
        ORDER BY v.id_registro DESC
        LIMIT :limite
    ";
    $st = $pdo->prepare($sql);
    $st->bindValue(':estado', $estado, PDO::PARAM_STR);
    $st->bindValue(':q', $q, PDO::PARAM_STR);
    $st->bindValue(':limite', $limite, PDO::PARAM_INT);
    $st->execute();
    $accesos = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errores[] = $e->getMessage();
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Panel de Accesos</title>
  <style>
    :root {
      --bg1: #f5fbff;
      --bg2: #eef8f1;
      --card: #ffffff;
      --line: #d8e3ee;
      --text: #13233a;
      --muted: #5f738d;
      --ok: #0e9b57;
      --deny: #c73d35;
      --pending: #be7e00;
      --accent: #0b63ce;
      --shadow: 0 10px 30px rgba(8, 38, 78, 0.08);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Segoe UI", Tahoma, sans-serif;
      color: var(--text);
      background: radial-gradient(circle at top left, var(--bg1), var(--bg2));
    }
    .wrap {
      max-width: 1220px;
      margin: 24px auto;
      padding: 0 14px 24px;
    }
    .header {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 14px;
    }
    h1 {
      margin: 0;
      font-size: clamp(1.3rem, 2.4vw, 2rem);
      letter-spacing: .3px;
    }
    .sub {
      margin-top: 4px;
      color: var(--muted);
      font-size: .95rem;
    }
    .cards {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 12px;
      margin-bottom: 14px;
    }
    .card {
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: 16px;
      padding: 14px;
      box-shadow: var(--shadow);
    }
    .label {
      color: var(--muted);
      font-size: .82rem;
      text-transform: uppercase;
      letter-spacing: .6px;
    }
    .value {
      font-size: 1.5rem;
      font-weight: 700;
      margin-top: 4px;
    }
    .filters {
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: 16px;
      box-shadow: var(--shadow);
      padding: 12px;
      margin-bottom: 14px;
      display: grid;
      gap: 10px;
      grid-template-columns: 1.4fr .8fr .6fr auto;
    }
    input, select, button {
      width: 100%;
      border: 1px solid #bfd0e0;
      border-radius: 10px;
      padding: 9px 10px;
      font-size: .95rem;
      background: #fff;
    }
    button {
      background: var(--accent);
      color: #fff;
      font-weight: 700;
      border: none;
      cursor: pointer;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: 16px;
      overflow: hidden;
      box-shadow: var(--shadow);
    }
    thead th {
      text-align: left;
      padding: 10px 8px;
      background: #f3f8ff;
      font-size: .82rem;
      color: #375170;
      border-bottom: 1px solid var(--line);
      white-space: nowrap;
    }
    tbody td {
      padding: 9px 8px;
      border-bottom: 1px solid #edf2f7;
      vertical-align: top;
      font-size: .92rem;
    }
    tbody tr:hover { background: #f9fcff; }
    .badge {
      display: inline-block;
      font-size: .75rem;
      border-radius: 999px;
      padding: 3px 9px;
      color: #fff;
      font-weight: 700;
      letter-spacing: .2px;
    }
    .ok { background: var(--ok); }
    .deny { background: var(--deny); }
    .pending { background: var(--pending); }
    .uid {
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-weight: 700;
      color: #16385b;
    }
    .photo {
      width: 90px;
      height: 66px;
      object-fit: cover;
      border-radius: 10px;
      border: 1px solid #d5e0ea;
      background: #f4f7fb;
    }
    .muted { color: var(--muted); }
    .error {
      margin-top: 10px;
      background: #ffe9e8;
      color: #842029;
      border: 1px solid #f1b2b0;
      border-radius: 12px;
      padding: 10px;
      font-size: .92rem;
    }

    .review-monitor {
      margin-top: 10px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 12px;
      border-radius: 999px;
      background: #eef7ff;
      border: 1px solid #c7dcf2;
      color: #325272;
      font-size: .85rem;
      font-weight: 600;
    }
    .review-controls {
      margin-top: 10px;
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      align-items: center;
    }
    .mode-chip {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 12px;
      border-radius: 12px;
      border: 1px solid #c8d7e8;
      background: #ffffff;
      box-shadow: 0 6px 14px rgba(13, 40, 71, 0.07);
      font-size: .88rem;
      font-weight: 600;
      color: #244867;
    }
    .mode-switch {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: #fff;
      border: 1px solid #c8d7e8;
      border-radius: 12px;
      padding: 8px 10px;
      box-shadow: 0 6px 14px rgba(13, 40, 71, 0.07);
    }
    .mode-switch label {
      font-size: .84rem;
      color: #416282;
      font-weight: 700;
      letter-spacing: .2px;
      text-transform: uppercase;
    }
    .mode-switch select {
      min-width: 130px;
      width: auto;
      padding: 7px 10px;
      font-size: .88rem;
      border-radius: 9px;
      margin: 0;
    }
    .review-dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      background: #2f90e7;
      box-shadow: 0 0 0 5px rgba(47, 144, 231, 0.22);
      animation: pulse 1.6s ease-in-out infinite;
    }

    @keyframes pulse {
      0%, 100% { transform: scale(1); opacity: 1; }
      50% { transform: scale(1.18); opacity: 0.7; }
    }

    .overlay {
      position: fixed;
      inset: 0;
      background: rgba(7, 19, 36, 0.74);
      backdrop-filter: blur(2px);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 9999;
      padding: 20px;
    }
    .overlay.visible {
      display: flex;
    }
    .auto-overlay {
      position: fixed;
      inset: 0;
      background: rgba(7, 19, 36, 0.62);
      backdrop-filter: blur(2px);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 9998;
      padding: 20px;
    }
    .auto-overlay.visible {
      display: flex;
    }
    .auto-box {
      width: min(520px, 95vw);
      background: #ffffff;
      border: 1px solid #c8d7e8;
      border-radius: 16px;
      padding: 18px;
      box-shadow: 0 18px 44px rgba(5, 15, 35, 0.32);
      text-align: center;
    }
    .auto-spinner {
      width: 44px;
      height: 44px;
      border-radius: 50%;
      border: 4px solid #d2e4f6;
      border-top-color: #0b63ce;
      margin: 4px auto 12px;
      animation: spin 1s linear infinite;
    }
    .auto-title {
      font-size: 1.05rem;
      font-weight: 700;
      color: #1d3755;
      margin-bottom: 6px;
    }
    .auto-msg {
      color: #4e6782;
      font-size: .93rem;
    }
    @keyframes spin {
      to { transform: rotate(360deg); }
    }
    .modal {
      width: min(1080px, 97vw);
      background: linear-gradient(180deg, #f7fcff 0%, #f0f5fb 100%);
      border-radius: 20px;
      border: 1px solid #cad8e8;
      box-shadow: 0 24px 64px rgba(5, 15, 35, 0.36);
      padding: 20px;
    }
    .modal h2 {
      margin: 0 0 6px;
      font-size: clamp(1.2rem, 2vw, 1.55rem);
      letter-spacing: 0.2px;
    }
    .modal p {
      margin: 0;
      color: #3f607f;
      font-size: .95rem;
    }
    .modal-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      margin-bottom: 12px;
    }
    .pill-pending {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      border: 1px solid #f0cc8e;
      background: #fff5e3;
      color: #9b5d00;
      border-radius: 999px;
      padding: 6px 11px;
      font-size: .78rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .4px;
    }
    .pill-pending::before {
      content: "";
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #f09a15;
    }
    .modal-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
      margin-top: 14px;
    }
    .photo-card {
      border: 1px solid #cdd8e8;
      border-radius: 16px;
      padding: 14px;
      background: #ffffff;
      box-shadow: 0 8px 20px rgba(13, 40, 71, 0.08);
    }
    .photo-card strong {
      display: block;
      margin-bottom: 10px;
      text-align: center;
      letter-spacing: 0.4px;
      color: #1b3552;
    }
    .photo-large {
      width: 100%;
      aspect-ratio: 5 / 4;
      object-fit: cover;
      border: 1px solid #cdd8e8;
      border-radius: 12px;
      background: #eef4f9;
    }
    .modal-actions {
      display: flex;
      gap: 16px;
      margin-top: 16px;
      justify-content: center;
    }
    .btn-allow {
      background: linear-gradient(180deg, #38d839 0%, #14b931 100%);
      max-width: 260px;
      min-height: 52px;
      font-size: 1.05rem;
      letter-spacing: .4px;
      box-shadow: 0 8px 18px rgba(33, 157, 55, 0.35);
    }
    .btn-deny {
      background: linear-gradient(180deg, #ff5050 0%, #df1515 100%);
      max-width: 260px;
      min-height: 52px;
      font-size: 1.05rem;
      letter-spacing: .4px;
      box-shadow: 0 8px 18px rgba(199, 61, 53, 0.33);
    }
    .btn-allow:disabled,
    .btn-deny:disabled {
      opacity: .55;
      cursor: not-allowed;
      box-shadow: none;
    }
    .modal small {
      display: block;
      margin-top: 12px;
      color: #63788e;
      text-align: center;
    }

    @media (max-width: 900px) {
      .filters { grid-template-columns: 1fr; }
      table { display: block; overflow-x: auto; }
      .modal-grid { grid-template-columns: 1fr; }
      .modal-actions { flex-direction: column; align-items: center; }
      .btn-allow, .btn-deny { max-width: 100%; }
    }
  </style>
</head>
<body>
  <main class="wrap">
    <section class="header">
      <div>
        <h1>Panel de Accesos RFID</h1>
        <div class="sub">Último evento: <?= h($stats['ultimo_evento']) ?> · Revisión en tiempo real activa</div>
      </div>
    </section>

    <section class="cards">
      <article class="card">
        <div class="label">Eventos de Hoy</div>
        <div class="value"><?= h((string)$stats['total_hoy']) ?></div>
      </article>
      <article class="card">
        <div class="label">Autorizados Finales</div>
        <div class="value" style="color: var(--ok);"><?= h((string)$stats['autorizados_hoy']) ?></div>
      </article>
      <article class="card">
        <div class="label">Denegados</div>
        <div class="value" style="color: var(--deny);"><?= h((string)$stats['denegados_hoy']) ?></div>
      </article>
      <article class="card">
        <div class="label">Pendientes Revisión</div>
        <div class="value" style="color: var(--pending);"><?= h((string)$stats['pendientes_hoy']) ?></div>
      </article>
    </section>

    <form class="filters" method="get">
      <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por UID, nombre, identificador o motivo">
      <select name="estado">
        <option value="ALL" <?= $estado === 'ALL' ? 'selected' : '' ?>>Todos</option>
        <option value="AUTORIZADO" <?= $estado === 'AUTORIZADO' ? 'selected' : '' ?>>Autorizados Finales</option>
        <option value="DENEGADO" <?= $estado === 'DENEGADO' ? 'selected' : '' ?>>Denegados</option>
        <option value="PENDIENTE" <?= $estado === 'PENDIENTE' ? 'selected' : '' ?>>Pendiente Revisión</option>
      </select>
      <select name="limite">
        <?php foreach ([25, 50, 100, 200] as $opt): ?>
        <option value="<?= $opt ?>" <?= $limite === $opt ? 'selected' : '' ?>><?= $opt ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit">Aplicar</button>
    </form>
    <div class="review-monitor">
      <span class="review-dot"></span>
      <span id="reviewStatusText">Monitoreando revisiones pendientes...</span>
    </div>
    <div class="review-controls">
      <div class="mode-chip">Modo de revisión: <strong id="reviewModeBadge">Cargando...</strong></div>
      <div class="mode-switch">
        <label for="reviewModeSelect">Switch</label>
        <select id="reviewModeSelect">
          <option value="MANUAL">Manual</option>
          <option value="AUTO">Automática Face ID</option>
        </select>
      </div>
    </div>

    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Fecha</th>
          <th>Estado</th>
          <th>Revisión</th>
          <th>UID</th>
          <th>Persona</th>
          <th>Tipo</th>
          <th>Puerta</th>
          <th>Motivo / Observación</th>
          <th>Foto</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($accesos)): ?>
        <tr>
          <td colspan="10" class="muted">No hay registros para los filtros seleccionados.</td>
        </tr>
        <?php endif; ?>
        <?php foreach ($accesos as $row): ?>
          <?php
            $revisionEstado = (string)($row['revision_estado'] ?? 'NO_REQUERIDA');
            $estadoTxt = ((int)$row['autorizado'] === 1) ? 'AUTORIZADO' : 'DENEGADO';
            if ($revisionEstado === 'PENDIENTE') {
                $estadoTxt = 'EN REVISION';
            }
            $uid = is_string($row['uid']) && $row['uid'] !== '' ? strtoupper($row['uid']) : extraerUidDesdeObservacion($row['observacion']);
            $persona = trim((string)$row['nombre'] . ' ' . (string)$row['apellido']);
            if ($persona === '') {
                $persona = 'N/D';
            }
            $foto = extraerFotoDesdeObservacion($row['observacion']);
          ?>
          <tr>
            <td><?= h((string)$row['id_registro']) ?></td>
            <td><?= h((string)$row['fecha_hora']) ?></td>
            <td>
              <span class="badge <?= $revisionEstado === 'PENDIENTE' ? 'pending' : (((int)$row['autorizado'] === 1) ? 'ok' : 'deny') ?>">
                <?= h($estadoTxt) ?>
              </span>
            </td>
            <td><?= h($revisionEstado) ?></td>
            <td class="uid"><?= h($uid) ?></td>
            <td>
              <?= h($persona) ?><br>
              <span class="muted"><?= h((string)$row['identificador']) ?> · <?= h((string)$row['tipo_persona']) ?></span>
            </td>
            <td><?= h((string)$row['tipo']) ?></td>
            <td><?= h((string)$row['puerta']) ?><br><span class="muted"><?= h((string)$row['ubicacion']) ?></span></td>
            <td>
              <?= h((string)($row['observacion'] ?? '')) ?>
              <?php if (!empty($row['revision_comentario'])): ?>
                <br><span class="muted">Revisión: <?= h((string)$row['revision_comentario']) ?></span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($foto !== null): ?>
                <a href="<?= h($foto) ?>" target="_blank" rel="noopener">
                  <img class="photo" src="<?= h($foto) ?>" alt="Foto de acceso <?= h((string)$row['id_registro']) ?>">
                </a>
              <?php else: ?>
                <span class="muted">Sin foto</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php if (!empty($errores)): ?>
      <div class="error">
        <?= h('Error DB: ' . implode(' | ', $errores)) ?>
      </div>
    <?php endif; ?>
  </main>

  <div id="reviewOverlay" class="overlay" aria-hidden="true">
    <div class="modal">
      <div class="modal-top">
        <div>
          <h2>Panel de Decisión Manual</h2>
          <p id="reviewInfo">Compara la foto registrada con la foto recién capturada.</p>
        </div>
        <span class="pill-pending">Pendiente</span>
      </div>

      <div class="modal-grid">
        <div class="photo-card">
          <strong>FOTO BASE DE DATOS</strong>
          <img id="fotoRegistrada" class="photo-large" alt="Foto registrada">
        </div>
        <div class="photo-card">
          <strong>FOTO ACTUAL ACCESO</strong>
          <img id="fotoCapturada" class="photo-large" alt="Foto capturada">
        </div>
      </div>

      <div class="modal-actions">
        <button id="btnDenegar" class="btn-deny" type="button">DENEGAR</button>
        <button id="btnPermitir" class="btn-allow" type="button">ACEPTAR</button>
      </div>
      <small id="reviewMeta"></small>
    </div>
  </div>

  <div id="autoOverlay" class="auto-overlay" aria-hidden="true">
    <div class="auto-box">
      <div id="autoSpinner" class="auto-spinner"></div>
      <div class="auto-title" id="autoTitle">Verificando Face ID...</div>
      <div class="auto-msg" id="autoMessage">Procesando validación automática, espera unos segundos.</div>
    </div>
  </div>

  <script>
    const API_CANDIDATES = [
      'acceso.php',
      '/acceso.php',
      'api/acceso.php',
      '/api/acceso.php'
    ];
    // Si definiste un token en acceso.php, ponlo aquí también
    const API_TOKEN = ''; 

    const OVERLAY = document.getElementById('reviewOverlay');
    const AUTO_OVERLAY = document.getElementById('autoOverlay');
    const AUTO_SPINNER = document.getElementById('autoSpinner');
    const AUTO_TITLE = document.getElementById('autoTitle');
    const AUTO_MESSAGE = document.getElementById('autoMessage');
    const FOTO_REG = document.getElementById('fotoRegistrada');
    const FOTO_CAP = document.getElementById('fotoCapturada');
    const REVIEW_INFO = document.getElementById('reviewInfo');
    const REVIEW_META = document.getElementById('reviewMeta');
    const REVIEW_STATUS = document.getElementById('reviewStatusText');
    const REVIEW_MODE_BADGE = document.getElementById('reviewModeBadge');
    const REVIEW_MODE_SELECT = document.getElementById('reviewModeSelect');
    const BTN_PERMITIR = document.getElementById('btnPermitir');
    const BTN_DENEGAR = document.getElementById('btnDenegar');

    const FALLBACK_IMG = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(
      '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 300">'
      + '<rect width="400" height="300" fill="#e9f0f6"/>'
      + '<text x="200" y="155" text-anchor="middle" fill="#6f8397" font-size="22" font-family="Segoe UI">Sin imagen</text>'
      + '</svg>'
    );

    let itemActual = null;
    let revisando = false;
    let apiUrlResuelta = null;
    let cambiandoModo = false;
    let autoFallbackMostradoPara = null;

    function textoDiagnosticoAuto(data) {
      const diag = data && typeof data === 'object' ? data.auto_diagnostico : null;
      if (!diag || typeof diag !== 'object') {
        return '';
      }
      const motivo = diag.motivo ? `Motivo: ${diag.motivo}` : '';
      const health = diag.health_url ? ` · Health: ${diag.health_url}` : '';
      return `${motivo}${health}`.trim();
    }

    function setButtonState(disabled) {
      BTN_PERMITIR.disabled = disabled;
      BTN_DENEGAR.disabled = disabled;
    }

    function setReviewStatus(texto) {
      REVIEW_STATUS.textContent = texto;
    }

    function mostrarAutoOverlay(titulo, mensaje, mostrarSpinner = true) {
      AUTO_TITLE.textContent = titulo;
      AUTO_MESSAGE.textContent = mensaje;
      AUTO_SPINNER.style.display = mostrarSpinner ? 'block' : 'none';
      AUTO_OVERLAY.classList.add('visible');
      AUTO_OVERLAY.setAttribute('aria-hidden', 'false');
    }

    function ocultarAutoOverlay() {
      AUTO_OVERLAY.classList.remove('visible');
      AUTO_OVERLAY.setAttribute('aria-hidden', 'true');
      AUTO_SPINNER.style.display = 'block';
    }

    function aplicarModoRevision(modo, autoDisponible = true) {
      const normalizado = (modo || 'MANUAL').toUpperCase() === 'AUTO' ? 'AUTO' : 'MANUAL';
      REVIEW_MODE_BADGE.textContent = normalizado === 'AUTO' ? 'AUTO FACE ID' : 'MANUAL';
      REVIEW_MODE_SELECT.value = normalizado;
      const optAuto = REVIEW_MODE_SELECT.querySelector('option[value="AUTO"]');
      if (optAuto) {
        optAuto.disabled = !autoDisponible;
        if (!autoDisponible && normalizado === 'AUTO') {
          REVIEW_MODE_BADGE.textContent = 'MANUAL (AUTO no disponible)';
          REVIEW_MODE_SELECT.value = 'MANUAL';
        }
      }
    }

    function construirUrlsApi() {
      const base = window.location.origin + window.location.pathname.replace(/[^/]*$/, '');
      const absolutas = API_CANDIDATES.map((ruta) => new URL(ruta, base).toString());
      return [...new Set(absolutas)];
    }

    async function intentarPost(url, data) {
      const params = new URLSearchParams(data);
      if (API_TOKEN && !params.has('token')) {
          params.append('token', API_TOKEN);
      }

      const resp = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
      });

      if (!resp.ok) {
          throw new Error(`Error servidor: ${resp.status}`);
      }

      const json = await resp.json();
      return json;
    }

    async function postApi(data) {
      if (apiUrlResuelta) {
        return intentarPost(apiUrlResuelta, data);
      }

      const urls = construirUrlsApi();
      let ultimoError = null;

      for (const url of urls) {
        try {
          const res = await intentarPost(url, data);
          apiUrlResuelta = url;
          setReviewStatus('Conexión API activa: ' + new URL(url).pathname);
          return res;
        } catch (err) {
          ultimoError = err;
        }
      }
      throw ultimoError || new Error('No se pudo conectar con la API');
    }

    async function cargarModoRevision() {
      try {
        const data = await postApi({ accion: 'OBTENER_MODO_REVISION' });
        if (data.ok) {
          aplicarModoRevision(data.modo_revision || 'MANUAL', Boolean(data.auto_disponible));
          if (!Boolean(data.auto_disponible)) {
            const diagTxt = textoDiagnosticoAuto(data);
            if (diagTxt) {
              setReviewStatus(`AUTO no disponible. ${diagTxt}`);
            }
          }
        } else {
          aplicarModoRevision('MANUAL', false);
        }
      } catch (_) {
        aplicarModoRevision('MANUAL', false);
      }
    }

    async function cambiarModoRevision(modo) {
      if (cambiandoModo) {
        return;
      }
      cambiandoModo = true;
      REVIEW_MODE_SELECT.disabled = true;
      try {
        const data = await postApi({
          accion: 'CAMBIAR_MODO_REVISION',
          modo_revision: modo
        });
        if (!data.ok) {
          const diagTxt = textoDiagnosticoAuto(data);
          alert('No se pudo cambiar el modo de revisión' + (diagTxt ? `\n${diagTxt}` : ''));
        }
        aplicarModoRevision(data.modo_revision || 'MANUAL', Boolean(data.auto_disponible));
        const diagTxt = textoDiagnosticoAuto(data);
        setReviewStatus(
          `Modo de revisión activo: ${data.modo_revision || 'MANUAL'}`
          + (!Boolean(data.auto_disponible) && diagTxt ? ` · ${diagTxt}` : '')
        );
      } catch (_) {
        alert('Error de red al cambiar modo de revisión');
      } finally {
        REVIEW_MODE_SELECT.disabled = false;
        cambiandoModo = false;
      }
    }

    function abrirModal(item) {
      ocultarAutoOverlay();
      itemActual = item;
      REVIEW_INFO.textContent = `UID ${item.uid || '-'} · ${item.nombre || 'N/D'} · ${item.tipo_persona || ''}`;
      REVIEW_META.textContent = `Registro #${item.id_registro} · ${item.fecha_hora || ''} · ${item.puerta || ''}`;

      FOTO_REG.src = item.foto_registrada || FALLBACK_IMG;
      FOTO_CAP.src = item.foto_capturada || FALLBACK_IMG;

      OVERLAY.classList.add('visible');
      OVERLAY.setAttribute('aria-hidden', 'false');
      setButtonState(false);
    }

    function cerrarModal() {
      OVERLAY.classList.remove('visible');
      OVERLAY.setAttribute('aria-hidden', 'true');
      itemActual = null;
    }

    async function resolver(decision) {
      if (!itemActual) {
        return;
      }
      setButtonState(true);
      try {
        const data = await postApi({
          accion: 'RESOLVER_REVISION',
          id_registro: String(itemActual.id_registro),
          decision,
          revisor: 'OPERADOR_WEB'
        });

        if (!data.ok) {
          alert('No se pudo resolver la revisión: ' + (data.motivo || 'ERROR'));
          setButtonState(false);
          return;
        }

        cerrarModal();
        window.location.reload();
      } catch (e) {
        alert('Error de red al resolver revisión');
        setReviewStatus('Error resolviendo revisión. Reintentando...');
        setButtonState(false);
      }
    }

    async function revisarPendientes() {
      if (revisando) {
        return;
      }
      revisando = true;
      try {
        // Si hay modal abierto, primero revisa si ya se resolvio automaticamente.
        if (OVERLAY.classList.contains('visible') && itemActual && itemActual.id_registro) {
          const estado = await postApi({
            accion: 'ESTADO_REVISION',
            id_registro: String(itemActual.id_registro)
          });
          if (estado.ok && Boolean(estado.finalizada)) {
            cerrarModal();
            window.location.reload();
            return;
          }
        }

        const data = await postApi({ accion: 'LISTAR_PENDIENTES' });
        if (data.modo_revision) {
          aplicarModoRevision(data.modo_revision, Boolean(data.auto_disponible ?? true));
        }
        if (!(data.ok && Array.isArray(data.pendientes))) {
          setReviewStatus('Sin pendientes por revisar');
          return;
        }

        if (data.pendientes.length === 0) {
          ocultarAutoOverlay();
          setReviewStatus('Sin pendientes por revisar');
          return;
        }

        const modoAutoActivo = (data.modo_revision || '').toUpperCase() === 'AUTO';
        const pendienteConFoto = data.pendientes.find((p) => Boolean(p.foto_capturada)) || data.pendientes[0];

        if (modoAutoActivo) {
          if (pendienteConFoto.auto_estado === 'FALLA') {
            setReviewStatus('No se pudo realizar AUTO. Pasando a revisión manual...');
            if (autoFallbackMostradoPara !== pendienteConFoto.id_registro && !OVERLAY.classList.contains('visible')) {
              autoFallbackMostradoPara = pendienteConFoto.id_registro;
              mostrarAutoOverlay(
                'No se pudo realizar AUTO',
                (pendienteConFoto.auto_mensaje || 'Se continuará con revisión manual.') + ' Pasando a modo manual...',
                false
              );
              setTimeout(() => {
                ocultarAutoOverlay();
                abrirModal(pendienteConFoto);
              }, 1200);
            }
            return;
          }

          if (!Boolean(pendienteConFoto.foto_capturada) || pendienteConFoto.auto_estado === 'CAPTURANDO') {
            mostrarAutoOverlay(
              'Verificando Face ID...',
              'Capturando evidencia y preparando comparación automática.',
              true
            );
            setReviewStatus('Pendiente detectado, esperando foto capturada...');
            return;
          }

          if (pendienteConFoto.auto_estado === 'PROCESANDO') {
            mostrarAutoOverlay(
              'Verificando Face ID...',
              'Procesando comparación automática. Si falla, se abrirá revisión manual.',
              true
            );
            setReviewStatus('Face ID automático en proceso...');
            return;
          }
        } else {
          ocultarAutoOverlay();
          if (!Boolean(pendienteConFoto.foto_capturada)) {
            setReviewStatus('Pendiente detectado, esperando foto capturada...');
            return;
          }
        }

        if (!OVERLAY.classList.contains('visible')) {
          setReviewStatus(`Pendiente detectado: ${data.pendientes.length} por revisar`);
          abrirModal(pendienteConFoto);
        } else {
          setReviewStatus(`Revisión en curso: registro #${itemActual?.id_registro ?? '-'}`);
        }
      } catch (e) {
        console.error("Error al consultar pendientes:", e);
        setReviewStatus('Sin conexión a API. Reintentando...');
      } finally {
        revisando = false;
      }
    }

    BTN_PERMITIR.addEventListener('click', () => resolver('PERMITIR'));
    BTN_DENEGAR.addEventListener('click', () => resolver('DENEGAR'));
    REVIEW_MODE_SELECT.addEventListener('change', (ev) => {
      const target = ev.target;
      if (!(target instanceof HTMLSelectElement)) {
        return;
      }
      cambiarModoRevision(target.value);
    });

    (async () => {
      await cargarModoRevision();
      revisarPendientes();
      setInterval(revisarPendientes, 3000);
      setInterval(() => {
        if (!OVERLAY.classList.contains('visible')) {
          window.location.reload();
        }
      }, 12000);
    })();
  </script>
</body>
</html>
