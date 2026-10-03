/**
 * Assistant IA : conversation avec ajax/assistant_chat.php.
 * L'historique initial est fourni par la page dans window.AI_HISTORY.
 *
 * Chargé uniquement par admin/assistant.php et client/assistant.php
 * (conteneur #aiChat) ; ne fait rien ailleurs. Dépend de jQuery ($.ajax,
 * qui ajoute le jeton CSRF via main.js) et de la constante globale SITE_URL.
 * Si la page affiche le compteur #aiRemaining (client gratuit), il est mis à
 * jour avec le champ « remaining » de chaque réponse du serveur.
 */
(function () {
    const root = document.getElementById('aiChat');
    if (!root) return;

    const log = document.getElementById('aiChatLog');
    const form = document.getElementById('aiChatForm');
    const input = document.getElementById('aiChatInput');
    const endpoint = () => SITE_URL + 'ajax/assistant_chat.php';

    /**
     * Échappe les caractères HTML spéciaux.
     * @param {string} text Texte brut.
     * @returns {string} Texte sûr à insérer via innerHTML.
     */
    function escapeHtml(text) {
        return text.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    /**
     * Écrit le texte d'un message. En mode formaté (réponses de l'IA), le
     * texte est d'abord échappé, puis seuls **gras** et les titres Markdown
     * sont convertis en <strong> : aucun HTML venant du modèle n'est exécuté.
     * @param {HTMLElement} el Bulle de message.
     * @param {string} text Contenu.
     * @param {boolean} formatted true pour appliquer la mise en forme minimale.
     */
    function setText(el, text, formatted) {
        if (!formatted) {
            el.textContent = text;
            return;
        }
        el.innerHTML = escapeHtml(text)
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/^#{1,4}\s*(.+)$/gm, '<strong>$1</strong>');
    }

    /**
     * Ajoute une bulle au fil de discussion et fait défiler jusqu'en bas.
     * @param {string} kind 'user', 'assistant' ou 'error' (classe CSS ai-msg-*).
     * @param {string} text Contenu du message.
     * @returns {HTMLDivElement} La bulle créée (réutilisée pour la réponse en attente).
     */
    function addMessage(kind, text) {
        const div = document.createElement('div');
        div.className = 'ai-msg ai-msg-' + kind;
        setText(div, text, kind === 'assistant');
        log.appendChild(div);
        log.scrollTop = log.scrollHeight;
        root.classList.add('has-messages');
        return div;
    }

    /**
     * Met à jour le compteur de messages gratuits restants, s'il est affiché.
     * @param {?Object} response Réponse JSON du serveur (champ remaining).
     */
    function updateRemaining(response) {
        const counter = document.getElementById('aiRemaining');
        if (counter && response && typeof response.remaining === 'number') {
            counter.textContent = response.remaining;
        }
    }

    (window.AI_HISTORY || []).forEach(m => addMessage(m.role === 'user' ? 'user' : 'assistant', m.content));

    /**
     * Envoie une question au serveur. Une seule requête à la fois (classe
     * is-busy) ; une bulle « réfléchit… » est remplacée par la réponse ou
     * par le message d'erreur renvoyé par le serveur.
     * @param {string} text Question saisie ou suggérée.
     */
    function send(text) {
        if (!text || root.classList.contains('is-busy')) return;
        addMessage('user', text);
        input.value = '';
        root.classList.add('is-busy');
        const pending = addMessage('assistant', 'L\u2019assistant réfléchit\u2026');
        pending.classList.add('pending');

        $.ajax({
            url: endpoint(),
            method: 'POST',
            dataType: 'json',
            data: { message: text },
            success: function (response) {
                pending.className = 'ai-msg ai-msg-assistant';
                setText(pending, response.answer, true);
                updateRemaining(response);
            },
            error: function (xhr) {
                pending.className = 'ai-msg ai-msg-error';
                setText(pending, (xhr.responseJSON && xhr.responseJSON.message) || 'Erreur de l\u2019assistant IA.', false);
                updateRemaining(xhr.responseJSON);
            },
            complete: function () {
                root.classList.remove('is-busy');
                log.scrollTop = log.scrollHeight;
                input.focus();
            }
        });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        send(input.value.trim());
    });

    root.querySelectorAll('[data-question]').forEach(function (button) {
        button.addEventListener('click', function () { send(button.dataset.question); });
    });

    // Nouvelle conversation : efface l'historique côté serveur, puis l'affichage
    // (même si la requête échoue, d'où always()).
    const reset = document.getElementById('aiChatReset');
    if (reset) {
        reset.addEventListener('click', function () {
            $.ajax({ url: endpoint(), method: 'POST', dataType: 'json', data: { action: 'reset' } }).always(function () {
                log.innerHTML = '';
                root.classList.remove('has-messages');
            });
        });
    }
})();
