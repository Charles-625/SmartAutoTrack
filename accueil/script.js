 let slideIndex = 0;
showSlides();

function showSlides() {
  let slides = document.getElementsByClassName("slides");
  for (let i = 0; i < slides.length; i++) {
    slides[i].style.display = "none";
  }
  slideIndex++;
  if (slideIndex > slides.length) {slideIndex = 1}
  slides[slideIndex-1].style.display = "block";
  setTimeout(showSlides, 4000); // Change chaque 4s
}

function plusSlides(n) {
  slideIndex += n - 1;
  showSlides();
}
// FAQ déroulante
const faqQuestions = document.querySelectorAll(".faq-question");

faqQuestions.forEach(btn => {
  btn.addEventListener("click", () => {
    const answer = btn.nextElementSibling;
    const icon = btn.querySelector("i");

    if (answer.style.maxHeight) {
      answer.style.maxHeight = null;
      answer.style.padding = "0 15px";
      icon.classList.replace("fa-chevron-up", "fa-chevron-down");
    } else {
      answer.style.maxHeight = answer.scrollHeight + "px";
      answer.style.padding = "15px";
      icon.classList.replace("fa-chevron-down", "fa-chevron-up");
    }
  });
});
// Animation des services au scroll
const serviceCards = document.querySelectorAll(".service-card");

function showServicesOnScroll() {
  const triggerBottom = window.innerHeight * 0.85;
  serviceCards.forEach(card => {
    const boxTop = card.getBoundingClientRect().top;
    if (boxTop < triggerBottom) {
      card.classList.add("show");
    }
  });
}

window.addEventListener("scroll", showServicesOnScroll);
window.addEventListener("load", showServicesOnScroll);
// Slider Témoignages
let currentTestimonial = 0;
const testimonials = document.querySelectorAll(".testimonial");
const prevBtn = document.getElementById("prev");
const nextBtn = document.getElementById("next");

function showTestimonial(index) {
  testimonials.forEach((t, i) => {
    t.classList.remove("active");
    if (i === index) {
      t.classList.add("active");
    }
  });
}

nextBtn.addEventListener("click", () => {
  currentTestimonial = (currentTestimonial + 1) % testimonials.length;
  showTestimonial(currentTestimonial);
});

prevBtn.addEventListener("click", () => {
  currentTestimonial =
    (currentTestimonial - 1 + testimonials.length) % testimonials.length;
  showTestimonial(currentTestimonial);
});

// Auto défilement
setInterval(() => {
  currentTestimonial = (currentTestimonial + 1) % testimonials.length;
  showTestimonial(currentTestimonial);
}, 6000);

// Init
showTestimonial(currentTestimonial);


// Ici tu pourras plus tard ajouter des animations supplémentaires
console.log("Script chargé avec succès !");
