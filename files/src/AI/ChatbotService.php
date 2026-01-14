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

namespace Glpi\AI;

use Ticket;
use Session;
use Toolbox;

/**
 * Serviço orquestrador do chatbot
 */
class ChatbotService
{
    /** @var AIClientInterface */
    private $aiClient;

    /** @var TicketAnalyzer */
    private $ticketAnalyzer;

    /** @var KnowledgeBaseSearcher */
    private $kbSearcher;

    /** @var int */
    private $ticketId;

    /** @var int */
    private $userId;

    /**
     * Constructor
     *
     * @param int $ticketId
     * @param int $userId
     * @param string|null $provider 'gemini' or 'ollama' (optional)
     */
    public function __construct(int $ticketId, int $userId = null, string $provider = null)
    {
        global $CFG_GLPI;

        $this->ticketId = $ticketId;
        $this->userId = $userId ?? Session::getLoginUserID();
        
        // If no provider specified, verify if specific preferred provider is set in session or use config
        if (empty($provider)) {
            $provider = $CFG_GLPI['chatbot_provider'] ?? 'gemini';
        }
        
        if ($provider === 'ollama') {
            $this->aiClient = new OllamaClient();
        } else {
            // Default to Gemini
            $this->aiClient = new GeminiClient();
        }

        $this->kbSearcher = new KnowledgeBaseSearcher();
    }

    /**
     * Análise inicial do ticket
     *
     * @return array
     */
    public function analyzeTicket(): array
    {
        $ticket = new Ticket();
        if (!$ticket->getFromDB($this->ticketId)) {
            return [
                'success' => false,
                'error' => 'Ticket não encontrado'
            ];
        }

        // Verificar permissões
        if (!$ticket->canViewItem()) {
            return [
                'success' => false,
                'error' => 'Sem permissão para visualizar este ticket'
            ];
        }

        $this->ticketAnalyzer = new TicketAnalyzer($ticket);
        $context = $this->ticketAnalyzer->extractContext();

        // Buscar FAQs relevantes
        $faqs = $this->kbSearcher->searchCombined(
            $context['keywords'],
            $context['category']['id'] ?? 0,
            5
        );

        // Gerar análise com AI
        $prompt = $this->buildAnalysisPrompt($context, $faqs);
        
        try {
            $aiResponse = $this->aiClient->generateContent($prompt);
            
            // Salvar no histórico
            $this->saveConversation(
                'Análise inicial do ticket',
                false
            );
            
            $this->saveConversation(
                $aiResponse['text'],
                true,
                $faqs
            );

            return [
                'success' => true,
                'analysis' => $aiResponse['text'],
                'suggested_faqs' => $faqs,
                'context' => [
                    'title' => $context['title'],
                    'category' => $context['category']['name'],
                    'priority' => $context['priority'],
                    'symptoms' => $context['symptoms']
                ]
            ];

        } catch (\Exception $e) {
            Toolbox::logError('Erro na análise do ticket: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => 'Erro ao processar análise. Por favor, tente novamente.',
                'suggested_faqs' => $faqs
            ];
        }
    }

    /**
     * Processa mensagem do chat
     *
     * @param string $message
     * @return array
     */
    public function processMessage(string $message): array
    {
        if (empty(trim($message))) {
            return [
                'success' => false,
                'error' => 'Mensagem vazia'
            ];
        }

        // Salvar mensagem do usuário
        $this->saveConversation($message, false);

        // Obter histórico da conversa
        $history = $this->getConversationHistory(10);

        // Buscar FAQs relacionadas à mensagem
        $keywords = $this->extractKeywordsFromMessage($message);
        $faqs = $this->kbSearcher->searchByKeywords($keywords, 3);

        // Construir prompt com contexto
        $prompt = $this->buildChatPrompt($message, $history, $faqs);

        try {
            $aiResponse = $this->aiClient->generateContent($prompt);
            
            // Salvar resposta do bot
            $this->saveConversation(
                $aiResponse['text'],
                true,
                $faqs
            );

            return [
                'success' => true,
                'response' => $aiResponse['text'],
                'suggested_faqs' => $faqs
            ];

        } catch (\Exception $e) {
            Toolbox::logError('Erro ao processar mensagem: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => 'Erro ao processar mensagem. Por favor, tente novamente.',
                'suggested_faqs' => $faqs
            ];
        }
    }

    /**
     * Constrói prompt para análise inicial
     *
     * @param array $context
     * @param array $faqs
     * @return string
     */
    private function buildAnalysisPrompt(array $context, array $faqs): string
    {
        global $CFG_GLPI;
        
        // System prompt personalizado
        $systemPrompt = $CFG_GLPI['chatbot_system_prompt'] ?? 
            "Você é um assistente de suporte técnico especializado em GLPI.";
        
        $prompt = $systemPrompt . "\n\n";
        $prompt .= "# ANÁLISE DE CHAMADO\n\n";
        $prompt .= "## Informações do Chamado\n\n";
        $prompt .= "**Título:** {$context['title']}\n\n";
        $prompt .= "**Descrição:**\n{$context['description']}\n\n";
        $prompt .= "**Categoria:** {$context['category']['name']}\n";
        $prompt .= "**Prioridade:** {$context['priority']}\n";
        $prompt .= "**Status:** {$context['status']}\n\n";
        
        if (!empty($context['symptoms'])) {
            $prompt .= "**Sintomas Detectados:** " . implode(', ', $context['symptoms']) . "\n\n";
        }

        if (!empty($context['keywords'])) {
            $prompt .= "**Palavras-chave:** " . implode(', ', array_slice($context['keywords'], 0, 5)) . "\n\n";
        }

        if (!empty($faqs)) {
            $prompt .= "## Base de Conhecimento Relacionada\n\n";
            foreach ($faqs as $idx => $faq) {
                $prompt .= "### FAQ " . ($idx + 1) . ": {$faq['title']}\n";
                $prompt .= "{$faq['content']}\n\n";
            }
        }

        $prompt .= "## Tarefa\n\n";
        $prompt .= "Forneça uma análise estruturada e profissional:\n\n";
        $prompt .= "1. **Resumo do Problema**: Identifique claramente o problema principal\n";
        $prompt .= "2. **Possíveis Causas**: Liste as causas mais prováveis\n";
        $prompt .= "3. **Soluções Recomendadas**: Forneça soluções práticas (referencie as FAQs quando aplicável)\n";
        $prompt .= "4. **Próximos Passos**: Ações específicas que o técnico deve tomar\n\n";
        $prompt .= "Seja objetivo, técnico e baseado em boas práticas de ITIL.";

        return $prompt;
    }

