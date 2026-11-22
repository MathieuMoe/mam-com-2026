/* ==========================================================================
   Mathieu Moreau — Portfolio · main.js
   Vanilla ES2020, zero dependency. Each feature is a small isolated module.
   ========================================================================== */
(() => {
  "use strict";

  const API = "api/";
  const $ = (s, c = document) => c.querySelector(s);
  const $$ = (s, c = document) => [...c.querySelectorAll(s)];
  const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
  const lerp = (a, b, t) => a + (b - a) * t;
  const isTouch = matchMedia("(hover: none), (pointer: coarse)").matches;
  const reduced = matchMedia("(prefers-reduced-motion: reduce)").matches;
  const params = new URLSearchParams(location.search);

  /* ------------------------------------------------------------------ */
  /* i18n                                                                */
  /* ------------------------------------------------------------------ */
  const SUPPORTED = ["fr", "en", "es"];
  const frCache = new Map();
  let lang = (() => {
    const q = params.get("lang");
    if (SUPPORTED.includes(q)) return q;
    try { const s = localStorage.getItem("lang"); if (SUPPORTED.includes(s)) return s; } catch (e) {}
    const nav = (navigator.language || "fr").slice(0, 2);
    return SUPPORTED.includes(nav) ? nav : "en";
  })();
  const ui = (k) => (I18N.ui[lang] && I18N.ui[lang][k]) ?? I18N.ui.fr[k];

  function applyLang(next, user = false) {
    lang = next;
    document.documentElement.lang = lang;
    $$("[data-i18n]").forEach((el) => {
      const key = el.dataset.i18n;
      if (!frCache.has(el)) frCache.set(el, el.innerHTML);
      const val = lang === "fr" ? frCache.get(el) : I18N[lang][key];
      if (val != null) el.innerHTML = val;
    });
    $$("[data-i18n-ph]").forEach((el) => {
      if (!el.dataset.phFr) el.dataset.phFr = el.placeholder;
      el.placeholder = lang === "fr" ? el.dataset.phFr : (I18N[lang][el.dataset.i18nPh] || el.dataset.phFr);
    });
    if (!document.documentElement.dataset.titleFr) document.documentElement.dataset.titleFr = document.title;
    document.title = lang === "fr" ? document.documentElement.dataset.titleFr : I18N[lang]["meta.title"];
    $$(".lang button").forEach((b) => b.classList.toggle("is-active", b.dataset.lang === lang));
    splitWords();
    Typer.reset();
    Greeting.render();
    try { localStorage.setItem("lang", lang); } catch (e) {}
    if (user) {
      const u = new URL(location); u.searchParams.set("lang", lang); history.replaceState(null, "", u);
      Track.event("lang_switch", lang);
    }
  }
  $$(".lang button").forEach((b) => b.addEventListener("click", () => applyLang(b.dataset.lang, true)));

  /* ------------------------------------------------------------------ */
  /* Text splitting                                                      */
  /* ------------------------------------------------------------------ */
  let charIndex = 0;
  $$("[data-split]").forEach((el) => {
    const txt = el.textContent;
    el.textContent = "";
    [...txt].forEach((c) => {
      const s = document.createElement("span");
      s.className = "char"; s.textContent = c; s.style.setProperty("--i", charIndex++);
      el.appendChild(s);
    });
  });

  function splitWords() {
    $$("[data-words]").forEach((el) => {
      const words = el.textContent.trim().split(/\s+/);
      el.innerHTML = words.map((w) => `<span class="word">${w}</span>`).join(" ");
    });
  }

  /* ------------------------------------------------------------------ */
  /* Loader                                                              */
  /* ------------------------------------------------------------------ */
  function runLoader() {
    const el = $("#loader"), num = $("#loaderNum");
    if (reduced) { el.remove(); document.body.classList.add("is-loaded"); return; }
    const start = performance.now(), dur = 1300;
    (function tick(t) {
      const p = clamp((t - start) / dur, 0, 1);
      num.textContent = Math.round((1 - Math.pow(1 - p, 3)) * 100);
      if (p < 1) return requestAnimationFrame(tick);
      el.classList.add("is-done");
      document.body.classList.add("is-loaded");
      setTimeout(() => el.remove(), 1200);
    })(start);
  }

  /* ------------------------------------------------------------------ */
  /* Cursor, magnetic elements, spotlight & tilt                         */
  /* ------------------------------------------------------------------ */
  const mouse = { x: innerWidth / 2, y: innerHeight / 2 };
  addEventListener("pointermove", (e) => { mouse.x = e.clientX; mouse.y = e.clientY; }, { passive: true });

  function initCursor() {
    if (isTouch) return;
    document.body.classList.add("has-cursor");
    const ring = $(".cursor"), dot = $(".cursor-dot"), label = $(".cursor__label");
    const pos = { x: mouse.x, y: mouse.y };
    (function loop() {
      pos.x = lerp(pos.x, mouse.x, .18); pos.y = lerp(pos.y, mouse.y, .18);
      ring.style.transform = `translate3d(${pos.x}px,${pos.y}px,0)`;
      dot.style.transform = `translate3d(${mouse.x}px,${mouse.y}px,0)`;
      requestAnimationFrame(loop);
    })();
    document.addEventListener("pointerover", (e) => {
      const t = e.target.closest("a, button, [data-drag], [data-cursor], input, textarea, .flash");
      ring.classList.remove("is-hover", "is-label", "is-drag");
      if (!t) return;
      if (t.dataset.cursor) { label.textContent = t.dataset.cursor; ring.classList.add("is-label"); }
      else if (t.hasAttribute("data-drag")) ring.classList.add("is-drag");
      else ring.classList.add("is-hover");
    });
  }

  function initMagnetic() {
    if (isTouch) return;
    $$(".magnetic").forEach((el) => {
      el.addEventListener("pointermove", (e) => {
        const r = el.getBoundingClientRect();
        const x = (e.clientX - r.left - r.width / 2) * .3, y = (e.clientY - r.top - r.height / 2) * .4;
        el.style.transform = `translate(${x}px, ${y}px)`;
      });
      el.addEventListener("pointerleave", () => { el.style.transform = ""; });
    });
  }

  function initCards() {
    $$(".spot").forEach((el) => {
      el.addEventListener("pointermove", (e) => {
        const r = el.getBoundingClientRect();
        el.style.setProperty("--mx", `${e.clientX - r.left}px`);
        el.style.setProperty("--my", `${e.clientY - r.top}px`);
        if (!isTouch && el.hasAttribute("data-tilt")) {
          const rx = ((e.clientY - r.top) / r.height - .5) * -6, ry = ((e.clientX - r.left) / r.width - .5) * 6;
          el.style.transform = `perspective(900px) rotateX(${rx}deg) rotateY(${ry}deg)`;
        }
      });
      el.addEventListener("pointerleave", () => { el.style.transform = ""; });
    });
  }

  /* ------------------------------------------------------------------ */
  /* Hero: variable-font weight reacts to cursor distance                */
  /* ------------------------------------------------------------------ */
  function initHeroWeight() {
    if (isTouch || reduced) return;
    const chars = $$(".hero__title .char");
    const hero = $("#hero");
    let active = true;
    new IntersectionObserver(([e]) => { active = e.isIntersecting; }).observe(hero);
    (function loop() {
      if (active) {
        chars.forEach((c) => {
          const r = c.getBoundingClientRect();
          const d = Math.hypot(mouse.x - (r.left + r.width / 2), mouse.y - (r.top + r.height / 2));
          const w = Math.round(lerp(800, 250, clamp(d / 380, 0, 1)));
          c.style.setProperty("--w", w);
        });
      }
      requestAnimationFrame(loop);
    })();
  }

  /* ------------------------------------------------------------------ */
  /* Typewriter                                                          */
  /* ------------------------------------------------------------------ */
  const Typer = (() => {
    const el = $("#typer");
    let i = 0, j = 0, del = false, timer;
    function step() {
      const roles = ui("roles"); const word = roles[i % roles.length];
      j += del ? -1 : 1;
      el.textContent = word.slice(0, j);
      let wait = del ? 35 : 75;
      if (!del && j === word.length) { del = true; wait = 1800; }
      else if (del && j === 0) { del = false; i++; wait = 300; }
      timer = setTimeout(step, wait);
    }
    return { reset() { clearTimeout(timer); i = 0; j = 0; del = false; el.textContent = ""; timer = setTimeout(step, 400); } };
  })();

  /* ------------------------------------------------------------------ */
  /* Draggable stickers (Pointer Events, works with touch & mouse)       */
  /* ------------------------------------------------------------------ */
  function initStickers() {
    $$("[data-drag]").forEach((el) => {
      let sx, sy, ox = 0, oy = 0;
      el.addEventListener("pointerdown", (e) => {
        el.setPointerCapture(e.pointerId); el.classList.add("is-dragging");
        sx = e.clientX - ox; sy = e.clientY - oy;
      });
      el.addEventListener("pointermove", (e) => {
        if (!el.hasPointerCapture(e.pointerId)) return;
        ox = e.clientX - sx; oy = e.clientY - sy;
        el.style.translate = `${ox}px ${oy}px`;
      });
      const end = (e) => { if (el.hasPointerCapture(e.pointerId)) { el.releasePointerCapture(e.pointerId); Track.event("sticker_drag", el.textContent.trim().slice(0, 20)); } el.classList.remove("is-dragging"); };
      el.addEventListener("pointerup", end); el.addEventListener("pointercancel", end);
    });
  }

  /* ------------------------------------------------------------------ */
  /* Marquee: infinite loop, speed reacts to scroll velocity             */
  /* ------------------------------------------------------------------ */
  let scrollVel = 0, lastY = scrollY;
  function initMarquee() {
    const rows = $$(".marquee__row").map((row) => {
      const track = $(".marquee__track", row);
      row.appendChild(track.cloneNode(true)).setAttribute("aria-hidden", "true");
      return { row, tracks: $$(".marquee__track", row), dir: +row.dataset.speed, x: 0 };
    });
    (function loop() {
      scrollVel = lerp(scrollVel, scrollY - lastY, .1); lastY = scrollY;
      const boost = 1 + Math.min(Math.abs(scrollVel) * .15, 6);
      rows.forEach((r) => {
        const w = r.tracks[0].offsetWidth;
        r.x -= r.dir * .6 * boost * (reduced ? 0 : 1) * (scrollVel < -0.5 ? -1 : 1);
        if (r.x <= -w) r.x += w; if (r.x > 0) r.x -= w;
        r.tracks.forEach((t) => { t.style.transform = `translate3d(${r.x}px,0,0)`; });
      });
      requestAnimationFrame(loop);
    })();
  }

  /* ------------------------------------------------------------------ */
  /* Scroll-driven effects (single rAF-throttled handler)                */
  /* ------------------------------------------------------------------ */
  const hasScrollTimeline = CSS.supports("animation-timeline: scroll()");
  const nav = $(".nav");
  let navLastY = 0;

  function onScroll() {
    const y = scrollY, vh = innerHeight;

    if (!hasScrollTimeline) {
      const max = document.documentElement.scrollHeight - vh;
      $(".progress").style.setProperty("--p", (y / max).toFixed(4));
    }

    // hide nav when scrolling down
    nav.classList.toggle("is-hidden", y > 300 && y > navLastY && !$("#palette:not([hidden])"));
    navLastY = y;

    // manifesto: words light up as you read
    const m = $(".manifesto__text");
    if (m) {
      const r = m.getBoundingClientRect();
      const p = clamp((vh * .85 - r.top) / (r.height + vh * .3), 0, 1);
      const words = $$(".word", m), n = words.length;
      words.forEach((w, i) => w.style.setProperty("--o", clamp(p * n * 1.15 - i, .12, 1).toFixed(2)));
    }

    // stacked cards: cards underneath shrink & darken
    const cards = $$(".stack__card");
    cards.forEach((c, i) => {
      const next = cards[i + 1]; if (!next) return;
      const r = c.getBoundingClientRect(), nr = next.getBoundingClientRect();
      const p = clamp((r.bottom - nr.top) / r.height, 0, 1);
      c.style.setProperty("--s", (1 - p * .06).toFixed(3));
      c.style.setProperty("--b", (1 - p * .12).toFixed(3));
    });

    // horizontal journey
    const j = $("#journey"), track = $("#journeyTrack");
    if (j && innerWidth > 720) {
      const r = j.getBoundingClientRect();
      const p = clamp(-r.top / (r.height - vh), 0, 1);
      const dist = track.scrollWidth - innerWidth + parseFloat(getComputedStyle(j.firstElementChild).paddingLeft) * 2;
      track.style.transform = `translate3d(${-p * Math.max(dist, 0)}px,0,0)`;
      $("#journeyBar").parentElement.style.setProperty("--jp", p.toFixed(3));
    }

    // footer outline fills
    const f = $(".footer__name");
    const fr = f.getBoundingClientRect();
    f.style.setProperty("--fill", `${clamp((vh - fr.top) / (fr.height + 120), 0, 1) * 100}%`);
  }
  let ticking = false;
  addEventListener("scroll", () => { if (!ticking) { ticking = true; requestAnimationFrame(() => { onScroll(); ticking = false; }); } }, { passive: true });
  addEventListener("resize", onScroll);

  /* ------------------------------------------------------------------ */
  /* Reveal on view, counters, active nav link, section tracking         */
  /* ------------------------------------------------------------------ */
  function initObservers() {
    const toReveal = [".section-head > *", ".stat", ".card", ".step", ".toolbox__col", ".contact__head > *", ".form", ".eduverse__copy > *", ".app"];
    toReveal.forEach((sel) => $$(sel).forEach((el, i) => { el.setAttribute("data-reveal", ""); el.style.setProperty("--d", `${(i % 6) * .07}s`); }));

    const io = new IntersectionObserver((entries) => {
      entries.forEach((e) => {
        if (!e.isIntersecting) return;
        e.target.classList.add("in");
        io.unobserve(e.target);
      });
    }, { threshold: .15 });
    $$("[data-reveal], .stack__card").forEach((el) => io.observe(el));
    $$(".stack__card").forEach((c, i) => c.style.setProperty("--idx", i));

    // counters
    const cio = new IntersectionObserver((entries) => {
      entries.forEach((e) => {
        if (!e.isIntersecting) return;
        const el = e.target, to = +el.dataset.count, t0 = performance.now();
        (function tick(t) { const p = clamp((t - t0) / 1400, 0, 1); el.textContent = Math.round(to * (1 - Math.pow(1 - p, 4))); if (p < 1) requestAnimationFrame(tick); })(t0);
        cio.unobserve(el);
      });
    }, { threshold: .6 });
    $$("[data-count]").forEach((el) => cio.observe(el));

    // active link + analytics "section reached"
    const seen = new Set();
    const sio = new IntersectionObserver((entries) => {
      entries.forEach((e) => {
        if (!e.isIntersecting) return;
        const id = e.target.id;
        $$(".nav__links a").forEach((a) => a.classList.toggle("is-active", a.getAttribute("href") === `#${id}`));
        if (!seen.has(id)) { seen.add(id); Track.event("section_view", id); }
      });
    }, { rootMargin: "-45% 0px -45% 0px" });
    $$("main section[id]").forEach((s) => sio.observe(s));
  }

  /* ------------------------------------------------------------------ */
  /* Clock (Europe/Paris)                                                */
  /* ------------------------------------------------------------------ */
  function initClock() {
    const el = $("#clock");
    const fmt = new Intl.DateTimeFormat("fr-FR", { hour: "2-digit", minute: "2-digit", timeZone: "Europe/Paris" });
    const tick = () => { el.textContent = fmt.format(new Date()); };
    tick(); setInterval(tick, 15000);
    $("#year").textContent = new Date().getFullYear();
  }

  /* ------------------------------------------------------------------ */
  /* Theme & code mode                                                   */
  /* ------------------------------------------------------------------ */
  function initTheme() {
    const root = document.documentElement;
    let theme;
    try { theme = localStorage.getItem("theme"); } catch (e) {}
    if (!theme) theme = matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
    const set = (t) => {
      root.dataset.theme = t;
      $('meta[name="theme-color"]').content = t === "dark" ? "#0F0F0D" : "#F2EEE5";
      try { localStorage.setItem("theme", t); } catch (e) {}
    };
    set(theme);
    const toggle = () => {
      const next = root.dataset.theme === "dark" ? "light" : "dark";
      if (document.startViewTransition && !reduced) document.startViewTransition(() => set(next)); else set(next);
      Track.event("theme", next);
    };
    $("#themeToggle").addEventListener("click", toggle);
    return toggle;
  }

  function toggleCodeMode() {
    const on = document.body.classList.toggle("code-mode");
    $("#modeToggle").setAttribute("aria-pressed", on);
    toast(ui(on ? "codeOn" : "codeOff"));
    Track.event("code_mode", on ? "on" : "off");
  }
  $("#modeToggle").addEventListener("click", toggleCodeMode);

  /* ------------------------------------------------------------------ */
  /* Toast                                                               */
  /* ------------------------------------------------------------------ */
  let toastTimer;
  function toast(msg) {
    const t = $("#toast"); t.textContent = msg; t.classList.add("is-on");
    clearTimeout(toastTimer); toastTimer = setTimeout(() => t.classList.remove("is-on"), 2800);
  }

  /* ------------------------------------------------------------------ */
  /* EduVerse interactive demo                                           */
  /* ------------------------------------------------------------------ */
  function initEduverse() {
    // tabs
    $$(".tab").forEach((t) => t.addEventListener("click", () => {
      $$(".tab").forEach((x) => x.classList.toggle("is-active", x === t));
      $$(".panel").forEach((p) => p.classList.toggle("is-active", p.dataset.panel === t.dataset.tab));
      Track.event("eduverse_tab", t.dataset.tab);
    }));

    // quiz + XP
    let xp = 620;
    const fill = $("#xpFill"), xpText = $("#xpText"), fb = $("#quizFb");
    $$(".quiz__opts button").forEach((b) => b.addEventListener("click", (e) => {
      if (b.dataset.ok === "1") {
        b.classList.add("is-ok"); fb.textContent = ui("quizOk");
        xp += 50; if (xp >= 1000) { xp -= 1000; setTimeout(() => toast(ui("levelUp")), 400); }
        fill.style.setProperty("--xp", `${xp / 10}%`); xpText.textContent = `${xp} / 1000 XP`;
        const pop = document.createElement("span"); pop.className = "xp-pop"; pop.textContent = "+50 XP";
        const r = b.getBoundingClientRect(), pr = b.offsetParent.getBoundingClientRect();
        pop.style.left = `${r.left - pr.left + r.width / 2 - 25}px`; pop.style.top = `${r.top - pr.top}px`;
        b.offsetParent.appendChild(pop); setTimeout(() => pop.remove(), 1100);
        setTimeout(() => { $$(".quiz__opts button").forEach((x) => x.classList.remove("is-ok", "is-ko")); fb.textContent = ""; }, 2200);
        Track.event("quiz", "correct");
      } else {
        b.classList.remove("is-ko"); void b.offsetWidth; b.classList.add("is-ko"); fb.textContent = ui("quizKo");
        Track.event("quiz", "wrong");
      }
    }));

    // pendulum: T = 2π √(L/g)
    const pend = $("#pendulum"), range = $("#pendLen"), out = $("#pendT");
    const upd = () => {
      const px = +range.value, L = px / 100, T = 2 * Math.PI * Math.sqrt(L / 9.81);
      pend.style.setProperty("--len", `${px}px`); pend.style.setProperty("--dur", `${(T / 2).toFixed(2)}s`);
      out.textContent = T.toFixed(2);
    };
    range.addEventListener("input", upd); upd();

    // flashcard
    const flash = $("#flash");
    const flip = () => flash.classList.toggle("is-flipped");
    flash.addEventListener("click", flip);
    flash.addEventListener("keydown", (e) => { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); flip(); } });

    // podcast via Web Speech API
    const pb = $("#podcastBtn");
    pb.addEventListener("click", () => {
      if (!("speechSynthesis" in window)) return;
      if (speechSynthesis.speaking) { speechSynthesis.cancel(); pb.classList.remove("is-playing"); return; }
      const u = new SpeechSynthesisUtterance(ui("podcastText"));
      u.lang = { fr: "fr-FR", en: "en-GB", es: "es-ES" }[lang]; u.rate = 1;
      u.onend = () => pb.classList.remove("is-playing");
      pb.classList.add("is-playing"); speechSynthesis.speak(u);
      Track.event("eduverse_podcast", lang);
    });
  }

  /* ------------------------------------------------------------------ */
  /* Email (obfuscated against scrapers) & contact form                  */
  /* ------------------------------------------------------------------ */
  function initEmail() {
    $$(".js-mail").forEach((a) => {
      const addr = `${a.dataset.u}@${a.dataset.d}`;
      a.href = `mailto:${addr}`;
      $("span", a).textContent = addr;
    });
  }
  async function copyEmail() {
    const a = $(".js-mail"); const addr = `${a.dataset.u}@${a.dataset.d}`;
    try { await navigator.clipboard.writeText(addr); toast(ui("emailCopied")); } catch (e) { location.href = `mailto:${addr}`; }
    Track.event("email_copy");
  }

  function initForm() {
    const form = $("#contactForm"), status = $("#formStatus");
    const t0 = Date.now();
    form.addEventListener("submit", async (e) => {
      e.preventDefault();
      const data = Object.fromEntries(new FormData(form));
      if (!data.name?.trim() || !/^\S+@\S+\.\S+$/.test(data.email || "") || !data.message?.trim()) {
        status.className = "form__status err"; status.textContent = ui("invalid"); return;
      }
      Object.assign(data, { lang, campaign: Track.campaign, session: Track.session, elapsed: Date.now() - t0 });
      status.className = "form__status"; status.textContent = ui("sending");
      try {
        const res = await fetch(API + "contact.php", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(data) });
        const json = await res.json();
        if (!res.ok || !json.ok) throw new Error(json.error || res.status);
        status.className = "form__status ok"; status.textContent = ui("sent");
        form.reset(); form.classList.add("is-sent");
      } catch (err) {
        status.className = "form__status err"; status.textContent = ui("error");
      }
    });
  }

  /* ------------------------------------------------------------------ */
  /* Command palette (Ctrl/Cmd + K)                                      */
  /* ------------------------------------------------------------------ */
  function initPalette(toggleTheme) {
    const pal = $("#palette"), input = $("#paletteInput"), list = $("#paletteList");
    const go = (id) => () => document.getElementById(id).scrollIntoView({ behavior: "smooth" });
    const actions = [
      ["services", "→", go("services")], ["eduverse", "→", go("eduverse")], ["work", "→", go("work")],
      ["journey", "→", go("journey")], ["contact", "→", go("contact")],
      ["cv", "PDF", () => { $('[data-track="cv_download"]').click(); }],
      ["email", "⌘C", copyEmail], ["theme", "◐", toggleTheme], ["code", "</>", toggleCodeMode],
      ["github", "↗", () => window.open("https://github.com/MathieuMoe", "_blank", "noopener")],
      ["linkedin", "↗", () => window.open("https://www.linkedin.com/in/mathieu-moreau-045b26114/", "_blank", "noopener")],
      ["fr", "FR", () => applyLang("fr", true)], ["en", "EN", () => applyLang("en", true)], ["es", "ES", () => applyLang("es", true)]
    ];
    let filtered = [], sel = 0;
    const render = () => {
      const q = input.value.toLowerCase().normalize("NFD").replace(/\p{Diacritic}/gu, "");
      filtered = actions.filter(([k]) => ui("cmd")[k].toLowerCase().normalize("NFD").replace(/\p{Diacritic}/gu, "").includes(q));
      sel = clamp(sel, 0, Math.max(filtered.length - 1, 0));
      list.innerHTML = filtered.map(([k, hint], i) => `<li class="${i === sel ? "is-sel" : ""}" data-i="${i}">${ui("cmd")[k]}<small>${hint}</small></li>`).join("");
    };
    const open = () => { pal.hidden = false; input.value = ""; sel = 0; render(); setTimeout(() => input.focus(), 20); Track.event("palette_open"); };
    const close = () => { pal.hidden = true; };
    const run = (i) => { const a = filtered[i]; if (!a) return; close(); a[2](); Track.event("palette_cmd", a[0]); };

    $("#paletteBtn").addEventListener("click", open);
    addEventListener("keydown", (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "k") { e.preventDefault(); pal.hidden ? open() : close(); }
      else if (!pal.hidden) {
        if (e.key === "Escape") close();
        else if (e.key === "ArrowDown") { sel = (sel + 1) % filtered.length; render(); e.preventDefault(); }
        else if (e.key === "ArrowUp") { sel = (sel - 1 + filtered.length) % filtered.length; render(); e.preventDefault(); }
        else if (e.key === "Enter") run(sel);
      }
    });
    input.addEventListener("input", () => { sel = 0; render(); });
    list.addEventListener("click", (e) => { const li = e.target.closest("li"); if (li) run(+li.dataset.i); });
    pal.addEventListener("click", (e) => { if (e.target === pal) close(); });
  }

  /* ------------------------------------------------------------------ */
  /* Privacy-friendly analytics (no cookies, respects Do Not Track)      */
  /* ------------------------------------------------------------------ */
  const Track = (() => {
    const dnt = navigator.doNotTrack === "1" || window.doNotTrack === "1";
    let session;
    try { session = sessionStorage.getItem("sid") || crypto.randomUUID(); sessionStorage.setItem("sid", session); } catch (e) { session = Math.random().toString(36).slice(2); }
    let campaign = (params.get("c") || "").replace(/[^a-z0-9-]/gi, "").slice(0, 60);
    try { if (campaign) sessionStorage.setItem("c", campaign); else campaign = sessionStorage.getItem("c") || ""; } catch (e) {}
    const base = () => ({ session, campaign, lang });
    const send = (path, body, beacon = false) => {
      if (location.protocol === "file:") return;
      const json = JSON.stringify({ ...base(), ...body });
      if (beacon && navigator.sendBeacon) return navigator.sendBeacon(API + path, new Blob([json], { type: "application/json" }));
      fetch(API + path, { method: "POST", headers: { "Content-Type": "application/json" }, body: json, keepalive: true }).catch(() => {});
    };
    return {
      session, campaign,
      pageview() {
        if (dnt && !campaign) return;
        send("track.php", { type: "pageview", path: location.pathname, referrer: document.referrer.slice(0, 255), screen: innerWidth, theme: document.documentElement.dataset.theme });
        const t0 = Date.now();
        addEventListener("visibilitychange", () => { if (document.visibilityState === "hidden") send("track.php", { type: "event", name: "time_on_page", value: String(Math.round((Date.now() - t0) / 1000)) }, true); });
      },
      event(name, value = "") { if (dnt && !campaign) return; send("track.php", { type: "event", name, value: String(value).slice(0, 120) }); }
    };
  })();
  document.addEventListener("click", (e) => { const t = e.target.closest("[data-track]"); if (t) Track.event(t.dataset.track); });

  /* ------------------------------------------------------------------ */
  /* Personalised greeting for recruiter links:  /?c=acme                */
  /* ------------------------------------------------------------------ */
  const Greeting = (() => {
    let company = "";
    return {
      async load() {
        if (!Track.campaign || location.protocol === "file:") return;
        try {
          const r = await fetch(`${API}campaign.php?c=${encodeURIComponent(Track.campaign)}`);
          const j = await r.json();
          if (j.ok && j.company) { company = j.company; if (j.lang && !params.get("lang")) applyLang(j.lang); else this.render(); }
        } catch (e) {}
      },
      render() {
        const el = $("#greet"); if (!el || !company) return;
        el.textContent = ui("greet").replace("{name}", company); el.hidden = false;
      }
    };
  })();

  /* ------------------------------------------------------------------ */
  /* Boot                                                                */
  /* ------------------------------------------------------------------ */
  applyLang(lang);
  runLoader();
  initCursor(); initMagnetic(); initCards(); initHeroWeight(); initStickers(); initMarquee();
  initObservers(); initClock(); initEduverse(); initEmail(); initForm();
  initPalette(initTheme());
  onScroll();
  Track.pageview();
  Greeting.load();

  console.log("%c Mathieu Moreau %c Vous lisez le code ? On devrait discuter → #contact ", "background:#C9F25A;color:#111;font-weight:700;padding:4px 8px;border-radius:4px 0 0 4px", "background:#111;color:#F2EEE5;padding:4px 8px;border-radius:0 4px 4px 0");
})();
