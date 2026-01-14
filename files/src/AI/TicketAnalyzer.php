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
use ITILCategory;
use Entity;
use CommonITILActor;
use CommonITILObject;

/**
 * Analisador de tickets para extração de contexto
 */
class TicketAnalyzer
{
    /** @var Ticket */
    private $ticket;

    /**
     * Constructor
     *
     * @param Ticket $ticket
     */
    public function __construct(Ticket $ticket)
    {
        $this->ticket = $ticket;
    }

    /**
     * Extrai contexto completo do ticket
     *
     * @return array
     */
    public function extractContext(): array
    {
        $context = [
            'id' => $this->ticket->getID(),
            'title' => $this->ticket->fields['name'] ?? '',
            'description' => $this->ticket->fields['content'] ?? '',
            'status' => $this->getStatusName(),
            'priority' => $this->getPriorityName(),
            'category' => $this->getCategoryInfo(),
            'entity' => $this->getEntityInfo(),
            'requester' => $this->getRequesterInfo(),
            'assigned' => $this->getAssignedInfo(),
            'keywords' => $this->extractKeywords(),
            'symptoms' => $this->extractSymptoms(),
            'timeline' => $this->getTimelineInfo(),
        ];

        return $context;
    }

    /**
     * Extrai palavras-chave do ticket
     *
     * @return array
     */
    private function extractKeywords(): array
    {
        $text = $this->ticket->fields['name'] . ' ' . $this->ticket->fields['content'];
        $text = strip_tags($text);
        $text = strtolower($text);

        // Remover stopwords comuns em português
        $stopwords = ['o', 'a', 'de', 'da', 'do', 'em', 'para', 'com', 'por', 'que', 'não', 'um', 'uma'];
        
        $words = preg_split('/\s+/', $text);
        $words = array_filter($words, function($word) use ($stopwords) {
            return strlen($word) > 3 && !in_array($word, $stopwords);
        });

        // Contar frequência
        $frequency = array_count_values($words);
        arsort($frequency);

        return array_slice(array_keys($frequency), 0, 10);
    }

    /**
     * Extrai sintomas/problemas mencionados
     *
     * @return array
     */
    private function extractSymptoms(): array
    {
        $text = strtolower($this->ticket->fields['content'] ?? '');
        
        $symptomPatterns = [
            'erro' => '/erro|error|falha|problema/i',
            'lentidão' => '/lento|devagar|travando|congela/i',
            'não funciona' => '/não funciona|não abre|não carrega/i',
            'acesso negado' => '/acesso negado|sem permissão|bloqueado/i',
            'senha' => '/senha|password|login|autenticação/i',
            'impressora' => '/impressora|imprimir|impressão/i',
            'rede' => '/rede|internet|conexão|wifi/i',
            'email' => '/email|e-mail|correio/i',
        ];

        $symptoms = [];
        foreach ($symptomPatterns as $symptom => $pattern) {
            if (preg_match($pattern, $text)) {
                $symptoms[] = $symptom;
            }
        }

        return $symptoms;
    }

    /**
     * Obtém nome do status
     *
     * @return string
     */
    private function getStatusName(): string
    {
        $statuses = Ticket::getAllStatusArray();
        return $statuses[$this->ticket->fields['status']] ?? 'Desconhecido';
    }

    /**
     * Obtém nome da prioridade
     *
     * @return string
     */
    private function getPriorityName(): string
    {
        return CommonITILObject::getPriorityName($this->ticket->fields['priority'] ?? 3);
    }

    /**
     * Obtém informações da categoria
     *
     * @return array
     */
    private function getCategoryInfo(): array
    {
        if (empty($this->ticket->fields['itilcategories_id'])) {
            return ['id' => 0, 'name' => 'Sem categoria'];
        }

        $category = new ITILCategory();
        if ($category->getFromDB($this->ticket->fields['itilcategories_id'])) {
            return [
                'id' => $category->getID(),
                'name' => $category->fields['name'],
                'completename' => $category->fields['completename'] ?? $category->fields['name']
            ];
        }

        return ['id' => 0, 'name' => 'Desconhecida'];
    }

