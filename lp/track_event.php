<?php
// track_event.php
if (!isset($_SESSION)) {
  @ini_set('session.cookie_httponly', true);
  @ini_set('session.use_only_cookies', true);
  session_start();
}

require_once __DIR__ . '/../Connections/bt.php';
$mysqli = $balcao;
$mysqli->set_charset('utf8mb4');

// Resposta sempre em JSON
header('Content-Type: application/json; charset=utf-8');

// Lê o JSON bruto
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || !is_array($data)) {
  http_response_code(400);
  echo json_encode([
    'status'  => 'error',
    'message' => 'Payload inválido ou ausente'
  ]);
  exit;
}

// Extrai campos principais
$quiz_session_id = isset($data['quiz_session_id']) ? (int)$data['quiz_session_id'] : 0;
$event_type      = isset($data['event_type']) ? trim($data['event_type']) : '';
$event_name      = isset($data['event_name']) ? trim($data['event_name']) : '';
$page_url        = isset($data['page_url']) ? trim($data['page_url']) : '';
$extra_data_arr  = isset($data['extra_data']) && is_array($data['extra_data']) ? $data['extra_data'] : null;

// Sanitiza tamanhos para caber na tabela pb_visit_event
$event_type = mb_substr($event_type, 0, 50, 'utf-8');
$event_name = mb_substr($event_name, 0, 100, 'utf-8');
$page_url   = mb_substr($page_url,   0, 500, 'utf-8');

$extra_data_json = $extra_data_arr
  ? json_encode($extra_data_arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
  : null;

// Validação básica
if ($event_type === '' || $event_name === '') {
  http_response_code(400);
  echo json_encode([
    'status'  => 'error',
    'message' => 'event_type e event_name são obrigatórios'
  ]);
  exit;
}

try {
  // 1) Insere na pb_visit_event
  $stmt = $mysqli->prepare("
    INSERT INTO pb_visit_event
      (quiz_session_id, event_type, event_name, page_url, extra_data)
    VALUES
      (?, ?, ?, ?, ?)
  ");
  $stmt->bind_param(
    'issss',
    $quiz_session_id,
    $event_type,
    $event_name,
    $page_url,
    $extra_data_json
  );
  $stmt->execute();
  $visit_event_id = $stmt->insert_id;
  $stmt->close();

  // =====================================================
  // 2) SE FOR InitiateCheckout → grava em pb_notif_diaria
  // =====================================================

  // Normaliza o nome do evento
  $lowerName = strtolower($event_name);

  // Casos aceitos:
  // - event_name == "InitiateCheckout" (exato, qualquer caixa)
  // - ou qualquer coisa terminando com "_initiate_checkout"
  $isInitiateCheckout =
    (strcasecmp($event_name, 'InitiateCheckout') === 0)
    || (substr($lowerName, -17) === '_initiate_checkout');

  if ($isInitiateCheckout) {
    // idusuario que vai receber o aviso (ajuste conforme sua lógica)
    $idusuario = 7;

    // Data de hoje (pode usar a do evento também, se preferir: DATE(NEW.created_at))
    $hoje = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))
      ->format('Y-m-d');

    // Extra: tenta pegar nome e produto do extra_data, se existir
    $nome    = null;
    $produto = null;

    if ($extra_data_arr) {
      // Ajuste os caminhos conforme o que você envia do JS
      // Exemplo: { user_name: 'João', product: 'Secrets of Powerball Decoded' }
      if (!empty($extra_data_arr['user_name'])) {
        $nome = $extra_data_arr['user_name'];
      } elseif (!empty($extra_data_arr['name'])) {
        $nome = $extra_data_arr['name'];
      }

      if (!empty($extra_data_arr['product'])) {
        $produto = $extra_data_arr['product'];
      } elseif (!empty($extra_data_arr['slug'])) {
        // fallback: usa o slug como "produto"
        $produto = $extra_data_arr['slug'];
      }
    }

    if (!$nome) {
      $nome = 'Um visitante';
    }
    if (!$produto) {
      $produto = 'um produto';
    }

    $titulo = 'Usuário no Checkout';

    // Monta o texto no estilo que você pediu
    // Ex: "João está tentando comprar Secrets of Powerball Decoded"
    $texto  = $nome . ' está tentando comprar ' . $produto;

    // Para não explodir o UNIQUE da uniq_guard (idusuario+data+slot+canal),
    // usamos INSERT IGNORE: se já existir um registro igual hoje, ele ignora.
    $stmt2 = $mysqli->prepare("
      INSERT IGNORE INTO pb_notif_diaria
        (idusuario, data, qtd_cliques, meta, titulo, texto, canal, slot)
      VALUES
        (?, ?, 0, 0, ?, ?, 'chat', 'qualquer')
    ");
    $stmt2->bind_param(
      'isss',
      $idusuario,
      $hoje,
      $titulo,
      $texto
    );
    $stmt2->execute();
    $stmt2->close();
  }

  // Resposta final
  echo json_encode([
    'status'          => 'ok',
    'visit_event_id'  => $visit_event_id,
    'initiated_check' => $isInitiateCheckout ? 1 : 0
  ]);

} catch (mysqli_sql_exception $e) {
  http_response_code(500);
  echo json_encode([
    'status'  => 'error',
    'message' => 'DB error in track_event.php',
    'error'   => $e->getMessage()
  ]);
  exit;
}
