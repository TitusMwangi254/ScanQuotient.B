document.addEventListener("DOMContentLoaded", () => {
  const legalHeader = document.getElementById("legalHeader");
  if (legalHeader) {
    document.body.classList.add("has-legal-header");
    const onHeaderScroll = () => {
      legalHeader.classList.toggle("scrolled", window.scrollY > 50);
    };
    window.addEventListener("scroll", onHeaderScroll, { passive: true });
    onHeaderScroll();
  }

  const scrollToTopBtn = document.getElementById("scrollToTopBtn");
  const scrollDownBtn = document.getElementById("scrollDownBtn");

  if (!scrollToTopBtn || !scrollDownBtn) {
    return;
  }

  let scrollDownStep = 0;

  const getMaxScroll = () =>
    Math.max(
      0,
      document.documentElement.scrollHeight - window.innerHeight
    );

  const updateButtonVisibility = () => {
    const scrollY = window.scrollY;
    const maxScroll = getMaxScroll();

    scrollToTopBtn.classList.toggle("visible", scrollY > 200);
    scrollDownBtn.classList.toggle("visible", scrollY < maxScroll - 40);

    if (scrollY < 80) {
      scrollDownStep = 0;
    }
  };

  scrollToTopBtn.addEventListener("click", () => {
    window.scrollTo({ top: 0, behavior: "smooth" });
    scrollDownStep = 0;
  });

  scrollDownBtn.addEventListener("click", () => {
    const maxScroll = getMaxScroll();
    const halfScroll = maxScroll / 2;

    if (scrollDownStep === 0) {
      window.scrollTo({ top: halfScroll, behavior: "smooth" });
      scrollDownStep = 1;
    } else {
      window.scrollTo({ top: maxScroll, behavior: "smooth" });
      scrollDownStep = 0;
    }
  });

  window.addEventListener("scroll", updateButtonVisibility, { passive: true });
  window.addEventListener("resize", updateButtonVisibility);
  updateButtonVisibility();
});
