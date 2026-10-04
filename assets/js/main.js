/**
 * SmartAutoTrack - JavaScript principal
  *
  * Chargé sur toutes les pages par includes/header.php, juste après jQuery
  * et avant assets/js/themes.js. Les globales SITE_URL et USER_ID sont
  * définies plus bas dans includes/footer.php : elles ne sont donc lues
  * qu'après le chargement du DOM (init, gestionnaires d'événements).
  *
  * Contenu : jeton CSRF ajouté aux requêtes AJAX jQuery, objet global
  * window.SmartAutoTrack (utilitaires, notifications, messages, fenêtre
  * modale, toasts, formulaires, tableaux), gestion commune des erreurs AJAX,
  * menu mobile des tableaux de bord (point rouge sur le bouton si la
  * sidebar porte une pastille de nouveautés .nav-unread), règles de mot de
  * passe affichées en direct, filtres de saisie (data-only) et fenêtre « Voir le rapport » des
  * journaux d'activité (boutons .report-modal-trigger, styles .report-modal
  * dans assets/css/style.css ; bouton « Payer cette réparation » pour le
  * client propriétaire).
 */

/**
 * Lit le jeton CSRF publié par includes/header.php dans
 * <meta name="csrf-token">. Lu à chaque appel (et non mis en cache) car la
 * balise est placée après le chargement de ce script.
 * Aussi utilisé par themes.js (en-tête X-CSRF-Token de fetch).
 * @returns {string} Le jeton, ou une chaîne vide si la balise est absente.
 */
function smartautotrackCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

// Toute requête jQuery vers le site porte l'en-tête X-CSRF-Token, vérifié
// côté serveur (config/config.php). Les requêtes vers un autre domaine ne le
// reçoivent pas, pour ne jamais divulguer le jeton.
if (window.jQuery) {
    jQuery.ajaxSetup({
        beforeSend: function(xhr, settings) {
            if (!settings.crossDomain) {
                xhr.setRequestHeader('X-CSRF-Token', smartautotrackCsrfToken());
            }
        }
    });
}