    /**
     * Obtém informações da entidade
     *
     * @return array
     */
    private function getEntityInfo(): array
    {
        $entity = new Entity();
        if ($entity->getFromDB($this->ticket->fields['entities_id'])) {
            return [
                'id' => $entity->getID(),
                'name' => $entity->fields['name']
            ];
        }

        return ['id' => 0, 'name' => 'Desconhecida'];
    }

    /**
     * Obtém informações do solicitante
     *
     * @return array
     */
    private function getRequesterInfo(): array
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => ['users_id'],
            'FROM' => 'glpi_tickets_users',
            'WHERE' => [
                'tickets_id' => $this->ticket->getID(),
                'type' => CommonITILActor::REQUESTER
            ],
            'LIMIT' => 1
        ]);

        if (count($iterator)) {
            $data = $iterator->current();
            return ['id' => $data['users_id']];
        }

        return ['id' => 0];
    }

    /**
     * Obtém informações do atribuído
     *
     * @return array
     */
    private function getAssignedInfo(): array
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => ['users_id'],
            'FROM' => 'glpi_tickets_users',
            'WHERE' => [
                'tickets_id' => $this->ticket->getID(),
                'type' => CommonITILActor::ASSIGN
            ],
            'LIMIT' => 1
        ]);

        if (count($iterator)) {
            $data = $iterator->current();
            return ['id' => $data['users_id']];
        }

        return ['id' => 0];
    }

    /**
     * Obtém informações da timeline
     *
     * @return array
     */
    private function getTimelineInfo(): array
    {
        global $DB;

        $followups = $DB->request([
            'SELECT' => ['content', 'date'],
            'FROM' => 'glpi_itilfollowups',
            'WHERE' => [
                'items_id' => $this->ticket->getID(),
                'itemtype' => 'Ticket'
            ],
            'ORDER' => 'date DESC',
            'LIMIT' => 5
        ]);

        $timeline = [];
        foreach ($followups as $followup) {
            $timeline[] = [
                'content' => strip_tags($followup['content']),
                'date' => $followup['date']
            ];
        }

        return $timeline;
    }

    /**
     * Gera prompt formatado para o Gemini
     *
     * @return string
     */
    public function generatePrompt(): string
    {
        $context = $this->extractContext();

        $prompt = "Você é um assistente de suporte técnico especializado em GLPI.\n\n";
        $prompt .= "Analise o seguinte chamado e sugira soluções baseadas na base de conhecimento:\n\n";
        $prompt .= "**Título:** {$context['title']}\n\n";
        $prompt .= "**Descrição:** {$context['description']}\n\n";
        $prompt .= "**Categoria:** {$context['category']['name']}\n\n";
        $prompt .= "**Prioridade:** {$context['priority']}\n\n";
        
        if (!empty($context['symptoms'])) {
            $prompt .= "**Sintomas identificados:** " . implode(', ', $context['symptoms']) . "\n\n";
        }

        if (!empty($context['keywords'])) {
            $prompt .= "**Palavras-chave:** " . implode(', ', $context['keywords']) . "\n\n";
        }

        if (!empty($context['timeline'])) {
            $prompt .= "**Histórico recente:**\n";
            foreach (array_slice($context['timeline'], 0, 2) as $entry) {
                $prompt .= "- " . substr($entry['content'], 0, 200) . "...\n";
            }
            $prompt .= "\n";
        }

        $prompt .= "Com base nessas informações, forneça:\n";
        $prompt .= "1. Uma análise do problema\n";
        $prompt .= "2. Possíveis causas\n";
        $prompt .= "3. Sugestões de solução\n";
        $prompt .= "4. Próximos passos recomendados\n";

        return $prompt;
    }
}
