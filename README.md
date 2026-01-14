# GLPI Chatbot Integration Installer

Este pacote instala a integração do Chatbot com IA (Gemini) no seu GLPI.

## Pré-requisitos
*   GLPI 10.0+
*   Acesso administrativo ao GLPI
*   Acesso de escrita nas pastas do GLPI (`src`, `ajax`, `js`, `css`, `front`)
*   Chave de API do Google Gemini

## Como Instalar

1.  **Backup:** Faça um backup do seu GLPI (arquivos e banco de dados) antes de prosseguir.
2.  **Upload:** Copie a pasta `glpi-chatbot-installer` para a raiz do seu GLPI.
3.  **Executar:** Acesse o instalador pelo navegador:
    `http://seu-glpi.com/glpi-chatbot-installer/install.php`
4.  **Verificar:** O instalador irá:
    *   Copiar os arquivos necessários.
    *   Criar as tabelas no banco de dados.
    *   Modificar o arquivo `front/ticket.form.php` para ativar o chatbot.
5.  **Configuração:**
    *   Adicione sua chave de API do Gemini no arquivo `inc/config.php` ou `config_db.php` (ou onde você gerencia configurações globais):
    ```php
    $CFG_GLPI['chatbot_enabled'] = true;
    $CFG_GLPI['gemini_api_key'] = 'SUA_CHAVE_AQUI';
    ```
6.  **Limpeza:** Após a instalação, **remova** a pasta `glpi-chatbot-installer` do seu servidor por segurança.

## Solução de Problemas

*   **Página em branco:** Verifique os logs do PHP/Apache.
*   **Permissão negada:** Garanta que o usuário do webserver tenha permissão de escrita nos arquivos.
*   **Botão não aparece:** Verifique se o arquivo `front/ticket.form.php` foi realmente modificado. Se não, você precisará adicionar o código manualmente (veja `install.php` para referência).
