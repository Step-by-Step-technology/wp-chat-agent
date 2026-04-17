/**
 * Assistant IA — widget de chat flottant.
 * Persistance : session | local | none (configurable via l'admin).
 */
jQuery(function ($) {
    'use strict';

    var cfg = window.aiAssistant || {};
    var STORAGE_KEY = 'ai_assistant_chat_v3';
    var storage = getStorage(cfg.persistence);

    // Nettoyage : supprime toute ancienne version de stockage (v1, v2, etc.)
    // qui pourrait contenir des messages de test/debug obsolètes.
    try {
        ['ai_assistant_chat', 'ai_assistant_chat_v1', 'ai_assistant_chat_v2'].forEach(function(k){
            if (window.sessionStorage) window.sessionStorage.removeItem(k);
            if (window.localStorage)   window.localStorage.removeItem(k);
        });
    } catch(e) {}

    var state = loadState();
    var lastDebugLogs = [];

    // ───────── Utils ─────────

    function getStorage(mode) {
        try {
            if (mode === 'local' && window.localStorage) return window.localStorage;
            if (mode === 'session' && window.sessionStorage) return window.sessionStorage;
        } catch (e) { /* stockage bloqué */ }
        return null;
    }

    function loadState() {
        var empty = { messages: [], history: [], opened: false, savedAt: 0 };
        if (!storage) return empty;
        try {
            var raw = storage.getItem(STORAGE_KEY);
            if (!raw) return empty;
            var data = JSON.parse(raw);

            // TTL en mode local.
            if (cfg.persistence === 'local' && cfg.persistenceTtlMs > 0) {
                if (data.savedAt && (Date.now() - data.savedAt) > cfg.persistenceTtlMs) {
                    storage.removeItem(STORAGE_KEY);
                    return empty;
                }
            }
            return {
                messages: Array.isArray(data.messages) ? data.messages : [],
                history:  Array.isArray(data.history)  ? data.history  : [],
                opened:   !!data.opened,
                savedAt:  data.savedAt || 0
            };
        } catch (e) {
            return empty;
        }
    }

    function saveState() {
        if (!storage) return;
        try {
            state.savedAt = Date.now();
            storage.setItem(STORAGE_KEY, JSON.stringify(state));
        } catch (e) { /* quota ou privé */ }
    }

    function clearState() {
        state = { messages: [], history: [], opened: false, savedAt: 0 };
        if (storage) {
            try { storage.removeItem(STORAGE_KEY); } catch (e) {}
        }
    }

    function escapeHtml(str) {
        return $('<div>').text(str == null ? '' : String(str)).html();
    }

    /**
     * Retourne true si l'URL pointe vers le même domaine racine que la page courante.
     * Compare les hostnames en ignorant le préfixe "www." pour que
     * example.com et www.example.com soient considérés identiques.
     */
    function isInternalUrl(url) {
        if (!url) return false;
        if (url.charAt(0) === '/' || url.charAt(0) === '#') return true;
        try {
            var u = new URL(url, window.location.href);
            var a = u.hostname.replace(/^www\./, '').toLowerCase();
            var b = window.location.hostname.replace(/^www\./, '').toLowerCase();
            return a === b;
        } catch (e) {
            return false;
        }
    }

    function markdownToHtml(text) {
        var html = escapeHtml(text);
        html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/__(.*?)__/g, '<strong>$1</strong>');
        html = html.replace(/\*(.*?)\*/g, '<em>$1</em>');
        html = html.replace(/_(.*?)_/g, '<em>$1</em>');
        html = html.replace(/^### (.*)$/gm, '<h4>$1</h4>');
        html = html.replace(/^## (.*)$/gm, '<h3>$1</h3>');
        html = html.replace(/^# (.*)$/gm, '<h2>$1</h2>');
        html = html.replace(/^[-*] (.*)$/gm, '<li>$1</li>');
        html = html.replace(/(<li>.*<\/li>(\n|$))+/g, '<ul>$&</ul>');
        // Liens markdown [texte](url) — traité AVANT les URLs brutes.
        // Même domaine → même onglet. Domaine différent → nouvel onglet.
        html = html.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, function (_, txt, url) {
            var attrs = isInternalUrl(url) ? '' : ' target="_blank" rel="noopener"';
            return '<a href="' + encodeURI(url) + '"' + attrs + '>' + txt + '</a>';
        });
        // Auto-linkification des URLs brutes (http/https) non déjà dans un <a>.
        html = html.replace(/(^|[^"'>=])((?:https?:\/\/)[^\s<]+[^\s<.,;:!?)])/g, function (_, prefix, url) {
            var attrs = isInternalUrl(url) ? '' : ' target="_blank" rel="noopener"';
            return prefix + '<a href="' + encodeURI(url) + '"' + attrs + '>' + url + '</a>';
        });
        html = html.replace(/\n/g, '<br>');
        return html;
    }

    function formatPhone(phone) {
        if (!phone) return '';
        var c = phone.replace(/[^\d+]/g, '');
        if (c.startsWith('+33') && c.length === 12) {
            return c.replace(/(\+33)(\d)(\d{2})(\d{2})(\d{2})(\d{2})/, '$1 $2 $3 $4 $5 $6');
        }
        return c.replace(/(\d{2})(?=\d)/g, '$1 ');
    }

    // ───────── Construction du DOM ─────────

    function buildWidget() {
        if ($('#ai-chat-widget').length) return;

        var pluginUrl = cfg.pluginUrl || '';
        var siteName  = escapeHtml(cfg.siteName || 'Assistant');
        var placeholder = escapeHtml(cfg.inputPlaceholder || 'Tapez votre question ici…');

        $('body').append(
            '<div id="ai-chat-bubble">' +
                '<img src="' + pluginUrl + 'assets/generative.png" class="bubble-icon" alt="Assistant" />' +
            '</div>'
        );

        var version = escapeHtml(cfg.version || '');
        var footer = '<div class="chat-footer">';
        if (cfg.showCredit !== 'no') {
            footer += '<span class="step-by-step-credit">développement <a href="https://step-by-step.technology" target="_blank" rel="noopener">Step by Step</a></span>';
        }
        footer += '<span class="chat-clear" title="' + escapeHtml(cfg.i18n.clearChat) + '" style="margin-left:10px; cursor:pointer; color:#666; font-size:11px;">↻ ' + escapeHtml(cfg.i18n.clearChat) + '</span>' +
            '<span class="chat-version" style="margin-left:10px; color:#888; font-size:11px;">v' + version + '</span>';

        if (cfg.enableDebug === 'yes') {
            footer += '<span class="debug-toggle" style="margin-left:10px; cursor:pointer; color:#666; font-size:11px;">[debug]</span>';
        }
        footer += '</div>';

        $('body').append(
            '<div id="ai-chat-widget" style="display:none;">' +
                '<div class="chat-header">' +
                    '<span class="chat-title">Assistant <img src="' + pluginUrl + 'assets/generative.png" class="header-icon" alt="IA" /> ' + siteName + '</span>' +
                    '<span class="close-chat">&times;</span>' +
                '</div>' +
                '<div class="chat-messages"></div>' +
                '<div class="chat-input-area">' +
                    '<textarea id="ai-chat-input" placeholder="' + placeholder + '" rows="2"></textarea>' +
                    '<button id="ai-chat-send" title="Envoyer">' +
                        '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">' +
                            '<path d="M15.854.146a.5.5 0 0 1 .11.54l-5.819 14.547a.75.75 0 0 1-1.329.124l-3.178-4.995L.643 7.184a.75.75 0 0 1 .124-1.33L15.314.037a.5.5 0 0 1 .54.11ZM6.636 10.07l2.761 4.338L14.13 2.576zm6.787-8.201L1.591 6.602l4.339 2.76z"/>' +
                        '</svg>' +
                    '</button>' +
                '</div>' +
                footer +
                '<div class="debug-panel" style="display:none; background:#f5f5f5; border-top:1px solid #ddd; padding:10px; font-size:11px; max-height:200px; overflow-y:auto;">' +
                    '<div class="debug-content"></div>' +
                '</div>' +
            '</div>'
        );

        bindEvents();
        restoreMessages();

        if (state.opened) {
            $('#ai-chat-widget').show();
            $('#ai-chat-bubble').hide();
            // Scroll après layout complet (images, polices chargées).
            setTimeout(scrollToBottom, 50);
            setTimeout(scrollToBottom, 300);
        }
    }

    function restoreMessages() {
        var $messages = $('.chat-messages').empty();

        if (!state.messages.length) {
            // Message de bienvenue par défaut.
            var welcome = cfg.welcomeMessage || ('Bonjour ! Je suis votre assistant IA ' + (cfg.siteName || '') + '. Posez-moi vos questions.');
            renderMessage('bot', welcome, { skipSave: true });
            return;
        }

        state.messages.forEach(function (msg) {
            renderMessage(msg.sender, msg.text, { skipSave: true, skipContactBubbles: msg.sender !== 'bot' });
        });

        scrollToBottom();
    }

    function renderMessage(sender, text, opts) {
        opts = opts || {};
        var $messages = $('.chat-messages');
        var content = (sender === 'bot') ? markdownToHtml(text) : escapeHtml(text);
        $messages.append('<div class="message ' + (sender === 'user' ? 'user' : 'bot') + '">' + content + '</div>');

        if (!opts.skipSave) {
            state.messages.push({ sender: sender, text: text });
            if (sender === 'user' || sender === 'bot') {
                state.history.push({
                    role: sender === 'user' ? 'user' : 'assistant',
                    content: text
                });
                var maxH = Math.max(2, parseInt(cfg.maxHistory || 10, 10));
                if (state.history.length > maxH) {
                    state.history = state.history.slice(-maxH);
                }
            }
            saveState();
        }

        if (sender === 'bot' && !opts.skipContactBubbles && text.indexOf('Désolé, une erreur') === -1) {
            maybeAppendContactBubbles(text);
        }

        smartScroll(sender);
    }

    function maybeAppendContactBubbles(text) {
        var wordCount = text.trim().split(/\s+/).length;
        var limit = parseInt(cfg.contactWordLimit || 50, 10);
        if (wordCount <= limit) return;

        var phone = (cfg.phoneNumber || '').trim();
        var contactUrl = (cfg.contactPageUrl || '').trim();
        if (!phone && !contactUrl) return;

        var isDesktop = window.innerWidth >= 769;
        var html = '<div class="contact-expert-section">' +
            '<div class="contact-expert-title">' + escapeHtml(cfg.i18n.contactTitle) + '</div>' +
            '<div class="contact-bubbles">';

        if (phone) {
            var phoneText = isDesktop ? formatPhone(phone) : cfg.i18n.phoneLabel;
            html += '<div class="contact-bubble contact-phone" title="' + escapeHtml(phoneText) + '">' +
                '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M3.654 1.328a.678.678 0 0 0-1.015-.063L1.605 2.3c-.483.484-.661 1.169-.45 1.77a17.6 17.6 0 0 0 4.168 6.608 17.6 17.6 0 0 0 6.608 4.168c.601.211 1.286.033 1.77-.45l1.034-1.034a.678.678 0 0 0-.063-1.015l-2.307-1.794a.68.68 0 0 0-.58-.122l-2.19.547a1.75 1.75 0 0 1-1.657-.459L5.482 8.062a1.75 1.75 0 0 1-.46-1.657l.548-2.19a.68.68 0 0 0-.122-.58z"/></svg>' +
                '<span>' + escapeHtml(phoneText) + '</span></div>';
        }
        if (contactUrl) {
            html += '<div class="contact-bubble contact-email" title="' + escapeHtml(cfg.i18n.emailLabel) + '">' +
                '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm2-1a1 1 0 0 0-1 1v.217l7 4.2 7-4.2V4a1 1 0 0 0-1-1zm13 2.383-4.708 2.825L15 11.105zm-.034 6.876-5.64-3.471L8 9.583l-1.326-.795-5.64 3.47A1 1 0 0 0 2 13h12a1 1 0 0 0 .966-.741M1 11.105l4.708-2.897L1 5.383z"/></svg>' +
                '<span>' + escapeHtml(cfg.i18n.emailLabel) + '</span></div>';
        }

        html += '</div></div>';
        $('.chat-messages').append(html);
    }

    function smartScroll(sender) {
        var container = $('.chat-messages')[0];
        var last = $('.chat-messages .message').last()[0];
        if (!container || !last) return;

        if (sender === 'bot' && last.offsetHeight > 300) {
            var lastUser = $('.chat-messages .message.user').last()[0];
            container.scrollTop = lastUser ? (lastUser.offsetTop - 10) : (last.offsetTop - 10);
        } else {
            container.scrollTop = container.scrollHeight;
        }
    }

    function scrollToBottom() {
        var c = $('.chat-messages')[0];
        if (c) c.scrollTop = c.scrollHeight;
    }

    // ───────── Événements ─────────

    function bindEvents() {
        $('#ai-chat-bubble').on('click', function () {
            $('#ai-chat-widget').fadeIn(200, function(){ scrollToBottom(); });
            $('#ai-chat-bubble').fadeOut(150);
            $('#ai-chat-input').focus();
            state.opened = true; saveState();
        });

        $(document).on('click', '.close-chat', function () {
            $('#ai-chat-widget').fadeOut(200);
            $('#ai-chat-bubble').fadeIn(150);
            state.opened = false; saveState();
        });

        $(document).on('click', '#ai-chat-send', sendMessage);

        $(document).on('keypress', '#ai-chat-input', function (e) {
            if (e.which === 13 && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        $(document).on('click', '.chat-clear', function () {
            if (!confirm('Effacer la conversation ?')) return;
            clearState();
            restoreMessages();
        });

        $(document).on('click', '.contact-bubble.contact-phone', function () {
            if (window.innerWidth >= 769) return;
            var p = (cfg.phoneNumber || '').trim();
            if (p) window.open((p.indexOf('tel:') === 0 ? p : 'tel:' + p), '_blank');
        });

        $(document).on('click', '.contact-bubble.contact-email', function () {
            var url = (cfg.contactPageUrl || '').trim();
            if (url) window.open(url, '_blank');
        });

        $(document).on('click', '.debug-toggle', function () {
            var $panel = $('.debug-panel');
            if ($panel.is(':visible')) {
                $panel.hide();
            } else {
                renderDebugPanel();
                $panel.show();
            }
        });
    }

    function renderDebugPanel() {
        var $c = $('.debug-content');
        if (!lastDebugLogs.length) {
            $c.html('<em>' + escapeHtml(cfg.i18n.debugEmpty) + '</em>');
            return;
        }
        var html = '<strong>' + escapeHtml(cfg.i18n.debugTitle) + '</strong><br><br>';
        lastDebugLogs.forEach(function (log, i) {
            html += '<div style="margin-bottom:5px; padding:5px; background:white; border-radius:3px;"><strong>#' + (i + 1) + ':</strong> ' + escapeHtml(log) + '</div>';
        });
        $c.html(html);
    }

    function sendMessage() {
        var input = ($('#ai-chat-input').val() || '').trim();
        if (!input) return;

        renderMessage('user', input);
        $('#ai-chat-input').val('');

        $('.chat-messages').append('<div class="message bot typing">' + escapeHtml(cfg.i18n.typing) + '</div>');
        scrollToBottom();

        $('#ai-chat-send').prop('disabled', true);

        var historyForApi = state.history.slice();

        $.ajax({
            url: cfg.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'ai_assistant_query',
                nonce: cfg.nonce,
                message: input,
                conversation_history: JSON.stringify(historyForApi)
            }
        }).done(function (resp) {
            $('.chat-messages .typing').remove();
            if (resp && resp.success) {
                if (resp.data && resp.data.debug) lastDebugLogs = resp.data.debug;
                renderMessage('bot', resp.data.response);
            } else {
                var msg = (resp && resp.data && resp.data.message) || cfg.i18n.errorGeneric;
                if (resp && resp.data && resp.data.debug) lastDebugLogs = resp.data.debug;
                renderMessage('bot', msg);
            }
        }).fail(function (xhr, status, error) {
            $('.chat-messages .typing').remove();
            var raw = ((xhr && xhr.responseText) || '').substring(0, 300);
            var httpStatus = (xhr && xhr.status) || 0;
            lastDebugLogs = [
                'HTTP ' + httpStatus,
                'Status: ' + status,
                'Error: ' + error,
                'Response: ' + raw
            ];

            // Essaie d'extraire le message propre depuis la réponse JSON.
            var cleanMsg = null;
            if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                cleanMsg = xhr.responseJSON.data.message;
            } else {
                try {
                    var parsed = JSON.parse(raw);
                    if (parsed && parsed.data && parsed.data.message) cleanMsg = parsed.data.message;
                } catch(e) {}
            }

            if (cleanMsg) {
                renderMessage('bot', cleanMsg);
            } else if (raw === '0' || raw === '-1') {
                renderMessage('bot', 'Le service est momentanément indisponible. Merci de réessayer.');
            } else {
                renderMessage('bot', cfg.i18n.errorNetwork);
            }
        }).always(function () {
            $('#ai-chat-send').prop('disabled', false);
        });
    }

    // ───────── Init ─────────

    buildWidget();

    // Firefox / Safari : bfcache restaure la page telle qu'à sa sortie.
    // On force un reload depuis le storage à chaque affichage de page pour être cohérent.
    window.addEventListener('pageshow', function () {
        state = loadState();
        restoreMessages();
        if (state.opened) {
            $('#ai-chat-widget').show();
            $('#ai-chat-bubble').hide();
            setTimeout(scrollToBottom, 50);
        } else {
            $('#ai-chat-widget').hide();
            $('#ai-chat-bubble').show();
        }
    });

    // Focus de la fenêtre : re-synchronise aussi (utile si plusieurs onglets en mode "local").
    window.addEventListener('focus', function () {
        var fresh = loadState();
        if (JSON.stringify(fresh.messages) !== JSON.stringify(state.messages)) {
            state = fresh;
            restoreMessages();
        }
    });

    // Synchronisation inter-onglets (mode "local" uniquement) :
    // si un autre onglet ajoute un message, on re-synchronise.
    window.addEventListener('storage', function (ev) {
        if (ev.key === STORAGE_KEY && cfg.persistence === 'local') {
            state = loadState();
            restoreMessages();
        }
    });
});
