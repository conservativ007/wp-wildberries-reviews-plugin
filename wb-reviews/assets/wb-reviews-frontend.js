document.addEventListener("DOMContentLoaded", function () {
  // Диагностический запрос — виден во вкладке Network
  // if (typeof wb_reviews_data !== "undefined") {
  //   fetch(
  //     wb_reviews_data.ajaxurl +
  //       "?action=wb_get_reviews&nm_id=" +
  //       wb_reviews_data.nm_id,
  //   )
  //     .then(function (r) {
  //       return r.json();
  //     })
  //     .then(function (data) {
  //       console.log("[WB Reviews] Ответ API:", data);
  //     })
  //     .catch(function (err) {
  //       console.error("[WB Reviews] Ошибка fetch:", err);
  //     });
  // }

  // ── Перемещаем блок перед .related-items.can-like ──
  var related = document.querySelector(".related-items.can-like");
  var reviews = document.querySelector(".wb-reviews");
  if (related && reviews) {
    related.parentNode.insertBefore(reviews, related);
  }

  var reviewsPfotos = document.querySelector(".wb-foto-reviews");
  if (related && reviewsPfotos) {
    related.parentNode.insertBefore(reviewsPfotos, related);
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
  // var total = items.length;
  var autoplay = null;

  var style = window.getComputedStyle(track);
  var gap = parseInt(style.gap) || 0;

  // console.log("gap ", gap);

  var itemWidth = items[0].offsetWidth + gap;
  var visibleWidth = track.parentElement.offsetWidth;

  // сколько максимум можно реально проскроллить
  var maxOffset = track.scrollWidth - visibleWidth;

  // максимальный индекс карточки, до которой можно дойти
  var maxIndex = Math.floor(maxOffset / itemWidth);

  var total = maxIndex + 1;

  // Создаём точки
  for (let i = 0; i < total; i++) {
    var dot = document.createElement("span");
    dot.className = "wb-reviews__dot" + (i === 0 ? " active" : "");
    dot.addEventListener("click", function () {
      goTo(i);
      resetAutoplay();
    });
    dotsBox.appendChild(dot);
  }

  // function goTo(index) {
  //   current = (index + total) % total;

  //   var offset = items[current].offsetLeft;

  //   var maxOffset = track.scrollWidth - track.parentElement.offsetWidth;
  //   if (offset > maxOffset) offset = maxOffset;

  //   track.style.transform = "translateX(-" + offset + "px)";

  //   document.querySelectorAll(".wb-reviews__dot").forEach(function (d, i) {
  //     d.classList.toggle("active", i === current);
  //   });
  // }

  // function goTo(index) {
  //   current = (index + total) % total;

  //   var item = items[current];

  //   var trackRect = track.parentElement.getBoundingClientRect();
  //   var itemRect = item.getBoundingClientRect();

  //   // центр контейнера
  //   var containerCenter = trackRect.width / 2;

  //   // позиция элемента внутри трека
  //   var itemOffset = item.offsetLeft;

  //   // центр карточки
  //   var itemCenter = itemOffset + item.offsetWidth / 2;

  //   // финальный сдвиг
  //   var offset = itemCenter - containerCenter;

  //   // защита от пустоты
  //   var maxOffset = track.scrollWidth - trackRect.width;
  //   if (offset < 0) offset = 0;
  //   if (offset > maxOffset) offset = maxOffset;

  //   track.style.transform = "translateX(-" + offset + "px)";

  //   document.querySelectorAll(".wb-reviews__dot").forEach(function (d, i) {
  //     d.classList.toggle("active", i === current);
  //   });
  // }

  function goTo(index) {
    current = (index + total) % total;

    var item = items[current];

    var containerWidth = track.parentElement.offsetWidth;

    // получаем gap
    var style = window.getComputedStyle(track);
    var gap = parseInt(style.gap) || 0;

    // реальная ширина карточки с gap
    var itemFullWidth = item.offsetWidth + gap;

    // центр контейнера
    var containerCenter = containerWidth / 2;

    // центр текущей карточки
    var itemCenter = itemFullWidth * current + item.offsetWidth / 2;

    // итоговый offset
    var offset = itemCenter - containerCenter;

    // ограничение
    var maxOffset = track.scrollWidth - containerWidth;
    if (offset < 0) offset = 0;
    if (offset > maxOffset) offset = maxOffset;

    track.style.transform = "translateX(-" + offset + "px)";

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
