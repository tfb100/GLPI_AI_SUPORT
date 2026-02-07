<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI - Gestionnaire Libre de Parc Informatique
 *
 * http://glpi-project.org
 *
 * @copyright 2015-2025 Teclib' and contributors.
 * @licence   https://www.gnu.org/licenses/gpl-3.0.html
 *
 * ---------------------------------------------------------------------
 */

use Glpi\AI\ChatbotService;

$AJAX_INCLUDE = 1;
include ('../inc/includes.php');

global $CFG_GLPI;

// Optional: Custom logging based on config
function chatbotLog(string $message) {
    global $CFG_GLPI;
    if (!empty($CFG_GLPI['chatbot_debug'])) {
        Toolbox::logInFile('chatbot', date('[Y-m-d H:i:s] ') . $message . "\n");
    }
}

chatbotLog("AJAX Request: " . ($_REQUEST['action'] ?? 'no action'));

// Send JSON headers
header('Content-Type: application/json; charset=UTF-8');
Html::header_nocache();

// Prevent warnings from corrupting JSON
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

Session::checkLoginUser();

// Ensure CSRF protection
if (isset($_POST['action'])) {
    Session::checkCSRF($_REQUEST);
}

$action = $_REQUEST['action'] ?? '';

try {
    switch ($action) {
        case 'analyze':
            handleAnalyze();
            break;
            
        case 'chat':
            handleChat();
            break;
            
        case 'feedback':
            handleFeedback();
            break;
            
        case 'history':
            handleHistory();
            break;
            
        case 'test_connection':
            handleTestConnection();
            break;
            
        default:
            throw new \InvalidArgumentException('Ação inválida');
    }
} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode(ChatbotService::fixUtf8Recursive([
        'success' => false,
        'error' => $e->getMessage()
    ]));
}

/**
 * Analisa um item (Ticket ou Problema)
 */
function handleAnalyze()
{
    $itemId = (int)($_POST['item_id'] ?? ($_POST['ticket_id'] ?? 0));
    $itemType = $_POST['item_type'] ?? 'Ticket';
    
    // Validate Item Type
    if (!in_array($itemType, ['Ticket', 'Problem'])) {
         throw new \InvalidArgumentException('Tipo de item inválido');
    }

    if ($itemId <= 0) {
        throw new \InvalidArgumentException("ID do {$itemType} inválido");
    }

    // Verificar se chatbot está habilitado
    if (!ChatbotService::isEnabled()) {
        $provider = $_POST['provider'] ?? 'AI';
        echo json_encode([
            'success' => false,
            'error' => "Chatbot ({$provider}) não está habilitado. Verifique as configurações no arquivo config_chatbot.php."
        ]);
        return;
    }

    // Rate limiting - máximo 10 análises por minuto por usuário
    if (!checkRateLimit('analyze', 10, 60)) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'error' => 'Muitas requisições. Aguarde um momento.'
        ]);
        return;
    }

    $provider = $_POST['provider'] ?? null;

    try {
        $chatbot = new ChatbotService($itemId, null, $provider, $itemType);
        $result = $chatbot->analyzeItem();
        
        // Garantir que sempre retornamos JSON válido
        if (!is_array($result)) {
            throw new \RuntimeException('Resultado inválido do ChatbotService');
        }
        
        $json = json_encode(ChatbotService::fixUtf8Recursive($result));
        if ($json === false) {
             echo json_encode(ChatbotService::fixUtf8Recursive([
                 'success' => false,
                 'error' => 'Erro interno ao codificar resposta (JSON error: ' . json_last_error_msg() . ')'
             ]));
        } else {
             echo $json;
        }
    } catch (\Exception $e) {
        // Log do erro
        Toolbox::logError("Erro ao analisar {$itemType} #" . $itemId . ': ' . $e->getMessage());
        
        echo json_encode(ChatbotService::fixUtf8Recursive([
            'success' => false,
            'error' => 'Erro ao analisar chamado: ' . $e->getMessage()
        ]));
    }
}

/**
 * Processa mensagem do chat
 */
function handleChat()
{
    $itemId = (int)($_POST['item_id'] ?? ($_POST['ticket_id'] ?? 0));
    $itemType = $_POST['item_type'] ?? 'Ticket';
    $message = trim($_POST['message'] ?? '');
    
    if ($itemId <= 0) {
        throw new \InvalidArgumentException("ID do {$itemType} inválido");
    }
    
    if (empty($message)) {
        throw new \InvalidArgumentException('Mensagem vazia');
    }

    // Sanitizar entrada
    $message = Html::cleanPostForTextArea($message);

    // Rate limiting
    if (!checkRateLimit('chat', 20, 60)) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'error' => 'Muitas mensagens. Aguarde um momento.'
        ]);
        return;
    }
    
    $provider = $_POST['provider'] ?? null;

    $chatbot = new ChatbotService($itemId, null, $provider, $itemType);
    $result = $chatbot->processMessage($message);
    
    $json = json_encode(ChatbotService::fixUtf8Recursive($result));
    if ($json === false) {
         echo json_encode(ChatbotService::fixUtf8Recursive([
             'success' => false,
             'error' => 'Erro interno ao codificar resposta chat (JSON error: ' . json_last_error_msg() . ')'
         ]));
    } else {
         echo $json;
    }
}