// Configuration globale
// Espace de noms unique : évite de multiplier les fonctions globales. Les
// sous-objets dépendent de jQuery ($) et des éléments communs du pied de page
// (#modal-overlay, #toast-container).
window.SmartAutoTrack = {
    // Fonctions utilitaires
    utils: {
        // Formater une date
        formatDate: function(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('fr-FR', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        },
        
        // Formater une date courte
        formatDateShort: function(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('fr-FR', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric'
            });
        },
        
        // Formater l'heure
        formatTime: function(dateString) {
            const date = new Date(dateString);
            return date.toLocaleTimeString('fr-FR', {
                hour: '2-digit',
                minute: '2-digit'
            });
        },
        
        // Échapper une valeur avant de l'insérer dans du HTML (anti-XSS).
        // Les entités déjà présentes (&amp;, &#039;...) sont conservées : les champs saisis
        // passent par sanitize() côté PHP et sont donc déjà encodés en base.
        escapeHtml: function(value) {
            if (value === null || value === undefined) return '';
            return String(value)
                .replace(/&(?!(?:[a-zA-Z][a-zA-Z0-9]*|#[0-9]+|#[xX][0-9a-fA-F]+);)/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        // Calculer le temps relatif
        getRelativeTime: function(dateString) {
            const now = new Date();
            const date = new Date(dateString);
            const diff = now - date;
            
            const seconds = Math.floor(diff / 1000);
            const minutes = Math.floor(seconds / 60);
            const hours = Math.floor(minutes / 60);
            const days = Math.floor(hours / 24);
            
            if (days > 0) return `il y a ${days} jour${days > 1 ? 's' : ''}`;
            if (hours > 0) return `il y a ${hours} heure${hours > 1 ? 's' : ''}`;
            if (minutes > 0) return `il y a ${minutes} minute${minutes > 1 ? 's' : ''}`;
            return 'à l\'instant';
        },
        
        // Valider un email
        validateEmail: function(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        },
        
        // Valider un téléphone français
        // Format français hérité du modèle d'origine : les numéros camerounais
        // sont contrôlés côté serveur, pas par cette fonction.
        validatePhone: function(phone) {
            const re = /^(?:(?:\+|00)33|0)\s*[1-9](?:[\s.-]*\d{2}){4}$/;
            return re.test(phone);
        },
        
        // Valider une immatriculation française
        // Format SIV strict (AB-123-CD). Le filtre de saisie data-only="plate" et
        // validatePlate() côté PHP (config/config.php), plus souples, font foi.
        validatePlate: function(plate) {
            const re = /^[A-Z]{2}-[0-9]{3}-[A-Z]{2}$/;
            return re.test(plate);
        },
        
        // Nettoyer une chaîne
        sanitize: function(str) {
            return str.replace(/[<>\"'&]/g, function(match) {
                const escape = {
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;',
                    '&': '&amp;'
                };
                return escape[match];
            });
        },
        
        // Copier dans le presse-papiers
        copyToClipboard: function(text) {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function() {
                    showToast('Copié dans le presse-papiers', 'success');
                });
            } else {
                // Fallback pour les navigateurs plus anciens
                const textArea = document.createElement('textarea');
                textArea.value = text;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                showToast('Copié dans le presse-papiers', 'success');
            }
        },
        
        // Télécharger un fichier
        downloadFile: function(url, filename) {
            const link = document.createElement('a');
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    },
    
    // Gestion des notifications
    // Menu des notifications de la barre de navigation : lecture via
    // ajax/notifications.php, marquage via ajax/mark_*_read.php. Le titre et le
    // message sont échappés avant insertion (contenu venant de la base).
    notifications: {
        // Charger les notifications
        load: function() {
            if (typeof USER_ID === 'undefined' || !USER_ID) return;
            
            $.ajax({
                url: SITE_URL + 'ajax/notifications.php',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        SmartAutoTrack.notifications.updateCounter(response.unread_count);
                        SmartAutoTrack.notifications.display(response.notifications);
                    }
                },
                error: function() {
                    console.error('Erreur lors du chargement des notifications');
                }
            });
        },
        
        // Mettre à jour le compteur
        updateCounter: function(count) {
            const counter = $('#notificationCounter');
            counter.text(count);
            if (count > 0) {
                counter.addClass('has-notifications');
            } else {
                counter.removeClass('has-notifications');
            }
        },
        
        // Afficher les notifications
        display: function(notifications) {
            const container = $('#notificationList');
            container.empty();
            
            if (notifications.length === 0) {
                container.html('<div class="no-notifications">Aucune notification</div>');
                return;
            }
            
            notifications.forEach(function(notification) {
                const item = $(`
                    <div class="notification-item ${notification.lu === 'non' ? 'unread' : ''}" data-id="${notification.id}">
                        <div class="notification-icon">
                            <i class="fas fa-${SmartAutoTrack.notifications.getIcon(notification.type)}"></i>
                        </div>
                        <div class="notification-content">
                            <div class="notification-title">${SmartAutoTrack.utils.escapeHtml(notification.titre)}</div>
                            <div class="notification-message">${SmartAutoTrack.utils.escapeHtml(notification.message)}</div>
                            <div class="notification-time">${SmartAutoTrack.utils.getRelativeTime(notification.date_creation)}</div>
                        </div>
                        ${notification.lu === 'non' ? '<div class="notification-dot"></div>' : ''}
                    </div>
                `);
                
                item.click(function() {
                    SmartAutoTrack.notifications.markAsRead(notification.id);
                });
                
                container.append(item);
            });
        },
        
        // Obtenir l'icône selon le type
        getIcon: function(type) {
            const icons = {
                'anomalie': 'exclamation-triangle',
                'intervention': 'calendar-check',
                'message': 'envelope',
                'rapport': 'file-alt',
                'validation': 'check-circle'
            };
            return icons[type] || 'bell';
        },
        
        // Marquer comme lue
        markAsRead: function(notificationId) {
            $.ajax({
                url: SITE_URL + 'ajax/mark_notification_read.php',
                method: 'POST',
                data: { notification_id: notificationId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        SmartAutoTrack.notifications.load();
                    }
                }
            });
        },
        
        // Marquer toutes comme lues
        markAllAsRead: function() {
            $.ajax({
                url: SITE_URL + 'ajax/mark_all_notifications_read.php',
                method: 'POST',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        SmartAutoTrack.notifications.load();
                    }
                }
            });
        }
    },
    
    // Gestion des messages
    messages: {
        // Charger le compteur de messages
        loadCount: function() {
            if (typeof USER_ID === 'undefined' || !USER_ID) return;
            
            $.ajax({
                url: SITE_URL + 'ajax/message_count.php',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#messageCounter').text(response.count);
                    }
                },
                error: function() {
                    console.error('Erreur lors du chargement du compteur de messages');
                }
            });
        }
    },
    
    // Gestion des modals
    modal: {
        // Afficher un modal
        /**
         * Ouvre la fenêtre modale commune (#modal-overlay de includes/footer.php).
         * Le contenu et le titre sont insérés tels quels en HTML : ne passer que du
         * HTML construit par le code, jamais une saisie non échappée.
         * @param {string} content HTML du corps.
         * @param {string} [title] Titre facultatif.
         */
        show: function(content, title) {
            const modal = $('#modal-overlay');
            const modalBody = $('.modal-body');
            
            if (title) {
                modalBody.html(`<h2 class="modal-title">${title}</h2>${content}`);
            } else {
                modalBody.html(content);
            }
            
            modal.fadeIn();
        },
        
        // Fermer le modal
        hide: function() {
            $('#modal-overlay').fadeOut();
        }
    },
    
    // Gestion des toasts
    toast: {
        // Afficher un toast
        /**
         * Affiche une notification éphémère (toast) dans #toast-container,
         * supprimée au bout de 5 secondes ou au clic sur la croix. Le message est
         * échappé : il est affiché comme du texte.
         * @param {string} message Texte à afficher.
         * @param {string} [type='info'] success, error, warning ou info.
         */
        show: function(message, type = 'info') {
            const toast = $(`
                <div class="toast toast-${type}">
                    <div class="toast-content">
                        <i class="fas fa-${SmartAutoTrack.toast.getIcon(type)}"></i>
                        <span>${SmartAutoTrack.utils.escapeHtml(message)}</span>
                    </div>
                    <button class="toast-close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `);
            
            $('#toast-container').append(toast);
            
            // Auto-suppression après 5 secondes
            setTimeout(function() {
                toast.remove();
            }, 5000);
            
            // Suppression manuelle
            toast.find('.toast-close').click(function() {
                toast.remove();
            });
        },
        
        // Obtenir l'icône selon le type
        getIcon: function(type) {
            const icons = {
                'success': 'check-circle',
                'error': 'exclamation-circle',
                'warning': 'exclamation-triangle',
                'info': 'info-circle'
            };
            return icons[type] || 'info-circle';
        }
    },
    
    // Gestion des formulaires
    forms: {
        // Valider un formulaire
        /**
         * Contrôle minimal avant envoi : champs [required] non vides et format des
         * champs email. Simple confort de saisie ; la validation qui fait foi est
         * faite côté serveur par chaque page.
         * @param {jQuery} form Formulaire à contrôler.
         * @returns {boolean} true si le formulaire peut être envoyé.
         */
        validate: function(form) {
            let isValid = true;
            const errors = [];
            
            // Vérifier les champs requis
            form.find('[required]').each(function() {
                const field = $(this);
                const value = field.val().trim();
                
                if (!value) {
                    isValid = false;
                    errors.push(`Le champ "${field.attr('name')}" est requis`);
                    field.addClass('error');
                } else {
                    field.removeClass('error');
                }
            });
            
            // Validation spécifique des emails
            form.find('input[type="email"]').each(function() {
                const field = $(this);
                const value = field.val().trim();
                
                if (value && !SmartAutoTrack.utils.validateEmail(value)) {
                    isValid = false;
                    errors.push('Format d\'email invalide');
                    field.addClass('error');
                }
            });
            
            // Afficher les erreurs
            if (!isValid) {
                SmartAutoTrack.toast.show(errors.join('<br>'), 'error');
            }
            
            return isValid;
        },
        
        // Réinitialiser un formulaire
        reset: function(form) {
            form[0].reset();
            form.find('.error').removeClass('error');
        }
    },
    
    // Gestion des tables
    tables: {
        // Initialiser une table avec recherche
        /**
         * Prépare les tableaux : les enveloppe dans .table-responsive (défilement
         * horizontal sur mobile) et branche le filtrage des lignes sur un champ
         * .table-search s'il existe.
         * @param {string} selector Sélecteur des tableaux (appelé avec '.table').
         */
        init: function(selector) {
            const table = $(selector);

            // Envelopper les tableaux pour le scroll horizontal sur mobile
            table.each(function() {
                const $t = $(this);
                if (!$t.parent().hasClass('table-responsive')) {
                    $t.wrap('<div class="table-responsive"></div>');
                }
            });

            const searchInput = table.find('.table-search');
            const rows = table.find('tbody tr');
            
            if (searchInput.length) {
                searchInput.on('input', function() {
                    const searchTerm = $(this).val().toLowerCase();
                    
                    rows.each(function() {
                        const row = $(this);
                        const text = row.text().toLowerCase();
                        
                        if (text.includes(searchTerm)) {
                            row.show();
                        } else {
                            row.hide();
                        }
                    });
                });
            }
        }
    },
    
    // Initialisation
    /**
     * Point d'entrée, appelé au chargement du DOM. Pour un utilisateur connecté,
     * charge puis rafraîchit toutes les 30 secondes les notifications et le
     * compteur de messages.
     */
    init: function() {
        // Charger les notifications et messages si l'utilisateur est connecté
        if (typeof USER_ID !== 'undefined' && USER_ID) {
            SmartAutoTrack.notifications.load();
            SmartAutoTrack.messages.loadCount();
            
            // Actualiser toutes les 30 secondes
            setInterval(function() {
                SmartAutoTrack.notifications.load();
                SmartAutoTrack.messages.loadCount();
            }, 30000);
        }
        
        // Initialiser les événements globaux
        this.initGlobalEvents();
        
        // Initialiser les tables
        this.tables.init('.table');
    },
    
    // Événements globaux
    /**
     * Branche les comportements communs à toutes les pages : menu mobile de la
     * barre historique, menus déroulants, fermeture de la modale, validation des
     * formulaires à l'envoi et confirmation des boutons .btn-confirm
     * (message dans data-confirm).
     */
    initGlobalEvents: function() {
        // Bascule menu mobile
        $('.navbar-toggle').on('click', function() {
            const $btn = $(this);
            const $menu = $('#navbarMenu');
            const isOpen = $menu.hasClass('open');
            $menu.toggleClass('open', !isOpen);
            $btn.attr('aria-expanded', String(!isOpen));
        });

        // Gestion des dropdowns
        $('.dropdown-toggle').click(function(e) {
            e.stopPropagation();
            $(this).next('.dropdown-menu').toggle();
        });
        
        // Fermer les dropdowns en cliquant ailleurs
        $(document).click(function() {
            $('.dropdown-menu').hide();
        });
        
        // Gestion des modals
        $('.modal-close, #modal-overlay').click(function(e) {
            if (e.target === this) {
                SmartAutoTrack.modal.hide();
            }
        });
        
        // Gestion des toasts
        $('.toast-close').click(function() {
            $(this).closest('.toast').remove();
        });
        
        // Validation des formulaires en temps réel
        $('form').on('submit', function(e) {
            if (!SmartAutoTrack.forms.validate($(this))) {
                e.preventDefault();
                return false;
            }
        });
        
        // Gestion des boutons de confirmation
        $('.btn-confirm').click(function(e) {
            const message = $(this).data('confirm') || 'Êtes-vous sûr de vouloir effectuer cette action ?';
            
            if (!confirm(message)) {
                e.preventDefault();
                return false;
            }
        });
    }
};

