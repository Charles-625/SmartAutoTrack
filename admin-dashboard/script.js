 // ==========================
// Navigation entre sections
// ==========================
const menuItems = document.querySelectorAll(".sidebar li[data-section]");
const sections = document.querySelectorAll(".content-section");

menuItems.forEach(item => {
  item.addEventListener("click", () => {
    menuItems.forEach(i => i.classList.remove("active"));
    sections.forEach(s => s.classList.remove("active"));

    item.classList.add("active");
    document.getElementById(item.dataset.section).classList.add("active");

    // Relancer les compteurs si on revient sur le dashboard
    if (item.dataset.section === "dashboard") {
      animateCounters();
    }
  });
});

// ==========================
// Animation des compteurs
// ==========================
function animateCounters() {
  const counters = document.querySelectorAll('.counter');
  const speed = 100;

  counters.forEach(counter => {
    counter.innerText = "0"; // réinitialiser avant animation
    const updateCount = () => {
      const target = +counter.getAttribute('data-target');
      const count = +counter.innerText;
      const increment = target / speed;

      if (count < target) {
        counter.innerText = Math.ceil(count + increment);
        setTimeout(updateCount, 30);
      } else {
        counter.innerText = target;
      }
    };
    updateCount();
  });
}

// Lancer animation au chargement
animateCounters();

// ==========================
// Génération simple calendrier
// ==========================
function generateCalendar() {
  const calendar = document.getElementById("calendar");
  if (!calendar) return; // sécurité

  const days = ["Lun","Mar","Mer","Jeu","Ven","Sam","Dim"];

  days.forEach(day => {
    const div = document.createElement("div");
    div.innerText = day;
    div.style.fontWeight = "bold";
    calendar.appendChild(div);
  });

  for (let i = 1; i <= 30; i++) {
    const div = document.createElement("div");
    div.innerText = i;
    calendar.appendChild(div);
  }
}
generateCalendar();

// ==========================
// Profil dynamique
// ==========================
const profileImg = document.getElementById("profile-img");
const profileMenu = document.getElementById("profile-menu");

if (profileImg && profileMenu) {
  profileImg.addEventListener("click", () => {
    profileMenu.style.display =
      profileMenu.style.display === "block" ? "none" : "block";
  });

  // Fermer si clic en dehors
  document.addEventListener("click", (e) => {
    if (!profileImg.contains(e.target) && !profileMenu.contains(e.target)) {
      profileMenu.style.display = "none";
    }
  });

  // Actions du menu profil
  const voirProfil = document.getElementById("voir-profil");
  const modifierProfil = document.getElementById("modifier-profil");
  const logout = document.getElementById("logout");

  if (voirProfil) {
    voirProfil.addEventListener("click", () => {
      window.location.href = "profil.html";
    });
  }

  if (modifierProfil) {
    modifierProfil.addEventListener("click", () => {
      window.location.href = "modifier-profil.html";
    });
  }

  if (logout) {
    logout.addEventListener("click", () => {
      alert("Déconnexion réussie !");
      window.location.href = "login.html";
    });
  }
}
