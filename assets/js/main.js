/**
 * SmartAutoTrack - JavaScript principal
 */

// Configuration globale
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
        validatePhone: function(phone) {
            const re = /^(?:(?:\+|00)33|0)\s*[1-9](?:[\s.-]*\d{2}){4}$/;
            return re.test(phone);
        },
        
        // Valider une immatriculation française
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
function showToast(message, type = 'info') {
    SmartAutoTrack.toast.show(message, type);
}

// Fonction globale pour afficher des modals
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
