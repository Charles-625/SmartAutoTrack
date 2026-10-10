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

    // ---- Photo jointe (facultative) ----
    const fileInput = document.getElementById('aiChatFile');
    const attachBtn = document.getElementById('aiChatAttach');
    const preview = document.getElementById('aiChatPreview');
    let photo = null; // { blob, url } : photo prête à partir avec le prochain message

    /** Retire la photo en attente et son aperçu. */
    function clearPhoto() {
        if (photo) URL.revokeObjectURL(photo.url);
        photo = null;
        if (preview) { preview.innerHTML = ''; preview.hidden = true; }
        if (fileInput) fileInput.value = '';
    }

    /**
     * Réduit la photo (1280 px max, JPEG) avant l'envoi : plus rapide sur
     * mobile et sous la limite du serveur. En cas d'échec, envoie l'original.
     * @param {File} file Fichier choisi.
     * @returns {Promise<Blob>}
     */
    function shrink(file) {
        return new Promise(function (resolve) {
            const img = new Image();
            const src = URL.createObjectURL(file);
            img.onload = function () {
                const scale = Math.min(1, 1280 / Math.max(img.width, img.height));
                const canvas = document.createElement('canvas');
                canvas.width = Math.round(img.width * scale);
                canvas.height = Math.round(img.height * scale);
                canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                URL.revokeObjectURL(src);
                canvas.toBlob(function (blob) { resolve(blob || file); }, 'image/jpeg', 0.85);
            };
            img.onerror = function () { URL.revokeObjectURL(src); resolve(file); };
            img.src = src;
        });
    }

    if (attachBtn && fileInput && preview) {
        attachBtn.addEventListener('click', function () { fileInput.click(); });
        fileInput.addEventListener('change', function () {
            const file = fileInput.files && fileInput.files[0];
            if (!file) return;
            if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
                clearPhoto();
                addMessage('error', 'Format non accepté : envoyez une photo JPG, PNG ou WebP.');
                return;
            }
            shrink(file).then(function (blob) {
                clearPhoto();
                photo = { blob: blob, url: URL.createObjectURL(blob) };
                const thumb = document.createElement('img');
                thumb.src = photo.url;
                thumb.alt = 'Photo jointe';
                const label = document.createElement('span');
                label.textContent = 'Photo prête : ajoutez une question ou envoyez directement.';
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'ai-chat-preview-remove';
                remove.setAttribute('aria-label', 'Retirer la photo');
                remove.textContent = '×';
                remove.addEventListener('click', clearPhoto);
                preview.append(thumb, label, remove);
                preview.hidden = false;
                input.focus();
            });
        });
    }

    /**
     * Ajoute au fil la bulle de l'utilisateur, avec la vignette de sa photo.
     * @param {string} text Question (peut être vide si une photo est jointe).
     * @param {?string} imageUrl URL locale de la photo.
     */
    function addUserMessage(text, imageUrl) {
        const div = addMessage('user', text);
        if (imageUrl) {
            const img = document.createElement('img');
            img.src = imageUrl;
            img.alt = 'Photo envoyée';
            img.className = 'ai-msg-photo';
            div.prepend(img);
            img.addEventListener('load', function () { log.scrollTop = log.scrollHeight; });
        }
    }

    /**
     * Envoie une question au serveur. Une seule requête à la fois (classe
     * is-busy) ; une bulle « réfléchit… » est remplacée par la réponse ou
     * par le message d'erreur renvoyé par le serveur.
     * @param {string} text Question saisie ou suggérée.
     */
    function send(text) {
        if ((!text && !photo) || root.classList.contains('is-busy')) return;
        const data = new FormData();
        data.append('message', text);
        let shownUrl = null;
        if (photo) {
            data.append('image', photo.blob, 'photo.jpg');
            shownUrl = URL.createObjectURL(photo.blob);
            clearPhoto();
        }
        addUserMessage(text, shownUrl);
        input.value = '';
        root.classList.add('is-busy');
        const pending = addMessage('assistant', 'L\u2019assistant réfléchit\u2026');
        pending.classList.add('pending');

        $.ajax({
            url: endpoint(),
            method: 'POST',
            dataType: 'json',
            data: data,
            processData: false,
            contentType: false,
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
                clearPhoto();
                log.innerHTML = '';
                root.classList.remove('has-messages');
            });
        });
    }
})();
