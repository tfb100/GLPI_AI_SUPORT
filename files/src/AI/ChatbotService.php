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

        // Definir threshold de relevância
        // Título match = 10pts, Conteúdo match = 2pts each
        // Threshold 12 garante pelo menos um match de título e um de conteúdo, 
        // ou 6 matches de conteúdo.
        $relevanceThreshold = 12.0;

        // Buscar FAQs relevantes
        $allFaqs = $this->kbSearcher->searchCombined(
            $context['keywords'],
            $context['category']['id'] ?? 0,
            10 // Buscar mais candidatos para filtrar
        );

        // Filtrar por relevância
        $faqs = array_filter($allFaqs, function($faq) use ($relevanceThreshold) {
            return isset($faq['relevance']) && $faq['relevance'] >= $relevanceThreshold;
        });

        // Limitar aos top 5 após filtro
        $faqs = array_slice($faqs, 0, 5);

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
            $prompt .= "## Base de Conhecimento (Fontes Disponíveis)\n\n";
            foreach ($faqs as $idx => $faq) {
                $score = $faq['score'] ?? 0;
                $prompt .= "### Fonte " . ($idx + 1) . ": {$faq['title']} (Relevância: {$score}%)\n";
                $prompt .= "{$faq['content']}\n\n";
            }
        }

        $prompt .= "## Tarefa (Modo Estrito de Base de Conhecimento)\n\n";
        $prompt .= "Analise o chamado e forneça recomendações SOMENTE se houver base de conhecimento correspondente listada acima.\n\n";
        $prompt .= "1. **Resumo**: Resuma o problema.\n";
        $prompt .= "2. **Análise de Causas**: Liste possíveis causas baseadas na descrição.\n";
        $prompt .= "3. **Solução (Restrita)**:\n";
        $prompt .= "   - Se as fontes acima contiverem a solução: Explique a solução citando a Fonte X e sua relevância.\n";
        $prompt .= "   - Se as fontes NÃO forem suficientes ou irrelevantes: Responda EXATAMENTE: 'Não encontrei uma solução específica na Base de Conhecimento para este caso. Recomendo análise manual de um técnico nível 2.'\n";
        $prompt .= "   - NÃO invente soluções que não estejam nas fontes.\n";
        $prompt .= "4. **Próximos Passos**: Instruções para triagem.\n";
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
            $prompt .= "**Fontes da Base de Conhecimento:**\n";
            foreach ($faqs as $idx => $faq) {
                $score = $faq['score'] ?? 0;
                $prompt .= ($idx + 1) . ". {$faq['title']} (Relevância: {$score}%)\n";
                // Include partial content/excerpt if available
                if (!empty($faq['content'])) {
                    $prompt .= "   Conteúdo: " . strip_tags($faq['content']) . "\n";
                } elseif (!empty($faq['answer'])) {
                     $prompt .= "   Conteúdo: " . substr(strip_tags($faq['answer']), 0, 300) . "...\n";
                }
                $prompt .= "\n";
            }
            $prompt .= "\n";
        }

        $prompt .= "**Nova mensagem do usuário:** {$message}\n\n";
        $prompt .= "DIRETRIZES ESTRITAS:\n";
        $prompt .= "1. Use APENAS as informações das Fontes acima para sugerir soluções.\n";
        $prompt .= "2. Se a resposta estiver nas fontes, cite qual fonte usou e sua relevância.\n";
        $prompt .= "3. Se as fontes não tiverem a resposta, diga: 'Desculpe, não encontrei informações sobre isso na Base de Conhecimento.'\n";
        $prompt .= "4. Não invente procedimentos técnicos que não estejam nas fontes.";

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
