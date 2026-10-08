(() => {
  "use strict";

  const style = document.createElement("style");
  style.textContent = `
    body { transition: opacity 280ms ease, transform 280ms ease; }
    body::after {
      position: fixed;
      z-index: 9999;
      inset: 0;
      background: #142a45;
      content: "";
      opacity: 0;
      pointer-events: none;
      transition: opacity 280ms ease;
    }
    html.page-leaving body { opacity: 0; transform: translateY(8px) scale(.995); }
    html.page-leaving body::after { opacity: .12; }
    @media (prefers-reduced-motion: reduce) {
      body, body::after { transition-duration: 0ms !important; }
    }
  `;
  document.head.append(style);

  window.RuangAbsenTransition = {
    async navigate(url) {
      const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
      document.documentElement.classList.add("page-leaving");
      await new Promise((resolve) => window.setTimeout(resolve, reduceMotion ? 0 : 280));
      window.location.replace(url);
    }
  };
})();
