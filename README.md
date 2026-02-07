# GLPI Chatbot Integration Installer

Este pacote instala a integração do Chatbot com IA (Gemini) no seu GLPI.

## Pré-requisitos

* GLPI 10.0+
* Acesso administrativo ao GLPI
* Acesso de escrita nas pastas do GLPI (`src`, `ajax`, `js`, `css`, `front`)
* Chave de API do Google Gemini

## Recursos

* **Análise Inteligente:** O Chatbot analisa o título e descrição do chamado para identificar problemas e sugerir soluções.
* **Sugestão de FAQs:** Busca artigos relevantes na Base de Conhecimento do GLPI.
* **Chat Interativo:** Converse com a IA para tirar dúvidas sobre o chamado.
* **Coleta de Feedback:** Usuários podem avaliar as respostas da IA (👍/👎), permitindo monitorar a qualidade das sugestões.

### Opção A: Instalação Automática (Recomendado)

**Windows:**

1. Abra a pasta `glpi-chatbot-installer`.
2. Dê um duplo-clique em `install_windows.bat`.

**Linux (Terminal):**

1. Acesse a pasta do instalador.
2. Execute: `chmod +x install_linux.sh && ./install_linux.sh`

### Opção B: Instalação via Navegador

1. **Backup:** Faça um backup do seu GLPI (arquivos e banco de dados).
2. **Upload:** Copie a pasta `glpi-chatbot-installer` para a raiz do seu GLPI.
3. **Executar:** Acesse: `http://seu-glpi.com/glpi-chatbot-installer/install.php`

4. **Verificar:** O instalador irá realizar a cópia de arquivos, criação de tabelas e aplicação de patches automaticamente.
5. **Configuração:**
    * Adicione sua chave de API do Gemini no arquivo `inc/config.php` ou `config_db.php` (ou onde você gerencia configurações globais):

    ```php
    $CFG_GLPI['chatbot_enabled'] = true;
    
    // Opção 1: Google Gemini (Padrão)
    $CFG_GLPI['chatbot_provider'] = 'gemini';
    $CFG_GLPI['gemini_api_key'] = 'SUA_CHAVE_AQUI';

    // Opção 2: Ollama (Local)
    // $CFG_GLPI['chatbot_provider'] = 'ollama';
    // $CFG_GLPI['ollama_host'] = 'http://localhost:11434';
    // $CFG_GLPI['ollama_model'] = 'llama3';
    ```

6. **Limpeza:** Após a instalação, **remova** a pasta `glpi-chatbot-installer` do seu servidor por segurança.

## Solução de Problemas

* **Página em branco:** Verifique os logs do PHP/Apache.
* **Permissão negada:** Garanta que o usuário do webserver tenha permissão de escrita nos arquivos.
* **Botão não aparece:** Verifique se o arquivo `front/ticket.form.php` foi realmente modificado. Se não, você precisará adicionar o código manualmente (veja `install.php` para referência).
