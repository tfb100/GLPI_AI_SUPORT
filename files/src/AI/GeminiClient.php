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

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Toolbox;

/**
 * Cliente para integração com Google Gemini API
 */
class GeminiClient implements AIClientInterface
{
    /** @var string */
    private $apiKey;

    /** @var string */
    private $model;

    /** @var Client */
    private $httpClient;

    /** @var string */
    private const API_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /**
     * Constructor
     *
     * @param string $apiKey API key do Gemini
     * @param string $model Modelo a usar (padrão: gemini-2.0-flash-exp)
     */
    public function __construct(string $apiKey = null, string $model = 'gemini-2.0-flash-exp')
    {
        global $CFG_GLPI;

        $this->apiKey = $apiKey ?? $CFG_GLPI['gemini_api_key'] ?? '';
        $this->model = $model ?? $CFG_GLPI['gemini_model'] ?? 'gemini-2.0-flash-exp';
        
        $this->httpClient = new Client([
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
            ]
        ]);
    }

    /**
     * Gera conteúdo usando o Gemini
     *
     * @param string $prompt Prompt para enviar ao modelo
     * @param array $options Opções adicionais
     * @return array Resposta do Gemini
     * @throws \RuntimeException
     */
    public function generateContent(string $prompt, array $options = []): array
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('Gemini API key não configurada');
        }

        $cacheKey = 'gemini_' . md5($prompt);
        
        // Verificar cache
        if ($cached = $this->getFromCache($cacheKey)) {
            return $cached;
        }

        $requestBody = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => array_merge([
                'temperature' => 0.7,
                'topK' => 40,
                'topP' => 0.95,
                'maxOutputTokens' => 2048,
            ], $options['generationConfig'] ?? [])
        ];

        try {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . 
                   $this->model . ':generateContent?key=' . $this->apiKey;
            
            $response = $this->httpClient->post($url, [
                'json' => $requestBody
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                throw new \RuntimeException('Resposta inválida do Gemini');
            }

            $result = [
                'text' => $data['candidates'][0]['content']['parts'][0]['text'],
                'raw' => $data
            ];

            // Salvar no cache por 1 hora
            $this->saveToCache($cacheKey, $result, 3600);

            return $result;

        } catch (GuzzleException $e) {
            Toolbox::logError('Erro ao chamar Gemini API: ' . $e->getMessage());
            throw new \RuntimeException('Erro ao comunicar com Gemini API: ' . $e->getMessage());
        }
    }

    /**
     * Gera resposta em formato de chat
     *
     * @param array $messages Histórico de mensagens
     * @param array $options Opções adicionais
     * @return array
     */
    public function chat(array $messages, array $options = []): array
    {
        $contents = [];
        
        foreach ($messages as $message) {
            $contents[] = [
                'role' => $message['role'] ?? 'user',
                'parts' => [
                    ['text' => $message['content']]
                ]
            ];
        }

        $requestBody = [
            'contents' => $contents,
            'generationConfig' => array_merge([
                'temperature' => 0.9,
                'topK' => 40,
                'topP' => 0.95,
                'maxOutputTokens' => 2048,
            ], $options['generationConfig'] ?? [])
        ];

        try {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . 
                   $this->model . ':generateContent?key=' . $this->apiKey;
            
            $response = $this->httpClient->post($url, [
                'json' => $requestBody
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            return [
                'text' => $data['candidates'][0]['content']['parts'][0]['text'] ?? '',
                'raw' => $data
            ];

        } catch (GuzzleException $e) {
            Toolbox::logError('Erro ao chamar Gemini API (chat): ' . $e->getMessage());
            throw new \RuntimeException('Erro ao comunicar com Gemini API');
        }
    }

    /**
     * Busca no cache
     *
     * @param string $key
     * @return array|null
     */
    private function getFromCache(string $key): ?array
    {
        global $GLPI_CACHE;
        
        if (!$GLPI_CACHE) {
            return null;
        }

        try {
            $cached = $GLPI_CACHE->get($key);
            return $cached ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Salva no cache
     *
     * @param string $key
     * @param array $data
     * @param int $ttl Tempo de vida em segundos
     * @return void
     */
    private function saveToCache(string $key, array $data, int $ttl): void
    {
        global $GLPI_CACHE;
        
        if (!$GLPI_CACHE) {
            return;
        }

        try {
            $GLPI_CACHE->set($key, $data, $ttl);
        } catch (\Exception $e) {
            // Silenciosamente falhar se cache não disponível
        }
    }

    /**
     * Verifica se a API está configurada
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Testa a conexão com a API
     *
     * @return bool
     */
    public function testConnection(): bool
    {
        try {
            $result = $this->generateContent('Olá, responda apenas "OK"');
            return !empty($result['text']);
        } catch (\Exception $e) {
            return false;
        }
    }
}
