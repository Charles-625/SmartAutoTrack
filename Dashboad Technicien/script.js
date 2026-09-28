 // Navigation sidebar
const links = document.querySelectorAll(".sidebar a");
const sections = document.querySelectorAll(".content-section");

links.forEach(link => {
  link.addEventListener("click", e => {
    e.preventDefault();
    const sectionId = link.getAttribute("data-section");

    sections.forEach(sec => sec.classList.remove("active"));
    document.getElementById(sectionId).classList.add("active");
  });
});

// Menu profil
document.getElementById("profileBtn").addEventListener("click", () => {
  const dropdown = document.querySelector(".dropdown-content");
  dropdown.style.display = dropdown.style.display === "block" ? "none" : "block";
});

// Compteurs KPI animés
function animateCounter(id, target) {
  let count = 0;
  const el = document.getElementById(id);
  const interval = setInterval(() => {
    count++;
    el.textContent = count;
    if (count >= target) clearInterval(interval);
  }, 50);
}
animateCounter("tasks-pending", 3);
animateCounter("tasks-progress", 2);
animateCounter("tasks-done", 5);
animateCounter("programmes", 1);

// Gestion des statuts
const statutSelects = document.querySelectorAll(".statut-select");
statutSelects.forEach(select => {
  select.addEventListener("change", (e) => {
    const newStatut = e.target.value;
    if (newStatut === "en-attente") e.target.style.background = "#FFD580";
    if (newStatut === "en-cours") e.target.style.background = "#87CEFA";
    if (newStatut === "termine") e.target.style.background = "#90EE90";
    alert(`Statut mis à jour : ${newStatut}`);
  });
  select.dispatchEvent(new Event("change"));
});

// Rédiger rapport
const rapportBtns = document.querySelectorAll(".rapport-btn");
rapportBtns.forEach(btn => {
  btn.addEventListener("click", () => {
    let row = btn.closest("tr");
    let nextRow = row.nextElementSibling;

    if (nextRow && nextRow.classList.contains("rapport-form-row")) {
      nextRow.style.display = nextRow.style.display === "none" ? "table-row" : "none";
    } else {
      const formRow = document.createElement("tr");
      formRow.classList.add("rapport-form-row");
      const formCell = document.createElement("td");
      formCell.colSpan = 5;
      formCell.innerHTML = `
        <div class="rapport-form">
          <label>Description :</label>
          <textarea placeholder="Décrire l’intervention réalisée"></textarea>
          <label>Pièces changées :</label>
          <input type="text" placeholder="Ex: Batterie, Pneu...">
          <button class="save-rapport">Enregistrer</button>
        </div>`;
      formRow.appendChild(formCell);
      row.parentNode.insertBefore(formRow, row.nextSibling);

      const saveBtn = formCell.querySelector(".save-rapport");
      saveBtn.addEventListener("click", () => {
        const description = formCell.querySelector("textarea").value;
        const pieces = formCell.querySelector("input").value;

        if (!description || !pieces) {
          alert("Veuillez remplir tous les champs !");
          return;
        }
        console.log("Rapport enregistré :", { description, pieces });
        alert("Rapport enregistré avec succès ✅");
        formRow.style.display = "none";
      });
    }
  });
});

// Voir rapport
const voirRapportBtns = document.querySelectorAll(".voir-rapport");
voirRapportBtns.forEach(btn => {
  btn.addEventListener("click", () => {
    alert("Affichage du rapport (fonctionnalité future avec base de données).");
  });
});

// Ajouter programme
document.getElementById("programme-form").addEventListener("submit", e => {
  e.preventDefault();
  alert("Programme ajouté avec succès ✅");
});
