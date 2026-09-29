/**
 * Assistant IA : conversation avec ajax/assistant_chat.php.
 * L'historique initial est fourni par la page dans window.AI_HISTORY.
 */
(function () {
    const root = document.getElementById('aiChat');
    if (!root) return;

    const log = document.getElementById('aiChatLog');
    const form = document.getElementById('aiChatForm');
    const input = document.getElementById('aiChatInput');
    const endpoint = () => SITE_URL + 'ajax/assistant_chat.php';

    function escapeHtml(text) {
        return text.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    function setText(el, text, formatted) {
        if (!formatted) {
            el.textContent = text;
            return;
        }
        el.innerHTML = escapeHtml(text)
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/^#{1,4}\s*(.+)$/gm, '<strong>$1</strong>');
    }

    function addMessage(kind, text) {
        const div = document.createElement('div');
        div.className = 'ai-msg ai-msg-' + kind;
        setText(div, text, kind === 'assistant');
        log.appendChild(div);
        log.scrollTop = log.scrollHeight;
        root.classList.add('has-messages');
        return div;
    }

    (window.AI_HISTORY || []).forEach(m => addMessage(m.role === 'user' ? 'user' : 'assistant', m.content));

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
            },
            error: function (xhr) {
                pending.className = 'ai-msg ai-msg-error';
                setText(pending, (xhr.responseJSON && xhr.responseJSON.message) || 'Erreur de l\u2019assistant IA.', false);
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
