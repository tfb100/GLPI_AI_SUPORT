# Guia de Instalação e Produção: GLPI Chatbot & Dashboard

Este documento detalha o processo de instalação do pacote "GLPI Chatbot AI + Dashboard de Contratos" em um ambiente de produção, bem como a análise de segurança e impacto.

## 1. Visão Geral dos Recursos

O pacote adiciona duas grandes funcionalidades ao GLPI:
1.  **Chatbot AI para Chamados:**
    *   Integrado à tela de visualização de tickets (`ticket.form.php`).
    *   Oferece análise de problemas usando IA (Google Gemini ou Ollama).
    *   Sugere soluções baseadas na Base de Conhecimento (KB) com índice de relevância.
    *   Modo Estrito: Só traz respostas da KB para evitar alucinações.
2.  **Dashboard de Contratos (TV Mode):**
    *   Página dedicada (`front/dashboard_contracts.php`) para monitoramento de vencimento de contratos.
    *   Visual "Semáforo" (Verde/Amarelo/Laranja/Vermelho) otimizado para grandes telas (TVs).

---

## 2. Requisitos

*   GLPI 10.0+ 
*   PHP 7.4 ou superior.
*   Acesso de Administrador ao GLPI.
*   (Opcional) Chave de API do Google Gemini OU Instância Ollama rodando localmente.

---

## 3. Instalação em Produção

### Passo 1: Upload dos Arquivos
Copie a pasta `glpi-chatbot-installer` para a raiz da instalação do GLPI no servidor (ex: `/var/www/html/glpi/` ou `C:\xampp\htdocs\glpi\`).

### Passo 2: Executar o Instalador
Acesse via navegador:
`http://seu-servidor-glpi/glpi/glpi-chatbot-installer/install.php`

O script irá automaticamente:
1.  Copiar os arquivos do sistema para as pastas corretas (`src/AI`, `ajax/`, `js/`, `css/`, `front/`).
2.  Criar/Verificar as tabelas no banco de dados (`glpi_chatbot_conversations`, `glpi_chatbot_feedback`).
3.  Aplicar o patch no arquivo `front/ticket.form.php` para injetar o botão do chatbot.

>**Nota de Segurança:** O patch verifica se o código já existe antes de inserir, evitando duplicações.

### Passo 3: Configuração
1.  Renomeie `config/config_chatbot.example.php` para `config/config_chatbot.php`.
2.  **Opção A (Recomendada): Ollama (Local)**
    *   Certifique-se de que o Ollama está rodando.
    *   O modelo padrão configurado é o **`deepseek-r1:8b`**.
    *   **Importante:** No servidor do Ollama, execute: `ollama pull deepseek-r1:8b` para baixar o modelo. caso contrário, o chatbot exibirá um erro.
3.  **Opção B: Google Gemini (Nuvem)**
    *   Edite o arquivo `config/config_chatbot.php`.
    *   Insira sua chave de API em `$CFG_GLPI['gemini_api_key']`.
    *   Altere `$CFG_GLPI['chatbot_provider']` para `'gemini'`.

---

## 4. Solução de Problemas Comuns

### Erro: "Modelo '...' não encontrado"
Se o chatbot retornar um erro dizendo que o modelo não foi encontrado:
1.  Acesse o terminal do servidor onde o Ollama está instalado.
2.  Execute o comando de download sugerido na mensagem de erro (ex: `ollama pull deepseek-r1:8b`).

### Erro: "Configure a Chave de API do Gemini"
Se você selecionou o provedor Gemini, é obrigatório editar o arquivo `config/config_chatbot.php` e inserir uma chave válida gerada no Google AI Studio.

---

## 5. Análise de Impacto e Segurança

### Arquivos Modificados
A instalação é projetada para ser minimamente invasiva. Apenas **UM** arquivo do núcleo do GLPI é modificado:

*   **`front/ticket.form.php`**:
    *   **Alteração:** Inserção de um bloco `if (ChatbotService::isEnabled()) { ... }` antes da renderização da página.
    *   **Risco:** Baixíssimo. Se o serviço estiver desabilitado ou ocorrer um erro na classe `ChatbotService`, o bloco é ignorado. O código é isolado e não altera a lógica de negócio do GLPI.
    *   **Teste de Regressão:** Verificado. O fluxo normal de abertura, edição e fechamento de chamados permanece inalterado.

### Arquivos Novos
Todos os outros arquivos são novos e isolados:
*   `src/AI/*`: Lógica de backend isolada via namespace.
*   `ajax/chatbot.php`: Endpoint dedicado, protegido por CSRF e verificação de login.
*   `front/dashboard_contracts.php`: Página independente, apenas leitura (`SELECT`), sem risco de corromper dados.

### Performance
*   **Chatbot:** As chamadas à IA são assíncronas (AJAX). Não travam o carregamento da página do chamado.
*   **Dashboard:** Consulta otimizada na tabela de contratos.

---

## 5. Acesso ao Dashboard

Para exibir o dashboard em uma TV:
1.  Acesse: `http://seu-servidor-glpi/glpi/front/dashboard_contracts.php`
2.  Recomenda-se usar um plugin de "Auto Refresh" no navegador da TV ou pressionar F11 para tela cheia.
