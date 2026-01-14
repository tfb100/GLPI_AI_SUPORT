<?php

namespace Glpi\AI;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Toolbox;

/**
 * Cliente para integração com Ollama (LLM Local)
 */
class OllamaClient implements AIClientInterface
{
    /** @var string */
    private $host;

    /** @var string */
    private $model;

    /** @var Client */
    private $httpClient;

    /**
     * Constructor
     *
     * @param string $host URL do Ollama (ex: http://localhost:11434)
     * @param string $model Modelo a usar (ex: llama3)
     */
    public function __construct(string $host = null, string $model = null)
    {
        global $CFG_GLPI;

        $this->host = $host ?? $CFG_GLPI['ollama_host'] ?? 'http://localhost:11434';
        $this->model = $model ?? $CFG_GLPI['ollama_model'] ?? 'llama3';
        
        // Remover barra final se existir
        $this->host = rtrim($this->host, '/');

        $this->httpClient = new Client([
            'timeout' => 60, // Ollama pode ser mais lento dependendo do hardware
            'headers' => [
                'Content-Type' => 'application/json',
            ]
        ]);
    }

    /**
     * Gera conteúdo usando Ollama
     *
     * @param string $prompt
     * @param array $options
     * @return array
     */
    public function generateContent(string $prompt, array $options = []): array
    {
        if (empty($this->host)) {
            throw new \RuntimeException('Host do Ollama não configurado');
        }

        $requestBody = [
            'model' => $this->model,
            'prompt' => $prompt,
            'stream' => false,
            'options' => array_merge([
                'temperature' => 0.7,
            ], $options['generationConfig'] ?? [])
        ];

        try {
            $response = $this->httpClient->post($this->host . '/api/generate', [
                'json' => $requestBody
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['response'])) {
                throw new \RuntimeException('Resposta inválida do Ollama');
            }

            return [
                'text' => $data['response'],
                'raw' => $data
            ];

        } catch (GuzzleException $e) {
            Toolbox::logError('Erro ao chamar Ollama API: ' . $e->getMessage());
            throw new \RuntimeException('Erro ao comunicar com Ollama: ' . $e->getMessage());
        }
    }

    /**
     * Chat com Ollama
     *
     * @param array $messages
     * @param array $options
     * @return array
     */
    public function chat(array $messages, array $options = []): array
    {
        // Converte formato de mensagens do Gemini/Interface para formato do Ollama
        $ollamaMessages = [];
        foreach ($messages as $msg) {
            $ollamaMessages[] = [
                'role' => $msg['role'],
                'content' => $msg['content']
            ];
        }

        $requestBody = [
            'model' => $this->model,
            'messages' => $ollamaMessages,
            'stream' => false,
            'options' => array_merge([
                'temperature' => 0.7,
            ], $options['generationConfig'] ?? [])
        ];

        try {
            $response = $this->httpClient->post($this->host . '/api/chat', [
                'json' => $requestBody
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return [
                'text' => $data['message']['content'] ?? '',
                'raw' => $data
            ];

        } catch (GuzzleException $e) {
            Toolbox::logError('Erro ao chamar Ollama API (chat): ' . $e->getMessage());
            throw new \RuntimeException('Erro ao comunicar com Ollama');
        }
    }

    /**
     * Verifica configuração
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        return !empty($this->host) && !empty($this->model);
    }

    /**
     * Testa conexão
     *
     * @return bool
     */
    public function testConnection(): bool
    {
        try {
            // Teste rápido com prompt simples
            $result = $this->generateContent('Say OK');
            return !empty($result['text']);
        } catch (\Exception $e) {
            return false;
        }
    }
}
