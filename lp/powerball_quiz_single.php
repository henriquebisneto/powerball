<?php 
// DEBUG (remover em produção)
/*
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
*/
// powerball_quiz_single.php
// Single-theme JackpotBlueprint-style Quiz com:
// - 1 categoria (tema) via slug em pb_quiz_category
// - Perguntas 1 a 1 (wizard)
// - Datepicker premium com máscara e validação
// - Signo + numerologia integrados
// - Simulação Powerball com roleta + chuva de dinheiro
// - Etapa extra de captura: Nome, E-mail, Telefone
// - Salva tudo em pb_quiz_profile (incluindo user_name, user_email, user_phone)

if (!isset($_SESSION)) {
  @ini_set('session.cookie_httponly', true);
  @ini_set('session.use_only_cookies', true);
  session_start();
}

require_once __DIR__ . '/../Connections/bt.php';
$mysqli = $balcao; // ajuste se sua conexão tiver outro nome
$mysqli->set_charset('utf8mb4');


// ===============================
// BREVO (Sendinblue) – CONFIG
// ===============================
require_once __DIR__ . '/../vendor/autoload.php'; // ajuste o caminho se o vendor estiver em outro lugar


/**
 * Defina aqui qual TEMA (categoria) este quiz vai usar
 * use o slug da tabela pb_quiz_category.slug
 */

$TARGET_CATEGORY_SLUG = 'statistics'; // <- TROCAR PARA O SLUG DO TEMA QUE VOCÊ

if (!empty($_GET['slug'])) {
  // Sanitiza: só letras, números, _ e -
  $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['slug']);
  if ($slug !== '') {
    $TARGET_CATEGORY_SLUG = $slug;
    
  }
}

/* ===========================
   META / SEO POR CATEGORIA
   =========================== */

$CAT_META = [
  'category_id'          => 0,
  'slug'                 => $TARGET_CATEGORY_SLUG,
  'title'                => 'Powerball Player Profile Quiz',
  'description'          => null,
  'seo_page_title'       => null,
  'seo_meta_description' => null,
  'seo_author'           => null,
  'seo_lang'             => 'en',
  'seo_og_image'         => null,
  'seo_favicon'          => null,
  'inject_head_bottom'   => null,
  'inject_body_top'      => null,
  'inject_footer'        => null,
];

try {
  $stmtMeta = $mysqli->prepare("
    SELECT 
      category_id,
      slug,
      title,
      description,
      seo_page_title,
      seo_meta_description,
      seo_author,
      seo_lang,
      seo_og_image,
      seo_favicon,
      inject_head_bottom,
      inject_body_top,
      inject_footer
    FROM pb_quiz_category
    WHERE slug = ? AND is_active = 1
    LIMIT 1
  ");
  $stmtMeta->bind_param('s', $TARGET_CATEGORY_SLUG);
  $stmtMeta->execute();
  $resMeta = $stmtMeta->get_result();
  if ($rowMeta = $resMeta->fetch_assoc()) {
    $CAT_META = array_merge($CAT_META, $rowMeta);
  }
  $stmtMeta->close();
} catch (mysqli_sql_exception $e) {
  // Se der erro aqui, segue com defaults e loga se quiser
}

/* ===========================
   FUNÇÕES BACKEND
   =========================== */

function create_quiz_session(mysqli $mysqli): int {
  if (!empty($_SESSION['quiz_session_id_single'])) {
    return (int)$_SESSION['quiz_session_id_single'];
  }
  $ip    = $_SERVER['REMOTE_ADDR']     ?? '';
  $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

  $stmt = $mysqli->prepare("
    INSERT INTO pb_quiz_session (user_ip, user_agent)
    VALUES (?, ?)
  ");
  $stmt->bind_param('ss', $ip, $agent);
  $stmt->execute();
  $quiz_session_id = $stmt->insert_id;
  $stmt->close();

  $_SESSION['quiz_session_id_single'] = $quiz_session_id;
  return $quiz_session_id;
}

/**
 * Salva respostas de UMA etapa (aqui: uma pergunta por vez).
 * $answers é array [question_code => answer_text, ...]
 */
function save_step_answers(mysqli $mysqli, int $quiz_session_id, int $step, array $answers): void {
  if ($quiz_session_id <= 0 || $step <= 0 || empty($answers)) return;

  $stmt = $mysqli->prepare("
    INSERT INTO pb_quiz_answer (quiz_session_id, step, question_code, answer_text)
    VALUES (?, ?, ?, ?)
  ");

  foreach ($answers as $code => $answer) {
    if ($answer === '' || $answer === null) continue;
    $codeSafe   = (string)$code;
    $answerSafe = (string)$answer;
    $stmt->bind_param('iiss', $quiz_session_id, $step, $codeSafe, $answerSafe);
    $stmt->execute();
  }
  $stmt->close();
}

/**
 * Gera um jogo aleatório simples (5 brancas + 1 vermelha)
 * FUTURO: trocar por sua função estatística (JackpotBlueprint engine)
 */
function generate_random_play(): array {
  $whitePool  = range(1, 69);
  shuffle($whitePool);
  $whiteBalls = array_slice($whitePool, 0, 5);
  sort($whiteBalls);
  $powerBall = random_int(1, 26);
  return ['white' => $whiteBalls, 'red' => $powerBall];
}

/**
 * Carrega UMA categoria (tema) e TODAS as perguntas ativas (pb_quiz_question)
 * para este quiz single-theme.
 */
function get_single_category_structure(mysqli $mysqli, string $slug): ?array {
  $sqlCat = "
    SELECT 
      category_id,
      slug,
      title,
      description,
      loading_html
    FROM pb_quiz_category
    WHERE slug = ? AND is_active = 1
    LIMIT 1
  ";
  $stmt = $mysqli->prepare($sqlCat);
  $stmt->bind_param('s', $slug);
  $stmt->execute();
  $res = $stmt->get_result();
  $cat = $res->fetch_assoc();
  $stmt->close();

  if (!$cat) {
    return null;
  }

  $category_id = (int)$cat['category_id'];

  $sqlQ = "
    SELECT question_id, question_code, question_text, input_type,
           is_required, options_json, sort_order
    FROM pb_quiz_question
    WHERE category_id = ? AND is_active = 1
    ORDER BY sort_order ASC
  ";
  $stmtQ = $mysqli->prepare($sqlQ);
  $stmtQ->bind_param('i', $category_id);
  $stmtQ->execute();
  $resQ = $stmtQ->get_result();

  $questions = [];
  while ($row = $resQ->fetch_assoc()) {
    $opts = [];
    if (!empty($row['options_json'])) {
      $dec = json_decode($row['options_json'], true);
      if (is_array($dec)) {
        $opts = $dec;
      }
    }

    $questions[] = [
      'code'        => $row['question_code'],
      'label'       => $row['question_text'],
      'type'        => $row['input_type'],
      'required'    => (bool)$row['is_required'],
      'options'     => $opts,                 // segue igual para radio/select/etc
      'options_raw' => $row['options_json'],  // <- aqui vem o HTML “cru” p/ static_html
    ];
  }

  $stmtQ->close();

  if (empty($questions)) {
    return null;
  }

  return [
    'category_id' => $category_id,
    'slug'        => $cat['slug'],
    'title'       => $cat['title'],
    'description' => $cat['description'],
    'loading_html'=> $cat['loading_html'] ?? null,
    'questions'   => $questions
  ];
}

/**
 * Carrega perfil de signo da tabela pb_zodiac_profile
 */
function get_zodiac_profile(mysqli $mysqli, string $sign_slug): ?array {
  $sql = "
    SELECT sign_slug, title, risk_style, money_mindset, play_advice
    FROM pb_zodiac_profile
    WHERE sign_slug = ?
    LIMIT 1
  ";
  $stmt = $mysqli->prepare($sql);
  $stmt->bind_param('s', $sign_slug);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res->fetch_assoc();
  $stmt->close();

  return $row ?: null;
}

/**
 * Salva o perfil final do quiz (signo, numerologia, números sorteados, contato)
 */
function save_quiz_profile(
  mysqli $mysqli,
  int $quiz_session_id,
  int $category_id,          // NOVO
  ?string $birth_date,
  ?string $zodiac_sign,
  ?string $zodiac_slug,
  ?int $life_path,
  array $white_balls,
  int $powerball,
  ?string $user_name = null,
  ?string $user_email = null,
  ?string $user_phone = null
){
  if ($quiz_session_id <= 0) return;

  $category_id = (int)$category_id;
  
  $whiteStr = implode(',', $white_balls);

  $stmt = $mysqli->prepare("
   INSERT INTO pb_quiz_profile
      (quiz_session_id, category_id, user_name, user_email, user_phone,
       birth_date, zodiac_sign, zodiac_slug, life_path, white_balls, powerball, brevo_synced)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$brevo_synced = 0;

$stmt->bind_param(
    'iissssssisii',   // << 12 tipos!
    $quiz_session_id,
    $category_id,
    $user_name,
    $user_email,
    $user_phone,
    $birth_date,
    $zodiac_sign,
    $zodiac_slug,
    $life_path,
    $whiteStr,
    $powerball,
    $brevo_synced
);

$stmt->execute();
$stmt->close();

}



/* ===========================
   ENDPOINTS AJAX
   =========================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  header('Content-Type: application/json; charset=utf-8');

  $action = strtolower(trim($_POST['action'] ?? ''));

  switch ($action) {
    case 'init_quiz':
      $quiz_session_id = create_quiz_session($mysqli);
      echo json_encode(['status' => 'ok', 'quiz_session_id' => $quiz_session_id]);
      exit;

    case 'get_sign_profile':
      $sign_slug = strtolower(trim($_POST['sign_slug'] ?? ''));
      if ($sign_slug === '') {
        echo json_encode(['status' => 'error', 'message' => 'Missing sign slug']);
        exit;
      }

      $profile = get_zodiac_profile($mysqli, $sign_slug);
      if (!$profile) {
        echo json_encode(['status' => 'error', 'message' => 'Sign profile not found']);
        exit;
      }

      echo json_encode([
        'status'        => 'ok',
        'sign_slug'     => $profile['sign_slug'],
        'title'         => $profile['title'],
        'risk_style'    => $profile['risk_style'],
        'money_mindset' => $profile['money_mindset'],
        'play_advice'   => $profile['play_advice'],
      ]);
      exit;

    case 'get_single_category':
      global $TARGET_CATEGORY_SLUG;
      $structure = get_single_category_structure($mysqli, $TARGET_CATEGORY_SLUG);
      if (!$structure) {
        echo json_encode(['status' => 'error','message' => 'Category or questions not found']);
        exit;
      }
      echo json_encode(['status' => 'ok','category' => $structure]);
      exit;

    case 'save_step':
      $quiz_session_id = (int)($_POST['quiz_session_id'] ?? 0);
      $step            = (int)($_POST['step'] ?? 0);
      $answersJson     = $_POST['answers_json'] ?? '[]';
      $answers         = json_decode($answersJson, true) ?: [];

      if ($quiz_session_id <= 0 || $step <= 0) {
        echo json_encode(['status' => 'error','message' => 'Invalid session or step']);
        exit;
      }
      save_step_answers($mysqli, $quiz_session_id, $step, $answers);
      echo json_encode(['status' => 'ok']);
      exit;

    case 'get_result':
      $quiz_session_id = (int)($_POST['quiz_session_id'] ?? 0);
      $category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
      if ($quiz_session_id <= 0) {
        echo json_encode(['status' => 'error','message' => 'Invalid session']);
        exit;
      }

      $play = generate_random_play();

      $birth_date  = $_POST['birth_date']  ?? null;
      $zodiac_sign = $_POST['zodiac_sign'] ?? null;
      $zodiac_slug = $_POST['zodiac_slug'] ?? null;
      $life_path   = isset($_POST['life_path']) ? (int)$_POST['life_path'] : null;

      $user_name  = $_POST['user_name']  ?? null;
      $user_email = $_POST['user_email'] ?? null;
      $user_phone = $_POST['user_phone'] ?? null;

      $categoryIdForProfile = (int)($CATEGORY_FOR_VIEW['category_id'] ?? 0);

        save_quiz_profile(
            $mysqli,
            $quiz_session_id,
            $category_id,        // 👈 AGORA VAI JUNTO
            $birth_date,
            $zodiac_sign,
            $zodiac_slug,
            $life_path,
            $play['white'],
            $play['red'],
            $user_name,
            $user_email,
            $user_phone
          );

      echo json_encode([
        'status'      => 'ok',
        'white_balls' => $play['white'],
        'powerball'   => $play['red'],
      ]);
      exit;

    default:
      echo json_encode(['status' => 'error','message' => 'Unknown action (QUIZ_V3): '.$action]);
      exit;
  }
}

// Não é AJAX -> renderiza HTML

// Garante que exista uma quiz_session para esta visita
$quiz_session_id = create_quiz_session($mysqli);

// Dados SEO prontos para o <head>
$seoLang  = $CAT_META['seo_lang'] ?: 'en';
$baseTitle = $CAT_META['title'] ? ($CAT_META['title'].' – JackpotBlueprint') : 'JackpotBlueprint – Powerball Player Profile Quiz';
$seoTitle = $CAT_META['seo_page_title'] ?: $baseTitle;


$ogLocale = 'en_US';
if ($seoLang === 'pt-BR') $ogLocale = 'pt_BR';
elseif ($seoLang === 'pt') $ogLocale = 'pt_PT';
elseif ($seoLang === 'es') $ogLocale = 'es_ES';

// Não é AJAX -> renderiza HTML
// Antes do <!doctype html>
$CATEGORY_FOR_VIEW = get_single_category_structure($mysqli, $TARGET_CATEGORY_SLUG);

// Template padrão da tela de loading (caso não tenha nada no banco)
$DEFAULT_LOADING_HTML = <<<HTML
<!-- Loading geral -->
<div id="loadingOverlay">
  <div class="text-center">
    <div class="spinner-border text-danger" role="status"></div>
    <div class="loading-text">
      <strong>Analyzing your response…</strong>
      <span id="loadingSubtext">Preparing your next insight.</span>
    </div>
  </div>
</div>
HTML;

// Se a categoria tiver um HTML próprio, usa ele; senão, usa o padrão
$LOADING_HTML = $CATEGORY_FOR_VIEW['loading_html'] ?? '';
if (trim($LOADING_HTML) === '') {
  $LOADING_HTML = $DEFAULT_LOADING_HTML;
}

// Carrega config de loading para esta categoria (por slug)
$catConfig = null;
$loadingHtml    = '';
$loadingDelayMs = 4000; // padrão

$stmtCfg = $mysqli->prepare("
  SELECT loading_html, loading_delay_ms
  FROM pb_quiz_category
  WHERE slug = ?
  LIMIT 1
");
$stmtCfg->bind_param('s', $TARGET_CATEGORY_SLUG);
$stmtCfg->execute();
$resCfg = $stmtCfg->get_result();
if ($rowCfg = $resCfg->fetch_assoc()) {
  $loadingHtml    = $rowCfg['loading_html'] ?? '';
  $loadingDelayMs = (int)($rowCfg['loading_delay_ms'] ?? 4000);
}
$stmtCfg->close();

if ($loadingDelayMs <= 0) {
  $loadingDelayMs = 4000;
}
?>
<!doctype html>
<html lang="<?php echo htmlspecialchars($seoLang); ?>">
<head>
  <meta charset="utf-8">
  <title><?php echo htmlspecialchars($seoTitle); ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <?php if (!empty($CAT_META['seo_meta_description'])): ?>
    <meta name="description" content="<?php echo htmlspecialchars($CAT_META['seo_meta_description']); ?>">
  <?php endif; ?>

  <?php if (!empty($CAT_META['seo_author'])): ?>
    <meta name="author" content="<?php echo htmlspecialchars($CAT_META['seo_author']); ?>">
  <?php endif; ?>

  <!-- OG / Social -->
  <meta property="og:title" content="<?php echo htmlspecialchars($seoTitle); ?>">
  <?php if (!empty($CAT_META['seo_meta_description'])): ?>
    <meta property="og:description" content="<?php echo htmlspecialchars($CAT_META['seo_meta_description']); ?>">
  <?php endif; ?>
  <meta property="og:type" content="website">
  <meta property="og:locale" content="<?php echo htmlspecialchars($ogLocale); ?>">
  <?php if (!empty($CAT_META['seo_og_image'])): ?>
    <meta property="og:image" content="<?php echo htmlspecialchars($CAT_META['seo_og_image']); ?>">
  <?php endif; ?>

  <?php if (!empty($CAT_META['seo_favicon'])): ?>
    <link rel="icon" href="<?php echo htmlspecialchars($CAT_META['seo_favicon']); ?>">
  <?php endif; ?>

  <!-- Your local CSS (preferred) -->
  <!-- <link href="/assets/css/bootstrap.min.css" rel="stylesheet"/> -->
  <!-- Fallback CDN -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css"/>
  <script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js"></script>

  <!-- Tempus Dominus (Datepicker Bootstrap 5) -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@eonasdan/tempus-dominus@6.7.4/dist/css/tempus-dominus.min.css" />
  
  
  <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700&family=Cormorant:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  
  <!-- Tempus Dominus (Datepicker Bootstrap 5) -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@eonasdan/tempus-dominus@6.7.4/dist/css/tempus-dominus.min.css" />


  <!-- Estilo base -->
<link rel="stylesheet" href="https://powerball.toplinework.com/lp/general.css" />

  <?php
    // Injeção extra no final do <head>, específica da categoria
    if (!empty($CAT_META['inject_head_bottom'])) {
      echo $CAT_META['inject_head_bottom'] . "\n";
    }
  ?>
</head>
<body>
  <?php
    // Códigos logo após <body>
    if (!empty($CAT_META['inject_body_top'])) {
      echo $CAT_META['inject_body_top'] . "\n";
    }
  ?>

<!-- TOP BAR FIXA DO QUIZ -->
<header class="quiz-topbar">
  <div class="quiz-topbar-inner">
    <!-- Botão Back -->
    <button type="button" class="quiz-topbar-back" id="topBackBtn">
      <span class="d-inline-flex align-items-center justify-content-center">
        <!-- Ícone seta para a esquerda -->
        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <g clip-path="url(#clip0_back_arrow)">
            <path d="M14.7062 8.11538C15.095 7.72569 15.0947 7.09466 14.7054 6.70538C14.3158 6.31581 13.6842 6.31581 13.2946 6.70538L8.70711 11.2929C8.31658 11.6834 8.31658 12.3166 8.70711 12.7071L13.2946 17.2946C13.6842 17.6842 14.3158 17.6842 14.7054 17.2946C15.0947 16.9053 15.095 16.2743 14.7062 15.8846L10.83 12L14.7062 8.11538Z" fill="#71A6A1"></path>
          </g>
          <defs>
            <clipPath id="clip0_back_arrow">
              <rect width="24" height="24" fill="white"></rect>
            </clipPath>
          </defs>
        </svg>
      </span>
      <span>Back</span>
    </button>

    <!-- "Logo" / nome da ferramenta -->
    <div class="quiz-topbar-logo">
      JACKPOTBLUEPRINT
    </div>

    <!-- Progresso -->
    <div class="quiz-topbar-progress">
      <span id="topProgressCurrent">0</span>
      <span>of</span>
      <span id="topProgressTotal">0</span>
    </div>
  </div>
</header>


   <!-- Overlay de brilho no background (ativado via JS / classe) -->
  <div id="bgShineOverlay"></div>

  <!-- Loading geral (dinâmico por categoria) -->
  <?php
    $overlayHtml = trim($loadingHtml);
    if ($overlayHtml === '') {
      // Fallback padrão COMPLETO (já com o id="loadingOverlay")
      $overlayHtml = '
      <div id="loadingOverlay">
        <div class="text-center">
          <div class="spinner-border text-danger" role="status"></div>
          <div class="loading-text">
            <strong>Analyzing your response…</strong>
            <span id="loadingSubtext">Preparing your next insight.</span>
          </div>
        </div>
      </div>';
    }
    echo $overlayHtml;
  ?>

  <!-- Overlay de celebração (dinheiro / confetes) -->
  <div id="celebrationOverlay"></div>

  <!-- SECTION HERO -->
  <?php /*
  <section class="hero-visuals">
    <div class="hero-stack">

      <!-- Imagem 1 
      <img
        src="img/mulher2.png"
        alt="Powerball insights illustration"
        class="hero-img hero-img-floating"
        style="--float-speed:3.2s;"
      >-->

      <div class="text-center">
        <h1>
          Discover <strong>the secrets</strong> behind your <strong>birthdate</strong> with the
        </h1>
      </div>

      <div style="margin-top:-30px;">
        <div class="balls">
          <div class="ball">P</div>
          <div class="ball">O</div>
          <div class="ball">W</div>
          <div class="ball">E</div>
          <div class="ball">R</div>
          <div>
            <div class="ballp" style="font-weight:bold;">B</div>
            <div class="ballp" style="font-weight:bold;">A</div>
            <div class="ballp" style="font-weight:bold;">L</div>
            <div class="ballp" style="font-weight:bold;">L</div>
          </div>
        </div>
      </div>

    </div>
  </section>
  */ ?>

  <!-- CARD DO QUIZ -->
  <div class="container" id="quizCard">
    <div class="quiz-card mx-auto">
      <div class="quiz-card-inner">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <div class="step-indicator" id="stepIndicator"></div>
            <h1 class="h4 mb-0 fw-bold" id="stepTitle">
              Unlock Your Powerball Player Profile
            </h1>
          </div>
          <span class="badge" id="stepTag">Q1</span>
        </div>

        <p class="mb-4" id="stepDescription">
          This quick quiz analyzes how you make lottery decisions — habits, intuition and logic.
          Each answer sharpens your simulation using real Powerball data.
        </p>

        <!-- Etapa 0 – START -->
        <div class="mt-4" id="startContainer">
          <button type="button" class="btn-primary-full btn-glow" id="startQuizBtn">
            Start My Player Analysis »
          </button>
        </div>

        <!-- Form do quiz -->
        <form id="quizForm" class="d-none">
          <div id="questionsContainer"></div>
          <div class="mt-4" id="buttonsContainer">
            <button type="button" class="btn-primary-full btn-glow" id="nextStepBtn">
              Next Question »
            </button>
          </div>
        </form>

        <!-- RESULTADO -->
        <div id="resultContainer" class="mt-4 d-none">
          <h2 class="h2 mb-3 text-danger text-center fw-bold d-none">Smart Powerball Simulation</h2>
          <p class="mb-3 d-none" id="resultIntro text-center">
            Your results are ready! Here’s what the numbers reveal about your life according to astrology and numerology!
            
          </p>

          <!-- 1) NÚMEROS PRIMEIRO -->
          <div class="slot-container d-none" id="slotRow"></div>
          <div class="d-flex justify-content-center gap-4 mb-3 d-none" id="slotSummary"></div>

          <!-- 2) PERFIL PESSOAL + 3) PERFIL ASTROLÓGICO -->
          <div id="zodiacInfo" class="mb-3 d-none"></div>
         

          <!-- 4) QUESTION + CTA --> 
