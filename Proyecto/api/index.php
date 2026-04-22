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

    .overlay {
      position: fixed;
      inset: 0;
      background: rgba(7, 19, 36, 0.72);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 9999;
      padding: 14px;
    }
    .overlay.visible {
      display: flex;
    }
    .modal {
      width: min(980px, 96vw);
      background: #fff;
      border-radius: 18px;
      border: 1px solid #d8e3ee;
      box-shadow: 0 18px 60px rgba(5, 15, 35, 0.35);
      padding: 18px;
    }
    .modal h2 {
      margin: 0 0 8px;
      font-size: 1.35rem;
    }
    .modal p {
      margin: 0;
      color: var(--muted);
      font-size: .95rem;
    }
    .modal-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      margin-top: 14px;
    }
    .photo-card {
      border: 1px solid #d9e4ee;
      border-radius: 14px;
      padding: 10px;
      background: #f9fcff;
    }
    .photo-card strong {
      display: block;
      margin-bottom: 8px;
    }
    .photo-large {
      width: 100%;
      aspect-ratio: 4 / 3;
      object-fit: cover;
      border: 1px solid #cfdbe8;
      border-radius: 10px;
      background: #eef4f9;
    }
    .modal-actions {
      display: flex;
      gap: 10px;
      margin-top: 14px;
    }
    .btn-allow {
      background: #0e9b57;
    }
    .btn-deny {
      background: #c73d35;
    }
    .modal small {
      display: block;
      margin-top: 10px;
      color: #63788e;
    }

    @media (max-width: 900px) {
      .filters { grid-template-columns: 1fr; }
      table { display: block; overflow-x: auto; }
      .modal-grid { grid-template-columns: 1fr; }
      .modal-actions { flex-direction: column; }
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
      <h2>Revisión Manual de Identidad</h2>
      <p id="reviewInfo">Compara la foto registrada con la foto recién capturada.</p>

      <div class="modal-grid">
        <div class="photo-card">
          <strong>Foto Registrada</strong>
          <img id="fotoRegistrada" class="photo-large" alt="Foto registrada">
        </div>
        <div class="photo-card">
          <strong>Foto Capturada Ahora</strong>
          <img id="fotoCapturada" class="photo-large" alt="Foto capturada">
        </div>
      </div>

      <div class="modal-actions">
        <button id="btnPermitir" class="btn-allow" type="button">Permitir</button>
        <button id="btnDenegar" class="btn-deny" type="button">Denegar</button>
      </div>
      <small id="reviewMeta"></small>
    </div>
  </div>

  <script>
    const API_URL = 'acceso.php';
    // Si definiste un token en acceso.php, ponlo aquí también
    const API_TOKEN = ''; 

    const OVERLAY = document.getElementById('reviewOverlay');
    const FOTO_REG = document.getElementById('fotoRegistrada');
    const FOTO_CAP = document.getElementById('fotoCapturada');
    const REVIEW_INFO = document.getElementById('reviewInfo');
    const REVIEW_META = document.getElementById('reviewMeta');
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

    function setButtonState(disabled) {
      BTN_PERMITIR.disabled = disabled;
      BTN_DENEGAR.disabled = disabled;
    }

    async function postApi(data) {
      const params = new URLSearchParams(data);
      if (API_TOKEN && !params.has('token')) {
          params.append('token', API_TOKEN);
      }

      const resp = await fetch(API_URL, {
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

    function abrirModal(item) {
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
        setButtonState(false);
      }
    }

    async function revisarPendientes() {
      if (revisando || OVERLAY.classList.contains('visible')) {
        return;
      }
      revisando = true;
      try {
        const data = await postApi({ accion: 'LISTAR_PENDIENTES' });
        if (data.ok && Array.isArray(data.pendientes) && data.pendientes.length > 0) {
          abrirModal(data.pendientes[0]);
        }
      } catch (e) {
        console.error("Error al consultar pendientes:", e);
      } finally {
        revisando = false;
      }
    }

    BTN_PERMITIR.addEventListener('click', () => resolver('PERMITIR'));
    BTN_DENEGAR.addEventListener('click', () => resolver('DENEGAR'));

    revisarPendientes();
    setInterval(revisarPendientes, 3000);
    setInterval(() => {
      if (!OVERLAY.classList.contains('visible')) {
        window.location.reload();
      }
    }, 12000);
  </script>
</body>
</html>
