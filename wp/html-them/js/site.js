(function () {
  /* Data ---------------------------------------------------------------- */

  var searchStories = [
    {
      kicker: "EKONOMİ",
      title: "SGK, borç yapılandırmasını kamu spotu ile anlattı",
      time: "2 saat önce",
    },
    {
      kicker: "EKONOMİ",
      title: "Tapuda 456 milyar liralık rekor",
      time: "3 saat önce",
    },
    {
      kicker: "YAŞAM",
      title: "Gezegenler 2 bin yıl önceki gibi aynı hizaya gelecek",
      time: "2 saat önce",
    },
    {
      kicker: "FİNANS",
      title: "Merkez Bankası faiz kararı öncesi piyasa beklentisi",
      time: "4 saat önce",
    },
    {
      kicker: "ALTIN",
      title: "Gram altında güne sert yükselişle başlandı",
      time: "5 saat önce",
    },
    {
      kicker: "GÜNDEM",
      title: "Anadolu'da 4 il kar yağışıyla beyaza büründü",
      time: "1 saat önce",
    },
    {
      kicker: "SPOR",
      title: "Yenilgi sonrası Mesut Özil'den flaş paylaşım!",
      time: "6 saat önce",
    },
    {
      kicker: "EKONOMİ",
      title: "Akaryakıt fiyatlarına bu gece zam bekleniyor",
      time: "7 saat önce",
    },
    {
      kicker: "BORSA",
      title: "Borsa İstanbul günü yükselişle kapattı",
      time: "8 saat önce",
    },
    {
      kicker: "YAZARLAR",
      title: "Ekranı katlanabilen telefonlar hayal kırıklığı mı?",
      time: "1 hafta önce",
    },
    {
      kicker: "YAZARLAR",
      title: "Enflasyonla yaşamanın yeni kuralları",
      time: "2 hafta önce",
    },
    {
      kicker: "EKONOMİ",
      title: "Emekli maaşlarında yeni düzenleme sinyali",
      time: "9 saat önce",
    },
  ];

  var economyPosts = [
    ["images/bitcoin.jpg", "Ekrandaki grafiklerin önünde duran bir Bitcoin", "Bitcoin 30 bin doları aştı", "2026-09-26T16:00:00", "2 saat önce", "haber.html"],
    ["images/euro.jpg", "Ev maketi ve anahtarlar", "Tapuda 456 milyar liralık rekor", "2026-09-26T15:00:00", "3 saat önce"],
    ["images/market.jpg", "Araba kullanan bir kişi", "Akaryakıt fiyatlarına bu gece zam bekleniyor", "2026-09-26T14:00:00", "4 saat önce"],
    ["images/economy.jpg", "Yan yana oturan bir grup arkadaş", "Borsa İstanbul günü yükselişle kapattı", "2026-09-26T13:00:00", "5 saat önce"],
    ["images/books.jpg", "Üst üste dizilmiş kitaplar", "Emekli maaşlarında yeni düzenleme sinyali", "2026-09-26T12:00:00", "6 saat önce"],
    ["images/gold.jpg", "Yaşlı bir kadının portresi", "Konut satışlarında son çeyrek rakamları açıklandı", "2026-09-26T10:00:00", "8 saat önce"],
    ["images/economy.jpg", "Yan yana oturan bir grup arkadaş", "Gram altında güne sert yükselişle başlandı", "2026-09-26T08:00:00", "10 saat önce"],
    ["images/euro.jpg", "Ev maketi ve anahtarlar", "Merkez Bankası faiz kararı öncesi piyasa beklentisi", "2026-09-26T06:00:00", "12 saat önce"],
    ["images/books.jpg", "Üst üste dizilmiş kitaplar", "Ekonomi yazarları yeni kitaplarıyla okurla buluştu", "2026-09-25T18:00:00", "1 gün önce"],
    ["images/market.jpg", "Araba kullanan bir kişi", "Hububat alım fiyatları üreticiyi bekletiyor", "2026-09-25T12:00:00", "1 gün önce"],
    ["images/sgk.jpg", "Gökdelenler", "Elektrik tüketiminde kış tarifesi gündemde", "2026-09-25T09:00:00", "1 gün önce"],
    ["images/gold.jpg", "Yaşlı bir kadının portresi", "Dijital abonelikler bütçeyi nasıl eritiyor?", "2026-09-24T16:00:00", "2 gün önce"],
    ["images/books.jpg", "Üst üste dizilmiş kitaplar", "Enflasyonla yaşamanın yeni kuralları", "2026-09-24T14:00:00", "2 gün önce"],
    ["images/euro.jpg", "Ev maketi ve anahtarlar", "Üniversite harçlarına ilişkin yeni takvim belli oldu", "2026-09-24T11:00:00", "2 gün önce"],
    ["images/market.jpg", "Araba kullanan bir kişi", "Asgari ücrette ara zam beklentisi güçleniyor", "2026-09-23T18:00:00", "3 gün önce"],
    ["images/economy.jpg", "Yan yana oturan bir grup arkadaş", "İhracat rakamları eylülde rekor kırdı", "2026-09-23T15:00:00", "3 gün önce"],
    ["images/sgk.jpg", "Gökdelenler", "Kredi kartı faizinde yeni üst sınır", "2026-09-23T12:00:00", "3 gün önce"],
    ["images/gold.jpg", "Yaşlı bir kadının portresi", "Market zincirlerinde fiyat indirimi yarışı", "2026-09-22T16:00:00", "4 gün önce"],
    ["images/euro.jpg", "Ev maketi ve anahtarlar", "Dolar kuru haftayı yükselişle kapattı", "2026-09-22T13:00:00", "4 gün önce"],
    ["images/books.jpg", "Üst üste dizilmiş kitaplar", "KOBİ'lere yeni destek paketi açıklandı", "2026-09-22T10:00:00", "4 gün önce"],
    ["images/economy.jpg", "Yan yana oturan bir grup arkadaş", "Konut kredisi faizleri geriledi", "2026-09-21T16:00:00", "5 gün önce"],
    ["images/market.jpg", "Araba kullanan bir kişi", "Otomotiv satışlarında eylül hareketi", "2026-09-21T14:00:00", "5 gün önce"],
    ["images/sgk.jpg", "Gökdelenler", "Turizm gelirleri beklentiyi aştı", "2026-09-21T11:00:00", "5 gün önce"],
    ["images/gold.jpg", "Yaşlı bir kadının portresi", "Doğalgaz faturasına kademeli tarife", "2026-09-20T16:00:00", "6 gün önce"],
    ["images/books.jpg", "Üst üste dizilmiş kitaplar", "Bütçe açığında eylül verisi yayımlandı", "2026-09-20T13:00:00", "6 gün önce"],
    ["images/euro.jpg", "Ev maketi ve anahtarlar", "Tarım destek ödemeleri hesaplara yatıyor", "2026-09-20T10:00:00", "6 gün önce"],
    ["images/economy.jpg", "Yan yana oturan bir grup arkadaş", "Altın ithalatında yeni düzenleme", "2026-09-19T16:00:00", "1 hafta önce"],
    ["images/market.jpg", "Araba kullanan bir kişi", "E-ticaret hacmi rekor tazeledi", "2026-09-19T13:00:00", "1 hafta önce"],
    ["images/sgk.jpg", "Gökdelenler", "İşsizlik oranı eylülde geriledi", "2026-09-19T10:00:00", "1 hafta önce"],
    ["images/gold.jpg", "Yaşlı bir kadının portresi", "Şirket birleşmelerinde izin süreci kısaldı", "2026-09-18T16:00:00", "1 hafta önce"],
  ];

  var writers = [
    ["Cem Seymen", "cem@cemseymen.com", "yazar.html"],
    ["Cem Seymen", "cem@cemseymen.com", "yazar.html"],
    ["Cem Seymen", "cem@cemseymen.com", "yazar.html"],
    ["Cem Seymen", "cem@cemseymen.com", "yazar.html"],
    ["Cem Seymen", "cem@cemseymen.com", "yazar.html"],
    ["Cem Seymen", "cem@cemseymen.com", "yazar.html"],
    ["Cem Seymen", "cem@cemseymen.com", "yazar.html"],
    ["Cem Seymen", "cem@cemseymen.com", "yazar.html"],
    ["Cem Seymen", "cem@cemseymen.com", "yazar.html"],
    ["Cem Seymen", "cem@cemseymen.com", "yazar.html"],
    ["Cem Seymen", "cem@cemseymen.com", "yazar.html"],
    ["Cem Seymen", "cem@cemseymen.com", "yazar.html"],
    ["Ali Kaya", "ali@kayayazar.com"],
    ["Deniz Acar", "deniz@acar.com"],
    ["Emre Yıldız", "emre@yildiz.com"],
    ["Hakan Öz", "hakan@oz.com"],
    ["Murat Can", "murat@can.com"],
    ["Selin Ak", "selin@ak.com"],
    ["Yeliz Kara", "yeliz@kara.com"],
    ["Zeynep Aydın", "zeynep@aydin.com"],
  ];

  var galleryShots = [
    ["images/bitcoin.jpg", "Ekrandaki grafiklerin önünde duran bir Bitcoin"],
    ["images/gold.jpg", "Yaşlı bir kadının portresi"],
    ["images/economy.jpg", "Yan yana oturan bir grup arkadaş"],
    ["images/market.jpg", "Araba kullanan bir kişi"],
    ["images/euro.jpg", "Ev maketi ve anahtarlar"],
    ["images/books.jpg", "Üst üste dizilmiş kitaplar"],
    ["images/sgk.jpg", "Gökdelenler"],
    ["images/bike.jpg", "Gün batımında motosiklet süren bir kişi"],
    ["images/bitcoin.jpg", "Bitcoin ve piyasa ekranı"],
  ];

  /* Shared UI ----------------------------------------------------------- */

  function initPopularTabs() {
    var tabs = document.querySelectorAll(".popular__tab");
    var panels = document.querySelectorAll(".popular__list");
    if (!tabs.length) return;

    tabs.forEach(function (tab) {
      tab.addEventListener("click", function () {
        var id = tab.getAttribute("aria-controls");
        tabs.forEach(function (item) {
          var selected = item === tab;
          item.setAttribute("aria-selected", selected ? "true" : "false");
          item.tabIndex = selected ? 0 : -1;
        });
        panels.forEach(function (panel) {
          panel.hidden = panel.id !== id;
        });
      });
    });
  }

  function initSearchModal() {
    var openButton = document.querySelector(".site-header__search");
    var modal = document.getElementById("search-modal");
    if (!openButton || !modal) return;

    var input = modal.querySelector(".search-modal__input");
    var results = modal.querySelector(".search-modal__results");
    var more = modal.querySelector(".search-modal__more");
    var pageSize = 3;
    var matches = [];
    var shown = 0;
    var timer = 0;

    function fold(value) {
      return value.toLocaleLowerCase("tr-TR");
    }

    function paint(reset) {
      if (reset) results.replaceChildren();
      if (!input.value.trim()) {
        more.hidden = true;
        return;
      }
      if (!matches.length) {
        var empty = document.createElement("p");
        empty.className = "search-modal__empty";
        empty.textContent = "Sonuç bulunamadı";
        results.replaceChildren(empty);
        more.hidden = true;
        return;
      }
      matches.slice(shown, shown + pageSize).forEach(function (story) {
        var link = document.createElement("a");
        link.className = "search-hit";
        link.href = "#";
        var kicker = document.createElement("span");
        kicker.className = "search-hit__kicker";
        kicker.textContent = story.kicker;
        var title = document.createElement("span");
        title.className = "search-hit__title";
        title.textContent = story.title;
        var time = document.createElement("span");
        time.className = "search-hit__time";
        time.textContent = story.time;
        link.append(kicker, title, time);
        results.append(link);
      });
      shown = Math.min(shown + pageSize, matches.length);
      more.hidden = shown >= matches.length;
    }

    function searchNow() {
      var query = fold(input.value.trim());
      shown = 0;
      matches = query
        ? searchStories.filter(function (story) {
            return fold(story.kicker + " " + story.title).indexOf(query) !== -1;
          })
        : [];
      paint(true);
    }

    function openModal() {
      var drawer = document.getElementById("drawer");
      var menu = document.querySelector(".site-header__menu");
      if (drawer && !drawer.hidden) {
        drawer.hidden = true;
        if (menu) menu.setAttribute("aria-expanded", "false");
      }
      modal.hidden = false;
      document.body.style.overflow = "hidden";
      input.value = "";
      results.replaceChildren();
      more.hidden = true;
      matches = [];
      shown = 0;
      input.focus();
    }

    function closeModal() {
      modal.hidden = true;
      document.body.style.overflow = "";
      openButton.focus();
    }

    openButton.addEventListener("click", openModal);
    modal.querySelectorAll("[data-search-close]").forEach(function (control) {
      control.addEventListener("click", closeModal);
    });
    input.addEventListener("input", function () {
      window.clearTimeout(timer);
      timer = window.setTimeout(searchNow, 300);
    });
    more.addEventListener("click", function () {
      paint(false);
    });
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && !modal.hidden) closeModal();
    });
  }

  function initDrawer() {
    var button = document.querySelector(".site-header__menu");
    var drawer = document.getElementById("drawer");
    if (!button || !drawer) return;

    var reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    var isOpen = false;

    function setOpen(open) {
      isOpen = open;
      var modal = document.getElementById("search-modal");
      if (open && modal && !modal.hidden) modal.hidden = true;
      button.setAttribute("aria-expanded", open ? "true" : "false");
      button.setAttribute("aria-label", open ? "Menüyü kapat" : "Menü");
      document.body.style.overflow = open ? "hidden" : "";

      if (open) {
        drawer.hidden = false;
        if (reduceMotion) {
          drawer.classList.add("is-open");
          return;
        }
        window.requestAnimationFrame(function () {
          window.requestAnimationFrame(function () {
            drawer.classList.add("is-open");
          });
        });
        return;
      }

      drawer.classList.remove("is-open");
      if (reduceMotion) drawer.hidden = true;
    }

    drawer.addEventListener("transitionend", function (event) {
      if (event.propertyName !== "transform") return;
      if (!drawer.classList.contains("is-open")) drawer.hidden = true;
    });

    button.addEventListener("click", function () {
      setOpen(!isOpen);
    });

    drawer.querySelectorAll("[data-drawer-close]").forEach(function (control) {
      control.addEventListener("click", function () {
        setOpen(false);
      });
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && !drawer.hidden) setOpen(false);
    });
  }

  /* Home ---------------------------------------------------------------- */

  function initHomeCarousels() {
    if (typeof Swiper !== "function") return;

    if (document.querySelector(".videos-swiper")) {
      new Swiper(".videos-swiper", {
        slidesPerView: "auto",
        centeredSlides: true,
        spaceBetween: 14,
        loop: true,
        speed: 450,
        grabCursor: true,
        navigation: {
          nextEl: ".videos__next",
          prevEl: ".videos__prev",
        },
        a11y: {
          prevSlideMessage: "Önceki videolar",
          nextSlideMessage: "Sonraki videolar",
        },
      });
    }

    var newsRoot = document.querySelector(".news-swiper");
    var pagerNumbers = document.querySelector(".news-pager__numbers");
    var pagerMore = document.querySelector(".news-pager__more");
    if (!newsRoot || !pagerNumbers || !pagerMore) return;

    var newsSwiper = new Swiper(".news-swiper", {
      slidesPerView: 1,
      speed: 450,
      rewind: true,
      grabCursor: true,
      autoplay: {
        delay: 5000,
        disableOnInteraction: false,
        pauseOnMouseEnter: true,
      },
      navigation: {
        nextEl: ".news-swiper__next",
        prevEl: ".news-swiper__prev",
      },
      pagination: {
        el: ".news-pager__numbers",
        clickable: true,
        renderBullet: function (index, className) {
          return (
            '<button type="button" class="' +
            className +
            '">' +
            (index + 1) +
            "</button>"
          );
        },
      },
      a11y: {
        prevSlideMessage: "Önceki haber",
        nextSlideMessage: "Sonraki haber",
        paginationBulletMessage: "{{index}}. habere git",
      },
    });

    pagerNumbers.addEventListener("mouseover", function (event) {
      var bullet = event.target.closest(".swiper-pagination-bullet");
      if (!bullet) return;
      var index = Array.prototype.indexOf.call(newsSwiper.pagination.bullets, bullet);
      if (index < 0 || index === newsSwiper.realIndex) return;
      newsSwiper.slideTo(index);
    });

    pagerMore.addEventListener("click", function () {
      if (newsSwiper.isEnd) newsSwiper.slideTo(0);
      else newsSwiper.slideNext();
    });
  }

  function initAuthorsScroll() {
    var viewport = document.querySelector(".authors__viewport");
    var down = document.querySelector(".authors__down");
    var up = document.querySelector(".authors__up");
    if (!viewport || !down || !up) return;

    function step() {
      var items = viewport.querySelectorAll(".authors__list > li");
      if (items.length < 2) return 72;
      return items[1].offsetTop - items[0].offsetTop;
    }

    down.addEventListener("click", function () {
      viewport.scrollBy({ top: step(), behavior: "smooth" });
    });

    up.addEventListener("click", function () {
      viewport.scrollBy({ top: -step(), behavior: "smooth" });
    });
  }

  /* Article ------------------------------------------------------------- */

  function initPromo() {
    var promo = document.getElementById("promo");
    var closeAd = promo && promo.querySelector(".promo__close");
    if (!closeAd) return;

    closeAd.addEventListener("click", function () {
      promo.hidden = true;
    });
  }

  function initLightbox() {
    var dialog = document.getElementById("lightbox");
    if (!dialog) return;

    var image = dialog.querySelector(".lightbox__image");
    var caption = dialog.querySelector(".lightbox__caption");
    var index = 0;

    function show(next) {
      index = (next + galleryShots.length) % galleryShots.length;
      image.src = galleryShots[index][0];
      image.alt = galleryShots[index][1];
      caption.textContent =
        index + 1 + " / " + galleryShots.length + " — " + galleryShots[index][1];
    }

    function openAt(next) {
      show(next);
      if (typeof dialog.showModal === "function") dialog.showModal();
      else dialog.setAttribute("open", "");
    }

    document.querySelectorAll("[data-gallery]").forEach(function (button) {
      button.addEventListener("click", function () {
        openAt(Number(button.getAttribute("data-gallery")));
      });
    });

    dialog.querySelectorAll("[data-lightbox-step]").forEach(function (button) {
      button.addEventListener("click", function () {
        show(index + Number(button.getAttribute("data-lightbox-step")));
      });
    });

    dialog.querySelector("[data-lightbox-close]").addEventListener("click", function () {
      if (typeof dialog.close === "function") dialog.close();
      else dialog.removeAttribute("open");
    });
  }

  /* Economy ------------------------------------------------------------- */

  function initEconomyArchive() {
    var grid = document.getElementById("category-grid");
    var nav = document.querySelector(".pagination");
    var archive = document.querySelector(".category-archive");
    if (!grid || !nav || !archive) return;

    var pageSize = 10;
    var page = 1;
    var pageCount = Math.ceil(economyPosts.length / pageSize);
    var clock =
      '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"></circle><path d="M12 8v4.5l2.5 1.5"></path></svg>';

    function render(scroll) {
      var start = (page - 1) * pageSize;
      grid.innerHTML = economyPosts
        .slice(start, start + pageSize)
        .map(function (post) {
          return (
            '<article class="story-card"><a href="' +
            (post[5] || "#") +
            '"><img src="' +
            post[0] +
            '" alt="' +
            post[1] +
            '" /><span class="story-card__body"><span class="story-card__kicker">EKONOMİ</span><h3 class="story-card__title">' +
            post[2] +
            '</h3><time class="story-card__time" datetime="' +
            post[3] +
            '">' +
            clock +
            post[4] +
            "</time></span></a></article>"
          );
        })
        .join("");

      nav.querySelectorAll(".pagination__page").forEach(function (button) {
        var current = Number(button.getAttribute("data-page")) === page;
        if (current) button.setAttribute("aria-current", "page");
        else button.removeAttribute("aria-current");
      });
      nav.querySelector('[data-step="-1"]').disabled = page === 1;
      nav.querySelector('[data-step="1"]').disabled = page === pageCount;

      if (!scroll) return;
      var reduce = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
      archive.scrollIntoView({
        behavior: reduce ? "auto" : "smooth",
        block: "start",
      });
    }

    nav.addEventListener("click", function (event) {
      var pageButton = event.target.closest(".pagination__page");
      var stepButton = event.target.closest(".pagination__step");
      if (pageButton) page = Number(pageButton.getAttribute("data-page"));
      else if (stepButton && !stepButton.disabled) {
        page += Number(stepButton.getAttribute("data-step"));
      } else return;
      render(true);
    });

    render(false);
  }

  /* Writers ------------------------------------------------------------- */

  function initWritersGrid() {
    var grid = document.getElementById("writers-grid");
    if (!grid) return;

    var facebook =
      '<a class="writer__social-link writer__social-link--facebook" href="#" aria-label="Facebook"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.5 8.2H17V5h-2.5C11.9 5 10 6.9 10 9.5V11H8v3h2v7h3v-7h2.4l.6-3H13V9.6c0-.8.6-1.4 1.5-1.4z"/></svg></a>';
    var twitter =
      '<a class="writer__social-link writer__social-link--x" href="#" aria-label="X"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.3 7.4c.5-.3.8-.8.9-1.3-.4.3-.9.5-1.5.6A2.3 2.3 0 0 0 15.4 8c-1.3 0-2.3 1-2.3 2.3 0 .2 0 .4.1.5-1.9-.1-3.6-1-4.7-2.4-.2.3-.3.7-.3 1.1 0 .8.4 1.5 1 1.9-.4 0-.7-.1-1-.3v.1c0 1.1.8 2 1.8 2.2-.2.1-.4.1-.7.1-.1 0-.3 0-.4-.1.3.9 1.1 1.5 2 1.6A4.6 4.6 0 0 1 7 16.2a6.5 6.5 0 0 0 3.5 1c4.2 0 6.5-3.5 6.5-6.5v-.3c.5-.3.8-.7 1.1-1.2-.4.2-.9.3-1.3.4z"/></svg></a>';
    var youtube =
      '<a class="writer__social-link writer__social-link--youtube" href="#" aria-label="YouTube"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.6 8.2a2.2 2.2 0 0 0-1.5-1.6C17.5 6.2 12 6.2 12 6.2s-5.5 0-7.1.4a2.2 2.2 0 0 0-1.5 1.6A23 23 0 0 0 3 12a23 23 0 0 0 .4 3.8 2.2 2.2 0 0 0 1.5 1.6c1.6.4 7.1.4 7.1.4s5.5 0 7.1-.4a2.2 2.2 0 0 0 1.5-1.6A23 23 0 0 0 21 12a23 23 0 0 0-.4-3.8zM10.3 15V9l5.2 3-5.2 3z"/></svg></a>';

    grid.innerHTML = writers
      .map(function (author) {
        return (
          '<article class="writer"><a class="writer__avatar" href="' +
          (author[2] || "#") +
          '"><img src="images/gold.jpg" alt=""></a><div><a class="writer__name" href="' +
          (author[2] || "#") +
          '">' +
          author[0] +
          '</a><a class="writer__mail" href="mailto:' +
          author[1] +
          '">' +
          author[1] +
          '</a><div class="writer__social">' +
          facebook +
          twitter +
          youtube +
          "</div></div></article>"
        );
      })
      .join("");
  }

  /* Boot ---------------------------------------------------------------- */

  function boot() {
    initPopularTabs();
    initSearchModal();
    initDrawer();
    initHomeCarousels();
    initAuthorsScroll();
    initPromo();
    initLightbox();
    initEconomyArchive();
    initWritersGrid();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();
