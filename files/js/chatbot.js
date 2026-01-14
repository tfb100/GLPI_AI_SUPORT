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
        ticketId: null,
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
    function init(ticketId, ajaxUrl) {
        config.ticketId = ticketId;
        config.ajaxUrl = ajaxUrl || '../ajax/chatbot.php';

        createWidget();
        bindEvents();
        loadHistory();
    }

    /**
     * Cria o widget do chatbot
     */
    function createWidget() {
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
                        <span>Assistente IA</span>
                    </div>
                    <div class="chatbot-header-actions">
                        <!-- Settings Button -->
                        <button class="chatbot-header-btn" id="chatbot-settings-btn" title="Configurações">
                            <i class="fas fa-cog"></i>
                        </button>
                        <!-- Analyze Button -->
                        <button id="chatbot-analyze-header-btn" class="chatbot-header-btn" title="Analisar Chamado">
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
                            <p>Posso ajudar a analisar este chamado e sugerir soluções da base de conhecimento.</p>
                            <button class="btn btn-primary btn-sm" id="chatbot-analyze-btn">
                                <i class="fas fa-search"></i> Analisar Chamado
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
        const savedProvider = localStorage.getItem('glpi_chatbot_provider') || 'gemini';
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
        var csrfToken = $('meta[property="glpi:csrf_token"]').attr('content') || '';

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            headers: {
                'X-Glpi-Csrf-Token': csrfToken
            },
            data: {
                action: 'analyze',
                ticket_id: config.ticketId,
                provider: $('#chatbot-provider-select').val() || 'gemini',
                _glpi_csrf_token: csrfToken
            },
            dataType: 'json',
            success: function (response) {
                hideTypingIndicator();
                config.isAnalyzing = false;

                if (response.success) {
                    addMessage(response.analysis, true, response.suggested_faqs);

                    if (response.context) {
                        addContextInfo(response.context);
                    }
                } else {
                    addMessage('Erro: ' + (response.error || 'Falha ao analisar chamado'), true, null, true);
                }
            },
            error: function (xhr) {
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
                ticket_id: config.ticketId,
                message: message,
                provider: $('#chatbot-provider-select').val() || 'gemini',
                _glpi_csrf_token: csrfToken
            },
            dataType: 'json',
            success: function (response) {
                hideTypingIndicator();

                if (response.success) {
                    addMessage(response.response, true, response.suggested_faqs);
                } else {
                    addMessage('Erro: ' + (response.error || 'Falha ao processar mensagem'), true, null, true);
                }
            },
            error: function (xhr) {
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
    function addMessage(text, isBot, faqs, isError) {
        // Remover mensagem de boas-vindas se existir
        $('.chatbot-welcome').remove();

        var messageClass = isBot ? 'chatbot-message-bot' : 'chatbot-message-user';
        if (isError) messageClass += ' chatbot-message-error';

        var html = `
            <div class="chatbot-message ${messageClass}">
                ${isBot ? '<i class="fas fa-robot chatbot-avatar"></i>' : '<i class="fas fa-user chatbot-avatar"></i>'}
                <div class="chatbot-message-content">
                    <div class="chatbot-message-text">${formatMessage(text)}</div>
                    ${faqs && faqs.length > 0 ? renderFAQs(faqs) : ''}
                    ${isBot && !isError ? renderFeedbackButtons() : ''}
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
     * Renderiza FAQs sugeridas
     */
    function renderFAQs(faqs) {
        var html = '<div class="chatbot-faqs"><div class="chatbot-faqs-title"><i class="fas fa-book"></i> FAQs Relacionadas:</div><ul>';

        faqs.forEach(function (faq) {
            html += `
                <li>
                    <a href="${faq.url}" class="chatbot-faq-link" target="_blank">
                        <i class="fas fa-external-link-alt"></i> ${faq.title}
                        ${faq.score ? `<span class="chatbot-faq-score" title="Relevância">${faq.score}%</span>` : ''}
                    </a>
                    <div class="chatbot-faq-excerpt">${faq.content}</div>
                </li>
            `;
        });

        html += '</ul></div>';
        return html;
    }

    /**
     * Renderiza botões de feedback
     */
    function renderFeedbackButtons() {
        return `
            <div class="chatbot-feedback">
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
        $.ajax({
            url: config.ajaxUrl,
            method: 'GET',
            data: {
                action: 'history',
                ticket_id: config.ticketId
            },
            dataType: 'json',
            success: function (response) {
                if (response.success && response.history && response.history.length > 0) {
                    $('.chatbot-welcome').remove();

                    response.history.forEach(function (entry) {
                        addMessage(entry.message, entry.is_bot);
                    });
                }
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

        feedbackContainer.html('<span class="chatbot-feedback-thanks"><i class="fas fa-check"></i> Obrigado pelo feedback!</span>');

        // Aqui você pode enviar o feedback para o servidor
        // Por enquanto, apenas mostra a mensagem de agradecimento
    }

    // API pública
    return {
        init: init,
        toggle: toggleChatbot,
        sendMessage: sendMessage
    };
})();

// Auto-inicializar se estiver na página de ticket
$(document).ready(function () {
    var ticketId = $('input[name="id"]').val();
    if (ticketId && window.location.href.indexOf('ticket.form.php') > -1) {
        GLPIChatbot.init(ticketId);
    }
});