// Fonction globale pour afficher des toasts
// Note : includes/footer.php déclare aussi showToast() et showModal() ; sur
// les pages qui l'incluent, ce sont ses versions (déclarées plus tard) qui
// s'appliquent.
/**
 * Raccourci global vers SmartAutoTrack.toast.show().
 * @param {string} message Texte à afficher.
 * @param {string} [type='info'] success, error, warning ou info.
 */
function showToast(message, type = 'info') {
    SmartAutoTrack.toast.show(message, type);
}

// Fonction globale pour afficher des modals
/**
 * Raccourci global vers SmartAutoTrack.modal.show().
 * @param {string} content HTML du corps (de confiance).
 * @param {string} [title=''] Titre facultatif.
 */
function showModal(content, title = '') {
    SmartAutoTrack.modal.show(content, title);
}

// Initialisation au chargement de la page
$(document).ready(function() {
    SmartAutoTrack.init();
});

// ============================================================
// Restriction de saisie par type de champ, sur toute l'application (tous
// les formulaires chargent ce fichier via includes/header.php) :
//   - nom / prénom  : lettres uniquement (accents, espace, tiret et
//     apostrophe conservés pour les noms composés type "Jean-Pierre" ou
//     "O'Brien") ;
//   - téléphone     : chiffres uniquement.
// Les champs potentiellement mixtes (immatriculation, marque/modèle,
// spécialité, raison sociale, nom de garage...) ne sont volontairement PAS
// concernés : ils utilisent un autre attribut "name" et ne matchent donc pas
// ces sélecteurs. Ceci est un confort de saisie côté client — la validation
// réelle reste faite côté serveur sur chaque formulaire.
// ============================================================
/**
 * Retire d'un champ les caractères interdits en gardant le curseur à sa
 * place (sinon il sauterait en fin de champ à chaque frappe).
 * @param {HTMLInputElement} el Champ à nettoyer.
 * @param {RegExp} regex Caractères interdits (drapeau g).
 */
