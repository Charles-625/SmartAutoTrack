    <?php if (!empty($showNavbar)): ?>
    </main>
    <?php endif; ?>
    
    <!-- Modals -->
    <div id="modal-overlay" class="modal-overlay">
        <div class="modal-content">
            <button class="modal-close" id="modalClose">
                <i class="fas fa-times"></i>
            </button>
            <div class="modal-body">
                <!-- Le contenu sera injecté via JavaScript -->
            </div>
        </div>
    </div>
    
    <!-- Toast notifications -->
    <div id="toast-container" class="toast-container"></div>
    
    <!-- Scripts -->
    <script>
        // Configuration globale
        const SITE_URL = '<?php echo SITE_URL; ?>';
        const USER_ROLE = '<?php echo h($userRole); ?>';
        const USER_ID = <?php echo h($isLoggedIn ? $_SESSION['user_id'] : 'null'); ?>;
        
        // Initialisation des notifications
        $(document).ready(function() {
            if (USER_ID) {
                loadNotifications();
                loadMessageCount();
                
                // Actualiser les notifications toutes les 30 secondes
                setInterval(function() {
                    loadNotifications();
                    loadMessageCount();
                }, 30000);
            }
        });
        
        // Fonction pour charger les notifications
        function loadNotifications() {
            $.ajax({
                url: SITE_URL + 'ajax/notifications.php',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        updateNotificationCounter(response.unread_count);
                        displayNotifications(response.notifications);
                    }
                },
                error: function() {
                    console.error('Erreur lors du chargement des notifications');
                }
            });
        }
        
        // Fonction pour charger le compteur de messages
        function loadMessageCount() {
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
        
        // Fonction pour mettre à jour le compteur de notifications
        function updateNotificationCounter(count) {
            const counter = $('#notificationCounter');
            counter.text(count);
            if (count > 0) {
                counter.addClass('has-notifications');
            } else {
                counter.removeClass('has-notifications');
            }
        }
        
        // Fonction pour afficher les notifications
        function displayNotifications(notifications) {
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
                            <i class="fas fa-${getNotificationIcon(notification.type)}"></i>
                        </div>
                        <div class="notification-content">
                            <div class="notification-title">${SmartAutoTrack.utils.escapeHtml(notification.titre)}</div>
                            <div class="notification-message">${SmartAutoTrack.utils.escapeHtml(notification.message)}</div>
                            <div class="notification-time">${formatTime(notification.date_creation)}</div>
                        </div>
                        ${notification.lu === 'non' ? '<div class="notification-dot"></div>' : ''}
                    </div>
                `);
                
                item.click(function() {
                    markNotificationAsRead(notification.id);
                });
                
                container.append(item);
            });
        }
        
        // Fonction pour obtenir l'icône selon le type de notification
        function getNotificationIcon(type) {
            const icons = {
                'anomalie': 'exclamation-triangle',
                'intervention': 'calendar-check',
                'message': 'envelope',
                'rapport': 'file-alt',
                'validation': 'check-circle'
            };
            return icons[type] || 'bell';
        }
        
        // Fonction pour formater l'heure
        function formatTime(dateString) {
            const date = new Date(dateString);
            const now = new Date();
            const diff = now - date;
            
            if (diff < 60000) return 'À l\'instant';
            if (diff < 3600000) return Math.floor(diff / 60000) + ' min';
            if (diff < 86400000) return Math.floor(diff / 3600000) + ' h';
            return Math.floor(diff / 86400000) + ' j';
        }
        
        // Fonction pour marquer une notification comme lue
        function markNotificationAsRead(notificationId) {
            $.ajax({
                url: SITE_URL + 'ajax/mark_notification_read.php',
                method: 'POST',
                data: { notification_id: notificationId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        loadNotifications();
                    }
                }
            });
        }
        
        // Marquer toutes les notifications comme lues
        $('#markAllRead').click(function() {
            $.ajax({
                url: SITE_URL + 'ajax/mark_all_notifications_read.php',
                method: 'POST',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        loadNotifications();
                    }
                }
            });
        });
        
        // Gestion des dropdowns
        $('.notification-btn').click(function(e) {
            e.stopPropagation();
            $('#notificationPanel').toggle();
        });
        
        $('.user-btn').click(function(e) {
            e.stopPropagation();
            $('.user-menu').toggle();
        });
        
        // Fermer les dropdowns en cliquant ailleurs
        $(document).click(function() {
            $('#notificationPanel').hide();
            $('.user-menu').hide();
        });
        
        // Fonction pour afficher des toasts
        function showToast(message, type = 'info') {
            const toast = $(`
                <div class="toast toast-${type}">
                    <div class="toast-content">
                        <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
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
        }
        
        // Fonction pour afficher un modal
        function showModal(content, title = '') {
            const modal = $('#modal-overlay');
            const modalBody = $('.modal-body');
            
            if (title) {
                modalBody.html(`<h2 class="modal-title">${title}</h2>${content}`);
            } else {
                modalBody.html(content);
            }
            
            modal.fadeIn();
        }
        
        // Fermer le modal
        $('#modalClose, #modal-overlay').click(function(e) {
            if (e.target === this) {
                $('#modal-overlay').fadeOut();
            }
        });
    </script>
</body>
</html>
