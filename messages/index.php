<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';

/**
 * Messagerie interne : liste des conversations de l'utilisateur connecté et
 * fenêtre de discussion, commune aux quatre rôles (habillage v2 par rôle).
 *
 * Accès : tout utilisateur connecté ; seules ses propres conversations sont
 * lues (requêtes bornées à $_SESSION['user_id']).
 *
 * GET contact=<id> : ouvre directement la conversation avec ce contact
 * (bouton « Contacter » d'une réparation, par exemple).
 * Aucun POST ici : le chargement d'une conversation et l'envoi passent par
 * ajax/load_conversation.php et ajax/send_message.php.
 *
 * Tables lues : messages, utilisateur, administrateur, client, technicien,
 * garage, intervention (badges de la sidebar).
 */

requireAuth();

$db = new Database();
$conn = $db->getConnection();

// Habillage v2 (sidebar + logo) pour les 4 rôles. $v2Role vaut 'client',
// 'garage', 'technicien', 'admin' ou null.
$isClientV2 = ($_SESSION['role'] === ROLE_CLIENT);
$isGarageV2 = ($_SESSION['role'] === ROLE_GARAGE);
$isTechnicienV2 = ($_SESSION['role'] === ROLE_TECHNICIEN);
$isAdminV2 = ($_SESSION['role'] === ROLE_ADMIN);
$v2Role = $isClientV2 ? 'client' : ($isGarageV2 ? 'garage' : ($isTechnicienV2 ? 'technicien' : ($isAdminV2 ? 'admin' : null)));
$clientRoleLabel = 'Client particulier';
$isEntreprise = false;
$interventionsActivesCount = 0;
$garagePendingCount = 0;
$technicienTachesCount = 0;
// Compteurs des badges de la sidebar, selon le rôle.
if ($isClientV2) {
    $clientProfile = getUserProfile($conn, (int)$_SESSION['user_id']);
    $isEntreprise = (($clientProfile['typeClient'] ?? 'PARTICULIER') === 'ENTREPRISE');
    $clientRoleLabel = $isEntreprise ? 'Client entreprise' : 'Client particulier';
    $stmtBadge = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idClient = ? AND statut IN ('PLANIFIEE', 'EN_COURS')");
    $stmtBadge->execute([$_SESSION['user_id']]);
    $interventionsActivesCount = (int)$stmtBadge->fetchColumn();
} elseif ($isGarageV2) {
    $garageProfile = getUserProfile($conn, (int)$_SESSION['user_id']);
    $garageId = (int)($garageProfile['idGarage'] ?? 0);
    $stmtBadge = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idGarage = ? AND idTechnicien IS NULL AND statut = 'PLANIFIEE'");
    $stmtBadge->execute([$garageId]);
    $garagePendingCount = (int)$stmtBadge->fetchColumn();
} elseif ($isTechnicienV2) {
    $stmtBadge = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idTechnicien = ? AND statut = 'PLANIFIEE'");
    $stmtBadge->execute([$_SESSION['user_id']]);
    $technicienTachesCount = (int)$stmtBadge->fetchColumn();
}

// Contact à pré-sélectionner (ex. bouton "Contacter" depuis une réparation)
$preselectContact = filter_var($_GET['contact'] ?? null, FILTER_VALIDATE_INT);