function smartautotrackFilterInput(el, regex) {
    const start = el.selectionStart;
    const originalLength = el.value.length;
    const filtered = el.value.replace(regex, '');
    if (filtered === el.value) return;
    el.value = filtered;
    const newPos = Math.max(0, start - (originalLength - filtered.length));
    if (typeof el.setSelectionRange === 'function') {
        el.setSelectionRange(newPos, newPos);
    }
}

$(document).on('input', 'input[name="nom"], input[name="prenom"]', function () {
    smartautotrackFilterInput(this, /[^A-Za-zÀ-ÖØ-öø-ÿ' -]/g);
});

$(document).on('input', 'input[name="telephone"]', function () {
    smartautotrackFilterInput(this, /[^0-9]/g);
});

// Gestion des erreurs AJAX globales
// 401 : session expirée, retour à la connexion ; 403 et 5xx : message
// générique, le détail de l'erreur restant dans les journaux du serveur.
$(document).ajaxError(function(event, xhr, settings, thrownError) {
    if (xhr.status === 401) {
        showToast('Session expirée. Veuillez vous reconnecter.', 'error');
        setTimeout(function() {
            window.location.href = SITE_URL + 'auth/login.php';
        }, 2000);
    } else if (xhr.status === 403) {
        showToast('Accès refusé.', 'error');
    } else if (xhr.status >= 500) {
        showToast('Erreur serveur. Veuillez réessayer plus tard.', 'error');
    }
});

// ============================================================
// Menu mobile des tableaux de bord (client/admin/garage/technicien).
// Ces 4 espaces partagent le même gabarit "{préfixe}-shell / {préfixe}-sidebar
// / {préfixe}-main" (voir assets/css/{client,admin,garage,technicien}_v2.css),
// seul le préfixe change. En dessous de 720px la sidebar passe en tiroir
// (position: fixed, masquée hors écran) : ce script détecte le préfixe
// présent sur la page, injecte le bouton hamburger + le rideau, et gère
// l'ouverture/fermeture — une seule fois pour les 4 espaces, sans toucher
// aux ~55 pages qui utilisent ce gabarit. Si la sidebar porte une pastille
// de nouveautés (.nav-unread), le bouton affiche un point rouge.
// ============================================================
$(function () {
    var prefix = ['v2', 'av2', 'gv2', 'tv2'].find(function (p) {
        return document.querySelector('.' + p + '-shell');
    });
    if (!prefix) return;

    var shell = document.querySelector('.' + prefix + '-shell');
    var sidebar = shell.querySelector('.' + prefix + '-sidebar');
    var main = shell.querySelector('.' + prefix + '-main');
    if (!sidebar || !main) return;

    var backdrop = document.createElement('div');
    backdrop.className = prefix + '-nav-backdrop';
    shell.appendChild(backdrop);

    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = prefix + '-menu-toggle';
    toggle.setAttribute('aria-label', 'Ouvrir le menu');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.innerHTML = '<svg width="19" height="19" viewBox="0 0 24 24" fill="none"><path d="M3 6H21M3 12H21M3 18H21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    main.insertBefore(toggle, main.firstChild);

    // Pastilles rouges de nouveautés (.nav-unread, onglets Journal et
    // Interventions) invisibles tant que le tiroir est fermé : point rouge
    // sur le bouton (.nav-toggle-unread, assets/css/style.css) et libellé
    // accessible complété.
    if (sidebar.querySelector('.nav-unread')) {
        toggle.classList.add('nav-toggle-unread');
        toggle.setAttribute('aria-label', 'Ouvrir le menu (nouveautés)');
    }

    /** Ferme le tiroir de navigation et rétablit le défilement de la page. */
    function closeNav() {
        sidebar.classList.remove('is-open');
        backdrop.classList.remove('is-open');
        document.body.classList.remove('nav-open');
        toggle.setAttribute('aria-expanded', 'false');
    }
    /** Ouvre le tiroir de navigation (classe nav-open : bloque le défilement du fond). */
    function openNav() {
        sidebar.classList.add('is-open');
        backdrop.classList.add('is-open');
        document.body.classList.add('nav-open');
        toggle.setAttribute('aria-expanded', 'true');
    }

    toggle.addEventListener('click', function () {
        if (sidebar.classList.contains('is-open')) closeNav(); else openNav();
    });
    backdrop.addEventListener('click', closeNav);
    sidebar.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', closeNav);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeNav();
    });
    window.addEventListener('resize', function () {
        if (window.innerWidth > 720) closeNav();
    });
});