<div class="mt-3">

<?php /*
<section id="stories" class="section-pad"><div class="container"><div class="text-center mb-4"><div class="badge fs-6 badge-soft-danger fw-semibold bg-danger">Real Stories</div><h2 class="fw-bold">Did you know that “luck” can be decoded?</h2><p class="mb-0">Famous examples of people who used math, statistics, and strategy.</p></div><!-- Narrativa --><div class="justify-content-center row"><div class=""><div class=""><div class="mb-4">
        <video-js  id="_a49f656b-9802-448e-909f-ce2bf4de872e" dlyTime="00:02:00" dlyClass="atomicat-delay" fluid="true" primaryColor="#4adede" muted autoplay sA="atomiSA" lg="en" pA="1" sP="true" sastart="51"     playsinline><source src="https://vz-b6c1b024-c29.b-cdn.net/a49f656b-9802-448e-909f-ce2bf4de872e/playlist.m3u8#t=0.001" type="application/x-mpegURL" /></video-js>
    <script type="text/javascript" defer>document.body.appendChild(Object.assign(document.createElement("script"), {src: "https://cdn.atomicatpages.net/cdn/s2.js?id=_a49f656b-9802-448e-909f-ce2bf4de872e&cache=" + Math.floor((Math.random() * 100000)),async: true}));</script>
		
        </div>

<p class="mb-3">Did you know <strong>Joan Ginther</strong>, a math teacher, won the lottery <strong>four times</strong> between 1993 and 2010 — totaling about <strong>$20.4 million</strong> in prizes?</p><p class="mb-3">Or that <strong>Richard Lustig</strong> became known for winning <strong>seven lottery prizes</strong>, totaling more than <strong>$1 million</strong>, by betting on <em>high-probability number sequences</em>?</p><p class="mb-3">And the legendary <strong>Stefan Mandel</strong>, world-famous for multiple wins using <em>combinatorial methods and coverage strategies</em> that reduce the luck factor?</p><p class="mb-0">More recently, on <strong>September 8, 2025</strong>, <strong>Carrie Edwards</strong> surprised everyone by winning <strong>$150,000</strong> in Powerball after asking <strong>ChatGPT</strong> to help pick her numbers.</p></div></div></div>

<!-- Carrossel de Provas Sociais --><div class="row"><div class="col-12"><div id="carouselProof" class="carousel pointer-event slide" data-bs-ride="carousel"><div class="rounded-4 carousel-inner overflow-hidden"><div class="carousel-item"><img src="https://media.atomicatpages.net/u/sjXlMxgXXoZa4DhOJ2sviTw2U903/Pictures/4zkr5/SitbMz9743494.jpeg?text=Joan+Ginther" class="d-block w-100" alt="Joan Ginther"><div class="text-start d-none d-md-block carousel-caption" style="padding:10px"><span class="badge badge-soft">4 wins • $20.4M</span><h5 class="mt-2">Joan Ginther</h5><p class="small">Math teacher who won four times between 1993 and 2010.</p></div></div><div class="carousel-item"><img src="https://media.atomicatpages.net/u/sjXlMxgXXoZa4DhOJ2sviTw2U903/Pictures/4zkr5/XdaDDM0065759.jpeg?text=Richard+Lustig" class="w-100 d-block" alt="Richard Lustig"><div class="d-none text-start d-md-block carousel-caption" style="padding:10px"><span class="badge-soft badge">7 prizes • $1M+</span><h5 class="mt-2">Richard Lustig</h5><p class="small">Became known for betting on high-probability sequences.</p></div></div><div class="carousel-item active carousel-item-start"><img src="https://media.atomicatpages.net/u/sjXlMxgXXoZa4DhOJ2sviTw2U903/Pictures/4zkr5/bpRSMN0274707.jpeg?text=Stefan+Mandel" class="d-block w-100" alt="Stefan Mandel"><div class="text-start carousel-caption d-none d-md-block" style="padding:10px"><span class="badge badge-soft">Multiple wins</span><h5 class="mt-2">Stefan Mandel</h5><p class="small">World-famous case for combinatorial methods and coverage.</p></div></div><div class="carousel-item carousel-item-next carousel-item-start"><img src="https://media.atomicatpages.net/u/sjXlMxgXXoZa4DhOJ2sviTw2U903/Pictures/4zkr5/nMzoYn0398011.jpeg?text=Carrie+Edwards" class="d-block w-100" alt="Carrie Edwards"><div class="text-start d-md-block d-none carousel-caption" style="padding:10px"><span class="badge badge-soft">Powerball • $150K</span><h5 class="mt-2">Carrie Edwards</h5><p class="small">Won on 09/08/2025 after asking ChatGPT to help pick numbers.</p></div></div></div><button class="carousel-control-prev" type="button" data-bs-target="#carouselProof" data-bs-slide="prev"><span class="carousel-control-prev-icon" aria-hidden="true"></span><span class="visually-hidden">Previous</span></button> <button class="carousel-control-next" type="button" data-bs-target="#carouselProof" data-bs-slide="next"><span class="carousel-control-next-icon" aria-hidden="true"></span><span class="visually-hidden">Next</span></button></div></div></div>


<div class="mt-5 row"><div class="col-lg-8 mx-auto"><div class="mb-4 divider"></div><h5 class="text-center ">Coincidence… or <strong>logic behind chance</strong>? Learn how to use real data to play consciously.</h5></div></div></div></section> */ ?>


<section><div class="bonus-alert mt-3">
  <h2><strong>🎉 Congratulations!</strong> </h2><p>You’ve just unlocked <b>2 exclusive bonuses</b> along with your <b>Exclusive Ebook</b>.</p><br>
  <span>Check them out below!</span>
