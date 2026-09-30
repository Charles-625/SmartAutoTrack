/**
 * Gestionnaire de thèmes pour SmartAutoTrack
  *
  * Chargé sur toutes les pages par includes/header.php, après main.js (dont
  * il réutilise smartautotrackCsrfToken()). Le thème s'applique via l'attribut
  * data-theme de <html>, lu par les feuilles de style.
  *
  * Priorité du thème au chargement : window.currentTheme (session PHP, posé
  * par includes/header.php), puis localStorage, puis 'light'. Chaque
  * changement choisi par l'utilisateur est aussi enregistré côté serveur via
  * ajax/save_theme.php (base + session).
 */

/**
 * Applique, mémorise et fait circuler le thème courant. Instance unique
 * exposée dans window.themeManager.
 */
class ThemeManager {
    /** Lit le thème mémorisé puis l'applique immédiatement. */
    constructor() {
        this.currentTheme = this.getStoredTheme() || 'light';
        this.init();
    }

    /** Applique le thème courant et branche les écouteurs d'événements. */
    init() {
        this.applyTheme(this.currentTheme);
        this.bindEvents();
    }

    /**
     * Thème mémorisé : la session PHP l'emporte sur le navigateur, pour qu'un
     * utilisateur retrouve son choix sur un autre appareil.
     * @returns {string|null} Identifiant du thème, ou null si aucun.
     */
    getStoredTheme() {
        // Vérifier d'abord la session PHP
        if (window.currentTheme) {
            return window.currentTheme;
        }
        
        // Sinon, vérifier localStorage
        return localStorage.getItem('smartautotrack_theme');
    }

    /**
     * Mémorise le thème dans le navigateur (clé smartautotrack_theme).
     * @param {string} theme Identifiant du thème.
     */
    storeTheme(theme) {
        localStorage.setItem('smartautotrack_theme', theme);
    }

    /**
     * Applique un thème sans l'envoyer au serveur : attribut data-theme,
     * localStorage, courte animation de transition, indicateur, puis événement
     * « themeChanged » pour les autres composants.
     * @param {string} theme Identifiant du thème.
     */
    applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        this.currentTheme = theme;
        this.storeTheme(theme);
        
        // Ajouter une classe pour l'animation de transition
        document.body.classList.add('theme-transition');
        setTimeout(() => {
            document.body.classList.remove('theme-transition');
        }, 500);
        
        // Mettre à jour l'indicateur de thème
        this.updateThemeIndicator();
        
