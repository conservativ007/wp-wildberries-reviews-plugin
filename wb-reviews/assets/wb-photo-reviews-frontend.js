document.addEventListener("DOMContentLoaded", function () {
  const photos = document.querySelectorAll(".wb-reviews__photo");

  const lightbox = document.getElementById("wbLightbox");
  const img = lightbox.querySelector(".wb-lightbox__img");
  const btnClose = lightbox.querySelector(".wb-lightbox__close");
  const btnPrev = lightbox.querySelector(".wb-lightbox__prev");
  const btnNext = lightbox.querySelector(".wb-lightbox__next");

  let current = 0;
  let images = [];

  // собираем все fullSize ссылки
  photos.forEach((photo) => {
    const link = photo.closest("a");
    if (link) {
      images.push(link.href);
    } else {
      images.push(photo.src);
    }
  });

  function open(index) {
    current = index;
    img.src = images[current];
    lightbox.classList.add("active");
    document.body.style.overflow = "hidden";
  }

  function close() {
    lightbox.classList.remove("active");
    document.body.style.overflow = "";
  }

  function next() {
    current = (current + 1) % images.length;
    img.src = images[current];
  }

  function prev() {
    current = (current - 1 + images.length) % images.length;
    img.src = images[current];
  }

  // клики по фоткам
  photos.forEach((photo, index) => {
    photo.addEventListener("click", function (e) {
      e.preventDefault();
      open(index);
    });
  });

  btnClose.addEventListener("click", close);
  btnNext.addEventListener("click", next);
  btnPrev.addEventListener("click", prev);

  // клик по фону
  lightbox.addEventListener("click", function (e) {
    if (e.target === lightbox) close();
  });

  // клавиатура
  document.addEventListener("keydown", function (e) {
    if (!lightbox.classList.contains("active")) return;

    if (e.key === "Escape") close();
    if (e.key === "ArrowRight") next();
    if (e.key === "ArrowLeft") prev();
  });
});