// Récupérer les conversations. Le rôle du contact n'est plus une colonne : il
// est déduit en regardant dans quelle table (administrateur/technicien/client/
// garage) son id apparaît. Le contact et la date du dernier message sont
// calculés dans une sous-requête pour pouvoir être réutilisés proprement.
$stmt = $conn->prepare("
    SELECT c.contact_id, u.nom, u.prenom,
           CASE WHEN a.idAdministrateur IS NOT NULL THEN 'admin' WHEN g.idGarage IS NOT NULL THEN 'garage' WHEN t.idTechnicien IS NOT NULL THEN 'technicien' ELSE 'client' END AS role,
           c.last_message_date,
           (SELECT COUNT(*) FROM messages WHERE destinataire_id = ? AND expediteur_id = c.contact_id AND lu = 'non') as unread_count,
           (SELECT m2.contenu FROM messages m2 WHERE (m2.expediteur_id = ? AND m2.destinataire_id = c.contact_id) OR (m2.expediteur_id = c.contact_id AND m2.destinataire_id = ?) ORDER BY m2.date_envoi DESC LIMIT 1) as last_message
    FROM (
        SELECT
            CASE WHEN expediteur_id = ? THEN destinataire_id ELSE expediteur_id END as contact_id,
            MAX(date_envoi) as last_message_date
        FROM messages
        WHERE expediteur_id = ? OR destinataire_id = ?
        GROUP BY contact_id
    ) c
    JOIN utilisateur u ON u.idUtilisateur = c.contact_id
    LEFT JOIN administrateur a ON a.idAdministrateur = c.contact_id
    LEFT JOIN technicien t ON t.idTechnicien = c.contact_id
    LEFT JOIN garage g ON g.idUtilisateur = c.contact_id
    ORDER BY c.last_message_date DESC
");
$uid = $_SESSION['user_id'];
$stmt->execute([$uid, $uid, $uid, $uid, $uid, $uid]);
$conversations = $stmt->fetchAll();

// Récupérer les utilisateurs disponibles pour nouveau message. Comme avant,
// chaque rôle ne peut écrire qu'aux autres rôles (jamais à son propre rôle) ;
// un technicien/garage non validé n'apparaît pas comme destinataire possible.
// La messagerie reste volontairement ouverte entre rôles (comme déjà pour
// admin/client/technicien) : un garage ne voit de toute façon jamais que ses
// propres conversations (la requête ci-dessus est déjà bornée à $uid).
$parts = [];
if ($_SESSION['role'] !== 'admin') {
    $parts[] = "SELECT u.idUtilisateur AS id, u.nom, u.prenom, u.email, 'admin' AS role FROM utilisateur u JOIN administrateur a ON a.idAdministrateur = u.idUtilisateur";
}
if ($_SESSION['role'] !== 'client') {
    $parts[] = "SELECT u.idUtilisateur AS id, u.nom, u.prenom, u.email, 'client' AS role FROM utilisateur u JOIN client c ON c.idClient = u.idUtilisateur";
}
if ($_SESSION['role'] !== 'technicien') {
    $parts[] = "SELECT u.idUtilisateur AS id, u.nom, u.prenom, u.email, 'technicien' AS role FROM utilisateur u JOIN technicien t ON t.idTechnicien = u.idUtilisateur AND t.statutValidation = 'VALIDE'";
}
if ($_SESSION['role'] !== 'garage') {
    $parts[] = "SELECT u.idUtilisateur AS id, u.nom, u.prenom, u.email, 'garage' AS role FROM utilisateur u JOIN garage g ON g.idUtilisateur = u.idUtilisateur AND g.statutGarage = 'VALIDE'";
}
$stmt = $conn->prepare("
    SELECT id, nom, prenom, role, email FROM (" . implode(' UNION ALL ', $parts) . ") x
    WHERE id != ?
    ORDER BY nom, prenom
");
$stmt->execute([$_SESSION['user_id']]);
$users = $stmt->fetchAll();

// Habillage : feuille de style et classe du <body> selon le rôle.
$pageTitle = 'Messages';
if ($v2Role === 'client') {
    $hideNavbar = true;
    $bodyClass = 'v2';
    $extraStylesheets = ['assets/css/client_v2.css'];
    $extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
} elseif ($v2Role === 'garage') {
    $hideNavbar = true;
    $bodyClass = 'gv2';
    $extraStylesheets = ['assets/css/garage_v2.css'];
    $extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
} elseif ($v2Role === 'technicien') {
    $hideNavbar = true;
    $bodyClass = 'tv2';
    $extraStylesheets = ['assets/css/technicien_v2.css'];
    $extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
} elseif ($v2Role === 'admin') {
    require_once '../admin/includes/helpers.php';
    $hideNavbar = true;
    $bodyClass = 'av2';
    $extraStylesheets = ['assets/css/admin_v2.css'];
    $extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
}
include '../includes/header.php';
?>
<?php if ($v2Role === 'client'): ?>
<div class="v2-shell">
    <?php $activeNav = 'messages'; $interventionsBadge = $interventionsActivesCount; include '../client/includes/sidebar.php'; ?>
    <main class="v2-main">
<?php elseif ($v2Role === 'garage'): ?>
<div class="gv2-shell">
    <?php $activeNav = 'messages'; $pendingBadge = $garagePendingCount; include '../garage/includes/sidebar.php'; ?>
    <main class="gv2-main">
<?php elseif ($v2Role === 'technicien'): ?>
<div class="tv2-shell">
    <?php $activeNav = 'messages'; $tachesBadge = $technicienTachesCount; include '../technicien/includes/sidebar.php'; ?>
    <main class="tv2-main">
<?php elseif ($v2Role === 'admin'): ?>
<div class="av2-shell">
    <?php $activeNav = 'messages'; include '../admin/includes/sidebar.php'; ?>
    <main class="av2-main">
<?php endif; ?>

<div class="messages-container">
    <div class="messages-header">
        <h1><i class="fas fa-envelope"></i> Messages</h1>
        <button class="btn btn-primary" id="newMessageBtn">
            <i class="fas fa-plus"></i> Nouveau message
        </button>
    </div>
    
    <div class="messages-layout">
        <!-- Liste des conversations -->
        <div class="conversations-panel">
            <div class="conversations-header">
                <h3>Conversations</h3>
                <div class="conversation-search">
                    <input type="text" id="conversationSearch" placeholder="Rechercher par nom…" class="form-control">
                    <i class="fas fa-search"></i>
                </div>
            </div>
            
            <div class="conversations-list" id="conversationsList">
                <?php if (empty($conversations)): ?>
                    <div class="empty-conversations">
                        <i class="fas fa-comments"></i>
                        <p>Aucune conversation</p>
                        <p class="text-muted">Commencez une nouvelle conversation</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($conversations as $conv): ?>
                        <div class="conversation-item" data-contact-id="<?php echo $conv['contact_id']; ?>">
                            <div class="conversation-avatar">
                                <i class="fas fa-user"></i>
                            </div>
                            <div class="conversation-content">
                                <div class="conversation-header">
                                    <div class="conversation-name">
                                        <?php echo h($conv['prenom'] . ' ' . $conv['nom']); ?>
                                        <span class="conversation-role"><?php echo h(ucfirst($conv['role'])); ?></span>
                                    </div>
                                    <div class="conversation-time">
                                        <?php echo formatDate($conv['last_message_date']); ?>
                                    </div>
                                </div>
                                <div class="conversation-preview">
                                    <?php echo h(substr($conv['last_message'], 0, 50)) . '...'; ?>
                                </div>
                            </div>
                            <?php if ($conv['unread_count'] > 0): ?>
                                <div class="conversation-badge">
                                    <?php echo $conv['unread_count']; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Zone de conversation -->
        <div class="chat-panel">
            <div class="chat-placeholder">
                <i class="fas fa-comments"></i>
                <h3>Sélectionnez une conversation</h3>
                <p>Choisissez une conversation dans la liste pour commencer à discuter</p>
            </div>
        </div>
    </div>
</div>

<!-- Modal nouveau message -->
<div id="newMessageModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Nouveau message</h3>
            <button class="modal-close" id="closeNewMessageModal">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <form id="newMessageForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <div class="form-group">
                    <label class="form-label">Destinataire</label>
                    <select name="destinataire_id" class="form-control" required>
                        <option value="">Sélectionner un destinataire</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>">
                                <?php echo h($user['prenom'] . ' ' . $user['nom'] . ' (' . ucfirst($user['role']) . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Sujet</label>
                    <input type="text" name="sujet" class="form-control" required placeholder="Ex. Question sur ma réparation">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Message</label>
                    <textarea name="contenu" class="form-control" rows="5" required placeholder="Écrivez votre message…"></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-outline" id="cancelNewMessage">Annuler</button>
                    <button type="submit" class="btn btn-primary">Envoyer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.messages-container {
    max-width: 1200px;
    margin: 0 auto;
    height: calc(100vh - 140px);
    display: flex;
    flex-direction: column;
}

.messages-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--border-color);
}

.messages-layout {
    display: grid;
    grid-template-columns: 350px 1fr;
    gap: 2rem;
    flex: 1;
    min-height: 0;
}

.conversations-panel {
    background: var(--secondary-color);
    border-radius: var(--border-radius);
    box-shadow: var(--shadow);
    display: flex;
    flex-direction: column;
}

.conversations-header {
    padding: 1.5rem;
    border-bottom: 1px solid var(--border-color);
}

.conversations-header h3 {
    margin-bottom: 1rem;
    color: var(--text-color);
}

.conversation-search {
    position: relative;
}

.conversation-search input {
    padding-right: 3rem;
}

.conversation-search i {
    position: absolute;
    right: 1rem;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-light);
}

