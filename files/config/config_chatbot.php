<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI - Gestionnaire Libre de Parc Informatique
 *
 * Configuração do Chatbot IA
 *
 * @copyright 2015-2025 Teclib' and contributors.
 * @licence   https://www.gnu.org/licenses/gpl-3.0.html
 *
 * ---------------------------------------------------------------------
 */

// Configurações do Chatbot IA com Gemini
$CFG_GLPI['chatbot_enabled'] = true;

// API Key do Google Gemini
$CFG_GLPI['gemini_api_key'] = 'AIzaSyDROuQMCXSVbBtKzqoUGQIjhG10hLnk0xk';

// Modelo do Gemini a usar
// Opções disponíveis:
// - 'gemini-2.0-flash-exp' (Gemini 2.5 Flash - Mais rápido e eficiente)
// - 'gemini-1.5-pro-002' (Gemini 1.5 Pro - Mais poderoso)
// - 'gemini-1.5-flash' (Gemini 1.5 Flash - Balanceado)
$CFG_GLPI['gemini_model'] = 'gemini-2.0-flash-exp';

// =========================================
// Escolha do Provedor de IA
// =========================================
// 'gemini' = Google Gemini (Cloud) - Padrão
// 'ollama' = Ollama (Local)
$CFG_GLPI['chatbot_provider'] = 'gemini';

// =========================================
// Configurações OLLAMA (Local)
// =========================================
// $CFG_GLPI['chatbot_provider'] = 'ollama'; // Default provider (ignored if dynamic switching matches valid config)
$CFG_GLPI['ollama_host'] = 'http://localhost:11434';
$CFG_GLPI['ollama_model'] = 'llama3';


// Número máximo de sugestões de FAQ
$CFG_GLPI['chatbot_max_suggestions'] = 5;

// Tempo de cache em segundos (1 hora)
$CFG_GLPI['chatbot_cache_ttl'] = 3600;

// Configurações de rate limiting
$CFG_GLPI['chatbot_rate_limit_analyze'] = 10;  // Máximo de análises por minuto
$CFG_GLPI['chatbot_rate_limit_chat'] = 20;     // Máximo de mensagens por minuto

// Configurações de geração do Gemini
$CFG_GLPI['gemini_generation_config'] = [
    'temperature' => 0.7,        // Criatividade (0.0 - 1.0)
    'topK' => 40,                // Diversidade de tokens
    'topP' => 0.95,              // Nucleus sampling
    'maxOutputTokens' => 2048,   // Tamanho máximo da resposta
];

// Prompt do sistema (opcional - personalizar comportamento do bot)
$CFG_GLPI['chatbot_system_prompt'] = 
    "Você é um assistente de suporte técnico especializado em GLPI (Gestionnaire Libre de Parc Informatique). " .
    "Seu objetivo é ajudar técnicos de TI a resolver chamados de forma rápida e eficiente. " .
    "Sempre forneça respostas técnicas, objetivas e baseadas em boas práticas de ITIL. " .
    "Quando possível, referencie artigos da base de conhecimento. " .
    "Use linguagem profissional mas acessível.";

// Habilitar logs detalhados (apenas para debug)
$CFG_GLPI['chatbot_debug'] = false;
