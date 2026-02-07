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

/* global $ */

/**
 * Chatbot IA para GLPI
 */
var GLPIChatbot = (function () {
    'use strict';

    var config = {
        itemId: null,
        itemType: 'Ticket', // Default to Ticket
        ajaxUrl: null,
        isOpen: false,
        isAnalyzing: false
    };

    var elements = {
        widget: null,
        toggle: null,
        messages: null,
        input: null,
        sendBtn: null
    };

    /**
     * Inicializa o chatbot
     */
    function init(itemId, ajaxUrl, itemType = 'Ticket') {
        console.info('[GLPI Chatbot] Inicializando...', { itemId, itemType, ajaxUrl });
        config.itemId = itemId;
        config.itemType = itemType;
        config.ajaxUrl = ajaxUrl || '../ajax/chatbot.php';

        createWidget();
        bindEvents();
        loadHistory();
    }

    /**
     * Cria o widget do chatbot
     */
    function createWidget() {
        // Adjust button title/icon based on itemType if needed, but generic "Analyze" works.
        // If Problem, maybe change "Analisar Chamado" to "Analisar Problema"?
        var analyzeText = config.itemType === 'Problem' ? 'Analisar Problema' : 'Analisar Chamado';

        var html = `
            <div id="glpi-chatbot-widget" class="chatbot-widget">
                <button id="chatbot-toggle" class="chatbot-toggle" title="Assistente IA">
                    <i class="fas fa-robot"></i>
                    <span class="chatbot-badge">AI</span>
                </button>
                
                <div id="chatbot-container" class="chatbot-container" style="display: none;">
                <div class="chatbot-header">
                    <div class="chatbot-title">
                        <i class="fas fa-robot"></i>
                        <span>Assistente IA (${config.itemType === 'Problem' ? 'Incidente' : 'Suporte'})</span>
                    </div>
                    <div class="chatbot-header-actions">
                        <!-- Settings Button -->
                        <button class="chatbot-header-btn" id="chatbot-settings-btn" title="Configurações">
                            <i class="fas fa-cog"></i>
                        </button>
                        <!-- Analyze Button -->
                        <button id="chatbot-analyze-header-btn" class="chatbot-header-btn" title="${analyzeText}">
                            <i class="fas fa-magic"></i>
                        </button>
                        <button class="chatbot-close" title="Fechar">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Settings Panel -->
                <div class="chatbot-settings-panel" style="display: none;">
                    <h4>Configurações de IA</h4>
                    <div class="chatbot-setting-item">
                        <label for="chatbot-provider-select">Provedor:</label>
                        <select id="chatbot-provider-select">
                            <option value="gemini">Google Gemini</option>
                            <option value="ollama">Ollama (Local)</option>
                        </select>
                    </div>
                </div>

                    
                    <div class="chatbot-messages" id="chatbot-messages">
                        <div class="chatbot-welcome">
                            <i class="fas fa-robot chatbot-welcome-icon"></i>
                            <p>Olá! Sou seu assistente de IA.</p>
                            <p>Posso ajudar a analisar este ${config.itemType === 'Problem' ? 'incidente' : 'chamado'} e sugerir soluções.</p>
                            <button class="btn btn-primary btn-sm" id="chatbot-analyze-btn">
                                <i class="fas fa-search"></i> ${analyzeText}
                            </button>
                        </div>
                    </div>
                    
                    <div class="chatbot-input-container">
                        <textarea 
                            id="chatbot-input" 
                            class="chatbot-input" 
                            placeholder="Digite sua mensagem..."
                            rows="1"
                        ></textarea>
                        <button id="chatbot-send" class="chatbot-send" title="Enviar">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </div>
                </div>
            </div>
        `;

        $('body').append(html);

        elements.widget = $('#glpi-chatbot-widget');
        elements.toggle = $('#chatbot-toggle');
        elements.messages = $('#chatbot-messages');
        elements.input = $('#chatbot-input');
        elements.sendBtn = $('#chatbot-send');
    }

    /**
     * Vincula eventos
     */
    function bindEvents() {
        // Toggle chatbot
        elements.toggle.on('click', toggleChatbot);
        $('.chatbot-close').on('click', toggleChatbot);

        // Settings Toggle
        $(document).on('click', '#chatbot-settings-btn', function (e) {
            e.stopPropagation();
            $('.chatbot-settings-panel').fadeToggle(200);
        });

        // Close settings when clicking outside
        $(document).on('click', function (e) {
            if (!$(e.target).closest('.chatbot-settings-panel, #chatbot-settings-btn').length) {
                $('.chatbot-settings-panel').fadeOut(200);
            }
        });

        // Load saved provider
        const savedProvider = localStorage.getItem('glpi_chatbot_provider') || 'ollama';
        $('#chatbot-provider-select').val(savedProvider);

        // Save provider on change
        $(document).on('change', '#chatbot-provider-select', function () {
            const provider = $(this).val();
            localStorage.setItem('glpi_chatbot_provider', provider);
        });

        // Enviar mensagem
        elements.sendBtn.on('click', sendMessage);
        elements.input.on('keypress', function (e) {
            if (e.which === 13 && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        // Auto-resize textarea
        elements.input.on('input', function () {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });

        // Botão de análise
        $(document).on('click', '#chatbot-analyze-btn', analyzeTicket);
        $(document).on('click', '#chatbot-analyze-header-btn', analyzeTicket);

        // Feedback buttons
        $(document).on('click', '.chatbot-feedback-btn', handleFeedback);

        // FAQ links
        $(document).on('click', '.chatbot-faq-link', function (e) {
            e.preventDefault();
            window.open($(this).attr('href'), '_blank');
        });
    }

    /**
     * Toggle chatbot aberto/fechado
     */
    function toggleChatbot() {
        config.isOpen = !config.isOpen;

        if (config.isOpen) {
            $('#chatbot-container').slideDown(300);
            elements.input.focus();
            scrollToBottom();
        } else {
            $('#chatbot-container').slideUp(300);
        }
    }

    /**
     * Analisa o ticket
     */
    function analyzeTicket() {
        if (config.isAnalyzing) return;

        config.isAnalyzing = true;
        $('#chatbot-analyze-btn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Analisando...');

        addMessage('Iniciando análise do chamado...', false);
        showTypingIndicator();

        // Obter CSRF token
        var csrfToken = $('meta[property="glpi:csrf_token"]').attr('content') || $('input[name="_glpi_csrf_token"]').val() || '';
        var provider = $('#chatbot-provider-select').val() || 'gemini';

        console.log('[GLPI Chatbot] Enviando requisição de análise...', {
            item_id: config.itemId,
            item_type: config.itemType,
            provider: provider,
            csrf_token_length: csrfToken.length,
            csrf_from_meta: !!$('meta[property="glpi:csrf_token"]').length,
            csrf_from_input: !!$('input[name="_glpi_csrf_token"]').length
        });

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            headers: {
                'X-Glpi-Csrf-Token': csrfToken
            },
            data: {
                action: 'analyze',
                item_id: config.itemId,
                item_type: config.itemType,
                provider: provider,
                _glpi_csrf_token: csrfToken
            },
            dataType: 'json',
            success: function (response) {
                console.log('[GLPI Chatbot] Resposta da análise recebida:', response);
                hideTypingIndicator();
                config.isAnalyzing = false;

                if (response.success) {
                    addMessage(
                        response.analysis || response.response,
                        true,
                        response.suggested_faqs,
                        false,
                        response.conversation_id,
                        response.is_external || false
                    );

                    if (response.context) {
                        addContextInfo(response.context);
                    }
                } else {
                    addMessage('Erro: ' + (response.error || 'Falha ao analisar chamado'), true, null, true);
                }
            },
            error: function (xhr) {
                console.error('[GLPI Chatbot] Erro na análise AJAX:', {
                    status: xhr.status,
                    statusText: xhr.statusText,
                    responseText: xhr.responseText,
                    json: xhr.responseJSON
                });
                hideTypingIndicator();
                config.isAnalyzing = false;

                var errorMsg = 'Erro ao comunicar com o servidor';

                if (xhr.responseJSON && xhr.responseJSON.error) {
                    errorMsg = 'Erro: ' + xhr.responseJSON.error;
                } else if (xhr.status === 429) {
                    errorMsg = 'Muitas requisições. Aguarde um momento.';
                } else if (xhr.status === 403) {
                    errorMsg = 'Acesso negado. Verifique suas permissões.';
                }

                addMessage(errorMsg, true, null, true);
            }
        });
    }

    /**
     * Envia mensagem
     */
    function sendMessage() {
        var message = elements.input.val().trim();

        if (!message) return;

        elements.input.val('').css('height', 'auto');
        addMessage(message, false);
        showTypingIndicator();

        // Obter CSRF token
        var csrfToken = $('meta[property="glpi:csrf_token"]').attr('content') || '';

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            headers: {
                'X-Glpi-Csrf-Token': csrfToken
            },
            data: {
                action: 'chat',
                item_id: config.itemId,
                item_type: config.itemType,
                message: message,
                provider: $('#chatbot-provider-select').val() || 'gemini',
                _glpi_csrf_token: csrfToken
            },
            dataType: 'json',
            success: function (response) {
                hideTypingIndicator();

                if (response.success) {
                    addMessage(response.response, true, response.suggested_faqs, false, response.conversation_id);
                } else {
                    addMessage('Erro: ' + (response.error || 'Falha ao processar mensagem'), true, null, true);
                }
            },
            error: function (xhr) {
                console.error('[GLPI Chatbot] Erro no chat AJAX:', {
                    status: xhr.status,
                    statusText: xhr.statusText,
                    responseText: xhr.responseText,
                    json: xhr.responseJSON
                });
                hideTypingIndicator();

                var errorMsg = 'Erro ao comunicar com o servidor';

                if (xhr.responseJSON && xhr.responseJSON.error) {
                    errorMsg = 'Erro: ' + xhr.responseJSON.error;
                } else if (xhr.status === 429) {
                    errorMsg = 'Muitas mensagens. Aguarde um momento.';
                } else if (xhr.status === 403) {
                    errorMsg = 'Acesso negado. Verifique suas permissões.';
                }

                addMessage(errorMsg, true, null, true);
            }
        });
    }

    /**
     * Adiciona mensagem ao chat
     */
    function addMessage(text, isBot, faqs, isError, conversationId, isExternal = false) {
        // Remover mensagem de boas-vindas se existir
        $('.chatbot-welcome').remove();

        var messageClass = isBot ? 'chatbot-message-bot' : 'chatbot-message-user';
        if (isError) messageClass += ' chatbot-message-error';

        var html = `
            <div class="chatbot-message ${messageClass}">
                ${isBot ? '<i class="fas fa-robot chatbot-avatar"></i>' : '<i class="fas fa-user chatbot-avatar"></i>'}
                <div class="chatbot-message-content">
                    <div class="chatbot-message-text">${formatMessage(text)}</div>
                    ${faqs && faqs.length > 0 ? renderFAQs(faqs, isExternal) : ''}
                    ${isBot && !isError && conversationId ? renderFeedbackButtons(conversationId) : ''}
                </div>
            </div>
        `;

        elements.messages.append(html);
        scrollToBottom();
    }

    /**
     * Formata mensagem (markdown básico)
     */
    function formatMessage(text) {
        return text
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            .replace(/\*(.*?)\*/g, '<em>$1</em>')
            .replace(/\n/g, '<br>');
    }

    /**
     * Renderiza FAQs sugeridas em formato de tabela
     */
    function renderFAQs(faqs, isExternal = false) {
        var title = isExternal ? 'Fontes Externas Sugeridas:' : 'Tutoriais Sugeridos:';
        var colLabel = isExternal ? 'Fonte' : 'POP';
        var itemLabel = isExternal ? 'Ref' : 'Tutorial';
        var externalClass = isExternal ? 'is-external' : '';

        var html = `
            <div class="chatbot-faqs ${externalClass}">
                <div class="chatbot-faqs-title">
                    <i class="fas ${isExternal ? 'fa-globe' : 'fa-book'}"></i> ${title}
                </div>
                <div class="chatbot-faq-table-container">
                    <table class="chatbot-faq-table">
                        <thead>
                            <tr>
                                <th>${colLabel}</th>
                                <th>${itemLabel}</th>
                                <th>Relevância</th>
                            </tr>
                        </thead>
                        <tbody>
        `;

        faqs.forEach(function (faq, index) {
            var scoreClass = '';
            var scoreText = 'Baixa';

            if (faq.score) {
                if (faq.score >= 70) {
                    scoreClass = 'chatbot-faq-score-high';
                    scoreText = `Alta (${faq.score}%)`;
                } else if (faq.score >= 40) {
                    scoreClass = 'chatbot-faq-score-medium';
                    scoreText = `Média (${faq.score}%)`;
                } else {
                    scoreClass = 'chatbot-faq-score-low';
                    scoreText = `Baixa (${faq.score}%)`;
                }
            }

            var typeLabel = isExternal ? 'Doc' : 'POP';
            var sourceLabel = `${typeLabel} ${index + 1}`;

            html += `
                <tr>
                    <td class="chatbot-faq-source-cell">${sourceLabel}</td>
                    <td class="chatbot-faq-title-cell">
                        <a href="${faq.url}" class="chatbot-faq-link" target="_blank" title="${faq.title}">
                            ${faq.title}
                        </a>
                    </td>
                    <td class="chatbot-faq-score-cell">
                        <span class="chatbot-faq-score ${scoreClass}">${scoreText}</span>
                    </td>
                </tr>
            `;
        });

        html += `
                        </tbody>
                    </table>
                </div>
            </div>
        `;
        return html;
    }

    /**
     * Renderiza botões de feedback
     */
    function renderFeedbackButtons(conversationId) {
        return `
            <div class="chatbot-feedback" data-conversation-id="${conversationId}">
                <span class="chatbot-feedback-label">Esta resposta foi útil?</span>
                <button class="chatbot-feedback-btn" data-helpful="1" title="Sim">
                    <i class="fas fa-thumbs-up"></i>
                </button>
                <button class="chatbot-feedback-btn" data-helpful="0" title="Não">
                    <i class="fas fa-thumbs-down"></i>
                </button>
            </div>
        `;
    }

    /**
     * Adiciona informações de contexto
     */
    function addContextInfo(context) {
        var html = `
            <div class="chatbot-context">
                <div class="chatbot-context-title"><i class="fas fa-info-circle"></i> Contexto do Chamado:</div>
                <ul>
                    <li><strong>Categoria:</strong> ${context.category}</li>
                    <li><strong>Prioridade:</strong> ${context.priority}</li>
                    ${context.symptoms && context.symptoms.length > 0 ?
                '<li><strong>Sintomas:</strong> ' + context.symptoms.join(', ') + '</li>' : ''}
                </ul>
            </div>
        `;

        elements.messages.append(html);
        scrollToBottom();
    }

    /**
     * Mostra indicador de digitação
     */
    function showTypingIndicator() {
        var html = `
            <div class="chatbot-typing" id="chatbot-typing">
                <i class="fas fa-robot chatbot-avatar"></i>
                <div class="chatbot-typing-indicator">
                    <span></span><span></span><span></span>
                </div>
            </div>
        `;
        elements.messages.append(html);
        scrollToBottom();
    }

    /**
     * Esconde indicador de digitação
     */
    function hideTypingIndicator() {
        $('#chatbot-typing').remove();
    }

    /**
     * Scroll para o final
     */
    function scrollToBottom() {
        setTimeout(function () {
            elements.messages.scrollTop(elements.messages[0].scrollHeight);
        }, 100);
    }

    /**
     * Carrega histórico
     */
    function loadHistory() {
        console.log('[GLPI Chatbot] Carregando histórico...', {
            item_id: config.itemId,
            item_type: config.itemType
        });
        $.ajax({
            url: config.ajaxUrl,
            method: 'GET',
            data: {
                action: 'history',
                item_id: config.itemId,
                item_type: config.itemType
            },
            dataType: 'json',
            success: function (response) {
                console.log('[GLPI Chatbot] Histórico carregado:', response);
                if (response.success && response.history && response.history.length > 0) {
                    $('.chatbot-welcome').remove();

                    response.history.forEach(function (entry) {
                        // Se sugeridos_kb_items for um objeto (fontes externas) ou array de IDs
                        var faqs = entry.suggested_kb_items;
                        var isExternal = false;

                        // Inferir se é externo baseado no conteúdo do primeiro item (se for objeto com url)
                        if (faqs && faqs.length > 0 && typeof faqs[0] === 'object' && faqs[0].url) {
                            isExternal = true;
                        }

                        addMessage(entry.message, entry.is_bot, faqs, false, entry.id, isExternal);
                    });
                }
            },
            error: function (xhr) {
                console.error('[GLPI Chatbot] Erro ao carregar histórico:', {
                    status: xhr.status,
                    statusText: xhr.statusText,
                    responseText: xhr.responseText
                });
            }
        });
    }

    /**
     * Trata feedback
     */
    function handleFeedback(e) {
        var btn = $(this);
        var wasHelpful = btn.data('helpful') === 1;
        var feedbackContainer = btn.closest('.chatbot-feedback');
        var conversationId = feedbackContainer.data('conversation-id');

        if (!conversationId) {
            console.error('Conversation ID missing for feedback');
            return;
        }

        feedbackContainer.html('<span class="chatbot-feedback-thanks"><i class="fas fa-spinner fa-spin"></i> Enviando...</span>');

        // Obter CSRF token
        var csrfToken = $('meta[property="glpi:csrf_token"]').attr('content') || '';

        console.log('[GLPI Chatbot] Enviando feedback...', {
            conversation_id: conversationId,
            was_helpful: wasHelpful
        });

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            headers: {
                'X-Glpi-Csrf-Token': csrfToken
            },
            data: {
                action: 'feedback',
                conversation_id: conversationId,
                was_helpful: wasHelpful ? 1 : 0,
                comment: '', // Future: allow comment input
                _glpi_csrf_token: csrfToken
            },
            dataType: 'json',
            success: function (response) {
                console.log('[GLPI Chatbot] Resposta do feedback:', response);
                if (response.success) {
                    feedbackContainer.html('<span class="chatbot-feedback-thanks"><i class="fas fa-check"></i> Obrigado pelo feedback!</span>');
                } else {
                    feedbackContainer.html('<span class="chatbot-feedback-error"><i class="fas fa-exclamation-circle"></i> Erro ao enviar.</span>');
                }
            },
            error: function (xhr) {
                console.error('[GLPI Chatbot] Erro ao enviar feedback:', {
                    status: xhr.status,
                    statusText: xhr.statusText,
                    responseText: xhr.responseText
                });
                feedbackContainer.html('<span class="chatbot-feedback-error"><i class="fas fa-exclamation-circle"></i> Erro ao enviar.</span>');
            }
        });
    }

    // API pública
    return {
        init: init,
        toggle: toggleChatbot,
        sendMessage: sendMessage
    };
})();

// Auto-inicializar se estiver na página de ticket ou problema
$(document).ready(function () {
    var itemId = $('input[name="id"]').val();
    var href = window.location.href;
    var itemType = null;

    if (itemId && itemId > 0) {
        if (href.indexOf('ticket.form.php') > -1) {
            itemType = 'Ticket';
        } else if (href.indexOf('problem.form.php') > -1) {
            itemType = 'Problem';
        }

        if (itemType) {
            console.log('[GLPI Chatbot] Auto-inicializando para ' + itemType + ' #' + itemId);
            GLPIChatbot.init(itemId, null, itemType);
        }
    }
});