// Robustesse des mots de passe : liste des règles cochée en direct sous chaque
// champ data-password-policy. Mêmes règles que includes/password_policy.php,
// qui reste la validation qui fait foi côté serveur.
document.addEventListener('DOMContentLoaded', function () {
    const rules = [
        { label: '8 caractères minimum', test: function (v) { return v.length >= 8; } },
        { label: 'Une lettre minuscule', test: function (v) { return /[a-z]/.test(v); } },
        { label: 'Une lettre majuscule', test: function (v) { return /[A-Z]/.test(v); } },
        { label: 'Un chiffre', test: function (v) { return /[0-9]/.test(v); } },
        { label: 'Un caractère spécial (ex. ! @ # $ %)', test: function (v) { return /[^A-Za-z0-9]/.test(v); } }
    ];

    document.querySelectorAll('input[data-password-policy]').forEach(function (input) {
        const list = document.createElement('ul');
        list.className = 'pw-rules';
        list.setAttribute('aria-live', 'polite');
        const items = rules.map(function (rule) {
            const li = document.createElement('li');
            li.textContent = rule.label;
            list.appendChild(li);
            return li;
        });
        input.insertAdjacentElement('afterend', list);

        /**
         * Coche les règles respectées et bloque l'envoi du formulaire
         * (setCustomValidity) tant qu'une règle manque.
         */
        function update() {
            const value = input.value;
            let missing = 0;
            rules.forEach(function (rule, i) {
                const ok = rule.test(value);
                items[i].classList.toggle('ok', ok);
                if (!ok) missing++;
            });
            // Un champ facultatif laissé vide (ex. profil sans changement) reste valide
            const blocking = missing > 0 && (value !== '' || input.required);
            input.setCustomValidity(blocking ? 'Le mot de passe ne respecte pas toutes les règles indiquées.' : '');
        }

        input.addEventListener('input', update);
        update();
    });
});

