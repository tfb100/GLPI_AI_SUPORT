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

// Send JSON headers
header('Content-Type: application/json; charset=UTF-8');
Html::header_nocache();

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
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Analisa um ticket
 */
function handleAnalyze()
{
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    
    if ($ticketId <= 0) {
        throw new \InvalidArgumentException('ID do ticket inválido');
    }

    // Verificar se chatbot está habilitado
    if (!ChatbotService::isEnabled()) {
        echo json_encode([
            'success' => false,
            'error' => 'Chatbot não está habilitado. Configure a API key do Gemini.'
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

    try {
        $chatbot = new ChatbotService($ticketId);
        $result = $chatbot->analyzeTicket();
        
        // Garantir que sempre retornamos JSON válido
        if (!is_array($result)) {
            throw new \RuntimeException('Resultado inválido do ChatbotService');
        }
        
        echo json_encode($result);
    } catch (\Exception $e) {
        // Log do erro
        Toolbox::logError('Erro ao analisar ticket #' . $ticketId . ': ' . $e->getMessage());
        
        echo json_encode([
            'success' => false,
            'error' => 'Erro ao analisar chamado: ' . $e->getMessage()
        ]);
    }
}

/**
 * Processa mensagem do chat
 */
function handleChat()
{
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    
    if ($ticketId <= 0) {
        throw new \InvalidArgumentException('ID do ticket inválido');
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

    $chatbot = new ChatbotService($ticketId);
    $result = $chatbot->processMessage($message);
    
    echo json_encode($result);
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

    $chatbot = new ChatbotService(0); // Ticket ID não necessário para feedback
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
    
    $ticketId = (int)($_GET['ticket_id'] ?? 0);
    
    if ($ticketId <= 0) {
        throw new \InvalidArgumentException('ID do ticket inválido');
    }

    // Verificar permissão
    $ticket = new Ticket();
    if (!$ticket->getFromDB($ticketId) || !$ticket->canViewItem()) {
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
            'tickets_id' => $ticketId
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

    echo json_encode([
        'success' => true,
        'history' => $history
    ]);
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

    $gemini = new \Glpi\AI\GeminiClient();
    
    if (!$gemini->isConfigured()) {
        echo json_encode([
            'success' => false,
            'error' => 'API key não configurada'
        ]);
        return;
    }

    $success = $gemini->testConnection();
    
    echo json_encode([
        'success' => $success,
        'message' => $success ? 'Conexão bem-sucedida!' : 'Falha na conexão. Verifique a API key.'
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
