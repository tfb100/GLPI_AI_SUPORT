<?php

namespace Glpi\AI;

/**
 * Interface para clientes de IA (Gemini, Ollama, etc)
 */
interface AIClientInterface
{
    /**
     * Gera conteúdo a partir de um prompt
     *
     * @param string $prompt
     * @param array $options
     * @return array Array com chaves 'text' (resposta) e 'raw' (resposta crua)
     */
    public function generateContent(string $prompt, array $options = []): array;

    /**
     * Gera resposta em formato de chat
     *
     * @param array $messages
     * @param array $options
     * @return array Array com chaves 'text' (resposta) e 'raw' (resposta crua)
     */
    public function chat(array $messages, array $options = []): array;

    /**
     * Verifica se o cliente está configurado
     *
     * @return bool
     */
    public function isConfigured(): bool;

    /**
     * Testa a conexão com o provedor
     *
     * @return bool
     */
    public function testConnection(): bool;
}