/**
 * Registra feedback
 */
function handleFeedback()
{
    $conversationId = (int)($_POST['conversation_id'] ?? 0);
    $wasHelpful = (bool)($_POST['was_helpful'] ?? false);
    $comment = trim($_POST['comment'] ?? '');
    
    if ($conversationId <= 0) {
        throw new \InvalidArgumentException('ID da conversa inválido');
    }

    $comment = Html::cleanPostForTextArea($comment);

    $chatbot = new ChatbotService(0); // Item ID não necessário para feedback
    $success = $chatbot->saveFeedback($conversationId, $wasHelpful, $comment);
    
    echo json_encode([
        'success' => $success
    ]);
}

/**
 * Obtém histórico do chat
 */
function handleHistory()
{
    global $DB;
    
    $itemId = (int)($_GET['item_id'] ?? ($_GET['ticket_id'] ?? 0));
    $itemType = $_GET['item_type'] ?? 'Ticket';
    
    if ($itemId <= 0) {
        throw new \InvalidArgumentException("ID do {$itemType} inválido");
    }

    // Verificar permissão
    if ($itemType === 'Problem') {
        $item = new \Problem();
    } else {
        $item = new \Ticket();
    }

    if (!$item->getFromDB($itemId) || !$item->canViewItem()) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Sem permissão'
        ]);
        return;
    }

    $iterator = $DB->request([
        'FROM' => 'glpi_chatbot_conversations',
        'WHERE' => [
            'items_id' => $itemId,
            'item_type' => $itemType
        ],
        'ORDER' => 'date_creation ASC'
    ]);

    $history = [];
    foreach ($iterator as $data) {
        $history[] = [
            'id' => $data['id'],
            'message' => $data['message'],
            'is_bot' => (bool)$data['is_bot'],
            'suggested_kb_items' => json_decode($data['suggested_kb_items'] ?? '[]', true),
            'date' => $data['date_creation']
        ];
    }

    echo json_encode(ChatbotService::fixUtf8Recursive([
        'success' => true,
        'history' => $history
    ]));
}

/**
 * Testa conexão com Gemini API
 */
function handleTestConnection()
{
    if (!Session::haveRight('config', UPDATE)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Sem permissão'
        ]);
        return;
    }
    
    global $CFG_GLPI;
    $provider = $CFG_GLPI['chatbot_provider'] ?? 'gemini';
    $client = null;
    
    if ($provider === 'ollama') {
        $client = new \Glpi\AI\OllamaClient();
    } else {
        $client = new \Glpi\AI\GeminiClient();
    }
    
    if (!$client->isConfigured()) {
        echo json_encode([
            'success' => false,
            'error' => 'Provedor (' . ucfirst($provider) . ') não configurado corretamente'
        ]);
        return;
    }

    $success = $client->testConnection();
    
    echo json_encode([
        'success' => $success,
        'message' => $success ? 'Conexão com ' . ucfirst($provider) . ' bem-sucedida!' : 'Falha na conexão com ' . ucfirst($provider)
    ]);
}

/**
 * Verifica rate limiting
 *
 * @param string $action
 * @param int $maxRequests
 * @param int $timeWindow Janela de tempo em segundos
 * @return bool
 */
function checkRateLimit(string $action, int $maxRequests, int $timeWindow): bool
{
    $userId = Session::getLoginUserID();
    $key = "chatbot_ratelimit_{$action}_{$userId}";
    
    global $GLPI_CACHE;
    if (!$GLPI_CACHE) {
        return true; // Se cache não disponível, permitir
    }

    try {
        $requests = $GLPI_CACHE->get($key) ?: [];
        $now = time();
        
        // Remover requisições antigas
        $requests = array_filter($requests, function($timestamp) use ($now, $timeWindow) {
            return ($now - $timestamp) < $timeWindow;
        });
        
        // Verificar limite
        if (count($requests) >= $maxRequests) {
            return false;
        }
        
        // Adicionar nova requisição
        $requests[] = $now;
        $GLPI_CACHE->set($key, $requests, $timeWindow);
        
        return true;
    } catch (\Exception $e) {
        return true; // Em caso de erro, permitir
    }
}

/**
 * Corrige recursivamente o encoding para UTF-8 válido em arrays ou strings
 */
function fixUtf8Recursive($data)
{
    return ChatbotService::fixUtf8Recursive($data);
}
