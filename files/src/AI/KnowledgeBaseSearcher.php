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

use KnowbaseItem;
use Session;

/**
 * Buscador inteligente na base de conhecimento
 */
class KnowledgeBaseSearcher
{
    /**
     * Busca FAQs relevantes baseado em palavras-chave
     *
     * @param array $keywords Palavras-chave para busca
     * @param int $limit Número máximo de resultados
     * @return array
     */
    public function searchByKeywords(array $keywords, int $limit = 5): array
    {
        global $DB;

        if (empty($keywords)) {
            return [];
        }

        // Construir condições de busca
        $searchConditions = [];
        foreach ($keywords as $keyword) {
            $searchConditions[] = [
                'OR' => [
                    ['name' => ['LIKE', '%' . $keyword . '%']],
                    ['answer' => ['LIKE', '%' . $keyword . '%']],
                ]
            ];
        }

        $iterator = $DB->request([
            'SELECT' => [
                'id',
                'name',
                'answer',
                'view',
                'date_mod'
            ],
            'FROM' => 'glpi_knowbaseitems',
            'WHERE' => [
                'OR' => $searchConditions,
                'is_faq' => 1
            ],
            'ORDER' => 'view DESC',
            'LIMIT' => $limit
        ]);

        $results = [];
        foreach ($iterator as $data) {
            $kb = new KnowbaseItem();
            if ($kb->getFromDB($data['id']) && $kb->canViewItem()) {
                $results[] = [
                    'id' => $data['id'],
                    'title' => $data['name'],
                    'content' => $this->extractRelevantContent($data['answer'], $keywords),
                    'full_content' => $data['answer'],
                    'views' => $data['view'],
                    'relevance' => $this->calculateRelevance($data, $keywords),
                    'url' => KnowbaseItem::getFormURLWithID($data['id'])
                ];
            }
        }

        // Ordenar por relevância
        usort($results, function($a, $b) {
            return $b['relevance'] <=> $a['relevance'];
        });

        return $results;
    }

    /**
     * Busca por categoria
     *
     * @param int $categoryId ID da categoria
     * @param int $limit Número máximo de resultados
     * @param array $keywords Palavras-chave para cálculo de relevância (opcional)
     * @return array
     */
    public function searchByCategory(int $categoryId, int $limit = 5, array $keywords = []): array
    {
        global $DB;

        if ($categoryId <= 0) {
            return [];
        }

        $iterator = $DB->request([
            'SELECT' => ['glpi_knowbaseitems.*'],
            'FROM' => 'glpi_knowbaseitems',
            'INNER JOIN' => [
                'glpi_knowbaseitems_knowbaseitemcategories' => [
                    'ON' => [
                        'glpi_knowbaseitems_knowbaseitemcategories' => 'knowbaseitems_id',
                        'glpi_knowbaseitems' => 'id'
                    ]
                ]
            ],
            'WHERE' => [
                'glpi_knowbaseitems_knowbaseitemcategories.knowbaseitemcategories_id' => $categoryId,
                'glpi_knowbaseitems.is_faq' => 1
            ],
            'ORDER' => 'glpi_knowbaseitems.view DESC',
            'LIMIT' => $limit
        ]);

        $results = [];
        foreach ($iterator as $data) {
            $kb = new KnowbaseItem();
            if ($kb->getFromDB($data['id']) && $kb->canViewItem()) {
                $results[] = [
                    'id' => $data['id'],
                    'title' => $data['name'],
                    'content' => $this->extractRelevantContent($data['answer'], $keywords),
                    'full_content' => $data['answer'],
                    'views' => $data['view'],
                    'relevance' => $this->calculateRelevance($data, $keywords),
                    'url' => KnowbaseItem::getFormURLWithID($data['id'])
                ];
            }
        }
        
        // Ordenar por relevância se houver palavras-chave
        if (!empty($keywords)) {
            usort($results, function($a, $b) {
                return $b['relevance'] <=> $a['relevance'];
            });
        }

        return $results;
    }

    /**
     * Busca combinada (keywords + categoria)
     *
     * @param array $keywords
     * @param int $categoryId
     * @param int $limit
     * @return array
     */
    public function searchCombined(array $keywords, int $categoryId = 0, int $limit = 5): array
    {
        $keywordResults = $this->searchByKeywords($keywords, $limit);
        
        if ($categoryId > 0) {
            // Passar keywords para calcular relevância também nos itens da categoria
            $categoryResults = $this->searchByCategory($categoryId, $limit, $keywords);
            
            // Mesclar resultados, removendo duplicatas
            $merged = $keywordResults;
            $existingIds = array_column($keywordResults, 'id');
            
            foreach ($categoryResults as $result) {
                if (!in_array($result['id'], $existingIds)) {
                    $merged[] = $result;
                }
            }
            
            // Reordenar tudo por relevância
            usort($merged, function($a, $b) {
                return $b['relevance'] <=> $a['relevance'];
            });
            
            return array_slice($merged, 0, $limit);
        }

        return $keywordResults;
    }

    /**
     * Extrai conteúdo relevante baseado nas palavras-chave
     *
     * @param string $content
     * @param array $keywords
     * @return string
     */
    private function extractRelevantContent(string $content, array $keywords): string
    {
        $content = strip_tags($content);
        
        // Encontrar primeira ocorrência de qualquer keyword
        $position = false;
        foreach ($keywords as $keyword) {
            $pos = stripos($content, $keyword);
            if ($pos !== false && ($position === false || $pos < $position)) {
                $position = $pos;
            }
        }

        if ($position === false) {
            return substr($content, 0, 300) . '...';
        }

        // Extrair contexto ao redor da keyword
        $start = max(0, $position - 100);
        $excerpt = substr($content, $start, 300);
        
        return ($start > 0 ? '...' : '') . $excerpt . '...';
    }

    /**
     * Calcula relevância do resultado
     *
     * @param array $data
     * @param array $keywords
     * @return float
     */
    private function calculateRelevance(array $data, array $keywords): float
    {
        $score = 0;
        $text = strtolower($data['name'] . ' ' . $data['answer']);

        foreach ($keywords as $keyword) {
            $keyword = strtolower($keyword);
            
            // Pontos por ocorrência no título (peso maior)
            $titleOccurrences = substr_count(strtolower($data['name']), $keyword);
            $score += $titleOccurrences * 10;

            // Pontos por ocorrência no conteúdo
            $contentOccurrences = substr_count(strtolower($data['answer']), $keyword);
            $score += $contentOccurrences * 2;
        }

        // Bônus por popularidade (views)
        $score += log($data['view'] + 1) * 0.5;

        return $score;
    }

    /**
     * Obtém FAQs mais populares
     *
     * @param int $limit
     * @return array
     */
    public function getPopularFAQs(int $limit = 10): array
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => ['id', 'name', 'answer', 'view'],
            'FROM' => 'glpi_knowbaseitems',
            'WHERE' => [
                'is_faq' => 1
            ],
            'ORDER' => 'view DESC',
            'LIMIT' => $limit
        ]);

        $results = [];
        foreach ($iterator as $data) {
            $kb = new KnowbaseItem();
            if ($kb->getFromDB($data['id']) && $kb->canViewItem()) {
                $results[] = [
                    'id' => $data['id'],
                    'title' => $data['name'],
                    'content' => substr(strip_tags($data['answer']), 0, 200) . '...',
                    'views' => $data['view'],
                    'url' => KnowbaseItem::getFormURLWithID($data['id'])
                ];
            }
        }

        return $results;
    }
}