</div></section>



  <?php /*
  <section id="science" class="section-pad text-left mt-4 mb-4">
      <div class="container"><div class="align-items-center g-4 row"><div class="col-lg-6"><div class="badge fs-6 badge-soft-danger bg-danger fw-semibold">How it works</div><h2 class="fw-bold mb-3">Powerball Secrets Are in the Data.</h2><p>We analyzed <strong>15 years</strong> of results and identified frequencies, resonances, and combinations that repeat cyclically. The <strong>JackpotBlueprint.com</strong> platform shows you what most eyes can’t see.</p><ul class="list-unstyled mt-3"><li class="mb-2 d-flex gap-3"><i class="bi-thermometer-high text-danger bi fs-4"></i><div><strong>Hot/Cold Numbers</strong> • See the most and least drawn.</div></li><li class="mb-2 d-flex gap-3"><i class="fs-4 bi-graph-up-arrow bi"></i><div><strong>Frequencies &amp; Patterns</strong> • Detect repetitions and trends.</div></li><li class="gap-3 mb-2 d-flex"><i class="bi-shuffle fs-4 bi"></i><div><strong>Smart Simulator</strong> • Test combinations with logic.</div></li><li class="d-flex gap-3 mb-2"><i class="bi bi-broadcast fs-4"></i><div><strong>Statistical Resonance</strong> • Score promising combinations.</div></li><li class="gap-3 d-flex"><i class="bi-clock-history fs-4 bi"></i><div><strong>Complete History</strong> • 15 years of Powerball for reverse engineering.</div></li></ul></div><div class="col-lg-6"><img class="rounded-4 shadow img-fluid" src="https://media.atomicatpages.net/u/sjXlMxgXXoZa4DhOJ2sviTw2U903/Pictures/4zkr5/EAnJHa1493085.jpeg?text=Analytics+Dashboard" alt="Analytics Dashboard"></div></div></div></section>
      */ ?>
      
  <section><div class="atomicat-container-360bf9f a-b-o-cont"><!-- container - 4g2y8v --><div class="a-cont-f-w a-o-cont"><div class="a-cont a-i-cont"><div class="a-b-o-cont a-u-2 atomicat-container-ed2ca56 a-s-d-0r0ihh" data-hex="Za4DhOJ2s"><!-- container - fbbfkk --><div class=""><div class="a-cont a-i-cont a-s-d-h894pe"><div class="a-r a-c-cont-b006f7a a-c-cont"><style>.a-e-cont.atomicat-heading-title-b006f7a p:hover{-webkit-text-fill-color:unset}.a-e-cont.atomicat-heading-title-b006f7a p{color:#000;font-size:48px;line-height:36px;text-align:center;font-weight:700}@media screen and (max-width:480px){.a-e-cont.atomicat-heading-title-b006f7a p{font-size:36px;font-weight:700}}.a-c-cont-b006f7a>.a-e-cont{margin-top:30px;margin-bottom:0}@media screen and (max-width:480px){.a-c-cont-b006f7a>.a-e-cont{margin-top:50px}}</style><div class="atomicat-text atomicat-heading-title-b006f7a a-e-cont atomicat-text-b006f7a atomicat-element-container-b006f7a a-r"><!-- text - pby56c --><p><span style="color:#b92020"></span></p></div></div><div class="a-r a-c-cont a-s-d-gyfeed a-c-cont-4b7acd1"><style>.a-e-cont.atomicat-heading-title-4b7acd1 p:hover{-webkit-text-fill-color:unset}.a-e-cont.atomicat-heading-title-4b7acd1 p{color:#0f1a45;font-size:24px;line-height:36px;text-align:center;font-weight:700;background-image:unset}@media screen and (max-width:480px){.a-e-cont.atomicat-heading-title-4b7acd1 p{font-size:24px;font-weight:500}}.a-c-cont-4b7acd1>.a-e-cont{margin-top:30px;margin-bottom:0}</style><div class="a-e-cont a-r atomicat-text atomicat-element-container-4b7acd1 atomicat-heading-title-4b7acd1 atomicat-text-4b7acd1"></div></div>
  
  <div class="a-s-d-gs5hni atomicat-hidden-mobile a-b-o-cont atomicat-container-d765d12" style="padding:30px 20px; border:2px solid #b92020; border-radius:16px; background:#fff; box-shadow:0 8px 25px rgba(0,0,0,.15); max-width:680px; margin:0 auto;">
      <!-- container - 4hteiq --><style>.atomicat-container-d765d12>.a-o-cont{padding-top:30px;padding-right:30px;padding-bottom:30px;padding-left:30px}.atomicat-container-d765d12>.a-o-cont>.a-cont{flex-direction:column;justify-content:flex-start}.a-b-cont .atomicat-container-d765d12.a-b-o-cont{margin-bottom:0;margin-right:0;margin-top:0;margin-left:0;background:0 0;background-size:cover;background-repeat:no-repeat;background-position:center;width:100%}@media screen and (max-width:480px){.a-b-cont .atomicat-container-d765d12.a-b-o-cont{background:0 0;width:100%}}</style><div class="a-o-cont a-cont-b a-s-d-q8ukcg"><div class="a-cont a-s-d-79o7as a-i-cont"><div class="a-c-cont-0a4d91e a-r a-c-cont a-s-d-fec9no"><style>.a-e-cont.atomicat-heading-title-0a4d91e h4:hover{-webkit-text-fill-color:unset}.a-e-cont.atomicat-heading-title-0a4d91e h4{color:#b92020;font-weight:600;font-family:"Space Grotesk",sans-serif;font-size:24px;background-image:unset;text-align:center}@media screen and (max-width:480px){.a-e-cont.atomicat-heading-title-0a4d91e h4{font-size:24px;text-align:center}}</style><div class="a-r atomicat-heading-title atomicat-element-container-0a4d91e atomicat-heading-title-0a4d91e atomicat-text-0a4d91e a-e-cont"><h4>EXCLUSIVE EBOOK</h4></div></div><div class="a-c-cont-d7e49e2 a-r a-c-cont"><div class="text-center"><!-- text - 2bqvht --><h3>SECRETS OF</h3></div></div><div class="a-r a-c-cont-2b97f37 a-s-d-e0l5rl a-c-cont"><style>@media screen and (max-width:480px){.a-c-cont-2b97f37>.a-e-cont{margin-top:-10px}}.a-e-a-2b97f37{animation:atomicat-animation-pulse 2s 1 linear}@media screen and (max-width:480px){.a-e-a-2b97f37{animation:none}}</style><div class="atomicat-infinite-entrance-animation atomicat-html a-html atomicat-element-container-2b97f37 a-html-2b97f37 a-e-cont a-r a-e-a-2b97f37" style="opacity: 1;"><!-- html - kcmm10 --><div class="a-i-e-cont"><style>.balls{display:flex;align-items:center;justify-content:center;gap:14px;margin:8px 0 18px}.ball{width:64px;height:64px;border-radius:50%;display:flex;align-items:center;justify-content:center;font:800 22px/1.1 ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,"Helvetica Neue",Arial,"Noto Sans","Apple Color Emoji","Segoe UI Emoji";color:#fff;background:#d92226;box-shadow:inset 0 3px 10px rgba(0,0,0,.2),inset 0 -2px 5px rgba(0,0,0,.1),0 0 10px rgba(217,34,38,.7);position:relative;animation:pulse 2.5s ease-in-out infinite}@keyframes pulse{0%,100%{box-shadow:inset 0 3px 10px rgba(0,0,0,.2),inset 0 -2px 5px rgba(0,0,0,.1),0 0 10px rgba(217,34,38,.6);transform:scale(1)}50%{box-shadow:inset 0 3px 10px rgba(0,0,0,.2),inset 0 -2px 5px rgba(0,0,0,.1),0 0 20px rgba(217,34,38,.9);transform:scale(1.05)}}@media (max-width:480px){.ball{width:54px;height:54px;font-size:20px}.balls{gap:10px}}.ballp{color:#fff}</style><div class="balls"><div class="ball">P</div><div class="ball">O</div><div class="ball">W</div><div class="ball">E</div><div class="ball">R</div><div><div class="ballp" style="color:#000">B</div><div class="ballp" style="color:#000">A</div><div class="ballp" style="color:#000">L</div><div class="ballp" style="color:#000">L</div></div></div></div></div></div><div class="a-c-cont a-r a-s-d-dlqgrw a-c-cont-b9408a7"><div class="text-center"><!-- text - nr0atl --><h3 style="
    margin-top: -30px;
    text-align: center;
">DECODED!</h3>

<div class="a-r a-s-d-g6tjwe a-c-cont a-c-cont-fddf407 a-c-f-w"><div class="text-center mb-5"><img loading="eager" fetchpriority="auto" decoding="async" width="" height="" src="https://media.atomicatpages.net/u/sjXlMxgXXoZa4DhOJ2sviTw2U903/Pictures/4zkr5/REAXSC4220621.png?width=300&height=160&quality=80#756454" alt="" sizes=""></div></div>

<p>Access to an exclusive advanced analysis,
with 49 pages that reveals
<b>patterns and trends</b> from nearly <b>2,000 winning games</b>
insights most players can’t even imagine—giving you a smarter way to increase your chances.</p>
<p style="margin:0 0 6px;font-size: 1.5rem;color:#111;">
    From <span style="color:#b92020; font-weight:700;"><s>$24.90</s></span>
  </p>
  </div></div></div></div></div>

<div class="a-r a-s-d-kh20dd a-c-cont-e267e1f a-c-cont"><div class="text-center" style="
    font-size: 60px;
    font-weight: 900;
"><!-- text - yy7bi2 --><p>+</p></div></div>


<div class="a-c-cont a-r a-c-cont-e1284a5 a-s-d-hazmzy" style="padding:30px 20px; border:2px solid #b92020; border-radius:16px; background:#fff; box-shadow:0 8px 25px rgba(0,0,0,.15); max-width:680px; margin:0 auto;"><div class="text-center"><h4 style="margin:0 0 6px; font-size:2.05rem;">
    🎯 Bonus 1: Full Powerball Number Ranking Report
  </h4>
  <p style="margin:0; font-size:1.25rem; line-height:1.4;">
    A complete ranking of the white and red numbers that have appeared the most in the historical draw series from <strong>2010 to 2025</strong>.
  </p>
<div class="a-r a-s-d-g6tjwe a-c-cont a-c-cont-fddf407 a-c-f-w"><div class="text-center mb-5"><img loading="eager" fetchpriority="auto" decoding="async" width="" height="" src="https://media.atomicatpages.net/u/sjXlMxgXXoZa4DhOJ2sviTw2U903/Pictures/4zkr5/HjDDAj3399610.png?width=300&quality=80#756454" alt="" sizes=""></div></div>
<p style="margin:0 0 6px;font-size: 1.5rem;color:#111;">
    From <span style="color:#b92020; font-weight:700;"><s>$15.90</s></span>
  </p>
</div></div>

<div class="a-r a-s-d-kh20dd a-c-cont-e267e1f a-c-cont"><div class="text-center" style="
    font-size: 60px;
    font-weight: 900;
"><!-- text - yy7bi2 --><p>+</p></div>

<div class="a-b-o-cont atomicat-container-a50486d"><!-- container - aplag9 --><div class="">
    <div class="bonus-offer-container text-center" style="padding:30px 20px; border:2px solid #b92020; border-radius:16px; background:#fff; box-shadow:0 8px 25px rgba(0,0,0,.15); max-width:680px; margin:0 auto;">

  <!-- HEADLINE -->
  <h2 style="color:#b92020; font-weight:900; font-size:32px; text-transform:uppercase; margin-bottom:10px;">
    🎯 Bonus 2: Top 20 Winning Ticket Patterns
  </h2>

  <p style="font-size:17px; font-weight:600; color:#111; margin-top:-4px; margin-bottom:18px;">
    Most Rewarded Games in 15 Years of Powerball
  </p>

  <!-- MAIN IMAGE -->
  <div class="text-center mb-3">
    
    <img 
      loading="eager" 
      decoding="async"
      src="https://media.atomicatpages.net/u/sjXlMxgXXoZa4DhOJ2sviTw2U903/Pictures/4zkr5/rBjMLb8764744.png?width=768&height=409&quality=88#357243"
      style="max-width:420px; width:100%; border-radius:12px;"
      alt="Bonus Image">
  </div>

  <!-- LOGO -->


  <!-- DESCRIPTION -->
  <p style="margin:0; font-size:1.25rem; line-height:1.4;">
    Imagine repeating the same number sequence for 15 years…
We analyzed pure statistics to identify the most rewarded Powerball sequences over time — and listed them for you. Check it out!
  </p>

  <!-- EXCLUSIVITY BAR 
  <div style="margin-top:20px; padding:10px 14px; border-radius:10px; background:#ffe5e5; border:1px solid #b92020;">
    <strong style="color:#b92020;">Exclusive Offer:</strong> This Annual free access is available only during this special promotion.
  </div>
  -->




  <div class="a-s-d-r8yweo a-c-cont-884b0d8 a-c-cont a-r"><div class="a-e-cont atomicat-element-container-884b0d8 a-html a-html-884b0d8 a-s-d-tiyyin atomicat-html a-r"><div class="a-i-e-cont">
            <ul class="" style="max-width:520px;list-style: none;text-align:left;font-size: 0.95em;"><li class="mb-2 gap-3 d-flex"><i class="bi-check2-circle text-success bi"></i> Ready-to-use templates</li>
<li class="mb-2 gap-3 d-flex"><i class="bi-check2-circle text-success bi"></i> Each pattern explained</li><li class="mb-2 d-flex gap-3"><i class="bi text-success bi-check2-circle"></i> Historical examples of when they hit</li><li class="gap-3 d-flex"><i class="bi-check2-circle bi text-success"></i> How often they repeat</li><li class="gap-3 d-flex"><i class="text-success bi-check2-circle bi"></i> The “probability behavior” behind each one</li></ul>
<p style="margin:0 0 6px;font-size: 1.5rem;color:#111;">
    From <span style="color:#b92020; font-weight:700;"><s>$25.00</s></span>
  </p>
</div>
</div></div>

</div>

        
        </div></div>
        
        
        <div class="final-offer-box" style="
  max-width:720px;
  margin:30px auto 10px;
  padding:24px 20px 22px;
  border-radius:18px;
  background:#ffffff;
  border:2px solid #e80a2e;
  box-shadow:0 14px 40px rgba(0,0,0,.12);
  text-align:center;
  font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
">
  <!-- Linha 1: âncora de preço antigo -->
  <p style="margin:0 0 6px; font-size:1rem; color:#111;">
    All  From <span style="color:#b92020; font-weight:700;"><s>$75.00</s></span>
  </p>

  <!-- Linha 2: texto de transição -->
  <p style="margin:0 0 8px; font-size:1rem; color:#111;">
    now only
  </p>

  <!-- Preço com animação de destaque -->
  <div style="margin-bottom:4px;">
    <span class="final-price" style="
      display:inline-block;
      font-size:4.0rem;
      line-height:1;
      font-weight:900;
      color:#e80a2e;
      text-shadow:0 0 14px rgba(232,10,46,.3);
      animation: finalPulse 2.2s ease-in-out infinite;
    ">
      $9.97
    </span>
  </div>

  <!-- Economia/benefício -->
  <p style="margin:0 0 10px; font-size:1rem; color:#b21111; font-weight:700;">
    SAVE <strong>86%</strong> TODAY!
  </p><Br><Br>

<div class="text-center"><style>@keyframes atomicat-animation-pulseShadow-8ea1ca29{0%{box-shadow:#a6ff60 0 0 0 0}80%{box-shadow:rgba(0,255,23,0) 0 0 0 12px}}.a-btn-c95d735.a-e-cont .a-btn:hover{background:#7dfbbe;color:#000;animation:1s ease-out 0s infinite normal none running atomicat-animation-pulseShadow-8ea1ca29}.a-btn-c95d735.a-e-cont .a-btn{animation:1s ease-out 0s infinite normal none running atomicat-animation-pulseShadow-8ea1ca29;font-size:48px;padding-bottom:16px;gap:11px;border-top-right-radius:100px;padding-top:16px;border-bottom-left-radius:100px;border-bottom-right-radius:100px;color:#000;border-color:#fbfafa;border-top-left-radius:100px;font-family:Montserrat,sans-serif;width:73%;padding-right:32px;font-weight:600;background:#a6ff60;padding-left:32px}@media screen and (max-width:480px){.a-btn-c95d735.a-e-cont .a-btn{font-size:36px;align-self:center;width:93%}}@media screen and (max-width:480px){.a-btn-c95d735.a-e-cont .a-btn svg{height:14px}}.a-c-cont-c95d735>.a-e-cont{border-top-left-radius:0;margin-bottom:30px;margin-top:30px}@media screen and (max-width:480px){.a-c-cont-c95d735>.a-e-cont{margin-bottom:0;margin-top:30px}}</style><div class="a-btn-c95d735 a-r atomicat-element-container-c95d735 a-btn a-f-c a-s-d-v5dwse a-e-cont atomicat-button"><a href="https://powerball.toplinework.com/buy/secrets-of-powerball-decoded-bonuses?qp=<?php echo (int)$quiz_session_id; ?>" id="btnInitialCheckout" class="btn-glow a-btn a-b-b atomicat-checkout-button" style="text-decoration: none; margin-top:40px; margin-bottom:40px"><span>BUY NOW!<br></span></a></div></div>
  <div class="m-4"><div class="text-center" style="font-size:small"><!-- text - jr1av4 --><p>Protected Privacy | Secure Purchase | Satisfaction Guarantee</p></div></div>
  
  <!-- Copy de fechamento: 3 bônus + plataforma -->
  <p style="margin:6px auto 0; max-width:520px; font-size:0.98rem; color:#222; line-height:1.5;">
    Get <strong>all 2 exclusive bonuses</strong> for a single, one-time payment of just <strong>$9.97</strong>.
    No monthly fees. No hidden charges.
  </p>

  <!-- Gatilho de exclusividade / urgência suave -->
  <p style="margin:10px auto 0; max-width:520px; font-size:0.9rem; color:#555;">
    <strong style="color:#e80a2e;">Exclusive launch deal:</strong>
    this special price is available only for a limited number.
    Once it’s gone, it’s gone.
  </p>
</div>

<!-- Animação do preço -->
<style>
@keyframes finalPulse {
  0%, 100% {
    transform: scale(1);
    text-shadow:0 0 10px rgba(232,10,46,.35);
  }
  50% {
    transform: scale(1.06);
    text-shadow:0 0 22px rgba(232,10,46,.7);
  }
}

/* Responsivo simples */
@media (max-width:480px){
  .final-offer-box{
    margin:20px 10px;
    padding:20px 14px 18px;
  }
  .final-offer-box .final-price{
    font-size:2.5rem !important;
  }
}
</style>




</div></div></div></div></div></div></section>
  
  
  <section id="stories" class="section-pad" style="margin-top:50px"><div class="container"><div class="text-center mb-4"><div class="badge fs-6 badge-soft-danger fw-semibold bg-danger">Real Stories</div><h2 class="fw-bold">Did you know that “luck” can be decoded?</h2><p class="mb-0">Famous examples of people who used math, statistics, and strategy.</p></div><!-- Narrativa --><div class="justify-content-center row"><div class=""><div class="">

<p class="mb-3">Did you know <strong>Joan Ginther</strong>, a math teacher, won the lottery <strong>four times</strong> between 1993 and 2010 — totaling about <strong>$20.4 million</strong> in prizes?</p><p class="mb-3">Or that <strong>Richard Lustig</strong> became known for winning <strong>seven lottery prizes</strong>, totaling more than <strong>$1 million</strong>, by betting on <em>high-probability number sequences</em>?</p><p class="mb-3">And the legendary <strong>Stefan Mandel</strong>, world-famous for multiple wins using <em>combinatorial methods and coverage strategies</em> that reduce the luck factor?</p><p class="mb-0">More recently, on <strong>September 8, 2025</strong>, <strong>Carrie Edwards</strong> surprised everyone by winning <strong>$150,000</strong> in Powerball after asking <strong>ChatGPT</strong> to help pick her numbers.</p></div></div></div>

<!-- Carrossel de Provas Sociais --><div class="row"><div class="col-12"><div id="carouselProof" class="carousel pointer-event slide" data-bs-ride="carousel"><div class="rounded-4 carousel-inner overflow-hidden"><div class="carousel-item"><img src="https://media.atomicatpages.net/u/sjXlMxgXXoZa4DhOJ2sviTw2U903/Pictures/4zkr5/SitbMz9743494.jpeg?text=Joan+Ginther" class="d-block w-100" alt="Joan Ginther"><div class="text-start d-none d-md-block carousel-caption" style="padding:10px"><span class="badge badge-soft">4 wins • $20.4M</span><h5 class="mt-2">Joan Ginther</h5><p class="small">Math teacher who won four times between 1993 and 2010.</p></div></div><div class="carousel-item"><img src="https://media.atomicatpages.net/u/sjXlMxgXXoZa4DhOJ2sviTw2U903/Pictures/4zkr5/XdaDDM0065759.jpeg?text=Richard+Lustig" class="w-100 d-block" alt="Richard Lustig"><div class="d-none text-start d-md-block carousel-caption" style="padding:10px"><span class="badge-soft badge">7 prizes • $1M+</span><h5 class="mt-2">Richard Lustig</h5><p class="small">Became known for betting on high-probability sequences.</p></div></div><div class="carousel-item active carousel-item-start"><img src="https://media.atomicatpages.net/u/sjXlMxgXXoZa4DhOJ2sviTw2U903/Pictures/4zkr5/bpRSMN0274707.jpeg?text=Stefan+Mandel" class="d-block w-100" alt="Stefan Mandel"><div class="text-start carousel-caption d-none d-md-block" style="padding:10px"><span class="badge badge-soft">Multiple wins</span><h5 class="mt-2">Stefan Mandel</h5><p class="small">World-famous case for combinatorial methods and coverage.</p></div></div><div class="carousel-item carousel-item-next carousel-item-start"><img src="https://media.atomicatpages.net/u/sjXlMxgXXoZa4DhOJ2sviTw2U903/Pictures/4zkr5/nMzoYn0398011.jpeg?text=Carrie+Edwards" class="d-block w-100" alt="Carrie Edwards"><div class="text-start d-md-block d-none carousel-caption" style="padding:10px"><span class="badge badge-soft">Powerball • $150K</span><h5 class="mt-2">Carrie Edwards</h5><p class="small">Won on 09/08/2025 after asking ChatGPT to help pick numbers.</p></div></div></div><button class="carousel-control-prev" type="button" data-bs-target="#carouselProof" data-bs-slide="prev"><span class="carousel-control-prev-icon" aria-hidden="true"></span><span class="visually-hidden">Previous</span></button> <button class="carousel-control-next" type="button" data-bs-target="#carouselProof" data-bs-slide="next"><span class="carousel-control-next-icon" aria-hidden="true"></span><span class="visually-hidden">Next</span></button></div></div></div>


<div class="mt-5 row"><div class="col-lg-8 mx-auto"><div class="mb-4 divider"></div><h5 class="text-center ">Coincidence… or <strong>logic behind chance</strong>? Learn how to use real data to play consciously.</h5></div></div></div></section>


  <section id="faq" class="section-pad bg bg-dark text-white m-4 pt-5 pb-5"><div class="container"><div class="text-center mb-4"><div class="bg-danger fw-semibold badge fs-6 badge-soft-danger">Frequently Asked Questions</div><h2 class="fw-bold">Before You Begin</h2></div><div class="row g-4"><div class="col-md-6"><div class="p-4 card-outline h-100 card"><h5><i class="bi bi-shield-check me-2"></i>Does this content promise winnings?</h5><p class="mb-0 text-muted">No. It’s about statistical analysis and education. Play responsibly.</p></div></div><div class="col-md-6"><div class="p-4 card-outline card h-100"><h5><i class="bi me-2 bi-bookmark-check"></i>What do I receive upon purchase?</h5><p class="mb-0 text-muted">The “Secrets of Powerball Decoded” PDF and 1 year of premium access to JackpotBlueprint.</p></div></div><div class="col-md-6"><div class="h-100 card card-outline p-4"><h5><i class="me-2 bi-credit-card-2-front bi"></i>Are there any monthly fees?</h5><p class="text-muted mb-0">No. It’s a one-time payment during the offer period.</p></div></div><div class="col-md-6"><div class="h-100 card p-4 card-outline"><h5><i class="bi-arrow-repeat me-2 bi"></i>Can I request a refund?</h5><p class="text-muted mb-0">Yes, you have an unconditional 7-day money-back guarantee.</p></div></div></div><div class="row mt-5"><div class="mx-auto col-lg-10"><div class="disclaimer"><p class="mb-2"><strong>Disclaimer:</strong> This material is for informational and educational purposes only. Lotteries involve risk and there is no guarantee of winnings. The examples mentioned (such as Joan Ginther, Richard Lustig, Stefan Mandel, and Carrie Edwards) are publicly reported cases that serve as inspiration regarding the use of methods, statistics, and strategy.</p></div></div></div></div></section>
  
  <section class="text-center bg bg-light text-black m-4 pt-5 pb-5">
    <div class="a-o-cont a-cont-f-w"><div class="a-cont a-i-cont"><div class="a-c-cont-e8192d1 a-r a-c-cont"><div class="atomicat-heading-title atomicat-heading-title-e8192d1 atomicat-text-e8192d1 a-e-cont a-r a-s-d-wlomu2 atomicat-element-container-e8192d1"><h2>7-Day Guarantee</h2></div></div><div class="a-c-cont-2a84288 a-c-f-w a-c-cont a-s-d-6sslez a-r"><div class="a-s-d-mglxmz a-e-cont a-img-ele a-r atomicat-element-container-2a84288 atomicat-image a-img-ele-2a84288 a-e-a-2a84288" style="opacity: 1;"><!-- image - ax7e16 --><img loading="lazy" fetchpriority="auto" decoding="async" width="" height="" src="https://media.atomicatpages.net/u/sjXlMxgXXoZa4DhOJ2sviTw2U903/Pictures/xzqjt/ifYSPn2496400.png?width=300&height=160&quality=70#89107" alt="" srcset="" sizes=""></div></div><div class="a-c-cont-d96e5ac a-r a-c-cont a-s-d-9pve34"><div class="atomicat-text-d96e5ac atomicat-heading-title-d96e5ac a-r atomicat-heading-title a-e-cont atomicat-element-container-d96e5ac"><h3>We believe so strongly in the quality that we offer a 7-day satisfaction guarantee.</h3></div></div><div class="a-c-cont-92626c1 a-r a-c-cont"><div class="atomicat-heading-title a-r atomicat-heading-title-92626c1 atomicat-element-container-92626c1 atomicat-text-92626c1 a-e-cont"><!-- text - reycjt --><h3>Risk-free and completely secure. <strong>Try it out and discover how access to an exclusive advanced analysis.</strong><br></h3></div></div></div></div>
</section>


</div>
        </div>

      </div>
    </div>
  </div>

  <!-- JS libs -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/luxon@3.4.4/build/global/luxon.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/@eonasdan/tempus-dominus@6.7.4/dist/js/tempus-dominus.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/inputmask@5.0.8/dist/inputmask.min.js"></script>
  

  <script>
    if (window.tempusDominus && window.luxon) {
      tempusDominus.extend(window.luxon.DateTime);
    }

    const ENABLE_BG_SHINE = true;

    let quizSessionId = null;
    let CATEGORY = null;
    let QUESTIONS = [];
    let currentQuestionIndex = 0;
    
    let CATEGORY_ID_FOR_PROFILE = 0; // 👈 NOVO
    
    let birthSign = null;
    let birthLifePath = null;
    let birthDateRaw = null;


    let isContactStep = false;
    let userName = '';
    let userEmail = '';
    let userPhone = '';

    const topBackBtn      = document.getElementById('topBackBtn');
    const topProgressCurr = document.getElementById('topProgressCurrent');
    const topProgressTotal= document.getElementById('topProgressTotal');

    let totalSteps = 0; // perguntas + etapa de contato
    
    const stepIndicator   = document.getElementById('stepIndicator');
    const startContainer  = document.getElementById('startContainer');
    const startQuizBtn    = document.getElementById('startQuizBtn');
    const stepTag         = document.getElementById('stepTag');
    const stepTitle       = document.getElementById('stepTitle');
    const stepDescription = document.getElementById('stepDescription');
    const questionsContainer = document.getElementById('questionsContainer');
    const nextStepBtn     = document.getElementById('nextStepBtn');
    const resultContainer = document.getElementById('resultContainer');
    const loadingOverlay  = document.getElementById('loadingOverlay');
    const LOADING_DELAY_MS = <?php echo (int)$loadingDelayMs; ?>;
    const loadingSubtext  = document.getElementById('loadingSubtext');
    const slotRow         = document.getElementById('slotRow');
    const slotSummary     = document.getElementById('slotSummary');
    const celebrationOverlay = document.getElementById('celebrationOverlay');
    const zodiacInfoEl    = document.getElementById('zodiacInfo');

    // Converte [texto] em <span class="qb-highlight">texto</span>
function highlightBrackets(str){
  if(!str) return '';
  return str.replace(/\[(.+?)\]/g, '<span class="qb-highlight">$1</span>');
}

function updateTopProgress(current){
  if (!topProgressCurr || !topProgressTotal) return;
  const safeCurrent = current < 0 ? 0 : current;
  topProgressCurr.textContent = safeCurrent;
}


function signToSlug(sign){
      if(!sign) return null;
      return sign.toLowerCase();
    }

    const ZODIAC_FALLBACK = {
      aries: {
        title: '',
        risk_style: '',
        money_mindset: '',
        play_advice: ''
      }
    };

    function loadZodiacProfile(signSlug){
      if(!signSlug) return;

      const fd = new FormData();
      fd.append('action','get_sign_profile');
      fd.append('sign_slug',signSlug);

      const displayName = userName || 'você';

      return fetch(window.location.pathname,{method:'POST',body:fd})
        .then(r => r.text())
        .then(text => {
          let data;
          try {
            data = JSON.parse(text);
          } catch (e) {
            console.error('JSON parse error:', e, text);
            const dbg = document.createElement('div');
            dbg.className = 'mt-2 alert alert-danger';
            dbg.innerHTML = '<strong>Debug Zodiac:</strong> Invalid JSON response.<br><small>'+text.replace(/</g,'&lt;')+'</small>';
            zodiacInfoEl.appendChild(dbg);
            return;
          }

          if(data.status !== 'ok'){
            console.warn('Zodiac profile not found or error:', data);

            const fb = ZODIAC_FALLBACK[signSlug];
            if (fb) {
              const box = document.createElement('div');
              box.className = 'mt-3';
              box.innerHTML = `
                <h2 class="h2 text-danger text-center mb-2">What is your profile?</h2>
                <p class="mb-1"><strong>${displayName}</strong>, the risk-taking style of ${fb.risk_style}</p>
                <p class="mb-1">${fb.money_mindset}</p>
                <p class="mb-0"><strong>${displayName}</strong>, , i have a suggestion for you: ${fb.play_advice}</p>
              `;
              zodiacInfoEl.appendChild(box);
              return;
            }

            const dbg = document.createElement('div');
            dbg.className = 'mt-2 alert alert-warning';
            dbg.innerHTML = '<strong>Debug Zodiac:</strong> '+ (data.message || 'Unknown error') +
                            '<br><small>sign_slug='+signSlug+'</small>';
            zodiacInfoEl.appendChild(dbg);
            return;
          }

          const box = document.createElement('div');
          box.className = 'mt-3';
          /*box.innerHTML = `
            <h2 class="h2 text-danger text-center mb-2">What is your astrological profile?</h2>
            <p class="mb-1"><strong>${displayName}</strong>, the risk-taking style of ${data.risk_style}</p>
            <p class="mb-1">${data.money_mindset}</p>
            <p class="mb-0"><strong>${displayName}</strong>, i have a suggestion for you: ${data.play_advice}</p>
          `;*/
          zodiacInfoEl.appendChild(box);
        })
        .catch(err=>{
          console.error('Error loading zodiac profile:', err);
          const dbg = document.createElement('div');
          dbg.className = 'mt-2 alert alert-danger';
          dbg.textContent = 'Debug Zodiac: Request failed – see console.';
          zodiacInfoEl.appendChild(dbg);
        });
    }


       function showLoading(show) {
      if (!loadingOverlay) return;
      if (show) {
        loadingOverlay.classList.add('show');
      } else {
        loadingOverlay.classList.remove('show');
      }
    }

    function getCurrentQuestionLoadingConfig() {
      const q = QUESTIONS[currentQuestionIndex];

      // Se não tiver questão atual, usa padrão da categoria
      if (!q) {
        return {
          enabled: true,
          delay: LOADING_DELAY_MS
        };
      }

      // Se a pergunta tiver desativado o loading
      if (q.disable_loading) {
        return { enabled: false, delay: 0 };
      }

      // Se a pergunta tiver delay customizado, usa; senão, categoria
      const delay = (q.loading_delay && q.loading_delay > 0)
        ? q.loading_delay
        : LOADING_DELAY_MS;

      return {
        enabled: true,
        delay
      };
    }

    function setLoadingMode(mode){
      if(!loadingSubtext) return;
      if(mode === 'result'){
        loadingSubtext.textContent = 'Generating your personalized Powerball simulation.';
      }else{
        loadingSubtext.textContent = 'Preparing your next insight.';
      }
    }


    function initQuizSession(){
      const fd = new FormData();
      fd.append('action','init_quiz');
      return fetch(window.location.pathname,{method:'POST',body:fd})
        .then(r=>r.json())
        .then(data=>{
          if(data.status==='ok') quizSessionId=data.quiz_session_id;
        });
    }

    function loadSingleCategory(){
      const fd = new FormData();
      fd.append('action','get_single_category');
      return fetch(window.location.pathname,{method:'POST',body:fd})
        .then(r=>r.json())
        .then(data=>{
          if(data.status==='ok'){
            CATEGORY = data.category;
            QUESTIONS = CATEGORY.questions || [];
            
            // 👇 GUARDA O ID DA CATEGORIA PARA USAR NO get_result
        CATEGORY_ID_FOR_PROFILE = CATEGORY.category_id || 0;

        // (se você tiver lógica de loading por categoria, pode ficar aqui também)
        // CATEGORY_LOADING_DELAY_MS = CATEGORY.loading_delay_ms || CATEGORY_LOADING_DELAY_MS;
        // CATEGORY_LOADING_HTML     = CATEGORY.loading_html || CATEGORY_LOADING_HTML;

            
            // total de passos = número de perguntas + 1 (etapa de contato)
        totalSteps = (QUESTIONS ? QUESTIONS.length : 0) + 1;
        
        if (topProgressTotal) {
          topProgressTotal.textContent = totalSteps;
        }
        
        // começa em 0 até clicar em Start
        updateTopProgress(0);
                    // Defaults de loading vindos da categoria
        const CATEGORY_LOADING_DELAY_MS = CATEGORY.loading_delay_ms || 4000;
        const CATEGORY_LOADING_HTML     = CATEGORY.loading_html || '';
        const DEFAULT_LOADING_HTML = `
          <div class="text-center">
            <div class="spinner-border text-danger" role="status"></div>
            <div class="loading-text">
              <strong>Analyzing your response…</strong>
              <span id="loadingSubtext">Preparing your next insight.</span>
            </div>
          </div>
        `;
          }else{
            alert('Quiz category not found.'); console.error(data);
          }
        });
    }

    function renderCurrentQuestion(){
      const totalQ = QUESTIONS.length;
      const q = QUESTIONS[currentQuestionIndex];
      const humanQ = currentQuestionIndex+1;
      
      updateTopProgress(humanQ);

      stepIndicator.textContent = `${humanQ} of ${totalQ}`;
      stepTag.textContent       = `Q${humanQ}`;
      stepTitle.textContent     = CATEGORY.title || 'Unlock Your Powerball Player Profile';

      if(CATEGORY.description && CATEGORY.description.trim() !== ''){
        stepDescription.textContent = CATEGORY.description;
      }else{
        stepDescription.textContent = 'This quick quiz analyzes how you make lottery decisions — habits, intuition and logic. Each answer sharpens your simulation using real Powerball data.';
      }

      questionsContainer.innerHTML = '';
      resultContainer.classList.add('d-none');
      document.getElementById('quizForm').classList.remove('d-none');

      // Bloco padrão de cada pergunta
      const wrap = document.createElement('div');
wrap.className = 'mb-3 ';

const label = document.createElement('label');
label.className = 'form-label fw-semibold lbquestion';
// aplica destaque nas partes entre [ ]
label.innerHTML = highlightBrackets(q.label);
label.setAttribute('for', q.code);
wrap.appendChild(label);

const type = (q.type || '').toLowerCase();

/* 1) PASSO: se for um passo estático, sai antes de tudo */
if (type === 'static' || type === 'static_html') {
  // usa o label como título (opcional)
  if (q.label && q.label.trim() !== '') {
    label.classList.add('h5');
  }

  const contentDiv = document.createElement('div');
  contentDiv.className = 'qb-static-content';

  const rawHtml = q.options_raw || '';
  if (rawHtml.trim() !== '') {
    contentDiv.innerHTML = rawHtml;
  } else {
    contentDiv.innerHTML = '<p>No content configured for this step.</p>';
  }

  wrap.appendChild(contentDiv);
  questionsContainer.appendChild(wrap);

  if (humanQ === totalQ){
    nextStepBtn.textContent='Continue »';
  }else{
    nextStepBtn.textContent='Next »';
  }
  return;
}

/* 2) PASSO: dica abaixo do label, dependendo do tipo de campo */
let hintText = '';
if (type === 'radio') {
  hintText = 'Please select one option.';
} else if (type === 'checkbox') {
  hintText = 'Please select all that apply.';
}

if (hintText) {
  const hint = document.createElement('p');
  hint.className = 'question-hint';
  hint.textContent = hintText;
  wrap.appendChild(hint);
}

      // TEXT / EMAIL / NUMBER
      if (type === 'text' || type === 'email' || type === 'number') {
        const input = document.createElement('input');
        input.type  = (type === 'email' ? 'email' : (type === 'number' ? 'number' : 'text'));
        input.name  = q.code;
        input.id    = q.code;
        input.className = 'form-control qb-control';
        if(q.required) input.required = true;
        wrap.appendChild(input);

        questionsContainer.appendChild(wrap);
        // fim TEXT/EMAIL/NUMBER

      // TEXTAREA
      } else if (type === 'textarea') {
        const ta = document.createElement('textarea');
        ta.name = q.code;
        ta.id   = q.code;
        ta.rows = 3;
        ta.className = 'form-control qb-control';
        if(q.required) ta.required = true;
        wrap.appendChild(ta);

        questionsContainer.appendChild(wrap);
        // fim TEXTAREA

      // DATE
      } else if (type === 'date') {
        const group = document.createElement('div');
        group.className = 'input-group';
        group.id = `${q.code}-picker`;
        group.setAttribute('data-td-target-input','nearest');
        group.setAttribute('data-td-target-toggle','nearest');

        const input = document.createElement('input');
        input.type = 'text';
        input.id   = q.code;
        input.name = q.code;
        input.className = 'form-control qb-control';
        input.setAttribute('data-td-target', `#${q.code}-picker`);
        input.setAttribute('placeholder', 'MM/DD/YYYY');
        input.setAttribute('autocomplete','off');
        if(q.required) input.required = true;

        const span = document.createElement('span');
        span.className = 'input-group-text';
        span.setAttribute('data-td-target', `#${q.code}-picker`);
        span.setAttribute('data-td-toggle','datetimepicker');
        span.innerHTML = '<i class="bi bi-calendar-date"></i>';

        group.appendChild(input);
        group.appendChild(span);
        wrap.appendChild(group);

        questionsContainer.appendChild(wrap);

        // inicialização do datepicker + validação (mantida)
        setTimeout(() => {
          if (window.Inputmask) {
            Inputmask('99/99/9999').mask(input);
          }

          const picker = new tempusDominus.TempusDominus(group, {
            display: {
              components: {
                calendar: true,
                date: true,
                month: true,
                year: true,
                decades: true,
                clock: false
              },
              buttons: {
                today: true,
                clear: true,
                close: true
              }
            },
            localization: {
              format: "MM/dd/yyyy"
            },
            restrictions: {
              minDate: new Date(1930, 0, 1),
              maxDate: new Date()
            },
            useCurrent: false
          });

          group.addEventListener('click', () => {
            setTimeout(() => {
              const widgets = document.querySelectorAll('.tempus-dominus-widget');
              widgets.forEach(w => {
                if (w.classList.contains('show')) {
                  w.classList.add('td-widget-popup-animate');
                  setTimeout(() => w.classList.remove('td-widget-popup-animate'), 260);
                }
              });
            }, 30);
          });

          function validateDateInput(){
            const val = input.value.trim();
            if(!val){
              input.classList.remove('is-invalid','is-valid');
              return null;
            }
            const parts = val.split('/');
            if(parts.length !== 3){
              input.classList.add('is-invalid');
              input.classList.remove('is-valid');
              return null;
            }
            const mm = parseInt(parts[0],10);
            const dd = parseInt(parts[1],10);
            const yyyy = parseInt(parts[2],10);
            if(isNaN(mm) || isNaN(dd) || isNaN(yyyy)){
              input.classList.add('is-invalid');
              input.classList.remove('is-valid');
              return null;
            }
            if(yyyy < 1930 || yyyy > new Date().getFullYear()){
              input.classList.add('is-invalid');
              input.classList.remove('is-valid');
              return null;
            }
            const dateObj = new Date(yyyy, mm-1, dd);
            if(dateObj.getFullYear() !== yyyy || dateObj.getMonth() !== (mm-1) || dateObj.getDate() !== dd){
              input.classList.add('is-invalid');
              input.classList.remove('is-valid');
              return null;
            }
            if(dateObj > new Date()){
              input.classList.add('is-invalid');
              input.classList.remove('is-valid');
              return null;
            }
            input.classList.remove('is-invalid');
            input.classList.add('is-valid');

            const sign = computeZodiac(mm, dd);
            const life = computeLifePath(yyyy, mm, dd);
            birthSign = sign;
            birthLifePath = life;
            birthDateRaw = val;

            return { mm, dd, yyyy, sign, life };
          }

          input.addEventListener('input', validateDateInput);
          input.addEventListener('blur', validateDateInput);

          group.addEventListener('change.td', () => {
            const dt = picker.dates.lastPossible;
            if(dt){
              const jsDate = dt.toJSDate();
              const mm = jsDate.getMonth()+1;
              const dd = jsDate.getDate();
              const yyyy = jsDate.getFullYear();
              input.value = String(mm).padStart(2,'0') + '/' + String(dd).padStart(2,'0') + '/' + String(yyyy);
              validateDateInput();
            }
          });

        }, 50);

        // fim DATE
        return;

      // RADIO – agora no formato "choice-item"
      } else if (type === 'radio') {
        const opts = q.options || [];

        opts.forEach((opt, idx) => {
          const labelEl = document.createElement('label');
          labelEl.className = 'choice-item';

          const input = document.createElement('input');
          input.type  = 'radio';
          input.name  = q.code;
          input.value = opt.value;
          input.id    = q.code + '_' + idx;
          if (q.required && idx === 0) {
            // required em pelo menos um radio do grupo
            input.required = true;
          }

          const iconClass = opt.icon || 'fa-solid fa-circle-dot';
            const optionText = highlightBrackets(opt.text || '');
            
            labelEl.innerHTML += `
              <div class="choice-main">
                <div class="choice-icon">
                  <i class="${iconClass}"></i>
                </div>
                <div class="choice-text">${optionText}</div>
              </div>
            <div class="choice-indicator choice-indicator--radio">
              <span class="choice-indicator-check">
                <svg viewBox="0 0 24 24">
                  <path d="M20.3 5.3 9 16.6 3.7 11.3 5.1 9.9 9 13.8 18.9 3.9z"></path>
                </svg>
              </span>
            </div>
          `;

          // input precisa ficar como primeiro filho
          labelEl.insertBefore(input, labelEl.firstChild);

          // destaque visual no grupo
          input.addEventListener('change', () => {
            const siblings = wrap.querySelectorAll('.choice-item');
            siblings.forEach(c => c.classList.remove('choice-item--active'));
            if (input.checked) {
              labelEl.classList.add('choice-item--active');
            }
          });

          wrap.appendChild(labelEl);
        });

        questionsContainer.appendChild(wrap);

      // CHECKBOX – agora no formato "choice-item"
      } else if (type === 'checkbox') {
        const opts = q.options || [];

        opts.forEach((opt, idx) => {
          const labelEl = document.createElement('label');
          labelEl.className = 'choice-item';

          const input = document.createElement('input');
          input.type  = 'checkbox';
          input.name  = q.code + '[]';
          input.value = opt.value;
          input.id    = q.code + '_' + idx;

          const iconClass = opt.icon || 'fa-regular fa-square-check';
            const optionText = highlightBrackets(opt.text || '');
            
            labelEl.innerHTML += `
              <div class="choice-main">
                <div class="choice-icon">
                  <i class="${iconClass}"></i>
                </div>
                <div class="choice-text">${optionText}</div>
              </div>
            <div class="choice-indicator choice-indicator--checkbox">
              <span class="choice-indicator-check">
                <svg viewBox="0 0 24 24">
                  <path d="M20.3 5.3 9 16.6 3.7 11.3 5.1 9.9 9 13.8 18.9 3.9z"></path>
                </svg>
              </span>
            </div>
          `;

          labelEl.insertBefore(input, labelEl.firstChild);

          input.addEventListener('change', () => {
            if (input.checked) {
              labelEl.classList.add('choice-item--active');
            } else {
              labelEl.classList.remove('choice-item--active');
            }
          });

          wrap.appendChild(labelEl);
        });

        questionsContainer.appendChild(wrap);

      // DEFAULT – SELECT
      } else {
        const select = document.createElement('select');
        select.name=q.code;
        select.id=q.code;
        select.className='form-select qb-control';
        if(q.required) select.required = true;

        const emptyOpt = document.createElement('option');
        emptyOpt.value = '';
        emptyOpt.textContent = 'Select an option';
        select.appendChild(emptyOpt);

        (q.options || []).forEach(opt=>{
          const o = document.createElement('option');
          o.value = opt.value;
          o.textContent = opt.text;
          select.appendChild(o);
        });
        wrap.appendChild(select);

        questionsContainer.appendChild(wrap);
      }

      // Texto do botão Next / Continue
      if(humanQ === totalQ){
        nextStepBtn.textContent='Continue »';
      }else{
        nextStepBtn.textContent='Next Question »';
      }
    }



    function renderContactStep(){
      isContactStep = true;
      
      updateTopProgress(totalSteps);

      stepIndicator.textContent = 'Final Step';
      stepTag.textContent       = 'Profile';
      stepTitle.textContent     = 'Where can we send your personalized insights?';
      stepDescription.textContent =
        'Enter your details so we can attach this simulation to your player profile. Name and e-mail are required.';

      questionsContainer.innerHTML = '';
      resultContainer.classList.add('d-none');
      document.getElementById('quizForm').classList.remove('d-none');

      const row = document.createElement('div');
      row.className = 'row g-3';

      const colName = document.createElement('div');
      colName.className = 'col-12';
      colName.innerHTML = `
        <section class="py-3">
  <div class="p-3 p-md-4 border rounded-3 bg-light">
    <div class="d-flex align-items-start gap-3">
      <div class="fs-3" aria-hidden="true">🔐</div>

      <div class="flex-grow-1">
        <h3 class="h5 mb-2">Your Analysis Is Almost Ready</h3>
        <p class="mb-3 text-muted">
          We’ll generate:
        </p>

        <ul class="list-unstyled mb-0">
          <li class="d-flex align-items-start gap-2 mb-2">
            <span class="mt-1" aria-hidden="true">✅</span>
            <span><strong>Your player profile</strong></span>
          </li>
          <li class="d-flex align-items-start gap-2 mb-2">
            <span class="mt-1" aria-hidden="true">✅</span>
            <span><strong>Your Life Path Number</strong></span>
          </li>
          <li class="d-flex align-items-start gap-2">
            <span class="mt-1" aria-hidden="true">✅</span>
            <span><strong>Access to Exclusive "Secrets of Powerball Decoded" offer.</span>
          </li>
        </ul>
      </div>
    </div>
  </div>
</section>
<p>Final step to receive your bonuses.<p>
        <label class="form-label fw-semibold" for="user_name">Name *</label>
        <input type="text" id="user_name" name="user_name" class="form-control" placeholder="Your full name" required>
      `;
      row.appendChild(colName);

      const colEmail = document.createElement('div');
      colEmail.className = 'col-12';
      colEmail.innerHTML = `
        <label class="form-label fw-semibold" for="user_email">E-mail *</label>
        <input type="email" id="user_email" name="user_email" class="form-control" placeholder="you@example.com" required>
      `;
      row.appendChild(colEmail);

      const colPhone = document.createElement('div');
      colPhone.className = 'col-12';
      colPhone.innerHTML = `
        <label class="form-label fw-semibold" for="user_phone">Phone (optional)</label>
        <input type="text" id="user_phone" name="user_phone" class="form-control" placeholder="(999) 999-9999">
      `;
      row.appendChild(colPhone);

      questionsContainer.appendChild(row);

      setTimeout(()=>{
        const phoneEl = document.getElementById('user_phone');
        if (window.Inputmask && phoneEl) {
          Inputmask('(999) 999-9999').mask(phoneEl);
        }
      }, 20);

      nextStepBtn.textContent = 'Generate My Results »';
    }

    function computeZodiac(month, day){
      const m = month, d = day;
      if     ((m==3 && d>=21) || (m==4 && d<=19)) return 'Aries';
      else if((m==4 && d>=20) || (m==5 && d<=20)) return 'Taurus';
      else if((m==5 && d>=21) || (m==6 && d<=20)) return 'Gemini';
      else if((m==6 && d>=21) || (m==7 && d<=22)) return 'Cancer';
      else if((m==7 && d>=23) || (m==8 && d<=22)) return 'Leo';
      else if((m==8 && d>=23) || (m==9 && d<=22)) return 'Virgo';
      else if((m==9 && d>=23) || (m==10 && d<=22)) return 'Libra';
      else if((m==10 && d>=23) || (m==11 && d<=21)) return 'Scorpio';
      else if((m==11 && d>=22) || (m==12 && d<=21)) return 'Sagittarius';
      else if((m==12 && d>=22) || (m==1 && d<=19)) return 'Capricorn';
      else if((m==1 && d>=20) || (m==2 && d<=18)) return 'Aquarius';
      else if((m==2 && d>=19) || (m==3 && d<=20)) return 'Pisces';
      return null;
    }

    function computeLifePath(year, month, day){
      function sumDigits(n){
        return n.toString().split('').reduce((acc,cur)=>acc+parseInt(cur,10),0);
      }
      let full = year.toString() + String(month).padStart(2,'0') + String(day).padStart(2,'0');
      let num = sumDigits(full);
      while(num > 9 && num !== 11 && num !== 22 && num !== 33){
        num = sumDigits(num);
      }
      return num;
    }

    function collectCurrentAnswer(){
      const q = QUESTIONS[currentQuestionIndex];
      const type = (q.type || '').toLowerCase();

      // STEP ESTÁTICO: não exige input; só registramos que o usuário viu
      if (type === 'static' || type === 'static_html') {
        const answers = {};
        // Pode ser qualquer flag; só pra gravar algo em pb_quiz_answer
        answers[q.code] = '__STATIC_SHOWN__';
        return answers;
      }

      let value = '';
      let valid = true;
      let el = null;

      if (type === 'radio') {
        el = document.querySelector(`input[name="${q.code}"]:checked`);
        value = el ? el.value : '';

      } else if (type === 'checkbox') {
        const els = document.querySelectorAll(`input[name="${q.code}[]"]:checked`);
        const vals = Array.from(els).map(e => e.value);
        value = vals.join(',');

      } else if (type === 'date') {
        el = document.querySelector(`[name="${q.code}"]`);
        value = el ? el.value : '';

      } else {
        el = document.querySelector(`[name="${q.code}"]`);
        value = el ? el.value : '';
      }

      if(type === 'date'){
        const val = value.trim();
        if(!val){
          valid = !q.required;
          if(q.required && el) el.classList.add('is-invalid');
        }else{
          const parts = val.split('/');
          if(parts.length !== 3){
            valid = false;
          }else{
            const mm = parseInt(parts[0],10);
            const dd = parseInt(parts[1],10);
            const yyyy = parseInt(parts[2],10);
            const dateObj = new Date(yyyy, mm-1, dd);
            if(dateObj.getFullYear() !== yyyy || dateObj.getMonth() !== (mm-1) || dateObj.getDate() !== dd){
              valid = false;
            }
          }
        }
        if(!valid && el){
          el.classList.add('is-invalid');
        }else if(el){
          el.classList.remove('is-invalid');
          el.classList.add('is-valid');
        }

      } else {
        if(q.required){
          if(type === 'checkbox'){
            if(!value){ valid = false; }
          }else if(!value || value.trim()===''){
            valid = false;
          }
        }
        if(!valid && el){
          el.classList.add('is-invalid');
        }else if(el){
          el.classList.remove('is-invalid');
        }
      }

      if(!valid){
        alert('Please answer the question correctly before continuing.');
        return null;
      }

      const answers = {};
      answers[q.code] = value;

      if(type === 'date' && value){
        const parts = value.split('/');
        if(parts.length === 3){
          const mm = parseInt(parts[0],10);
          const dd = parseInt(parts[1],10);
          const yyyy = parseInt(parts[2],10);
          const sign = computeZodiac(mm, dd);
          const life = computeLifePath(yyyy, mm, dd);
          birthSign = sign;
          birthLifePath = life;
          birthDateRaw = value;

          answers[q.code + '_sign']     = sign || '';
          answers[q.code + '_lifepath'] = life ? life.toString() : '';
        }
      }

      return answers;
    }

    function saveStepToServer(stepIndex, answers){
      const fd = new FormData();
      fd.append('action','save_step');
      fd.append('quiz_session_id',quizSessionId);
      fd.append('step',stepIndex+1);
      fd.append('answers_json',JSON.stringify(answers));
      return fetch(window.location.pathname,{method:'POST',body:fd})
        .then(r=>r.json());
    }

    function spinReel(elInner,maxNumber,finalNumber,durationMs){
      const start = performance.now();
      function frame(now){
        const elapsed = now-start;
        if(elapsed<durationMs){
          const rand = Math.floor(Math.random()*maxNumber)+1;
          elInner.textContent = String(rand).padStart(2,'0');
          requestAnimationFrame(frame);
        }else{
          elInner.textContent = String(finalNumber).padStart(2,'0');
          elInner.classList.add('final');
        }
      }
      requestAnimationFrame(frame);
    }

    function requestResultFromServer(){
      const fd = new FormData();
      fd.append('action','get_result');
      fd.append('quiz_session_id',quizSessionId);
      
      // 👇 ESTA LINHA É A CRÍTICA – agora CATEGORY_ID_FOR_PROFILE COM CERTEZA EXISTE
    fd.append('category_id', CATEGORY_ID_FOR_PROFILE || 0);
  
  
      fd.append('birth_date', birthDateRaw || '');
      fd.append('zodiac_sign', birthSign || '');
      fd.append('zodiac_slug', signToSlug(birthSign) || '');
      fd.append('life_path', birthLifePath || '');

      fd.append('user_name',  userName  || '');
      fd.append('user_email', userEmail || '');
      fd.append('user_phone', userPhone || '');

      return fetch(window.location.pathname,{method:'POST',body:fd})
        .then(r=>r.json());
    }
    
    // ---- helpers de numerologia ----
        function sumDigits(n) {
          return String(Math.abs(n)).split('').reduce((a, d) => a + (+d), 0);
        }
        function reduceWithMasters(n) {
          const masters = new Set([11, 22, 33]);
          n = Math.abs(n);
          while (n > 9 && !masters.has(n)) n = sumDigits(n);
          return n;
        }
        
        // Detecta e parseia a data (aceita 'YYYY-MM-DD', 'DD/MM/YYYY' ou 'MM/DD/YYYY')
        function parseBirthDate(birthDateRaw) {
          if (!birthDateRaw) return null;
        
          // ISO
          const iso = /^(\d{4})-(\d{2})-(\d{2})$/;
          const br  = /^(\d{2})\/(\d{2})\/(\d{4})$/; // DD/MM/YYYY
          const us  = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/; // MM/DD/YYYY
        
          let y, m, d;
        
          if (iso.test(birthDateRaw)) {
            const [, yy, mm, dd] = birthDateRaw.match(iso);
            y = +yy; m = +mm; d = +dd;
          } else if (br.test(birthDateRaw)) {
            const [, dd, mm, yy] = birthDateRaw.match(br);
            y = +yy; m = +mm; d = +dd;
          } else if (us.test(birthDateRaw)) {
            const [, mm, dd, yy] = birthDateRaw.match(us);
            y = +yy; m = +mm; d = +dd;
          } else {
            return null; // formato desconhecido
          }
        
          // validação simples
          const dt = new Date(y, m - 1, d);
          if (dt.getFullYear() !== y || (dt.getMonth()+1) !== m || dt.getDate() !== d) return null;
          return { y, m, d };
        }
        
        // Life Path: soma TODOS os dígitos do dia+mes+ano, com números-mestres
        function calcLifePathFromYMD(y, m, d) {
          const total = sumDigits(y) + sumDigits(m) + sumDigits(d);
          return reduceWithMasters(total);
        }

       

    function showResult(whiteBalls,powerball){
      document.getElementById('quizForm').classList.add('d-none');
      resultContainer.classList.remove('d-none');

      slotRow.innerHTML='';
      slotSummary.innerHTML='';
      zodiacInfoEl.innerHTML='';
      zodiacInfoEl.classList.add('d-none');

      whiteBalls.forEach((num,idx)=>{
        const slot=document.createElement('div');
        slot.className='slot slot-white';
        const inner=document.createElement('div');
        inner.className='slot-inner';
        inner.textContent='00';
        slot.appendChild(inner);
        slotRow.appendChild(slot);
        const duration = 1500 + idx*400;
        spinReel(inner,69,num,duration);
      });

      const slotRed=document.createElement('div');
      slotRed.className='slot slot-red';
      const innerRed=document.createElement('div');
      innerRed.className='slot-inner';
      innerRed.textContent='00';
      slotRed.appendChild(innerRed);
      slotRow.appendChild(slotRed);

      const maxDurationWhite = 1500 + (whiteBalls.length-1)*400;
      spinReel(innerRed,26,powerball,maxDurationWhite+600);

      const whiteBox=document.createElement('div');
      whiteBox.innerHTML = `
        <div class="slot-label">White Balls</div>
        <div>${whiteBalls.map(n=>String(n).padStart(2,'0')).join(' – ')}</div>`;
      const redBox=document.createElement('div');
      redBox.innerHTML = `
        <div class="slot-label">Powerball</div>
        <div>${String(powerball).padStart(2,'0')}</div>`;
      slotSummary.appendChild(whiteBox);
      slotSummary.appendChild(redBox);

      if(birthSign || birthLifePath || birthDateRaw || userName){
        const displayName = userName || 'You';
        
        const LIFE_PATH_MEANINGS = {
  1: {
    title: "Leadership & New Beginnings",
    core:  displayName + ", you have an independent posture, with initiative and a strong drive to be a protagonist and pioneer. You are naturally focused on opening new paths and ready to make bold decisions.",
    love:  "In relationships, you value autonomy. Things work best when your personal space and individuality are respected.",
    strengths: [
      "Strong natural leadership and initiative.",
      "Courage to start new projects from zero.",
      "High level of determination and willpower.",
      "Capacity to inspire others by example.",
      "Ability to make quick, decisive choices."
    ],
    weaknesses: [
      "Tendency toward impatience and impulsiveness.",
      "Difficulty accepting criticism or opposition.",
      "Can come across as domineering or inflexible.",
      "Struggles to ask for help or share control.",
      "Risk of loneliness by insisting on doing everything alone."
    ]
  },

  2: {
    title: "Partnership & Diplomacy",
    core:  displayName + ", you are naturally cooperative, sensitive, and attentive to the people around you. You have a talent for mediating situations and bringing harmony wherever you go.",
    love:  "You seek a stable and affectionate bond, where active listening, emotional support, and reciprocity are truly valued.",
    strengths: [
      "Great diplomacy and conflict-resolution skills.",
      "High sensitivity to others’ needs and emotions.",
      "Strong ability to cooperate and work in teams.",
      "Natural gift for creating harmony and peace.",
      "Loyal, supportive presence in relationships."
    ],
    weaknesses: [
      "Tendency to avoid confrontation at any cost.",
      "Can be overly dependent on others’ approval.",
      "Difficulty setting clear boundaries.",
      "Risk of suppressing personal needs to keep the peace.",
      "Emotional vulnerability to criticism or rejection."
    ]
  },

  3: {
    title: "Expression & Creativity",
    core:  displayName + ", you have a strong gift for communication, charisma, and optimism. Creativity flows easily for you, whether in the arts, social life, or the way you express ideas and feelings.",
    love:  "In love, you tend to be romantic and playful. You need lightness, joy, and mental and emotional stimulation in your relationships.",
    strengths: [
      "Powerful verbal and emotional expression.",
      "Natural creativity in ideas, art, or communication.",
      "Optimistic outlook that uplifts others.",
      "Charismatic social presence and humor.",
      "Ability to connect with many different people."
    ],
    weaknesses: [
      "Tendency to scatter focus and lose discipline.",
      "Difficulty handling criticism of your ideas or art.",
      "Can escape into fun and avoid responsibilities.",
      "Mood can shift quickly between enthusiasm and discouragement.",
      "Risk of superficiality if depth is avoided."
    ]
  },

  4: {
    title: "Structure & Consistency",
    core:  displayName + ", you are disciplined, practical, and focused on building solid foundations. Others see you as reliable and consistent, someone who thinks long term.",
    love:  "In relationships, you value loyalty and commitment. Security, stability, and shared long-term plans are especially important to you.",
    strengths: [
      "Strong sense of responsibility and reliability.",
      "Excellent organizational and planning skills.",
      "Persistence in building solid, long-term results.",
      "Practical mindset for solving concrete problems.",
      "High capacity for work, routine, and consistency."
    ],
    weaknesses: [
      "Tendency toward rigidity and resistance to change.",
      "Can be overly controlling about methods and rules.",
      "Difficulty relaxing and enjoying spontaneity.",
      "Risk of becoming pessimistic or overly cautious.",
      "May struggle to delegate or trust others’ way of doing things."
    ]
  },

  5: {
    title: "Change & Freedom",
    core:  displayName + ", you are versatile, adventurous, and adapt quickly to new situations. You learn by living, moving, exploring, and trying things in real life.",
    love:  "In love, you need dynamism and movement. Your relationships flourish when there is novelty, freedom, and room for authentic self-expression.",
    strengths: [
      "High adaptability to new environments and people.",
      "Love of adventure, travel, and new experiences.",
      "Quick thinking and mental agility.",
      "Ability to reinvent yourself when needed.",
      "Charisma that attracts others to your energy."
    ],
    weaknesses: [
      "Tendency to restlessness and impatience with routine.",
      "Difficulty committing for the long term.",
      "Risk of scattering energy in too many directions.",
      "Impulse to escape when feeling limited or bored.",
      "Can resist responsibility or structure if it feels restrictive."
    ]
  },

  6: {
    title: "Care & Harmony",
    core:  displayName + ", you are naturally responsible and caring, with a strong connection to family and community. You seek balance, harmony, and emotional warmth in your environment.",
    love:  "In relationships, you are affectionate and protective. You prioritize home, trust, and shared values as the basis for a meaningful bond.",
    strengths: [
      "Deep sense of care, protection, and responsibility.",
      "Strong commitment to family and loved ones.",
      "Ability to create warm, welcoming environments.",
      "Talent for mediating conflicts with empathy.",
      "Reliable support in times of difficulty."
    ],
    weaknesses: [
      "Tendency to overprotect or control loved ones.",
      "Can sacrifice too much and neglect self-care.",
      "Difficulty saying no or setting boundaries.",
      "Risk of feeling unappreciated or resentful.",
      "Can worry excessively about others’ problems."
    ]
  },

  7: {
    title: "Analysis & Introspection",
    core:  displayName + ", you have a deep, analytical way of seeing life and a strong inner search for meaning. Study, reflection, and spirituality tend to be important themes in your journey.",
    love:  "For you, intellectual and spiritual connection is essential. You need time alone to recharge, and relationships work best when this is understood and respected.",
    strengths: [
      "Powerful analytical and investigative mind.",
      "Capacity for deep reflection and introspection.",
      "Strong spiritual or philosophical sensitivity.",
      "Ability to see hidden patterns and underlying truths.",
      "Comfort with solitude and inner exploration."
    ],
    weaknesses: [
      "Tendency to isolate and withdraw emotionally.",
      "Can be overly skeptical, critical, or distrustful.",
      "Difficulty sharing feelings openly.",
      "Risk of overthinking and mental overload.",
      "May appear distant or cold to more emotional people."
    ]
  },

  8: {
    title: "Achievement & Power",
    core:  displayName + ", you have a strong sense of ambition, management, and results. You are naturally inclined to organize resources, lead projects, and pursue material and professional success.",
    love:  "In love, you admire competence, focus, and clear goals. You value loyalty and a shared vision of the future with your partner.",
    strengths: [
      "Strong leadership and decision-making abilities.",
      "High ambition, determination, and perseverance.",
      "Capacity to manage resources and organizations.",
      "Natural magnetism for success and influence.",
      "Practical mindset for achieving concrete results."
    ],
    weaknesses: [
      "Tendency to be authoritarian or overly controlling.",
      "Risk of focusing too much on money and status.",
      "Difficulty delegating and trusting others.",
      "Can neglect emotional needs—your own and others’.",
      "Stress and pressure may lead to rigidity and tension."
    ]
  },

  9: {
    title: "Humanitarianism & Closure",
    core:  displayName + ", you have a generous heart, empathy, and a broad vision of life. You are drawn to helping others and often go through important cycles of closure and emotional renewal.",
    love:  "In relationships, you tend to be idealistic and giving. You need a sense of shared purpose and emotional depth in your connections.",
    strengths: [
      "Deep compassion and humanitarian spirit.",
      "Strong sense of justice and global awareness.",
      "Creative and inspiring vision of life.",
      "Ability to forgive and see the big picture.",
      "Generosity in giving time, energy, and support."
    ],
    weaknesses: [
      "Tendency to self-sacrifice and emotional exhaustion.",
      "Difficulty setting limits when helping others.",
      "Can procrastinate or feel overwhelmed by big ideals.",
      "Risk of disillusionment when reality doesn’t match your vision.",
      "May struggle to take care of your own needs first."
    ]
  },

  11: {
    title: "Inspiration & Intuition (Master Number)",
    core:  displayName + ", you carry heightened sensitivity, powerful intuition, and an inspiring vision. Your path often involves creativity, spirituality, and the ability to uplift others with your presence and ideas.",
    love:  "In love, you seek deep, soulful connections. Emotional balance and mutual understanding are crucial for your relationships to truly flourish.",
    strengths: [
      "Very strong intuition and spiritual perception.",
      "Natural ability to inspire and motivate others.",
      "High creativity and visionary thinking.",
      "Deep sensitivity to subtle emotional and energetic signals.",
      "Potential to be a guide, teacher, or healer."
    ],
    weaknesses: [
      "Tendency to emotional overload and anxiety.",
      "Difficulty grounding your visions in practical steps.",
      "Risk of idealizing people or relationships too much.",
      "Can feel misunderstood or overly different from others.",
      "Periods of intense inner conflict or self-doubt."
    ]
  },

  22: {
    title: "Master Builder of Dreams (Master Number)",
    core:  displayName + ", you have the potential to turn big visions into concrete projects. Your organizational ability, practicality, and strategic mind allow you to build on a large scale when you are aligned with your purpose.",
    love:  "In relationships, you value practical partnership. You look for someone who can walk by your side, add to your long-term plans, and help turn shared dreams into reality.",
    strengths: [
      "Exceptional capacity to plan and execute large projects.",
      "Combination of vision and practicality.",
      "Strong leadership in building lasting structures.",
      "Ability to coordinate people and resources effectively.",
      "High sense of responsibility for collective results."
    ],
    weaknesses: [
      "Tendency to take on too much responsibility.",
      "Risk of workaholism and neglect of personal life.",
      "Can be very critical of yourself and others.",
      "Pressure to “succeed big” may generate stress.",
      "Difficulty relaxing and trusting the process."
    ]
  },

  33: {
    title: "Compassionate Service (Master Number)",
    core:  displayName + ", you carry a strong energy of unconditional love, guidance, and healing. You tend to positively influence those around you through support, care, and emotional presence.",
    love:  "In love, you express mature and generous affection. Your challenge is to care deeply without sacrificing yourself too much or losing sight of your own needs.",
    strengths: [
      "Deep capacity for unconditional love and compassion.",
      "Natural gift for teaching, mentoring, and healing.",
      "Ability to comfort and emotionally support many people.",
      "Strong sense of moral and spiritual responsibility.",
      "Inspiring example of service, kindness, and empathy."
    ],
    weaknesses: [
      "Tendency to over-give and forget your own limits.",
      "Risk of emotional burnout by taking on others’ pain.",
      "Difficulty saying no or stepping back when needed.",
      "Can feel guilty when prioritizing yourself.",
      "May attract people who are overly dependent on your help."
    ]
  }
};

        let html = '<div style="text-center">';
        
        // Interpretação curta
          if (birthLifePath != null && LIFE_PATH_MEANINGS[birthLifePath]) {
            const m = LIFE_PATH_MEANINGS[birthLifePath];
             html += '<h3 class="text-center">🎉 Your Player Analysis Is Ready — Unlock the Full Advantage</h3><br><p class="text-center">Date of Birth: <span class="text-danger">' + birthDateRaw + '</span></p><br><br><div class="lucky-seven-wrapper"><div class="lucky-seven" style="margin-top:10px">' + birthLifePath + '</div><h4 class="text-center">'+ displayName + ', ' + birthLifePath + ' is your Life Path Number! What does mean?</h4><br><br><br></div>';
            html += '<div class="text-start" style="max-width:720px;margin:0 auto;">';
           html += '<div class="fw-bold">' + m.title + '</div>';
            html += '<div class="small mb-2">' + m.core + '</div><br><br><br>';
            
            // STRENGTHS
            html += '<div class="mt-2">';
            html += '<div class="fw-semibold text-success mb-1">Strengths</div>';
            html += '<ul style="padding-left:18px; margin:0; list-style: none;">';
            
            m.strengths.forEach(function(item){
              html += `
                <li style="margin-bottom:4px;">
                  <span style="color:#4CAF50; font-weight:bold;">✔</span> 
                  ${item}
                </li>
              `;
            });
            
            html += '</ul>';
            html += '</div>';
            
            // WEAKNESSES
            html += '<div class="mt-3">';
            html += '<div class="fw-semibold text-danger mb-1">Weaknesses</div>';
            html += '<ul style="padding-left:18px; margin:0; list-style: none;">';
            
            m.weaknesses.forEach(function(item){
              html += `
                <li style="margin-bottom:4px;">
                  <span style="color:#d9534f; font-weight:bold;">⚠</span> 
                  ${item}
                </li>
              `;
            });
            
            html += '</ul>';
            html += '</div>';
            html += '</div>';
          }
       
        
        /*if(birthDateRaw){
          html += 'Date of Birth: <span class="text-warning">' + birthDateRaw + '</span><br>';
        }
        if(birthSign){
          html += 'Zodiac Sign: <span class="text-warning">' + birthSign + '</span><br>';
        }*/
        /*if(birthLifePath){
          html += 'Life Path Number (Numerology): <span class="text-warning">' + birthLifePath + '</span>';
        }*/
        html += '<br><h3 class="text-danger">These traits can influence how you relate to risk, intuition and long-term plays.</h3>';
        html += '</div><br><br><br>';

        zodiacInfoEl.innerHTML = html;
        zodiacInfoEl.classList.remove('d-none');

        const slug = signToSlug(birthSign);
        if(slug){
          loadZodiacProfile(slug);
        }
      }

      setTimeout(()=>{ startCelebration(); }, maxDurationWhite + 1200);
    }

    function startCelebration(){
      celebrationOverlay.innerHTML = '';
      celebrationOverlay.classList.add('show');

      const totalBills = 60;
      const totalConf  = 80;

      for(let i=0;i<totalBills;i++){
        const bill = document.createElement('div');
        bill.className='bill';
        bill.textContent='$';
        const startLeft = Math.random()*100;
        const delay     = Math.random()*0.8;
        const duration  = 3 + Math.random()*2;
        const drift     = (Math.random()*200 - 100);

        bill.style.left = startLeft + 'vw';
        bill.style.animationDuration = duration + 's';
        bill.style.animationDelay    = delay + 's';
        bill.style.setProperty('--drift-x', drift + 'px');

        celebrationOverlay.appendChild(bill);
      }

      for(let i=0;i<totalConf;i++){
        const conf = document.createElement('div');
        conf.className='confetti-piece';
        const startLeft = Math.random()*100;
        const delay     = Math.random()*1.2;
        const duration  = 2 + Math.random()*2.5;
        const drift     = (Math.random()*260 - 130);

        conf.style.left = startLeft + 'vw';
        conf.style.animationDuration = duration + 's';
        conf.style.animationDelay    = delay + 's';
        conf.style.setProperty('--drift-x', drift + 'px');

        if(i % 3 === 0) conf.style.left = (10 + Math.random()*15) + 'vw';
        if(i % 3 === 1) conf.style.left = (70 + Math.random()*15) + 'vw';

        celebrationOverlay.appendChild(conf);
      }

      setTimeout(()=>{
        celebrationOverlay.classList.remove('show');
      }, 8000);
    }

       if (startQuizBtn) {
      startQuizBtn.addEventListener('click', function () {
        // Garante que as perguntas já foram carregadas
        if (!CATEGORY || !Array.isArray(QUESTIONS) || QUESTIONS.length === 0) {
          alert('No questions found for this quiz.');
          return;
        }

        // Ocultar container inicial
        
        if (startContainer) {
          startContainer.classList.add('d-none');
        }
        
        /*
        // Ocultar hero
        const hero = document.querySelector('.hero-visuals');
        if (hero) {
          hero.classList.add('fade-out');
          hero.classList.add('reduzir');
        }
        */

        // Subir o card
        /*
        const quizCard = document.querySelector('#quizCard');
        if (quizCard) {
          quizCard.classList.add('quiz-slide-up');
        }*/

        // Rolagem suave pro topo
        setTimeout(() => {
          window.scrollTo({
            top: 0,
            behavior: 'smooth'
          });
        }, 300);

        // Mostrar formulário e renderizar primeira pergunta
        document.getElementById('quizForm').classList.remove('d-none');
        currentQuestionIndex = 0;
        isContactStep = false;
        renderCurrentQuestion();
      });
    }



    nextStepBtn.addEventListener('click', function () {
      const totalQ = QUESTIONS.length;

      // STEP DE CONTATO
      if (isContactStep) {
        const nameEl  = document.getElementById('user_name');
        const emailEl = document.getElementById('user_email');
        const phoneEl = document.getElementById('user_phone');

        const name  = nameEl ? nameEl.value.trim()  : '';
        const email = emailEl ? emailEl.value.trim() : '';
        const phone = phoneEl ? phoneEl.value.trim() : '';

        let valid = true;
        if (!name) {
          valid = false;
          nameEl.classList.add('is-invalid');
        } else {
          nameEl.classList.remove('is-invalid');
        }

        if (!email) {
          valid = false;
          emailEl.classList.add('is-invalid');
        } else {
          emailEl.classList.remove('is-invalid');
        }

        if (!valid) {
          alert('Please fill in your Name and E-mail before continuing.');
          return;
        }

        userName  = name;
        userEmail = email;
        userPhone = phone;

        const answers = {
          user_name: name,
          user_email: email,
          user_phone: phone
        };
        
        // DISPARAR EVENTO CENTRALIZADO DE COMPLETE REGISTRATION
        trackCompleteRegistration({
          source: 'contact_step',
          name: userName || null,
          email: userEmail || null,
          phone: userPhone || null,
          quiz_session_id: quizSessionId || null,
          slug: QUIZ_SLUG || null
        });


        saveStepToServer(totalQ, answers).then(data => {
          if (data.status !== 'ok') {
            console.error(data);
            alert('Error saving your contact details.');
            return;
          }

          setLoadingMode('result');
          showLoading(true);

          const cfg = getCurrentQuestionLoadingConfig();
          const delay = cfg.enabled ? cfg.delay : 0;

          setTimeout(() => {
            requestResultFromServer().then(res => {
              showLoading(false);
              if (res.status === 'ok') {
                showResult(res.white_balls || [], res.powerball);
              } else {
                console.error(res);
                alert('Could not generate the simulation right now.');
              }
            }).catch(err => {
              showLoading(false);
              console.error(err);
              alert('Unexpected error while generating your simulation.');
            });
          }, delay);
        });

        return;
      }

      // PERGUNTAS NORMAIS
      const answers = collectCurrentAnswer();
      if (!answers) return;

      saveStepToServer(currentQuestionIndex, answers).then(data => {
        if (data.status !== 'ok') {
          console.error(data);
          alert('Error saving your answer.');
          return;
        }

        const totalQ = QUESTIONS.length;
        const isLastQuestion = (currentQuestionIndex === totalQ - 1);
        const cfg = getCurrentQuestionLoadingConfig();

        setLoadingMode('next');

        if (cfg.enabled) {
          showLoading(true);

          setTimeout(() => {
            showLoading(false);

            if (!isLastQuestion) {
              currentQuestionIndex++;
              renderCurrentQuestion();
            } else {
              renderContactStep();
            }
          }, cfg.delay);

        } else {
          // sem loading/delay
          if (!isLastQuestion) {
            currentQuestionIndex++;
            renderCurrentQuestion();
          } else {
            renderContactStep();
          }
        }
      });
    });
    
    if (topBackBtn) {
  topBackBtn.addEventListener('click', function(){
    const quizFormEl = document.getElementById('quizForm');
    const quizCard   = document.querySelector('#quizCard');

    // 1) Se está na etapa de contato → volta para a última pergunta
    if (isContactStep) {
      isContactStep = false;
      currentQuestionIndex = Math.max(0, QUESTIONS.length - 1);
      renderCurrentQuestion();
      return;
    }

    // 2) Se está em qualquer pergunta depois da primeira → volta uma
    if (quizFormEl && !quizFormEl.classList.contains('d-none') && currentQuestionIndex > 0) {
      currentQuestionIndex--;
      renderCurrentQuestion();
      return;
    }

    // 3) Se está na primeira pergunta → volta para a tela inicial (hero + botão Start)
    if (quizFormEl && !quizFormEl.classList.contains('d-none') && currentQuestionIndex === 0) {
      quizFormEl.classList.add('d-none');
      resultContainer.classList.add('d-none');

      if (startContainer) {
        startContainer.classList.remove('d-none');
      }

      // traz o hero de volta
      if (hero) {
        hero.classList.remove('fade-out');
        hero.classList.remove('reduzir');
      }

      // retorna o card à posição original
      if (quizCard) {
        quizCard.classList.remove('quiz-slide-up');
      }

      // progresso volta para 0
      updateTopProgress(0);
      return;
    }

    // 4) fallback: se nada acima se aplicar, usa histórico do navegador
    window.history.back();
  });
}


    // Inicialização ao carregar a página
    document.addEventListener('DOMContentLoaded', function () {
      if (ENABLE_BG_SHINE) {
        document.body.classList.add('bg-shine-active');
      }

      // Cria sessão e carrega categoria/perguntas
      Promise.resolve()
        .then(() => initQuizSession())
        .then(() => loadSingleCategory())
        .then(() => {
          if (!CATEGORY || !Array.isArray(QUESTIONS) || QUESTIONS.length === 0) {
            alert('No questions found for this quiz.');
            return;
          }
          // Aqui você poderia, se quiser, já mostrar a primeira pergunta
          // renderCurrentQuestion();
        })
        .catch(err => {
          console.error('Error initializing quiz:', err);
          alert('Could not initialize the quiz.');
        });
    });

  </script>

  <?php
    // Códigos no footer (antes de </body>) específicos da categoria
    if (!empty($CAT_META['inject_footer'])) {
      echo $CAT_META['inject_footer'] . "\n";
    }
  ?>
  
<script>
  // GRAVA O TRAJETO DO USUÁRIO NA PÁGINA. NÃO DELETAR.
  // Agora usamos somente quiz_session_id (pb_quiz_session) + pb_visit_event
  const QUIZ_SESSION_ID = <?php echo (int)$quiz_session_id; ?>;
  const QUIZ_SLUG       = "<?php echo $TARGET_CATEGORY_SLUG; ?>";

  // Função genérica para enviar eventos ao track_event.php
  function sendTrackingEvent(eventType, eventName, extraData) {
    const payload = {
      quiz_session_id: typeof QUIZ_SESSION_ID !== 'undefined' ? QUIZ_SESSION_ID : null,
      event_type: eventType,
      event_name: eventName,
      page_url: window.location.href,
      extra_data: extraData || null
    };

    console.log('[TRACK] sending →', payload);

    // IMPORTANTE: sem /lp/ na frente
    fetch('track_event.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      keepalive: true
    })
      .then(res => res.text())
      .then(text => {
        console.log('[TRACK] response from track_event.php →', text);
      })
      .catch(function (err) {
        console.warn('Tracking error:', err);
      });
  }

  document.addEventListener('DOMContentLoaded', function () {
    // 1) Page view
    sendTrackingEvent('page_view', `quiz_view`);

    // 2) Clique no botão Reveal / Start Quiz
    const revealBtn =
      document.getElementById('revealBtnLucky') ||
      document.getElementById('startQuizBtn')   ||
      document.querySelector('[data-track="reveal_profile"]');

    if (revealBtn) {
      revealBtn.addEventListener('click', function () {
        sendTrackingEvent('click', 'quiz_start', {
          button_id: this.id || null,
          text: this.textContent.trim()
        });
      });
    }

    // 3) Funções CENTRALIZADAS de conversão + Pixel
    (function () {
      let completeRegistrationFired = false;
      let initiateCheckoutFired     = false;

      // COMPLETE REGISTRATION – uso: window.trackCompleteRegistration(...)
      window.trackCompleteRegistration = function (extra) {
        if (completeRegistrationFired) {
          console.warn('CompleteRegistration já foi disparado nesta sessão.');
          return;
        }
        completeRegistrationFired = true;

        let payload = {
          slug: typeof QUIZ_SLUG !== 'undefined' ? QUIZ_SLUG : null,
          timestamp: new Date().toISOString()
        };

        if (extra && typeof extra === 'object') {
          payload = { ...payload, ...extra };
        }

        // 1) tracking interno
        sendTrackingEvent(
          'conversion',
          'quiz_complete_registration',
          payload
        );
        
        window.enqueueAdminChatNotif('quiz_complete_registration', payload);

        // 2) Pixel
        if (window.fbq) {
          fbq('track', 'CompleteRegistration', {
            slug: payload.slug,
            email: payload.email || undefined,
            name: payload.name || undefined,
            source: payload.source || 'contact_step'
          });
        }

        console.log('%c[TRACK] CompleteRegistration fired', 'color: green; font-weight:bold;', payload);
      };

      // INITIATE CHECKOUT – uso: window.trackInitiateCheckout(...)
      window.trackInitiateCheckout = function (extra) {
        if (initiateCheckoutFired) {
          console.warn('InitiateCheckout já foi disparado nesta sessão.');
          return;
        }
        initiateCheckoutFired = true;

        let payload = {
          slug: typeof QUIZ_SLUG !== 'undefined' ? QUIZ_SLUG : null,
          timestamp: new Date().toISOString(),
          value: 9.90,
          currency: 'USD',
          source: 'btnInitialCheckout'
        };

        if (extra && typeof extra === 'object') {
          payload = { ...payload, ...extra };
        }

        

        // 2) Pixel do Facebook
        // SE FOR PARA O SISTEMA DA STRIPE, O LINK JA IRA GERAR O INITIATECHECKOUT AUTOMATICO. SE DEIXAR ATIVADO, ENVIA DUPLICADO.
        //POR ISSO EU ALTEREI PARA EVENTO quiz_initiate_checkout. Entendeu?
        if (window.fbq) {
          fbq('track', 'quiz_initiate_checkout', {
            value: payload.value,
            currency: payload.currency,
            slug: payload.slug,
            source: payload.source
          });
        }

        console.log('%c[TRACK] InitiateCheckout fired', 'color: orange; font-weight:bold;', payload);
      };

      // 4) Clique no botão Unlock the Full JackpotBlueprint Experience
      const unlockBtn = document.getElementById('btnInitialCheckout');

      if (unlockBtn) {
        unlockBtn.addEventListener('click', function () {
          // ainda registramos o clique "simples"
          sendTrackingEvent('click', 'InitiateCheckout', {
            button_id: this.id || null,
            text: this.textContent.trim(),
            slug: typeof QUIZ_SLUG !== 'undefined' ? QUIZ_SLUG : null,
            quiz_session_id: typeof QUIZ_SESSION_ID !== 'undefined' ? QUIZ_SESSION_ID : null
          });

          // agora disparamos o InitiateCheckout CENTRALIZADO
          trackInitiateCheckout({
            source: 'btnInitialCheckout',
            button_id: this.id || null,
            text: this.textContent.trim(),
            quiz_session_id: typeof QUIZ_SESSION_ID !== 'undefined' ? QUIZ_SESSION_ID : null
            // se quiser, pode sobrescrever value/currency aqui:
            // value: 9.97,
            // currency: 'USD'
          });
        });
      }

    })(); // fim IIFE centralizadora
  });

  // 5) Evento de saída da página
  window.addEventListener('beforeunload', function () {
    sendTrackingEvent('exit', 'page_exit');
  });
</script>


<script>
  // Mesmo token do /api/pb_notifs_event_enqueue.php
  window.PB_NOTIF_TOKEN = "6)yscAlPdc7Y1oD4DZB=0[<C$KTplT19";

  // Função global: pode chamar de qualquer lugar
  window.enqueueAdminChatNotif = function(eventName, payload) {
    try {
      return fetch('/api/pb_notifs_event_enqueue.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Notif-Token': window.PB_NOTIF_TOKEN
        },
        body: JSON.stringify({
          event_name: eventName,
          payload: payload || {}
        })
      });
    } catch (e) {
      return Promise.resolve(null);
    }
  };
</script>

</body>
</html>
