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
    private $itemId;

    /** @var string */
    private $itemType;

    /** @var int */
    private $userId;

    /**
     * Constructor
     *
     * @param int $itemId
     * @param int $userId
     * @param string|null $provider 'gemini' or 'ollama' (optional)
     * @param string $itemType 'Ticket' or 'Problem' (default: 'Ticket')
     */
    public function __construct(int $itemId, int $userId = null, string $provider = null, string $itemType = 'Ticket')
    {
        global $CFG_GLPI;

        $this->itemId = $itemId;
        $this->itemType = $itemType;
        $this->userId = $userId ?? Session::getLoginUserID();
        
        // Ensure ticketId mapping for consistency if needed or use specific property
        // But better to use $itemId generic name.

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

        $this->logDebug("ChatbotService initialized for user " . $this->userId);
    }

    /**
     * Registra log se o modo debug estiver ativo
     *
     * @param string $message
     * @return void
     */
    private function logDebug(string $message): void
    {
        global $CFG_GLPI;
        if (!empty($CFG_GLPI['chatbot_debug'])) {
            Toolbox::logInFile('chatbot', date('[Y-m-d H:i:s] ') . $message . "\n");
        }
    }

    /**
     * Análise inicial do item (Ticket ou Problem)
     *
     * @return array
     */
    public function analyzeItem(): array
    {
        if ($this->itemType === 'Problem') {
            return $this->analyzeProblem();
        }
        
        return $this->analyzeTicket();
    }

    /**
     * Análise de Problema (Incidente) - Conhecimento Geral
     */
    private function analyzeProblem(): array {
        $problem = new \Problem();
        if (!$problem->getFromDB($this->itemId)) {
             return ['success' => false, 'error' => 'Problema não encontrado'];
        }

        if (!$problem->canViewItem()) {
            return ['success' => false, 'error' => 'Sem permissão para visualizar este problema'];
        }
        
        $this->logDebug("analyzeProblem: Context extracted for Problem #" . $this->itemId);

        // Extract context manually for now (since TicketAnalyzer is specific)
        $context = [
            'id' => $problem->getID(),
            'title' => $problem->fields['name'],
            'description' => $problem->fields['content'],
            'priority' => $problem->fields['priority'],
            'status' => $problem->fields['status']
        ];

        // Validar Configuração do Gemini
        if ($this->aiClient instanceof GeminiClient && !$this->aiClient->isConfigured()) {
            return [
                'success' => true,
                'analysis' => '⚠️ **Atenção:** Configure a Chave de API para utilizar este recurso.',
                'context' => $context
            ];
        }

        $prompt = $this->buildProblemAnalysisPrompt($context);

        try {
            $aiResponse = $this->aiClient->generateContent($prompt);
            $responseText = $aiResponse['text'];

            // Extrair fontes estruturadas
            $externalSources = $this->extractSourcesFromResponse($responseText);

            $this->saveConversation('Análise inicial do problema', false);
            
            // Salvar no histórico (armazenando as fontes como suggested_kb_items para reutilizar a coluna)
            $conversationId = $this->saveConversation($responseText, true, $externalSources);

            return [
                'success' => true,
                'conversation_id' => $conversationId,
                'analysis' => $responseText,
                'suggested_faqs' => $externalSources, // Reutilizar a chave para o frontend
                'is_external' => true, // Flag para o frontend saber que são fontes externas
                'context' => $context
            ];

        } catch (\Exception $e) {
            $errorMessage = $this->getFriendlyErrorMessage($e);
            Toolbox::logError('Erro na análise do problema: ' . $e->getMessage());
            return ['success' => false, 'error' => $errorMessage];
        }
    }

    /**
     * Análise de Ticket (Suporte) - Foco em KB
     */
    private function analyzeTicket(): array
    {
        $ticket = new Ticket();
        if (!$ticket->getFromDB($this->itemId)) {
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

        $this->logDebug("analyzeTicket: Context extracted for Ticket #" . $this->itemId);

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

        // Validar Configuração do Gemini
        if ($this->aiClient instanceof GeminiClient && !$this->aiClient->isConfigured()) {
            return [
                'success' => true, // Return success to show message as bot response
                'analysis' => '⚠️ **Atenção:** Configure a Chave de API do Gemini para utilizar este provedor.',
                'suggested_faqs' => $faqs,
                'context' => [
                   'title' => $context['title'],
                   'category' => $context['category']['name'],
                   'priority' => $context['priority'],
                   'symptoms' => $context['symptoms']
                ]
            ];
        }

        // Gerar análise com AI
        $prompt = $this->buildAnalysisPrompt($context, $faqs);
        
        $this->logDebug("analyzeTicket: Sending prompt to AI (" . get_class($this->aiClient) . ")");

        try {
            $aiResponse = $this->aiClient->generateContent($prompt);
            
            // Salvar no histórico
            $this->saveConversation(
                'Análise inicial do ticket',
                false
            );
            
            $conversationId = $this->saveConversation(
                $aiResponse['text'],
                true,
                $faqs
            );

            return [
                'success' => true,
                'conversation_id' => $conversationId,
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
            $errorMessage = $this->getFriendlyErrorMessage($e);
            Toolbox::logError('Erro na análise do ticket: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $errorMessage,
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
        
        // Se for Problema, usa lógica sem KB
        if ($this->itemType === 'Problem') {
             return $this->processProblemMessage($message, $history);
        }

        return $this->processTicketMessage($message, $history);
    }
    
    private function processProblemMessage($message, $history) {
        // Build prompt purely on history + general knowledge
        $prompt = $this->buildProblemChatPrompt($message, $history);
        
        if ($this->aiClient instanceof GeminiClient && !$this->aiClient->isConfigured()) {
            return ['success' => true, 'response' => '⚠️ Configure a API Key.'];
        }

        try {
            $aiResponse = $this->aiClient->generateContent($prompt);
            $responseText = $aiResponse['text'];

            // Extrair fontes estruturadas
            $externalSources = $this->extractSourcesFromResponse($responseText);
            
            // Salvar resposta do bot com as fontes
            $conversationId = $this->saveConversation($responseText, true, $externalSources);

            return [
                'success' => true, 
                'conversation_id' => $conversationId,
                'response' => $responseText,
                'suggested_faqs' => $externalSources,
                'is_external' => true
            ];
        } catch (\Exception $e) {
            $errorMessage = $this->getFriendlyErrorMessage($e);
            Toolbox::logError('Erro ao processar mensagem (problem): ' . $e->getMessage());
            return ['success' => false, 'error' => $errorMessage];
        }
    }

    private function processTicketMessage($message, $history) {
        // Buscar FAQs relacionadas à mensagem
        $keywords = $this->extractKeywordsFromMessage($message);
        $faqs = $this->kbSearcher->searchByKeywords($keywords, 3);

        // Construir prompt com contexto
        $prompt = $this->buildChatPrompt($message, $history, $faqs);

        // Validar Configuração do Gemini
        if ($this->aiClient instanceof GeminiClient && !$this->aiClient->isConfigured()) {
            return [
                'success' => true,
                'response' => '⚠️ **Atenção:** Configure a Chave de API do Gemini para utilizar este provedor.',
                'suggested_faqs' => $faqs
            ];
        }

        try {
            $aiResponse = $this->aiClient->generateContent($prompt);
            
            // Salvar resposta do bot
            $conversationId = $this->saveConversation(
                $aiResponse['text'],
                true,
                $faqs
            );

            return [
                'success' => true,
                'conversation_id' => $conversationId,
                'response' => $aiResponse['text'],
                'suggested_faqs' => $faqs
            ];

        } catch (\Exception $e) {
            $errorMessage = $this->getFriendlyErrorMessage($e);
            Toolbox::logError('Erro ao processar mensagem: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $errorMessage,
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
        $prompt .= "   - Se as fontes NÃO forem suficientes ou irrelevantes: Responda EXATAMENTE: 'Não encontrei uma solução específica na Base de Conhecimento para este caso. Recomendo análise manual de um técnico nível 2. **Sugestão:** Após resolver este chamado, crie uma FAQ na base de conhecimento para que eu possa aprender e ajudar na próxima vez!'\n";
        $prompt .= "   - NÃO invente soluções que não estejam nas fontes.\n";
        $prompt .= "4. **Próximos Passos**: Instruções para triagem.\n";
        $prompt .= "Seja objetivo, técnico e baseado em boas práticas de ITIL.";

        return $prompt;
    }

    /**
     * Constrói prompt para análise de Problema (Incidente)
     *
     * @param array $context
     * @return string
     */
    private function buildProblemAnalysisPrompt(array $context): string
    {
        global $CFG_GLPI;
        
        // System prompt personalizado
        $systemPrompt = $CFG_GLPI['chatbot_system_prompt'] ?? 
            "Você é um especialista em TI Sênior e Gerente de Incidentes.";
        
        $prompt = $systemPrompt . "\n\n";
        $prompt .= "# ANÁLISE DE INCIDENTE (PROBLEMA)\n\n";
        $prompt .= "## Detalhes do Incidente\n";
        $prompt .= "**Título:** {$context['title']}\n";
        $prompt .= "**Descrição:**\n{$context['description']}\n";
        $prompt .= "**Prioridade:** {$context['priority']}\n";
        $prompt .= "**Status:** {$context['status']}\n\n";
        
        $prompt .= "## Tarefa\n";
        $prompt .= "Use seu conhecimento técnico geral para analisar este incidente. NÃO se restrinja a bases de conhecimento internas.\n";
        $prompt .= "**IMPORTANTE:** Se a descrição ou título do incidente estiver em inglês ou outro idioma, TRADUZA para Português do Brasil na sua explicação.\n\n";
        $prompt .= "1. **Explicação Clara**: Explique o que é este problema de forma que um técnico júnior ou usuário entenda (em Português).\n";
        $prompt .= "2. **Impactos**: Quais os possíveis impactos deste problema no ambiente de TI?\n";
        $prompt .= "3. **Possíveis Causas**: Liste as causas mais prováveis (ex: conectividade, disco cheio, falha de serviço).\n";
        $prompt .= "4. **Plano de Ação Sugerido**: Proponha passos técnicos para resolver ou diagnosticar (comandos, verificações).\n";
        $prompt .= "5. **Referências & Fontes Confiáveis**: Forneça links ou referências para documentações oficiais.\n\n";
        
        $prompt .= "## Requisito de Formato Estruturado\n";
        $prompt .= "Ao final de sua resposta, você DEVE adicionar uma seção estritamente com o marcador [SOURCES] contendo um array JSON com as referências citadas no seguinte formato:\n";
        $prompt .= "[SOURCES]\n";
        $prompt .= "[\n";
        $prompt .= "  {\"title\": \"Título da Documentação\", \"url\": \"https://link-da-referencia\", \"score\": 95},\n";
        $prompt .= "  {\"title\": \"Busca Sugerida: Como resolver erro X\", \"url\": \"https://www.google.com/search?q=como+resolver+erro+X\", \"score\": 80}\n";
        $prompt .= "]\n\n";
        
        $prompt .= "Seja educativo, profissional e traduza termos complexos.";

        return $prompt;
    }

    /**
     * Constrói prompt para chat de Problema
     */
    private function buildProblemChatPrompt(string $message, array $history): string
    {
        $prompt = "Você é um especialista em TI Sênior ajudando a resolver um Incidente Grave.\n\n";
        
        if (!empty($history)) {
            $prompt .= "**Histórico da conversa:**\n";
            foreach (array_slice($history, -5) as $entry) {
                $role = $entry['is_bot'] ? 'Especialista' : 'Usuário';
                $prompt .= "{$role}: {$entry['message']}\n";
            }
            $prompt .= "\n";
        }

        $prompt .= "**Nova pergunta do usuário:** {$message}\n\n";
        $prompt .= "Responda usando seu conhecimento técnico geral. Sugira comandos, scripts ou verificações lógicas. Seja direto e focado na resolução do incidente.\n\n";
        $prompt .= "## Requisito de Fontes\n";
        $prompt .= "Se você citar links ou documentações, ADICIONE obrigatoriamente ao final o bloco [SOURCES] formatado em JSON:\n";
        $prompt .= "[SOURCES]\n";
        $prompt .= "[\n";
        $prompt .= "  {\"title\": \"Título da Fonte\", \"url\": \"https://...\", \"score\": 90}\n";
        $prompt .= "]\n";

        return $prompt;
    }

    /**
     * Constrói prompt para chat de Ticket
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
        $prompt .= "3. Se as fontes não tiverem a resposta, diga: 'Desculpe, não encontrei informações sobre isso na Base de Conhecimento. Sugiro que, ao encontrar a solução, crie uma nova FAQ para enriquecer nossa base e me ajudar no futuro.'\n";
        $prompt .= "4. Não invente procedimentos técnicos que não estejam nas fontes.";

        return $prompt;
    }

    /**
     * Salva conversa no banco de dados
     *
     * @param string $message
     * @param bool $isBot
     * @param array $suggestedKbItems
     * @return int
     */
    private function saveConversation(string $message, bool $isBot, array $suggestedKbItems = []): int
    {
        global $DB;

        $kbItemsJson = !empty($suggestedKbItems) ? json_encode(array_column($suggestedKbItems, 'id')) : null;
        $date = date('Y-m-d H:i:s');
        $isBotInt = $isBot ? 1 : 0;
        
        // Escape values
        $e_items_id = (int)$this->itemId;
        $e_item_type = $DB->escape($this->itemType);
        $e_users_id = (int)$this->userId;
        $e_message = $DB->escape($message);
        $e_kb_items = $kbItemsJson ? "'" . $DB->escape($kbItemsJson) . "'" : "NULL";
        $e_date = $DB->escape($date);

        $query = "INSERT INTO glpi_chatbot_conversations 
                  (items_id, item_type, users_id, message, is_bot, suggested_kb_items, date_creation) 
                  VALUES ($e_items_id, '$e_item_type', $e_users_id, '$e_message', $isBotInt, $e_kb_items, '$e_date')";

        $DB->query($query);
        
        return $DB->insertId();
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
                'items_id' => $this->itemId,
                'item_type' => $this->itemType
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

        $wasHelpfulInt = $wasHelpful ? 1 : 0;
        $e_comment = $DB->escape($comment);
        $date = date('Y-m-d H:i:s');
        
        $query = "INSERT INTO glpi_chatbot_feedback 
                  (conversations_id, was_helpful, comment, date_creation) 
                  VALUES ($conversationId, $wasHelpfulInt, '$e_comment', '$date')";

        return $DB->query($query);
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

    /**
     * Retorna mensagem de erro amigável baseada na exceção
     *
     * @param \Exception $e
     * @return string
     */
    private function getFriendlyErrorMessage(\Exception $e): string
    {
        $msg = $e->getMessage();
        
        // Detectar erros de conexão (cURL, timeouts, conexões recusadas)
        if (strpos($msg, 'cURL error') !== false || 
            strpos($msg, 'Failed to connect') !== false || 
            strpos($msg, 'Erro ao comunicar') !== false ||
            strpos($msg, 'Connection refused') !== false) {
                
            return 'Não foi possível comunicar com o provedor de IA no momento. Verifique se o serviço está ativo ou tente novamente em alguns instantes.';
        }
        
        return 'Erro ao processar solicitação: ' . $msg;
    }

    /**
     * Corrige recursivamente o encoding para UTF-8 válido em arrays ou strings
     *
     * @param mixed $data
     * @return mixed
     */
    public static function fixUtf8Recursive($data)
    {
        if (is_string($data)) {
            if (function_exists('mb_convert_encoding')) {
                return mb_convert_encoding($data, 'UTF-8', 'UTF-8');
            }
            return $data;
        }
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = self::fixUtf8Recursive($value);
            }
        }
        return $data;
    }

    /**
     * Extrai fontes estruturadas da resposta da IA
     *
     * @param string $text
     * @return array
     */
    private function extractSourcesFromResponse(string &$text): array
    {
        $sources = [];
        $pattern = '/\[SOURCES\]\s*(\[.*?\])/s';
        
        if (preg_match($pattern, $text, $matches)) {
            $json = $matches[1];
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $sources = $decoded;
            }
            // Remover o bloco [SOURCES] do texto para não exibir o JSON para o usuário
            $text = trim(preg_replace($pattern, '', $text));
        }
        
        return $sources;
    }
}