// Champs restreints : data-only="digits" (téléphone, montants…) n'accepte que
// des chiffres, data-only="letters" (nom, prénom…) que des lettres, accents,
// espace, tiret et apostrophe, data-only="plate" (immatriculation) lettres,
// chiffres, espace et tiret en majuscules, data-only="model" (modèle de
// véhicule) lettres, chiffres, espace et - . + ! /. Le texte collé est filtré
// aussi. La validation serveur (validateDigitsOnly, validateLettersOnly,
// validatePlate, validateModel) reste celle qui fait foi.
(function () {
    const forbidden = {
        digits: /[^0-9]/g,
        letters: /[^A-Za-zÀ-ÖØ-öø-ÿ' -]/g,
        plate: /[^A-Za-z0-9 -]/g,
        model: /[^A-Za-zÀ-ÖØ-öø-ÿ0-9 .\/+!-]/g
    };

    // Délégation sur document : couvre aussi les champs ajoutés après le
    // chargement (modales, formulaires dynamiques).
    document.addEventListener('input', function (e) {
        const input = e.target;
        const pattern = input.dataset && forbidden[input.dataset.only];
        if (!pattern || input.type === 'number') return;
        let cleaned = input.value.replace(pattern, '');
        if (input.dataset.only === 'plate') cleaned = cleaned.toUpperCase();
        if (cleaned === input.value) return;
        const caret = input.selectionStart - (input.value.length - cleaned.length);
        input.value = cleaned;
        try { input.setSelectionRange(caret, caret); } catch (err) { /* type sans sélection */ }
    });

    // Un <input type="number"> accepte nativement e, E, + et - (notation
    // scientifique) : on les bloque, ainsi que la virgule/le point sur les
    // champs entiers (data-only="digits").
    document.addEventListener('keydown', function (e) {
        const input = e.target;
        if (!(input instanceof HTMLInputElement) || input.type !== 'number') return;
        const blocked = ['e', 'E', '+', '-'];
        if (input.dataset.only === 'digits') blocked.push('.', ',');
        if (blocked.indexOf(e.key) !== -1) e.preventDefault();
    });
})();

// Fenêtre « Voir le rapport » des journaux d'activité (client, technicien,
// garage, admin). Chaque bouton .report-modal-trigger porte dans data-report
// le JSON de activity_log_report_json() (includes/activity_log.php) :
// { meta: [{label, value}], fields: [{label, value}], pdf: url|null,
//   pay: url|null }. pay (bouton « Payer cette réparation ») n'est fourni
// qu'au client propriétaire d'une réparation à payer ; null ailleurs.
// Une seule fenêtre par page, créée au premier clic. Tout le texte est posé
// avec textContent (jamais innerHTML) : les valeurs saisies ne sont jamais
// interprétées comme du HTML.
(function () {
    let overlay = null;
    let opener = null;

    /**
     * Crée un élément avec une classe et, éventuellement, un texte.
     * @param {string} tag
     * @param {string} className
     * @param {string} [text] posé avec textContent.
     * @returns {HTMLElement}
     */
    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    /**
     * Remplit un <dl> avec des paires libellé/valeur. Une paire sans
     * libellé occupe toute la largeur.
     * @param {HTMLElement} dl
     * @param {Array<{label: string, value: string}>} pairs
     */
    function fillPairs(dl, pairs) {
        while (dl.firstChild) dl.removeChild(dl.firstChild);
        (Array.isArray(pairs) ? pairs : []).forEach(function (pair) {
            const row = el('div', 'report-modal-row');
            const label = String(pair && pair.label != null ? pair.label : '');
            if (label !== '') row.appendChild(el('dt', '', label));
            else row.classList.add('report-modal-row-full');
            row.appendChild(el('dd', '', String(pair && pair.value != null ? pair.value : '')));
            dl.appendChild(row);
        });
    }

    /**
     * Construit la fenêtre (une fois) : en-tête, informations de
     * l'intervention, tableau du rapport, boutons Payer, PDF et Fermer.
     * @returns {HTMLElement} Le fond (.report-modal-overlay).
     */
    function build() {
        overlay = el('div', 'report-modal-overlay');
        overlay.hidden = true;

        const dialog = el('div', 'report-modal');
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-labelledby', 'report-modal-title');
        dialog.tabIndex = -1;

        const head = el('div', 'report-modal-head');
        const title = el('h2', 'report-modal-title', 'Rapport de fin d’intervention');
        title.id = 'report-modal-title';
        const closeX = el('button', 'report-modal-x', '×');
        closeX.type = 'button';
        closeX.setAttribute('aria-label', 'Fermer');
        head.appendChild(title);
        head.appendChild(closeX);

        const body = el('div', 'report-modal-body');
        const meta = el('dl', 'report-modal-meta');
        const fields = el('dl', 'report-modal-fields');
        const empty = el('p', 'report-modal-empty', 'Le rapport ne contient aucune information.');
        body.appendChild(meta);
        body.appendChild(fields);
        body.appendChild(empty);

        const foot = el('div', 'report-modal-foot');
        const pay = el('a', 'report-modal-btn report-modal-btn-pay', 'Payer cette réparation');
        pay.hidden = true;
        const pdf = el('a', 'report-modal-btn report-modal-btn-primary', 'Télécharger en PDF');
        const close = el('button', 'report-modal-btn', 'Fermer');
        close.type = 'button';
        foot.appendChild(pay);
        foot.appendChild(pdf);
        foot.appendChild(close);

        dialog.appendChild(head);
        dialog.appendChild(body);
        dialog.appendChild(foot);
        overlay.appendChild(dialog);
        document.body.appendChild(overlay);

        closeX.addEventListener('click', hide);
        close.addEventListener('click', hide);
        // Clic hors de la fenêtre : sur le fond uniquement.
        overlay.addEventListener('mousedown', function (e) {
            if (e.target === overlay) hide();
        });
        // Sur document : Échap ferme même si le focus a quitté la fenêtre.
        document.addEventListener('keydown', function (e) {
            if (overlay.hidden) return;
            if (e.key === 'Escape') {
                e.preventDefault();
                hide();
            } else if (e.key === 'Tab') {
                // Le focus reste dans la fenêtre (aria-modal).
                const items = Array.prototype.filter.call(
                    dialog.querySelectorAll('a[href], button'),
                    function (n) { return !n.hidden; }
                );
                if (!items.length) return;
                const first = items[0];
                const last = items[items.length - 1];
                if (e.shiftKey && (document.activeElement === first || document.activeElement === dialog)) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            }
        });
        return overlay;
    }

    /**
     * Pose l'URL sur un lien de la fenêtre seulement si elle pointe vers ce
     * site ; sinon le lien est masqué.
     * @param {HTMLAnchorElement} link
     * @param {*} value URL reçue dans data-report (chaîne ou null).
     */
    function setSameSiteLink(link, value) {
        const url = typeof value === 'string' ? value : '';
        let sameSite = false;
        try {
            sameSite = url !== '' && new URL(url, window.location.href).origin === window.location.origin;
        } catch (err) {
            sameSite = false;
        }
        if (sameSite) link.setAttribute('href', url);
        else link.removeAttribute('href');
        link.hidden = !sameSite;
    }

    /**
     * Ouvre la fenêtre avec les données d'un bouton.
     * @param {HTMLElement} trigger Bouton .report-modal-trigger cliqué.
     */
    function show(trigger) {
        let data;
        try {
            data = JSON.parse(trigger.getAttribute('data-report') || '{}') || {};
        } catch (err) {
            data = {};
        }
        if (!overlay) build();

        const meta = overlay.querySelector('.report-modal-meta');
        const fields = overlay.querySelector('.report-modal-fields');
        fillPairs(meta, data.meta);
        fillPairs(fields, data.fields);
        meta.hidden = !meta.children.length;
        fields.hidden = !fields.children.length;
        overlay.querySelector('.report-modal-empty').hidden = !fields.hidden;

        // Liens PDF et Payer seulement s'ils sont fournis, et vers ce site.
        setSameSiteLink(overlay.querySelector('.report-modal-btn-primary'), data.pdf);
        setSameSiteLink(overlay.querySelector('.report-modal-btn-pay'), data.pay);

        opener = trigger;
        overlay.hidden = false;
        document.documentElement.classList.add('report-modal-open');
        overlay.querySelector('.report-modal-body').scrollTop = 0;
        overlay.querySelector('.report-modal').focus();
    }

    /** Ferme la fenêtre et rend le focus au bouton qui l'a ouverte. */
    function hide() {
        if (!overlay || overlay.hidden) return;
        overlay.hidden = true;
        document.documentElement.classList.remove('report-modal-open');
        if (opener && document.contains(opener)) opener.focus();
        opener = null;
    }

    // Délégation : couvre tous les boutons de la page, même ajoutés plus tard.
    document.addEventListener('click', function (e) {
        const trigger = e.target && e.target.closest ? e.target.closest('.report-modal-trigger') : null;
        if (!trigger) return;
        e.preventDefault();
        show(trigger);
    });
})();
