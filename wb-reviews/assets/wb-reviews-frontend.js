document.addEventListener("DOMContentLoaded", function () {
  // Диагностический запрос — виден во вкладке Network
  if (typeof wb_reviews_data !== "undefined") {
    fetch(
      wb_reviews_data.ajaxurl +
        "?action=wb_get_reviews&nm_id=" +
        wb_reviews_data.nm_id,
    )
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        console.log("[WB Reviews] Ответ API:", data);
      })
      .catch(function (err) {
        console.error("[WB Reviews] Ошибка fetch:", err);
      });
  }

  // ── Перемещаем блок перед .related-items.can-like ──
  var related = document.querySelector(".related-items.can-like");
  var reviews = document.querySelector(".wb-reviews");
  if (related && reviews) {
    related.parentNode.insertBefore(reviews, related);
  }
  // return;
  // ── Карусель ──
  var track = document.querySelector(".wb-reviews__track");
  var items = document.querySelectorAll(".wb-reviews__item");
  var dotsBox = document.querySelector(".wb-reviews__dots");
  var btnPrev = document.querySelector(".wb-reviews__btn--prev");
  var btnNext = document.querySelector(".wb-reviews__btn--next");

  if (!track || items.length < 2) return;

  var current = 0;
  var total = items.length;
  var autoplay = null;

  // Создаём точки
  items.forEach(function (_, i) {
    var dot = document.createElement("span");
    dot.className = "wb-reviews__dot" + (i === 0 ? " active" : "");
    dot.addEventListener("click", function () {
      goTo(i);
      resetAutoplay();
    });
    dotsBox.appendChild(dot);
  });

  function goTo(index) {
    current = (index + total) % total;
    track.style.transform = "translateX(-" + current * 280 + "px)";
    document.querySelectorAll(".wb-reviews__dot").forEach(function (d, i) {
      d.classList.toggle("active", i === current);
    });
  }

  function resetAutoplay() {
    clearInterval(autoplay);
    autoplay = setInterval(function () {
      goTo(current + 1);
    }, 4000);
  }

  btnPrev.addEventListener("click", function () {
    goTo(current - 1);
    resetAutoplay();
  });
  btnNext.addEventListener("click", function () {
    goTo(current + 1);
    resetAutoplay();
  });

  // Свайп на мобиле
  var startX = 0;
  track.addEventListener("touchstart", function (e) {
    startX = e.touches[0].clientX;
  });
  track.addEventListener("touchend", function (e) {
    var diff = startX - e.changedTouches[0].clientX;
    if (Math.abs(diff) > 50) {
      goTo(diff > 0 ? current + 1 : current - 1);
      resetAutoplay();
    }
  });

  resetAutoplay();
});
