(() => {
  "use strict";

  const API_URL = "index.php";
  const IDLE_TIMEOUT_MS = 30 * 60 * 1000;
  const PING_INTERVAL_MS = 60 * 1000;
  let csrfToken = "";
  let lastActivityAt = 0;
  let lastPingAt = 0;
  let pingInProgress = false;
  let started = false;
  let lastPointerMoveAt = 0;

  function loginUrl() {
    const page = window.location.pathname.split("/").pop();
    const allowedPages = new Set(["index.html", "profil.html", "riwayat.html", "divisi.html", "pengelola.html"]);
    const next = allowedPages.has(page) ? `${page}${window.location.hash}` : "index.html";
    return `login.html?next=${encodeURIComponent(next)}`;
  }

  async function pingActivity() {
    const now = Date.now();
    if (!csrfToken || pingInProgress || now - lastPingAt < PING_INTERVAL_MS) {
      return;
    }

    pingInProgress = true;
    lastPingAt = now;
    try {
      const response = await fetch(`${API_URL}?action=session-activity`, {
        method: "POST",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          "X-CSRF-Token": csrfToken
        },
        credentials: "same-origin",
        body: "{}"
      });
      if (response.status === 401) {
        await window.RuangAbsenTransition.navigate(loginUrl());
        return;
      }
      if (!response.ok) {
        const result = await response.json().catch(() => null);
        throw new Error(result?.error || `Pembaruan sesi gagal (${response.status}).`);
      }
      lastPingAt = Date.now();
    } catch (error) {
      console.error("Tidak dapat memperbarui aktivitas sesi.", error);
    } finally {
      pingInProgress = false;
    }
  }

  function recordActivity() {
    lastActivityAt = Date.now();
    if (lastActivityAt - lastPingAt >= PING_INTERVAL_MS) {
      void pingActivity();
    }
  }

  function checkIdleTimeout() {
    if (started && Date.now() - lastActivityAt >= IDLE_TIMEOUT_MS) {
      void window.RuangAbsenTransition.navigate(loginUrl());
      return;
    }
    window.setTimeout(checkIdleTimeout, 5000);
  }

  async function start(token) {
    if (typeof token !== "string" || token === "") {
      return;
    }
    csrfToken = token;
    if (started) {
      return;
    }
    started = true;
    lastActivityAt = Date.now();
    lastPingAt = 0;
    document.addEventListener("pointerdown", recordActivity, { passive: true });
    document.addEventListener("keydown", recordActivity);
    document.addEventListener("touchstart", recordActivity, { passive: true });
    document.addEventListener("scroll", recordActivity, { passive: true });
    document.addEventListener("wheel", recordActivity, { passive: true });
    document.addEventListener("pointermove", () => {
      const now = Date.now();
      if (now - lastPointerMoveAt >= 1000) {
        lastPointerMoveAt = now;
        recordActivity();
      }
    }, { passive: true });
    document.addEventListener("visibilitychange", () => {
      if (document.visibilityState === "visible") {
        recordActivity();
      }
    });
    window.setTimeout(checkIdleTimeout, 5000);
    await pingActivity();
  }

  window.RuangAbsenSession = { start };
})();