.conversations-list {
    flex: 1;
    overflow-y: auto;
}

.conversation-item {
    display: flex;
    align-items: center;
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--border-color);
    cursor: pointer;
    transition: var(--transition);
    position: relative;
}

.conversation-item:hover {
    background-color: var(--background-color);
}

.conversation-item.active {
    background-color: var(--primary-light);
    border-left: 4px solid var(--primary-color);
}

.conversation-avatar {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background: var(--primary-color);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 1rem;
    flex-shrink: 0;
}

.conversation-content {
    flex: 1;
    min-width: 0;
}

.conversation-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 0.25rem;
}

.conversation-name {
    font-weight: 600;
    color: var(--text-color);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.conversation-role {
    font-size: 0.75rem;
    background: var(--background-color);
    color: var(--text-light);
    padding: 0.25rem 0.5rem;
    border-radius: 12px;
    font-weight: 500;
}

.conversation-time {
    font-size: 0.8rem;
    color: var(--text-light);
    white-space: nowrap;
}

.conversation-preview {
    font-size: 0.9rem;
    color: var(--text-light);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.conversation-badge {
    position: absolute;
    top: 1rem;
    right: 1rem;
    background: var(--danger-color);
    color: white;
    border-radius: 50%;
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    font-weight: 600;
}

.empty-conversations {
    text-align: center;
    padding: 3rem 1.5rem;
    color: var(--text-light);
}

.empty-conversations i {
    font-size: 3rem;
    margin-bottom: 1rem;
    opacity: 0.5;
}

.chat-panel {
    background: var(--secondary-color);
    border-radius: var(--border-radius);
    box-shadow: var(--shadow);
    display: flex;
    flex-direction: column;
}

.chat-placeholder {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: var(--text-light);
    padding: 3rem;
}

.chat-placeholder i {
    font-size: 4rem;
    margin-bottom: 1rem;
    opacity: 0.5;
}

.chat-placeholder h3 {
    margin-bottom: 0.5rem;
    color: var(--text-color);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.5rem 2rem 0;
    border-bottom: 1px solid var(--border-color);
    margin-bottom: 1.5rem;
}

.modal-header h3 {
    margin: 0;
    color: var(--text-color);
}

.form-actions {
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
    margin-top: 2rem;
}

.text-muted {
    color: var(--text-light);
    font-size: 0.9rem;
}

@media (max-width: 768px) {
    .messages-layout {
        grid-template-columns: 1fr;
        grid-template-rows: auto 1fr;
    }
    
    .conversations-panel {
        max-height: 300px;
    }
    
    .messages-header {
        flex-direction: column;
        gap: 1rem;
        align-items: stretch;
    }
    
    .conversation-header {
        flex-direction: column;
        gap: 0.25rem;
    }
    
    .conversation-time {
        align-self: flex-start;
    }
}
</style>

<script>
$(document).ready(function() {
    let currentContactId = null;
    
    // Ouvrir le modal nouveau message
    $('#newMessageBtn').click(function() {
        $('#newMessageModal').fadeIn();
    });
    
    // Fermer le modal nouveau message
    $('#closeNewMessageModal, #cancelNewMessage').click(function() {
        $('#newMessageModal').fadeOut();
        $('#newMessageForm')[0].reset();
    });
    
    // Fermer le modal en cliquant à l'extérieur
    $('#newMessageModal').click(function(e) {
        if (e.target === this) {
            $(this).fadeOut();
            $('#newMessageForm')[0].reset();
        }
    });
    
    // Envoyer un nouveau message
    $('#newMessageForm').submit(function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        
        $.ajax({
            url: SITE_URL + 'ajax/send_message.php',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showToast('Message envoyé avec succès', 'success');
                    $('#newMessageModal').fadeOut();
                    $('#newMessageForm')[0].reset();
                    
                    // Recharger la page pour mettre à jour les conversations
                    location.reload();
                } else {
                    showToast(response.message || 'Erreur lors de l\'envoi', 'error');
                }
            },
            error: function() {
                showToast('Erreur lors de l\'envoi du message', 'error');
            }
        });
    });
    
    // Sélectionner une conversation
    $('.conversation-item').click(function() {
        const contactId = $(this).data('contact-id');
        
        // Marquer comme active
        $('.conversation-item').removeClass('active');
        $(this).addClass('active');
        
        // Charger la conversation
        loadConversation(contactId);
        currentContactId = contactId;
    });
    
    // Recherche de conversations
    $('#conversationSearch').on('input', function() {
        const searchTerm = $(this).val().toLowerCase();
        
        $('.conversation-item').each(function() {
            const name = $(this).find('.conversation-name').text().toLowerCase();
            if (name.includes(searchTerm)) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    });
    
    function loadConversation(contactId) {
        $.ajax({
            url: SITE_URL + 'ajax/load_conversation.php',
            method: 'GET',
            data: { contact_id: contactId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    displayConversation(response.contact, response.messages);
                } else {
                    showToast('Erreur lors du chargement de la conversation', 'error');
                }
            },
            error: function() {
                showToast('Erreur lors du chargement de la conversation', 'error');
            }
        });
    }
    
    function displayConversation(contact, messages) {
        const chatPanel = $('.chat-panel');
        
        // Créer l'en-tête de la conversation
        const chatHeader = $(`
            <div class="chat-header">
                <div class="chat-contact">
                    <div class="chat-avatar">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="chat-info">
                        <h4>${SmartAutoTrack.utils.escapeHtml(contact.prenom)} ${SmartAutoTrack.utils.escapeHtml(contact.nom)}</h4>
                        <span class="chat-role">${SmartAutoTrack.utils.escapeHtml(contact.role)}</span>
                    </div>
                </div>
                <div class="chat-actions">
                    <button class="btn btn-outline btn-sm" id="refreshChat">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
            </div>
        `);
        
        // Créer la zone de messages
        const chatMessages = $(`
            <div class="chat-messages" id="chatMessages">
                ${messages.map(msg => createMessageElement(msg)).join('')}
            </div>
        `);
        
        // Créer la zone de saisie
        const chatInput = $(`
            <div class="chat-input">
                <form id="chatForm">
                    <div class="chat-input-group">
                        <input type="text" name="message" class="chat-input-field" placeholder="Écrivez votre message…" required>
                        <button type="submit" class="btn btn-primary chat-send-btn">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </div>
                </form>
            </div>
        `);
        
        // Remplacer le contenu du panel de chat
        chatPanel.html('').append(chatHeader, chatMessages, chatInput);
        
        // Faire défiler vers le bas
        scrollToBottom();
        
        // Gérer l'envoi de messages
        $('#chatForm').submit(function(e) {
            e.preventDefault();
            
            const message = $('input[name="message"]').val().trim();
            if (!message) return;
            
            $.ajax({
                url: SITE_URL + 'ajax/send_message.php',
                method: 'POST',
                data: {
                    destinataire_id: contact.id,
                    sujet: 'Message',
                    contenu: message,
                    csrf_token: $('meta[name="csrf-token"]').attr('content')
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('input[name="message"]').val('');
                        loadConversation(contact.id);
                        loadMessageCount();
                    } else {
                        showToast(response.message || 'Erreur lors de l\'envoi', 'error');
                    }
                },
                error: function() {
                    showToast('Erreur lors de l\'envoi du message', 'error');
                }
            });
        });
        
        // Rafraîchir la conversation
        $('#refreshChat').click(function() {
            loadConversation(contact.id);
        });
    }
    
    function createMessageElement(message) {
        const isOwn = message.expediteur_id == USER_ID;
        const time = new Date(message.date_envoi).toLocaleTimeString('fr-FR', {
            hour: '2-digit',
            minute: '2-digit'
        });
        
        return `
            <div class="message-item ${isOwn ? 'own' : 'other'}">
                <div class="message-content">
                    <div class="message-text">${SmartAutoTrack.utils.escapeHtml(message.contenu)}</div>
                    <div class="message-time">${time}</div>
                </div>
            </div>
        `;
    }
    
    function scrollToBottom() {
        const chatMessages = document.getElementById('chatMessages');
        if (chatMessages) {
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }
    }

    <?php if ($preselectContact): ?>
    // Ouvre directement la conversation avec ce contact (venant par ex. du
    // bouton "Contacter" d'une réparation), même si aucun message n'existe
    // encore entre les deux comptes.
    (function () {
        const contactId = <?php echo (int)$preselectContact; ?>;
        $('.conversation-item[data-contact-id="' + contactId + '"]').addClass('active');
        loadConversation(contactId);
        currentContactId = contactId;
    })();
    <?php endif; ?>
});
</script>

<?php if ($v2Role !== null): ?>
    </main>
</div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