    /**
     * Constrói prompt para chat
     *
     * @param string $message
     * @param array $history
     * @param array $faqs
     * @return string
     */
    private function buildChatPrompt(string $message, array $history, array $faqs): string
    {
        $prompt = "Você é um assistente de suporte técnico especializado em GLPI.\n\n";
        
        if (!empty($history)) {
            $prompt .= "**Histórico da conversa:**\n";
            foreach (array_slice($history, -5) as $entry) {
                $role = $entry['is_bot'] ? 'Assistente' : 'Usuário';
                $prompt .= "{$role}: {$entry['message']}\n";
            }
            $prompt .= "\n";
        }

        if (!empty($faqs)) {
            $prompt .= "**FAQs relacionadas:**\n";
            foreach ($faqs as $idx => $faq) {
                $prompt .= ($idx + 1) . ". {$faq['title']}\n";
                // Include partial content/excerpt if available
                if (!empty($faq['content'])) {
                    $prompt .= "   Resumo: " . strip_tags($faq['content']) . "\n";
                } elseif (!empty($faq['answer'])) {
                     $prompt .= "   Resumo: " . substr(strip_tags($faq['answer']), 0, 300) . "...\n";
                }
                $prompt .= "\n";
            }
            $prompt .= "\n";
        }

        $prompt .= "**Nova mensagem do usuário:** {$message}\n\n";
        $prompt .= "Responda de forma útil e profissional. Use as informações das FAQs acima para responder, se aplicável. Se a resposta estiver nas FAQs, cite a fonte.";

        return $prompt;
    }

    /**
     * Salva conversa no banco de dados
     *
     * @param string $message
     * @param bool $isBot
     * @param array $suggestedKbItems
     * @return bool
     */
    private function saveConversation(string $message, bool $isBot, array $suggestedKbItems = []): bool
    {
        global $DB;

        $kbItemsJson = !empty($suggestedKbItems) ? json_encode(array_column($suggestedKbItems, 'id')) : null;

        return $DB->insert('glpi_chatbot_conversations', [
            'tickets_id' => $this->ticketId,
            'users_id' => $this->userId,
            'message' => $message,
            'is_bot' => $isBot ? 1 : 0,
            'suggested_kb_items' => $kbItemsJson,
            'date_creation' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Obtém histórico da conversa
     *
     * @param int $limit
     * @return array
     */
    private function getConversationHistory(int $limit = 10): array
    {
        global $DB;

        $iterator = $DB->request([
            'FROM' => 'glpi_chatbot_conversations',
            'WHERE' => [
                'tickets_id' => $this->ticketId
            ],
            'ORDER' => 'date_creation DESC',
            'LIMIT' => $limit
        ]);

        $history = [];
        foreach ($iterator as $data) {
            $history[] = [
                'id' => $data['id'],
                'message' => $data['message'],
                'is_bot' => (bool)$data['is_bot'],
                'date' => $data['date_creation']
            ];
        }

        return array_reverse($history);
    }

    /**
     * Extrai palavras-chave de uma mensagem
     *
     * @param string $message
     * @return array
     */
    private function extractKeywordsFromMessage(string $message): array
    {
        $text = strtolower(strip_tags($message));
        
        $stopwords = ['o', 'a', 'de', 'da', 'do', 'em', 'para', 'com', 'por', 'que', 'não', 'um', 'uma', 'como', 'onde', 'quando'];
        
        $words = preg_split('/\s+/', $text);
        $words = array_filter($words, function($word) use ($stopwords) {
            return strlen($word) > 3 && !in_array($word, $stopwords);
        });

        return array_values(array_unique($words));
    }

    /**
     * Registra feedback sobre uma sugestão
     *
     * @param int $conversationId
     * @param bool $wasHelpful
     * @param string $comment
     * @return bool
     */
    public function saveFeedback(int $conversationId, bool $wasHelpful, string $comment = ''): bool
    {
        global $DB;

        return $DB->insert('glpi_chatbot_feedback', [
            'conversations_id' => $conversationId,
            'was_helpful' => $wasHelpful ? 1 : 0,
            'comment' => $comment,
            'date_creation' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Verifica se o chatbot está habilitado
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        global $CFG_GLPI;
        
        if (empty($CFG_GLPI['chatbot_enabled'])) {
            return false;
        }

        $provider = $CFG_GLPI['chatbot_provider'] ?? 'gemini';
        
        if ($provider === 'ollama') {
            return !empty($CFG_GLPI['ollama_host']);
        }
        
        return !empty($CFG_GLPI['gemini_api_key']);
    }
}
