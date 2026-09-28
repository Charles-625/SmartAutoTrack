/**
 * Gestionnaire de thèmes pour SmartAutoTrack
 */

class ThemeManager {
    constructor() {
        this.currentTheme = this.getStoredTheme() || 'light';
        this.init();
    }

    init() {
        this.applyTheme(this.currentTheme);
        this.bindEvents();
    }

    getStoredTheme() {
        // Vérifier d'abord la session PHP
        if (window.currentTheme) {
            return window.currentTheme;
        }
        
        // Sinon, vérifier localStorage
        return localStorage.getItem('smartautotrack_theme');
    }

    storeTheme(theme) {
        localStorage.setItem('smartautotrack_theme', theme);
    }

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

    updateThemeIndicator() {
        const indicator = document.querySelector('.theme-indicator');
        if (indicator) {
            indicator.innerHTML = this.getThemeIcon(this.currentTheme);
            indicator.title = `Thème actuel: ${this.getThemeName(this.currentTheme)}`;
        }
    }

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

    cycleTheme() {
        const themes = ['light', 'dark', 'blue', 'green', 'purple', 'orange'];
        const currentIndex = themes.indexOf(this.currentTheme);
        const nextIndex = (currentIndex + 1) % themes.length;
        this.applyTheme(themes[nextIndex]);
        
        // Sauvegarder sur le serveur si possible
        this.saveThemeToServer(themes[nextIndex]);
    }

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

    saveThemeToServer(theme) {
        // Envoyer le thème au serveur via AJAX
        fetch('ajax/save_theme.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
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
function changeTheme(theme) {
    if (window.themeManager) {
        window.themeManager.setTheme(theme);
    }
}

function cycleTheme() {
    if (window.themeManager) {
        window.themeManager.cycleTheme();
    }
}

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
function detectSystemTheme() {
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
        return 'dark';
    }
    return 'light';
}

// Appliquer le thème système si aucun thème n'est défini
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