        // Déclencher un événement personnalisé
        document.dispatchEvent(new CustomEvent('themeChanged', {
            detail: { theme: theme }
        }));
    }

    /**
     * Crée la pastille flottante .theme-indicator (un clic passe au thème
     * suivant). N'est appelée nulle part par défaut.
     */
    createThemeIndicator() {
        // Supprimer l'ancien indicateur s'il existe
        const existingIndicator = document.querySelector('.theme-indicator');
        if (existingIndicator) {
            existingIndicator.remove();
        }

        const indicator = document.createElement('div');
        indicator.className = 'theme-indicator';
        indicator.innerHTML = this.getThemeIcon(this.currentTheme);
        indicator.title = `Thème actuel: ${this.getThemeName(this.currentTheme)}`;
        
        document.body.appendChild(indicator);
        
        // Ajouter un clic pour changer de thème rapidement
        indicator.addEventListener('click', () => {
            this.cycleTheme();
        });
    }

    /** Met à jour l'icône et l'infobulle de la pastille, si elle existe. */
    updateThemeIndicator() {
        const indicator = document.querySelector('.theme-indicator');
        if (indicator) {
            indicator.innerHTML = this.getThemeIcon(this.currentTheme);
            indicator.title = `Thème actuel: ${this.getThemeName(this.currentTheme)}`;
        }
    }

    /**
     * @param {string} theme Identifiant du thème.
     * @returns {string} Emoji représentant le thème.
     */
    getThemeIcon(theme) {
        const icons = {
            'light': '☀️',
            'dark': '🌙',
            'blue': '🔵',
            'green': '🟢',
            'purple': '🟣',
            'orange': '🟠'
        };
        return icons[theme] || '🎨';
    }

    /**
     * @param {string} theme Identifiant du thème.
     * @returns {string} Nom du thème en français.
     */
    getThemeName(theme) {
        const names = {
            'light': 'Clair',
            'dark': 'Sombre',
            'blue': 'Bleu',
            'green': 'Vert',
            'purple': 'Violet',
            'orange': 'Orange'
        };
        return names[theme] || 'Inconnu';
    }

    /** Passe au thème suivant de la liste (en boucle) et l'enregistre sur le serveur. */
    cycleTheme() {
        const themes = ['light', 'dark', 'blue', 'green', 'purple', 'orange'];
        const currentIndex = themes.indexOf(this.currentTheme);
        const nextIndex = (currentIndex + 1) % themes.length;
        this.applyTheme(themes[nextIndex]);
        
        // Sauvegarder sur le serveur si possible
        this.saveThemeToServer(themes[nextIndex]);
    }

    /**
     * Écoute les trois sources de changement : boutons radio name="theme" des
     * pages de paramètres, raccourci Ctrl+T, et événement « themeChange » émis
     * par d'autres scripts (celui-ci n'est pas renvoyé au serveur).
     */
    bindEvents() {
        // Écouter les changements de thème depuis les formulaires
        document.addEventListener('change', (e) => {
            if (e.target.name === 'theme' && e.target.type === 'radio') {
                this.applyTheme(e.target.value);
                this.saveThemeToServer(e.target.value);
            }
        });

        // Raccourci clavier pour changer de thème (Ctrl + T)
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey && e.key === 't') {
                e.preventDefault();
                this.cycleTheme();
            }
        });

        // Écouter les changements de thème depuis d'autres composants
        document.addEventListener('themeChange', (e) => {
            this.applyTheme(e.detail.theme);
        });
    }

    /**
     * Enregistre le thème pour l'utilisateur connecté (ajax/save_theme.php,
     * protégé par le jeton CSRF). Un échec est seulement journalisé dans la
     * console : le thème reste appliqué localement.
     * @param {string} theme Identifiant du thème.
     */
    saveThemeToServer(theme) {
        // Envoyer le thème au serveur via AJAX
        fetch((typeof SITE_URL !== 'undefined' ? SITE_URL : '') + 'ajax/save_theme.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': typeof smartautotrackCsrfToken === 'function' ? smartautotrackCsrfToken() : '',
            },
            body: `theme=${encodeURIComponent(theme)}`
        }).catch(error => {
            console.log('Impossible de sauvegarder le thème sur le serveur:', error);
        });
    }

    // Méthode pour obtenir le thème actuel
    getCurrentTheme() {
        return this.currentTheme;
    }

    // Méthode pour définir un thème spécifique
    // Seuls les six thèmes connus sont acceptés ; toute autre valeur est ignorée.
    setTheme(theme) {
        if (['light', 'dark', 'blue', 'green', 'purple', 'orange'].includes(theme)) {
            this.applyTheme(theme);
            this.saveThemeToServer(theme);
        }
    }

    // Méthode pour réinitialiser au thème par défaut
    resetTheme() {
        this.setTheme('light');
    }
}

// Initialiser le gestionnaire de thèmes quand le DOM est prêt
document.addEventListener('DOMContentLoaded', function() {
    window.themeManager = new ThemeManager();
});

// Fonctions utilitaires globales
/**
 * Raccourci global : applique et enregistre un thème.
 * @param {string} theme Identifiant du thème.
 */
function changeTheme(theme) {
    if (window.themeManager) {
        window.themeManager.setTheme(theme);
    }
}

/** Raccourci global : passe au thème suivant. */
function cycleTheme() {
    if (window.themeManager) {
        window.themeManager.cycleTheme();
    }
}

/**
 * @returns {string} Thème courant ('light' tant que le gestionnaire n'est pas prêt).
 */
function getCurrentTheme() {
    return window.themeManager ? window.themeManager.getCurrentTheme() : 'light';
}

// Animation pour les changements de thème
function animateThemeChange() {
    document.body.style.transition = 'all 0.3s ease';
    setTimeout(() => {
        document.body.style.transition = '';
    }, 300);
}

// Détecter les préférences système
/**
 * @returns {string} 'dark' si le système préfère le mode sombre, sinon 'light'.
 */
function detectSystemTheme() {
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
        return 'dark';
    }
    return 'light';
}

// Appliquer le thème système si aucun thème n'est défini
/**
 * Suit la préférence du système uniquement si aucun thème n'a été choisi
 * (ni en session, ni dans le navigateur). En pratique includes/header.php
 * pose toujours window.currentTheme ('light' par défaut) : ce repli ne joue
 * que sur une page qui n'utilise pas cet en-tête.
 */
function applySystemTheme() {
    if (!localStorage.getItem('smartautotrack_theme') && !window.currentTheme) {
        const systemTheme = detectSystemTheme();
        if (window.themeManager) {
            window.themeManager.setTheme(systemTheme);
        }
    }
}

// Écouter les changements de préférences système
if (window.matchMedia) {
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
        if (!localStorage.getItem('smartautotrack_theme') && !window.currentTheme) {
            const systemTheme = e.matches ? 'dark' : 'light';
            if (window.themeManager) {
                window.themeManager.setTheme(systemTheme);
            }
        }
    });
}

// Appliquer le thème système au chargement
document.addEventListener('DOMContentLoaded', applySystemTheme);
