// mam-com.com/v2 — "Spectrum"
// GSAP + ScrollTrigger + SplitText + Lenis (self-hosted globals). WebGL hero written by hand (raymarched shader, no library).
// three.js only for the pendulum lab (lazy module). Statistics, contact, CV and assistant APIs unchanged.
const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];
const root = document.documentElement;
const body = document.body;
const lang = body.dataset.lang;
const forSlug = body.dataset.for || '';
const BASE = body.dataset.base || '';
const T = JSON.parse($('#i18n')?.textContent || '{}');
const LINKS = JSON.parse($('#links')?.textContent || '{}');
const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
const finePointer = matchMedia('(hover: hover) and (pointer: fine)').matches;
const mobile = matchMedia('(max-width: 760px)').matches;
const wide = matchMedia('(min-width: 1101px)').matches;
const { gsap, ScrollTrigger, SplitText, Lenis } = window;
const motion = !reduced && !!gsap && !!ScrollTrigger && !!SplitText;
const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
const lerp = (a, b, t) => a + (b - a) * t;
root.classList.add('js', motion ? 'motion' : 'no-motion');
setTimeout(() => { if (!root.classList.contains('ready')) { root.classList.replace('motion', 'no-motion'); $('.loader')?.remove(); } }, 7000);

// Same session id key as the main site, so one visit across both versions is one session
let session;
try { session = sessionStorage.getItem('sid') || crypto.randomUUID(); sessionStorage.setItem('sid', session); } catch { session = Math.random().toString(36).slice(2); }

/* ---------------- Helpers: API, tracking, toast ---------------- */
const api = async (path, data) => {
    const res = await fetch(`${BASE}/api/${path}.php`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...data, lang, for: forSlug, session }) });
    const json = await res.json().catch(() => ({}));
    if (!res.ok || json.ok === false) throw Object.assign(new Error(json.error || 'error'), { status: res.status });
    return json;
};
const beacon = (data) => navigator.sendBeacon?.(`${BASE}/api/track.php`, new Blob([JSON.stringify({ ...data, lang, for: forSlug, session })], { type: 'application/json' }));
const track = (name, value = '') => beacon({ type: 'event', name, value: String(value).slice(0, 120) });
let toastTimer;
const toast = (msg) => {
    const el = $('[data-toast]');
    el.textContent = msg;
    el.classList.add('is-on');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => el.classList.remove('is-on'), 2800);
};

/* ---------------- Rolling labels (buttons + menu) ---------------- */
$$('.btn [data-text]').forEach((el) => {
    const text = el.dataset.text;
    el.innerHTML = '<span class="roll-in"><span></span><span aria-hidden="true"></span></span>';
    el.querySelectorAll('.roll-in > span').forEach((s) => { s.textContent = text; });
});
$$('.mi-t').forEach((el) => { el.innerHTML = `<span>${el.textContent}</span>`; });

/* ---------------- Smooth scroll + anchors ---------------- */
let lenis = null;
if (motion) {
    gsap.registerPlugin(ScrollTrigger, SplitText);
    if (Lenis && !mobile) {
        lenis = new Lenis({ lerp: 0.1 });
        window.lenis = lenis;
        lenis.on('scroll', ScrollTrigger.update);
        gsap.ticker.add((t) => lenis.raf(t * 1000));
        gsap.ticker.lagSmoothing(0);
    }
}
const goTo = (target) => {
    if (target === 'top' || target === 0) return lenis ? lenis.scrollTo(0, { duration: 1.6 }) : scrollTo({ top: 0, behavior: 'smooth' });
    const el = typeof target === 'string' ? document.getElementById(target) : target;
    if (!el) return;
    lenis ? lenis.scrollTo(el, { offset: -10, duration: 1.6 }) : el.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth' });
};

/* ---------------- Full-screen 3D menu ---------------- */
const menuBtn = $('[data-menu]'), menu = $('[data-menu-panel]'), cyl = $('[data-menu-cyl]');
const menuItems = $$('[data-menu-item]');
let menuOpen = false;
const setCyl = (k) => {
    cyl?.style.setProperty('--ry', `${-k * 360 / menuItems.length}deg`);
    menuItems.forEach((a, i) => a.classList.toggle('is-on', i === k));
};
const openMenu = () => {
    if (menuOpen) return;
    menuOpen = true;
    menu.hidden = false;
    root.classList.add('menu-open');
    menuBtn.setAttribute('aria-expanded', 'true');
    lenis?.stop();
    requestAnimationFrame(() => menu.classList.add('is-open'));
    setCyl(Math.max(0, menuItems.findIndex((a) => a.getAttribute('href') === `#${activeSection}`)));
    if (motion) {
        gsap.fromTo(menuItems, { yPercent: 110, rotateX: -80, opacity: 0 }, { yPercent: 0, rotateX: 0, opacity: 1, duration: 1, ease: 'expo.out', stagger: 0.045, delay: 0.15 });
        gsap.fromTo('.menu-cyl', { scale: 0.6, opacity: 0, rotateZ: -10 }, { scale: 1, opacity: 1, rotateZ: 0, duration: 1.3, ease: 'expo.out', delay: 0.2 });
        gsap.fromTo('.menu-foot > *', { y: 20, opacity: 0 }, { y: 0, opacity: 1, stagger: 0.05, duration: 0.8, ease: 'expo.out', delay: 0.4 });
    }
    setTimeout(() => menuItems[0]?.focus({ preventScroll: true }), 400);
    track('menu_open');
};
const closeMenu = (then) => {
    if (!menuOpen) { then?.(); return; }
    menuOpen = false;
    menu.classList.remove('is-open');
    root.classList.remove('menu-open');
    menuBtn.setAttribute('aria-expanded', 'false');
    lenis?.start();
    setTimeout(() => { if (!menuOpen) menu.hidden = true; }, 900);
    then && setTimeout(then, 250);
};
menuBtn.addEventListener('click', () => (menuOpen ? closeMenu() : openMenu()));
menuItems.forEach((a, i) => { a.addEventListener('mouseenter', () => setCyl(i)); a.addEventListener('focus', () => setCyl(i)); });

document.addEventListener('click', (e) => {
    const a = e.target.closest('a[href^="#"]');
    if (!a) return;
    e.preventDefault();
    const id = a.getAttribute('href').slice(1) || 'top';
    if (menuOpen) closeMenu(() => goTo(id)); else goTo(id);
});

/* ---------------- Top bar: hide on scroll, progress, current section ---------------- */
const nav = $('.nav');
let lastY = scrollY, activeSection = 'top';
addEventListener('scroll', () => {
    nav.classList.toggle('is-hidden', !menuOpen && scrollY > lastY && scrollY > 500);
    lastY = scrollY;
    $('.progress').style.transform = `scaleX(${scrollY / Math.max(1, document.documentElement.scrollHeight - innerHeight)})`;
}, { passive: true });
const nowN = $('[data-now-n]'), nowT = $('[data-now-t]'), nowWrap = $('.nav-now');
const numbers = { top: '00', manifesto: '01', services: '02', nabbu: '03', eduverse: '04', suite: '04b', work: '05', journey: '06', method: '07', skills: '08', ask: '09', contact: '10' };
const seen = new Set();
const secIo = new IntersectionObserver((entries) => entries.forEach((en) => {
    if (!en.isIntersecting) return;
    const id = en.target.id;
    if (id !== activeSection) {
        activeSection = id;
        nowWrap.classList.add('is-swap');
        requestAnimationFrame(() => {
            nowN.textContent = numbers[id] || '';
            nowT.textContent = T.sec?.[id] || '';
            requestAnimationFrame(() => nowWrap.classList.remove('is-swap'));
        });
    }
    if (!seen.has(id)) { seen.add(id); track('section_view', 'v2:' + id); }
}), { rootMargin: '-45% 0px -50% 0px' });
$$('main section[id]').forEach((sec) => secIo.observe(sec));

/* ---------------- Theme & design/code mode ---------------- */
let lab = null;
const setTheme = (t) => { root.dataset.theme = t; try { localStorage.setItem('v2theme', t); } catch { /* private mode */ } lab?.setDark(t === 'dark'); $('meta[name="theme-color"]').content = t === 'dark' ? '#08080C' : '#F5F2EB'; };
const toggleTheme = () => { const next = root.dataset.theme === 'dark' ? 'light' : 'dark'; (document.startViewTransition && !reduced) ? document.startViewTransition(() => setTheme(next)) : setTheme(next); track('theme', next); };
const toggleCode = () => {
    const on = root.classList.toggle('code-mode');
    $('[data-code-toggle]').setAttribute('aria-pressed', String(on));
    lab?.setWire(on);
    toast(on ? T.codeOn : T.codeOff);
    track('code_mode', on ? 'on' : 'off');
};
$('[data-theme-toggle]').addEventListener('click', toggleTheme);
$('[data-code-toggle]').addEventListener('click', toggleCode);

/* ---------------- E-mail (built in JS against scrapers) ---------------- */
const email = (() => { const a = $('[data-mail]'); return a ? `${a.dataset.u}@${a.dataset.d}` : ''; })();
$$('[data-mail]').forEach((a) => { a.href = `mailto:${email}`; $('span', a).textContent = email; });
const copyEmail = () => { navigator.clipboard?.writeText(email).then(() => toast(T.emailCopied)).catch(() => toast(email)); track('email_copy'); };
const openCv = () => { window.open(LINKS.cv, '_blank', 'noopener'); };

/* ---------------- Command palette (Ctrl/⌘ + K) ---------------- */
(() => {
    const pal = $('[data-palette]'), input = $('[data-palette-input]'), list = $('[data-palette-list]');
    const L = (l) => () => { location.href = `${BASE}/${l}/${forSlug ? '?c=' + forSlug : ''}`; };
    const actions = [
        ['services', '→', () => goTo('services')], ['eduverse', '→', () => goTo('eduverse')], ['lab', '3D', () => { goTo('eduverse'); setTimeout(() => $('[data-tab="1"]').click(), 900); }],
        ['work', '→', () => goTo('work')], ['journey', '→', () => goTo('journey')], ['skills', '→', () => goTo('skills')], ['ask', 'AI', () => { goTo('ask'); setTimeout(() => $('#ask-input').focus(), 1200); }],
        ['contact', '→', () => goTo('contact')], ['cv', 'PDF ↗', openCv], ['email', '⌘C', copyEmail], ['theme', '◐', toggleTheme], ['code', '</>', toggleCode],
        ['github', '↗', () => window.open(LINKS.github, '_blank', 'noopener')], ['linkedin', '↗', () => window.open(LINKS.linkedin, '_blank', 'noopener')],
        ['fr', 'FR', L('fr')], ['en', 'EN', L('en')], ['es', 'ES', L('es')], ['classic', 'v1', () => { location.href = LINKS.classic; }], ['top', '↑', () => goTo('top')],
    ].filter(([k]) => T.cmd?.[k]);
    const norm = (s) => s.toLowerCase().normalize('NFD').replace(/\p{Diacritic}/gu, '');
    let filtered = [], sel = 0;
    const render = () => {
        const q = norm(input.value);
        filtered = actions.filter(([k]) => norm(T.cmd[k]).includes(q));
        sel = clamp(sel, 0, Math.max(filtered.length - 1, 0));
        list.innerHTML = '';
        filtered.forEach(([k, hint], i) => {
            const li = document.createElement('li');
            li.className = i === sel ? 'is-sel' : '';
            li.dataset.i = i;
            li.textContent = T.cmd[k];
            li.append(Object.assign(document.createElement('small'), { textContent: hint }));
            list.append(li);
        });
    };
    const open = () => { pal.hidden = false; input.value = ''; sel = 0; render(); lenis?.stop(); setTimeout(() => input.focus(), 20); track('palette_open'); };
    const close = () => { pal.hidden = true; if (!menuOpen) lenis?.start(); };
    const run = (i) => { const a = filtered[i]; if (!a) return; close(); closeMenu(); a[2](); track('palette_cmd', a[0]); };
    $('[data-palette-open]').addEventListener('click', open);
    addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); pal.hidden ? open() : close(); return; }
        if (pal.hidden) { if (e.key === 'Escape' && menuOpen) { closeMenu(); menuBtn.focus(); } return; }
        if (e.key === 'Escape') close();
        else if (e.key === 'ArrowDown') { sel = (sel + 1) % Math.max(1, filtered.length); render(); e.preventDefault(); }
        else if (e.key === 'ArrowUp') { sel = (sel - 1 + filtered.length) % Math.max(1, filtered.length); render(); e.preventDefault(); }
        else if (e.key === 'Enter') run(sel);
    });
    input.addEventListener('input', () => { sel = 0; render(); });
    list.addEventListener('click', (e) => { const li = e.target.closest('li'); if (li) run(+li.dataset.i); });
    pal.addEventListener('click', (e) => { if (e.target === pal) close(); });
})();

/* ---------------- Hero: clock, typed roles ---------------- */
const tick = () => { const t = new Intl.DateTimeFormat(lang, { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Paris' }).format(new Date()); $$('[data-clock]').forEach((c) => { c.textContent = t; }); };
tick(); setInterval(tick, 15000);
(() => {
    const el = $('[data-typer]');
    const roles = T.roles || [];
    if (!roles.length) return;
    if (reduced) { el.textContent = roles[0]; return; }
    let r = 0, i = 0, del = false;
    const step = () => {
        const word = roles[r];
        i += del ? -1 : 1;
        el.textContent = word.slice(0, i);
        let wait = del ? 35 : 70;
        if (!del && i === word.length) { del = true; wait = 1700; } else if (del && i === 0) { del = false; r = (r + 1) % roles.length; wait = 300; }
        setTimeout(step, wait);
    };
    step();
})();

/* ---------------- Hero name: variable weight follows the cursor ---------------- */
const chars = [];
$$('[data-chars]').forEach((el) => {
    const txt = el.textContent;
    el.textContent = '';
    [...txt].forEach((c) => {
        const s = document.createElement('span');
        s.className = 'ch'; s.textContent = c; s.style.setProperty('--ci', chars.length);
        el.append(s); chars.push(s);
    });
});
const mouse = { x: innerWidth * 0.7, y: innerHeight * 0.4, nx: 0.3, ny: 0.1 };
addEventListener('pointermove', (e) => { mouse.x = e.clientX; mouse.y = e.clientY; mouse.nx = e.clientX / innerWidth * 2 - 1; mouse.ny = -(e.clientY / innerHeight * 2 - 1); }, { passive: true });
let heroVisible = true;
new IntersectionObserver(([en]) => { heroVisible = en.isIntersecting; }).observe($('.hero'));
if (finePointer && !reduced) {
    const loop = () => {
        if (heroVisible) {
            chars.forEach((c) => {
                const r = c.getBoundingClientRect();
                const d = Math.hypot(mouse.x - (r.left + r.width / 2), mouse.y - (r.top + r.height / 2));
                c.style.setProperty('--w', String(Math.round(lerp(800, 250, clamp(d / 420, 0, 1)))));
            });
        }
        requestAnimationFrame(loop);
    };
    loop();
}

/* ---------------- Stickers: throw them (inertia + 3D tilt + shine) ---------------- */
$$('[data-drag]').forEach((el) => {
    let x = 0, y = 0, vx = 0, vy = 0, drag = null, raf = 0;
    const bounds = () => { const h = $('.hero').getBoundingClientRect(), r = el.getBoundingClientRect(); return { minX: h.left - (r.left - x), maxX: h.right - (r.right - x), minY: h.top - (r.top - y), maxY: h.bottom - (r.bottom - y) }; };
    const paint = () => {
        el.style.translate = `${x}px ${y}px`;
        el.style.setProperty('--ty', `${clamp(vx * 2.2, -40, 40)}deg`);
        el.style.setProperty('--tx', `${clamp(-vy * 2.2, -40, 40)}deg`);
        el.style.setProperty('--shine', `${50 - clamp(vx * 6, -80, 80)}%`);
    };
    const fly = () => {
        if (drag) return;
        const b = bounds();
        x += vx; y += vy; vx *= 0.94; vy *= 0.94;
        if (x < b.minX || x > b.maxX) { vx *= -0.7; x = clamp(x, b.minX, b.maxX); }
        if (y < b.minY || y > b.maxY) { vy *= -0.7; y = clamp(y, b.minY, b.maxY); }
        paint();
        raf = Math.abs(vx) + Math.abs(vy) > 0.15 ? requestAnimationFrame(fly) : 0;
        if (!raf) { vx = vy = 0; paint(); }
    };
    el.addEventListener('pointerdown', (e) => { cancelAnimationFrame(raf); drag = { sx: e.clientX - x, sy: e.clientY - y, lx: e.clientX, ly: e.clientY }; el.setPointerCapture(e.pointerId); el.style.zIndex = '5'; });
    el.addEventListener('pointermove', (e) => {
        if (!drag) return;
        vx = e.clientX - drag.lx; vy = e.clientY - drag.ly; drag.lx = e.clientX; drag.ly = e.clientY;
        x = e.clientX - drag.sx; y = e.clientY - drag.sy; paint();
    });
    const up = () => { if (!drag) return; drag = null; track('sticker_drag', el.textContent.trim().slice(0, 30)); raf = requestAnimationFrame(fly); };
    el.addEventListener('pointerup', up); el.addEventListener('pointercancel', up);
});

/* ---------------- Hero WebGL: raymarched iridescent blobs (hand-written shader) ---------------- */
(() => {
    const canvas = $('[data-hero-gl]');
    if (reduced || !canvas) { root.classList.add('no-webgl'); return; }
    const gl = canvas.getContext('webgl', { alpha: true, antialias: false, premultipliedAlpha: true, powerPreference: 'high-performance' });
    if (!gl) { root.classList.add('no-webgl'); return; }
    const vs = 'attribute vec2 p;void main(){gl_Position=vec4(p,0.,1.);}';
    const fs = `precision highp float;
uniform vec2 uRes;uniform float uT;uniform vec2 uM;uniform float uOff;uniform float uScroll;uniform float uLight;uniform float uZoom;uniform float uY;
float smin(float a,float b,float k){float h=clamp(.5+.5*(b-a)/k,0.,1.);return mix(b,a,h)-k*h*(1.-h);}
float map(vec3 p){float t=uT*.45;
 float d=length(p-vec3(sin(t)*.55,cos(t*.8)*.35,0.))-.78;
 d=smin(d,length(p-vec3(cos(t*.7)*.95,sin(t*1.1)*.65,sin(t*.5)*.4))-.5,.62);
 d=smin(d,length(p-vec3(sin(t*1.3)*.55,-cos(t*.6)*.85,cos(t*.9)*.3))-.46,.62);
 d=smin(d,length(p-vec3(-.9+cos(t*.9)*.3,.2+sin(t*.7)*.5,-.3))-.38,.55);
 d=smin(d,length(p-vec3(uM.x*1.6,uM.y*1.1,.45))-.3,.75);
 d+=sin(p.x*4.+t*2.)*sin(p.y*4.+t*1.7)*sin(p.z*4.+t)*.035;
 return d;}
vec3 nrm(vec3 p){vec2 e=vec2(.0025,0.);return normalize(vec3(map(p+e.xyy)-map(p-e.xyy),map(p+e.yxy)-map(p-e.yxy),map(p+e.yyx)-map(p-e.yyx)));}
vec3 pal(float t){return .5+.5*cos(6.28318*(t+vec3(0.,.33,.67)));}
void main(){
 vec2 uv=(gl_FragCoord.xy-.5*uRes)/uRes.y; uv.x-=uOff; uv.y+=uY-uScroll*.6;
 vec3 ro=vec3(0.,0.,3.3*uZoom),rd=normalize(vec3(uv,-1.65));
 float t=0.,d=1.,glow=0.,md=9.,mt=0.;bool hit=false;
 for(int i=0;i<72;i++){vec3 p=ro+rd*t;d=map(p);glow+=.012/(.04+d*d*6.);if(d<md){md=d;mt=t;}if(d<.0015){hit=true;break;}t+=d*.9;if(t>8.)break;}
 vec3 col=vec3(0.);float a=0.;
 float edge=hit?1.:1.-smoothstep(.0,.045*uZoom,md);
 if(edge>0.){if(!hit)t=mt;vec3 p=ro+rd*t;vec3 n=nrm(p);float fr=pow(1.-max(dot(n,-rd),0.),2.2);
  vec3 iri=pal(dot(n,vec3(.35,.6,.25))*1.1+fr*.9+uT*.04+p.y*.25);
  vec3 l=normalize(vec3(.6,.8,.6));float dif=max(dot(n,l),0.);float sp=pow(max(dot(reflect(-l,n),-rd),0.),48.);
  vec3 env=pal(reflect(rd,n).y*.5+uT*.05+.2);
  col=mix(iri*(.32+.7*dif),env,.25+fr*.35)+sp*1.1;col=mix(col,col*1.08+.04,uLight);a=edge;col*=edge;}
 vec3 g=pal(uT*.05+uv.y*.6+uv.x*.2)*min(glow,6.)*.045;col+=g*(1.-a);a=max(a,clamp(glow*.03,0.,.55)*(1.-uLight*.4));
 gl_FragColor=vec4(col,a);}`;
    const sh = (type, src) => { const s = gl.createShader(type); gl.shaderSource(s, src); gl.compileShader(s); if (!gl.getShaderParameter(s, gl.COMPILE_STATUS)) throw new Error(gl.getShaderInfoLog(s)); return s; };
    let prog;
    try { prog = gl.createProgram(); gl.attachShader(prog, sh(gl.VERTEX_SHADER, vs)); gl.attachShader(prog, sh(gl.FRAGMENT_SHADER, fs)); gl.linkProgram(prog); gl.useProgram(prog); }
    catch (err) { console.warn('hero gl', err); root.classList.add('no-webgl'); return; }
    gl.bindBuffer(gl.ARRAY_BUFFER, gl.createBuffer());
    gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 3, -1, -1, 3]), gl.STATIC_DRAW);
    const loc = gl.getAttribLocation(prog, 'p'); gl.enableVertexAttribArray(loc); gl.vertexAttribPointer(loc, 2, gl.FLOAT, false, 0, 0);
    const U = Object.fromEntries(['uRes', 'uT', 'uM', 'uOff', 'uScroll', 'uLight', 'uZoom', 'uY'].map((n) => [n, gl.getUniformLocation(prog, n)]));
    const scale = mobile ? 0.55 : 0.7;
    const resize = () => {
        const w = canvas.clientWidth, h = canvas.clientHeight, dpr = Math.min(devicePixelRatio, 1.5) * scale;
        canvas.width = Math.max(1, Math.round(w * dpr)); canvas.height = Math.max(1, Math.round(h * dpr));
        gl.viewport(0, 0, canvas.width, canvas.height);
    };
    resize(); addEventListener('resize', resize);
    let m = { x: 0.3, y: 0.1 }, t0 = performance.now(), raf = 0, frames = 0, slowStart = performance.now();
    const frame = (now) => {
        raf = 0;
        if (!heroVisible || document.hidden) return;
        m.x = lerp(m.x, mouse.nx, 0.05); m.y = lerp(m.y, mouse.ny, 0.05);
        const off = innerWidth > 1100 ? 0.55 : innerWidth > 760 ? 0.3 : 0.2;
        gl.uniform1f(U.uY, innerWidth > 1100 ? 0.02 : innerWidth > 760 ? 0.12 : 0.42);
        gl.uniform1f(U.uZoom, innerWidth > 1100 ? 1.28 : innerWidth > 760 ? 1.45 : 2.1);
        gl.uniform2f(U.uRes, canvas.width, canvas.height);
        gl.uniform1f(U.uT, (now - t0) / 1000);
        gl.uniform2f(U.uM, m.x - off * 0.8, m.y);
        gl.uniform1f(U.uOff, off);
        gl.uniform1f(U.uScroll, clamp(scrollY / innerHeight, 0, 1.5));
        gl.uniform1f(U.uLight, root.dataset.theme === 'light' ? 1 : 0);
        gl.drawArrays(gl.TRIANGLES, 0, 3);
        // too slow on this device (< 24 fps over the first 2 s): fall back to the CSS version
        frames++;
        if (frames === 60 && now - slowStart > 2500 && location.hash !== '#gl') { root.classList.add('no-webgl'); canvas.remove(); return; }
        raf = requestAnimationFrame(frame);
    };
    const start = () => { if (!raf) raf = requestAnimationFrame(frame); };
    new IntersectionObserver(([en]) => { heroVisible = en.isIntersecting; if (heroVisible) start(); }).observe($('.hero'));
    document.addEventListener('visibilitychange', start);
    start();
})();

/* ---------------- Intro + scroll animations ---------------- */
async function intro() {
    if (!motion) { root.classList.add('ready'); return; }
    const num = $('[data-loader-num]'), bars = $$('.prism i'), n = { v: 0 };
    const tl0 = gsap.timeline();
    tl0.to(n, { v: 100, duration: 1.5, ease: 'power2.inOut', onUpdate: () => { num.textContent = Math.round(n.v); } }, 0)
        .to(bars, { '--p': 1, duration: 0.9, ease: 'expo.out', stagger: 0.07 }, 0.25);
    await Promise.all([document.fonts.ready, tl0.then()]);
    root.classList.add('ready');
    gsap.set('.hl-in', { yPercent: 115, rotate: 4 });
    gsap.set('.hero [data-in]', { opacity: 0, y: 30 });
    const tl = gsap.timeline({ defaults: { ease: 'expo.out' } });
    tl.to('.loader', { clipPath: 'inset(0 0 100% 0)', duration: 1.1, ease: 'expo.inOut' })
        .set('.loader', { display: 'none' })
        .to('.hl-in', { yPercent: 0, rotate: 0, duration: 1.5, stagger: 0.12 }, '-=0.45')
        .to('.hero [data-in]', { opacity: 1, y: 0, duration: 1.1, stagger: 0.08 }, '-=1.2')
        .from('.sticker', { scale: 0, rotate: () => gsap.utils.random(-50, 50), duration: 1.1, ease: 'back.out(1.8)', stagger: 0.07 }, '-=1.1')
        .from('.hero-gl', { opacity: 0, scale: 1.15, duration: 2, ease: 'power2.out' }, '-=1.8')
        .from('.nav', { yPercent: -150, duration: 1 }, '-=1.6');
}
async function animations() {
    if (!motion) return;
    $$('[data-split]').forEach((el) => {
        const split = SplitText.create(el, { type: 'lines', mask: 'lines' });
        gsap.from(split.lines, { yPercent: 115, rotate: 3, duration: 1.3, ease: 'expo.out', stagger: 0.1, scrollTrigger: { trigger: el, start: 'top 86%' } });
    });
    $$('[data-words]').forEach((el) => {
        const split = SplitText.create(el, { type: 'words' });
        gsap.fromTo(split.words, { opacity: 0.12, y: 10, filter: 'blur(4px)' }, { opacity: 1, y: 0, filter: 'blur(0px)', stagger: 0.1, ease: 'none', scrollTrigger: { trigger: el, start: 'top 82%', end: 'bottom 50%', scrub: true } });
    });
    $$('main [data-in]').forEach((el) => { if (!el.closest('.hero')) gsap.from(el, { opacity: 0, y: 40, duration: 1.1, ease: 'expo.out', scrollTrigger: { trigger: el, start: 'top 90%' } }); });
    // stats: 3D flip tiles
    gsap.fromTo('[data-flip]', { rotateX: -95, opacity: 0, transformPerspective: 900 }, { rotateX: 0, opacity: 1, duration: 1.4, ease: 'expo.out', stagger: 0.12, scrollTrigger: { trigger: '.stats', start: 'top 88%' } });
    ScrollTrigger.batch('[data-card], .aud-list.is-on li, .diplomas li', {
        start: 'top 92%',
        onEnter: (els) => gsap.fromTo(els, { opacity: 0, y: 70, rotateX: -18, transformPerspective: 1000 }, { opacity: 1, y: 0, rotateX: 0, duration: 1.2, ease: 'expo.out', stagger: 0.07, overwrite: 'auto', clearProps: 'transform' }),
    });
    $$('[data-count]').forEach((b) => {
        const n = { v: 0 }, to = +b.dataset.count;
        ScrollTrigger.create({ trigger: b, start: 'top 92%', once: true, onEnter: () => gsap.to(n, { v: to, duration: 2, ease: 'power3.out', onUpdate: () => { b.textContent = Math.round(n.v); } }) });
    });
    $$('[data-stagger]').forEach((ul) => gsap.from(ul.children, { opacity: 0, y: 20, scale: 0.85, duration: 0.8, ease: 'back.out(1.7)', stagger: 0.05, scrollTrigger: { trigger: ul, start: 'top 92%' } }));
    // hero leaves: title flies back in 3D
    gsap.to('.hero-title', { yPercent: 25, z: -200, rotateX: 18, opacity: 0.1, transformPerspective: 1000, ease: 'none', scrollTrigger: { trigger: '.hero', start: 'top top', end: 'bottom top', scrub: true } });
    // marquee: speed follows the scroll velocity
    const rows = $$('[data-velo]').map((row) => {
        const dir = +row.dataset.velo, tr = $('.marquee-track', row);
        return gsap.fromTo(tr, { xPercent: dir > 0 ? 0 : -33.333 }, { xPercent: dir > 0 ? -33.333 : 0, duration: 45, ease: 'none', repeat: -1 });
    });
    ScrollTrigger.create({ trigger: '.marquee', start: 'top bottom', end: 'bottom top', onUpdate: (s) => rows.forEach((tw) => gsap.to(tw, { timeScale: (1 + Math.min(6, Math.abs(s.getVelocity()) / 300)) * (s.direction || 1), duration: 0.3, overwrite: true })) });
    stack3d();
    scroll3d();
    journey();
    cube();
    // skill bars fill when visible
    $$('.eq').forEach((g) => gsap.fromTo($$('em', g), { '--p': 0 }, { '--p': 1, duration: 1.4, ease: 'expo.out', stagger: 0.05, scrollTrigger: { trigger: g, start: 'top 88%' } }));
    // footer columns rise in
    gsap.from('.ft-grid > *', { y: 40, opacity: 0, duration: 1, ease: 'expo.out', stagger: 0.08, scrollTrigger: { trigger: '.ft', start: 'top 90%' } });
    ScrollTrigger.refresh();
}
intro().then(animations).catch((e) => { console.error(e); root.classList.replace('motion', 'no-motion'); $('.loader')?.remove(); });

/* ---------------- Work: stacked cards that sink back in 3D ---------------- */
function stack3d() {
    const cards = $$('[data-stack-card]');
    cards.forEach((c, i) => {
        const next = cards[i + 1];
        if (!next) return;
        gsap.to(c, { scale: 0.88, rotateX: 14, y: -44, filter: 'brightness(.58) saturate(.8)', transformPerspective: 1400, ease: 'none',
            scrollTrigger: { trigger: next, start: 'top bottom', end: `top ${mobile ? 90 : 110}px`, scrub: true } });
    });
}

/* ---------------- Scroll 3D: Suite EduVerse cards fly in from depth, EduVerse title + app rig turn ---------------- */
function scroll3d() {
    // Suite EduVerse: each card comes from far away, tilted, and lands in place as you scroll
    $$('.holo').forEach((card, i) => {
        const col = i === 0 ? -1 : ((i + 1) % 4) - 1.5;
        gsap.fromTo(card, { '--sz': '-900px', '--srx': '62deg', '--sry': `${col * 22}deg`, '--sy': '160px', opacity: 0 },
            { '--sz': '0px', '--srx': '0deg', '--sry': '0deg', '--sy': '0px', opacity: 1, ease: 'power2.out',
              scrollTrigger: { trigger: card, start: 'top 110%', end: 'top 50%', scrub: 1 } });
    });
    // EduVerse: extruded title and app rig rotate with the scroll
    const ex = $('[data-ex3d]');
    if (ex) gsap.fromTo(ex, { '--ex': '38deg', '--ey': '-38deg' }, { '--ex': '6deg', '--ey': '14deg', ease: 'none', scrollTrigger: { trigger: '#eduverse', start: 'top bottom', end: 'bottom top', scrub: 1 } });
    const rig = $('[data-app-rig]');
    if (rig && wide) gsap.fromTo(rig, { '--sry': '-30deg', '--srx': '16deg' }, { '--sry': '14deg', '--srx': '-8deg', ease: 'none', scrollTrigger: { trigger: '#eduverse', start: 'top bottom', end: 'bottom top', scrub: 1 } });
    // Nabbü: the screenshots slide while the gallery is pinned
    const show = $('[data-nb-show]'), stage = $('[data-nb-stage]');
    if (show && stage?._setPos && wide) {
        const n = stage._count;
        stage.classList.add('is-scrub');
        const st = ScrollTrigger.create({ trigger: show, pin: true, start: 'center center', end: () => '+=' + innerHeight * 0.55 * Math.max(1, n - 1), scrub: 0.6,
            onUpdate: (self) => stage._setPos(self.progress * (n - 1)) });
        stage._goto = (i) => { const y = st.start + (st.end - st.start) * (i / Math.max(1, n - 1)); lenis ? lenis.scrollTo(y, { duration: 1.1 }) : scrollTo({ top: y, behavior: 'smooth' }); };
    }
}

/* ---------------- Pointer: cursor, magnetic, tilt, spotlight, holo, app parallax ---------------- */
if (finePointer && !reduced) {
    body.classList.add('has-cursor');
    const cursor = $('.cursor'), dot = $('.cursor-dot'), ring = $('.cursor-ring'), label = $('.cursor-label');
    let rx = -100, ry = -100;
    addEventListener('pointermove', (e) => { dot.style.setProperty('--x', `${e.clientX}px`); dot.style.setProperty('--y', `${e.clientY}px`); }, { passive: true });
    const loop = () => { rx += (mouse.x - rx) * 0.18; ry += (mouse.y - ry) * 0.18; ring.style.setProperty('--x', `${rx}px`); ring.style.setProperty('--y', `${ry}px`); requestAnimationFrame(loop); };
    loop();
    document.addEventListener('pointerover', (e) => {
        const l = e.target.closest('[data-cursor]'), a = e.target.closest('a, button, input, textarea, [data-drag], [data-star]');
        cursor.classList.toggle('is-label', !!l); cursor.classList.toggle('is-link', !l && !!a); label.textContent = l ? l.dataset.cursor : '';
    });
    $$('.magnetic').forEach((el) => {
        el.addEventListener('pointermove', (e) => { const r = el.getBoundingClientRect(); el.style.translate = `${(e.clientX - r.left - r.width / 2) * 0.25}px ${(e.clientY - r.top - r.height / 2) * 0.35}px`; });
        el.addEventListener('pointerleave', () => { el.style.translate = ''; });
    });
    $$('[data-tilt]').forEach((el) => {
        el.addEventListener('pointermove', (e) => {
            const r = el.getBoundingClientRect(), px = (e.clientX - r.left) / r.width - 0.5, py = (e.clientY - r.top) / r.height - 0.5;
            el.style.setProperty('--ry', `${px * 12}deg`); el.style.setProperty('--rx', `${py * -12}deg`);
            el.style.setProperty('--mx', `${e.clientX - r.left}px`); el.style.setProperty('--my', `${e.clientY - r.top}px`);
        });
        el.addEventListener('pointerleave', () => { el.style.removeProperty('--rx'); el.style.removeProperty('--ry'); });
    });
    $$('[data-stack-card]').forEach((card) => {
        const v = $('.sc-visual', card);
        card.addEventListener('pointermove', (e) => {
            const r = v.getBoundingClientRect(), px = (e.clientX - r.left) / r.width - 0.5, py = (e.clientY - r.top) / r.height - 0.5;
            v.style.setProperty('--vy', `${clamp(px, -0.6, 0.6) * 18}deg`); v.style.setProperty('--vx', `${clamp(py, -0.6, 0.6) * -14}deg`);
        });
        card.addEventListener('pointerleave', () => { v.style.removeProperty('--vy'); v.style.removeProperty('--vx'); });
    });
    $$('[data-holo]').forEach((el) => {
        el.addEventListener('pointermove', (e) => {
            const r = el.getBoundingClientRect(), px = (e.clientX - r.left) / r.width, py = (e.clientY - r.top) / r.height;
            el.style.setProperty('--ry', `${(px - 0.5) * 22}deg`); el.style.setProperty('--rx', `${(py - 0.5) * -22}deg`);
            el.style.setProperty('--hx', `${px * 100}%`); el.style.setProperty('--hy', `${py * 100}%`);
            el.style.setProperty('--gx', `${px * 100}%`); el.style.setProperty('--gy', `${py * 100}%`);
        });
        el.addEventListener('pointerleave', () => { ['--rx', '--ry'].forEach((p) => el.style.removeProperty(p)); });
    });
    const ex = $('[data-ex3d]'), eduSec = $('#eduverse');
    if (ex && eduSec) {
        eduSec.addEventListener('pointermove', (e) => { ex.style.setProperty('--mey', `${(e.clientX / innerWidth - 0.5) * 30}deg`); ex.style.setProperty('--mex', `${(0.5 - e.clientY / innerHeight) * 20}deg`); });
        eduSec.addEventListener('pointerleave', () => { ex.style.setProperty('--mey', '0deg'); ex.style.setProperty('--mex', '0deg'); });
    }
    const scene = $('[data-app-scene]'), app = $('[data-app-rig]', scene);
    if (scene && wide) {
        scene.addEventListener('pointermove', (e) => {
            const r = scene.getBoundingClientRect(), px = (e.clientX - r.left) / r.width - 0.5, py = (e.clientY - r.top) / r.height - 0.5;
            app.style.setProperty('--ry', `${-6 + px * 16}deg`); app.style.setProperty('--rx', `${4 - py * 12}deg`);
            $$('.float-chip', scene).forEach((c, i) => { const k = (i + 1) * 18; c.style.setProperty('--fx', `${px * k}px`); c.style.setProperty('--fy', `${py * k}px`); });
        });
        scene.addEventListener('pointerleave', () => { app.style.removeProperty('--ry'); app.style.removeProperty('--rx'); });
    }
}

/* ---------------- Services: companies / individuals switch ---------------- */
(() => {
    const box = $('[data-audience]');
    if (!box) return;
    $$('[data-aud]', box).forEach((b) => b.addEventListener('click', () => {
        const k = b.dataset.aud;
        box.dataset.on = k;
        $$('[data-aud]', box).forEach((x) => x.setAttribute('aria-selected', String(x === b)));
        $$('[data-aud-list]', box).forEach((l) => l.classList.toggle('is-on', l.dataset.audList === k));
        if (motion) gsap.fromTo($$(`[data-aud-list="${k}"] li`, box), { opacity: 0, y: 30, rotateY: -25, transformPerspective: 800 }, { opacity: 1, y: 0, rotateY: 0, duration: 0.9, ease: 'expo.out', stagger: 0.06 });
        track('audience', k);
    }));
})();

/* ---------------- Nabbü: 3D coverflow of real screenshots + lightbox (pinned & scroll-driven on desktop) ---------------- */
(() => {
    const stage = $('[data-nb-stage]');
    if (!stage) return;
    const shots = $$('[data-nb]', stage), dots = $$('[data-nb-dots] button'), n = shots.length;
    let pos = 0, timer = 0, drag = null, stageVisible = false;
    const cur = () => Math.round(pos);
    const layout = () => {
        const w = stage.clientWidth, c = cur();
        shots.forEach((el, i) => {
            const d = i - pos, a = Math.abs(d);
            el.classList.toggle('is-front', i === c);
            el.style.transform = `translateX(${d * Math.min(w * 0.36, 420)}px) translateZ(${-a * 260}px) rotateY(${clamp(-d * 32, -60, 60)}deg)`;
            el.style.opacity = a > 2.4 ? '0' : String(1 - a * 0.22);
            el.style.filter = a > 0.05 ? `brightness(${1 - Math.min(a, 2) * 0.25}) blur(${(Math.min(a, 2) * 0.6).toFixed(2)}px)` : '';
            el.style.zIndex = String(20 - Math.round(a * 2));
        });
        dots.forEach((d, i) => d.toggleAttribute('aria-current', i === c));
    };
    const scrubbing = () => stage.classList.contains('is-scrub');
    const go = (i, user = true) => {
        const target = (i + n) % n;
        if (scrubbing() && stage._goto) { stage._goto(target); return; }
        pos = target; layout(); if (user) { track('nabbu_shot', target + 1); restart(); }
    };
    const restart = () => { clearInterval(timer); if (!reduced && n > 1) timer = setInterval(() => { if (!document.hidden && stageVisible && !scrubbing()) go(cur() + 1, false); }, 4500); };
    stage._setPos = (p) => { pos = clamp(p, 0, n - 1); layout(); };
    stage._count = n;
    new IntersectionObserver(([en]) => { stageVisible = en.isIntersecting; }).observe(stage);
    $('[data-nb-prev]').addEventListener('click', () => go(cur() - 1));
    $('[data-nb-next]').addEventListener('click', () => go(cur() + 1));
    dots.forEach((d, i) => d.addEventListener('click', () => go(i)));
    stage.addEventListener('keydown', (e) => { if (e.key === 'ArrowLeft') go(cur() - 1); if (e.key === 'ArrowRight') go(cur() + 1); });
    stage.addEventListener('pointerdown', (e) => { drag = { x: e.clientX }; });
    addEventListener('pointerup', (e) => {
        if (!drag) return;
        const dx = e.clientX - drag.x; drag = null;
        if (Math.abs(dx) > 40) go(cur() + (dx < 0 ? 1 : -1));
    });
    const box = $('[data-nb-lightbox]');
    shots.forEach((el, i) => el.addEventListener('click', () => {
        if (i !== cur()) { go(i); return; }
        const img = $('img', el);
        if (!img || !box) return;
        $('img', box).src = img.src; $('img', box).alt = img.alt; $('p', box).textContent = $('figcaption', el).textContent;
        box.showModal(); lenis?.stop(); track('nabbu_zoom', i + 1);
    }));
    box?.addEventListener('close', () => lenis?.start());
    box?.addEventListener('click', (e) => { if (e.target === box) box.close(); });
    addEventListener('resize', layout);
    layout(); restart();
})();

/* ---------------- EduVerse: tabs, quiz, 3D lab, generator ---------------- */
const tabs = $$('.tab');
const showPanel = (i) => {
    tabs.forEach((t, j) => { t.classList.toggle('is-active', i === j); t.setAttribute('aria-selected', String(i === j)); });
    $$('[data-panel]').forEach((p) => p.classList.toggle('is-active', +p.dataset.panel === i));
    track('eduverse_tab', i);
    if (i === 1) startLab(); else lab?.stop();
};
tabs.forEach((t) => t.addEventListener('click', () => showPanel(+t.dataset.tab)));
let xp = 620;
const setXp = (v) => { xp = v; $('[data-xp-fill]').style.setProperty('--xp', `${Math.min(100, xp / 10)}%`); $('[data-xp-text]').textContent = `${Math.min(xp, 1000)} / 1000 XP`; };
$$('.quiz-opts button').forEach((b) => b.addEventListener('click', () => {
    const ok = b.dataset.ok === '1';
    b.classList.remove('ok', 'ko'); void b.offsetWidth; b.classList.add(ok ? 'ok' : 'ko');
    $('[data-quiz-fb]').textContent = ok ? T.quizOk : T.quizKo;
    if (ok) { setXp(xp + 50); if (xp >= 1000) toast(T.levelUp); }
    track('quiz', ok ? 'correct' : 'wrong');
}));
const lenInput = $('[data-len]');
const theory = () => {
    const L = +lenInput.value, g = +$('.gravity .is-on').dataset.g;
    $('[data-len-out]').textContent = L.toFixed(2);
    $('[data-t-theory]').textContent = (2 * Math.PI * Math.sqrt(L / g)).toFixed(2);
};
theory();
lenInput.addEventListener('input', () => { theory(); lab?.setLength(+lenInput.value); });
$$('.gravity button').forEach((b) => b.addEventListener('click', () => {
    $$('.gravity button').forEach((x) => x.classList.toggle('is-on', x === b));
    theory(); lab?.setGravity(+b.dataset.g); lab?.release();
    track('lab_gravity', b.dataset.g);
}));
let labLoading = null;
function startLab() {
    if (lab) { lab.start(); return; }
    if (labLoading || reduced) return;
    const ok = (() => { try { const c = document.createElement('canvas'); return !!(c.getContext('webgl2') || c.getContext('webgl')); } catch { return false; } })();
    if (!ok) { root.classList.add('no-webgl'); return; }
    labLoading = import(body.dataset.lab).then((m) => {
        lab = m.createLab($('[data-lab3d] canvas'), {
            dark: false,
            onPeriod: (t) => { $('[data-t-measured]').textContent = t ? t.toFixed(2) : '—'; },
        });
        lab.setLength(+lenInput.value);
        lab.setGravity(+$('.gravity .is-on').dataset.g);
        lab.setWire(root.classList.contains('code-mode'));
        lab.release();
        lab.start();
    }).catch((err) => { console.warn('3D lab disabled', err); root.classList.add('no-webgl'); });
}
new IntersectionObserver(([en]) => { if (!lab) return; if (en.isIntersecting && $('[data-panel="1"]').classList.contains('is-active')) lab.start(); else lab.stop(); }).observe($('[data-lab3d]'));
$('[data-flash]').addEventListener('click', (e) => { e.currentTarget.classList.toggle('is-flipped'); track('eduverse_flash'); });
const podcastBtn = $('[data-podcast]');
podcastBtn.addEventListener('click', () => {
    if (!('speechSynthesis' in window)) return;
    const label = $('span', podcastBtn);
    if (speechSynthesis.speaking) { speechSynthesis.cancel(); return; }
    const u = new SpeechSynthesisUtterance(T.podcastText);
    u.lang = { fr: 'fr-FR', en: 'en-GB', es: 'es-ES' }[lang];
    const orig = label.textContent;
    u.onend = u.onerror = () => { label.textContent = orig; };
    label.textContent = T.podcastStop;
    speechSynthesis.speak(u);
    track('eduverse_podcast', lang);
});

/* ---------------- Journey: the camera moves through time (3D) ---------------- */
function journey() {
    if (mobile) return;
    const pin = $('[data-journey]'), world = $('[data-world]'), cards = $$('[data-jcard]'), years = $$('[data-years] span');
    const D = 900, n = cards.length;
    const render = (p) => {
        const cam = p * (n - 1) * D;
        world.style.setProperty('--cam', `${cam}px`);
        pin.style.setProperty('--floor', `${cam * 0.5}px`);
        $('[data-years]').style.setProperty('--yp', `${p * 100}%`);
        cards.forEach((c, i) => {
            const d = i * D - cam;
            c.style.setProperty('--o', d < -250 ? '0' : String(clamp(1 - d / (D * 2.6), 0.05, 1)));
            c.style.pointerEvents = Math.abs(d) < D / 2 ? 'auto' : 'none';
        });
        const a = Math.round(p * (n - 1));
        years.forEach((y, i) => y.classList.toggle('is-on', i === a));
    };
    render(0);
    ScrollTrigger.create({ trigger: '.journey-pin', pin: true, start: 'top top', end: () => '+=' + innerHeight * (n - 1) * 0.6, scrub: 1, onUpdate: (s) => render(s.progress) });
}

/* ---------------- Method: a 3D cube turns from one step to the next ---------------- */
const cubeEl = $('[data-cube]'), mSteps = $$('[data-mstep]');
const setStep = (k) => mSteps.forEach((li, i) => li.classList.toggle('is-on', i === k));
setStep(0);
function cube() {
    if (!wide) return;
    ScrollTrigger.create({
        trigger: '.method-pin', pin: true, start: 'top top', end: () => '+=' + innerHeight * 2.4, scrub: 1,
        onUpdate: (s) => {
            const p = s.progress, raw = p * 3, k = Math.round(raw);
            // ease each quarter turn so the cube "snaps" softly on each face
            const eased = Math.floor(raw) + gsap.parseEase('power2.inOut')(raw - Math.floor(raw));
            cubeEl.style.setProperty('--cx', `${eased * 90}deg`);
            cubeEl.style.setProperty('--cy', `${-18 + Math.sin(p * Math.PI) * 26}deg`);
            setStep(clamp(k, 0, 3));
        },
    });
}
mSteps.forEach((li, i) => li.addEventListener('click', () => {
    if (wide && motion) return;
    cubeEl.style.transition = 'transform 1s cubic-bezier(.22,1,.36,1)';
    cubeEl.style.setProperty('--cx', `${i * 90}deg`); setStep(i);
}));
if (!wide || !motion) {
    let k = 0;
    setInterval(() => { if (document.hidden) return; k = (k + 1) % 4; cubeEl.style.transition = 'transform 1.2s cubic-bezier(.22,1,.36,1)'; cubeEl.style.setProperty('--cx', `${k * 90}deg`); setStep(k); }, 3200);
}

/* ---------------- Toolbox: 3D constellation, gauge, equalizer ---------------- */
(() => {
    const sphere = $('[data-sphere]'), stars = $$('[data-star]'), detail = $('[data-sk-detail]');
    if (!stars.length) return;
    const n = stars.length;
    const gColor = { dev: 'var(--s5)', design: 'var(--s1)', ai: 'var(--s7)' };
    const pts = stars.map((_, i) => {
        const y = 1 - (i / (n - 1)) * 2, r = Math.sqrt(1 - y * y), th = Math.PI * (3 - Math.sqrt(5)) * i;
        return [Math.cos(th) * r, y, Math.sin(th) * r];
    });
    let rotX = -0.25, rotY = 0, vx = 0, vy = 0.004, drag = null, visible = false, raf = 0, focus = null;
    const show = (el, bring = false) => {
        if (!el) return;
        stars.forEach((s) => s.classList.toggle('is-sel', s === el));
        const g = el.dataset.g, lv = +el.dataset.lv;
        detail.style.setProperty('--gc', gColor[g]);
        $('[data-gauge]').style.setProperty('--v', lv);
        $('[data-gauge-n]').textContent = lv;
        $('[data-sk-name]').textContent = el.textContent.trim();
        $('[data-sk-group]').textContent = T.groups?.[g] || g;
        $$('[data-eq-k]').forEach((li) => li.classList.toggle('is-on', li.dataset.eqK === el.dataset.k));
        if (bring) {
            const [x0, y0, z0] = pts[stars.indexOf(el)];
            let ty = Math.atan2(-x0, z0);
            while (ty - rotY > Math.PI) ty -= Math.PI * 2;
            while (ty - rotY < -Math.PI) ty += Math.PI * 2;
            const z1 = Math.hypot(x0, z0);
            focus = { ry: ty, rx: Math.atan2(y0, z1), t: performance.now() };
            vx = vy = 0;
            if (!raf) raf = requestAnimationFrame(loop);
        }
    };
    const render = () => {
        const R = sphere.clientWidth * 0.39;
        const cx = Math.cos(rotX), sx = Math.sin(rotX), cy = Math.cos(rotY), sy = Math.sin(rotY);
        stars.forEach((s, i) => {
            const [x0, y0, z0] = pts[i];
            const x1 = x0 * cy + z0 * sy, z1 = -x0 * sy + z0 * cy;
            const y2 = y0 * cx - z1 * sx, z2 = y0 * sx + z1 * cx;
            const scale = 0.5 + (z2 + 1) * 0.38;
            s.style.transform = `translate3d(${(x1 * R).toFixed(1)}px, ${(y2 * R).toFixed(1)}px, 0) scale(${scale.toFixed(3)})`;
            s.style.zIndex = String(Math.round((z2 + 1) * 100));
            s.style.filter = z2 < -0.2 ? `blur(${((-z2 - 0.2) * 2.2).toFixed(1)}px)` : '';
            if (!s.classList.contains('is-dim')) s.style.opacity = String((0.25 + (z2 + 1) * 0.375).toFixed(2));
        });
    };
    const loop = () => {
        if (focus) {
            rotY = lerp(rotY, focus.ry, 0.08); rotX = lerp(rotX, focus.rx, 0.08);
            if (Math.abs(rotY - focus.ry) + Math.abs(rotX - focus.rx) < 0.002 || performance.now() - focus.t > 2500) { focus = null; vy = 0.0005; }
        } else if (!drag) { rotY += vy; rotX += vx; vy += (0.003 - vy) * 0.02; vx *= 0.95; rotX = clamp(rotX, -1.3, 1.3); }
        render();
        raf = visible && !reduced ? requestAnimationFrame(loop) : 0;
    };
    new IntersectionObserver(([en]) => { visible = en.isIntersecting; if (visible && !raf) raf = requestAnimationFrame(loop); }).observe(sphere);
    render();
    sphere.addEventListener('pointerdown', (e) => { if (e.target.closest('[data-star]')) return; focus = null; drag = { x: e.clientX, y: e.clientY, rx: rotX, ry: rotY, lx: e.clientX, ly: e.clientY }; sphere.setPointerCapture(e.pointerId); });
    sphere.addEventListener('pointermove', (e) => {
        if (!drag) return;
        rotY = drag.ry + (e.clientX - drag.x) * 0.008;
        rotX = clamp(drag.rx - (e.clientY - drag.y) * 0.008, -1.3, 1.3);
        vy = (e.clientX - drag.lx) * 0.002; vx = -(e.clientY - drag.ly) * 0.002;
        drag.lx = e.clientX; drag.ly = e.clientY;
    });
    sphere.addEventListener('pointerup', () => { if (drag && Math.abs(vx) + Math.abs(vy) > 0) track('skills_spin'); drag = null; });
    stars.forEach((s) => {
        s.addEventListener('mouseenter', () => show(s));
        s.addEventListener('focus', () => show(s));
        s.addEventListener('click', () => { show(s, true); track('skill', s.textContent.slice(0, 40)); });
    });
    $$('[data-eq-k]').forEach((li) => li.addEventListener('click', () => { const s = stars.find((x) => x.dataset.k === li.dataset.eqK); if (s) { show(s, true); goTo(sphere); } }));
    $$('[data-filter]').forEach((b) => b.addEventListener('click', () => {
        $$('[data-filter]').forEach((x) => x.classList.toggle('is-on', x === b));
        const f = b.dataset.filter;
        stars.forEach((s) => s.classList.toggle('is-dim', f !== 'all' && s.dataset.g !== f));
        $$('[data-eq]').forEach((g) => { g.style.opacity = f === 'all' || g.dataset.eq === f ? '1' : '.35'; });
        vy += 0.05;
        const best = stars.filter((s) => f === 'all' || s.dataset.g === f).reduce((a, x) => (+x.dataset.lv > +a.dataset.lv ? x : a));
        show(best, true);
        track('skills_filter', f);
    }));
    show(stars.find((s) => s.classList.contains('is-highlight')) || stars.reduce((a, b) => (+b.dataset.lv > +a.dataset.lv ? b : a)));
})();

/* ---------------- Language select (nav) ---------------- */
(() => {
    const box = $('[data-lang-select]');
    if (!box) return;
    const btn = $('.ls-btn', box), items = $$('.ls-menu a', box);
    const set = (open) => { box.classList.toggle('is-open', open); btn.setAttribute('aria-expanded', String(open)); };
    btn.addEventListener('click', (e) => { e.stopPropagation(); const open = !box.classList.contains('is-open'); set(open); if (open) (items.find((a) => !a.hasAttribute('aria-current')) || items[0]).focus(); });
    document.addEventListener('click', (e) => { if (!box.contains(e.target)) set(false); });
    box.addEventListener('keydown', (e) => {
        const i = items.indexOf(document.activeElement);
        if (e.key === 'Escape') { set(false); btn.focus(); }
        else if (e.key === 'ArrowDown') { e.preventDefault(); if (!box.classList.contains('is-open')) set(true); items[(i + 1) % items.length].focus(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); items[(i - 1 + items.length) % items.length].focus(); }
    });
})();

/* ---------------- Footer: the dot matrix lights up in the spectrum around the cursor ---------------- */
(() => {
    const ft = $('[data-ft]');
    if (!ft || !finePointer) return;
    ft.addEventListener('pointermove', (e) => { const r = ft.getBoundingClientRect(); ft.style.setProperty('--fx', `${e.clientX - r.left}px`); ft.style.setProperty('--fy', `${e.clientY - r.top}px`); });
    ft.addEventListener('pointerleave', () => { ft.style.setProperty('--fy', '120%'); });
})();

/* ---------------- "Ask me" assistant: answers with links, action buttons and follow-up questions ---------------- */
const log = $('[data-chat-log]'), askForm = $('[data-ask]');
const history = [];
const linkify = (el, text) => {
    el.textContent = '';
    text.split(/(https?:\/\/[^\s)]+|[\w.+-]+@[\w-]+\.[\w.]+)/g).forEach((part, i) => {
        if (i % 2 === 0) { if (part) el.append(document.createTextNode(part)); return; }
        const a = document.createElement('a');
        const isMail = part.includes('@') && !part.startsWith('http');
        a.href = isMail ? `mailto:${part}` : part;
        if (!isMail) { a.target = '_blank'; a.rel = 'noopener'; }
        a.textContent = part.replace(/^https?:\/\//, '');
        el.append(a);
    });
};
const addMsg = (text, who) => { const m = document.createElement('div'); m.className = `msg ${who}`; m.textContent = text; log.append(m); log.scrollTo({ top: log.scrollHeight, behavior: 'smooth' }); return m; };
const typeOut = (el, text) => new Promise((resolve) => {
    if (reduced) { linkify(el, text); return resolve(); }
    let i = 0;
    const step = () => { i = Math.min(text.length, i + 4); el.textContent = text.slice(0, i); log.scrollTop = log.scrollHeight; if (i < text.length) setTimeout(step, 10); else { linkify(el, text); resolve(); } };
    step();
});
const runAction = ([label, type, value]) => {
    if (type === 'url') window.open(value, '_blank', 'noopener');
    else if (type === 'copy') { const done = () => toast(`✓ ${value}`); navigator.clipboard ? navigator.clipboard.writeText(value).then(done).catch(() => toast(value)) : toast(value); }
    else if (type === 'mail') location.href = `mailto:${value}`;
    else if (type === 'goto') goTo(value);
    track('assistant_action', `${type}:${label}`.slice(0, 120));
};
const addExtras = (actions = [], suggestions = []) => {
    if (!actions.length && !suggestions.length) return;
    const box = document.createElement('div');
    box.className = 'msg-extras';
    actions.forEach((a) => {
        const b = Object.assign(document.createElement('button'), { type: 'button', className: `msg-act t-${a[1]}`, textContent: { url: '↗ ', copy: '⧉ ', mail: '✉ ', goto: '→ ' }[a[1]] + a[0] });
        b.addEventListener('click', () => runAction(a));
        box.append(b);
    });
    suggestions.forEach((q) => {
        const b = Object.assign(document.createElement('button'), { type: 'button', className: 'msg-sug', textContent: q });
        b.addEventListener('click', () => { box.querySelectorAll('.msg-sug').forEach((x) => x.remove()); ask(q); });
        box.append(b);
    });
    log.append(box);
    log.scrollTo({ top: log.scrollHeight, behavior: 'smooth' });
};
const ask = async (q) => {
    q = q.trim();
    if (!q) return;
    addMsg(q, 'me');
    const typing = addMsg('', 'ai typing');
    typing.innerHTML = '<i></i><i></i><i></i>';
    typing.setAttribute('aria-label', T.thinking);
    const btn = $('button', askForm);
    btn.disabled = true;
    try {
        const r = await api('ask', { q, history: history.slice(-8) });
        typing.remove();
        await typeOut(addMsg('', `ai src-${r.source || 'offline'}`), r.answer);
        addExtras(r.actions, r.suggestions);
        history.push({ role: 'user', text: q }, { role: 'assistant', text: r.answer });
    } catch (err) { typing.remove(); addMsg(err.status === 429 ? T.ask_limit : T.ask_error, 'ai'); }
    finally { btn.disabled = false; }
};
askForm.addEventListener('submit', (e) => { e.preventDefault(); const input = $('input', askForm); ask(input.value); input.value = ''; });
$$('[data-suggest]').forEach((b) => b.addEventListener('click', () => { ask(b.textContent); b.remove(); }));

/* ---------------- Contact form ---------------- */
const form = $('[data-contact]');
form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const status = $('[data-form-status]');
    status.className = 'form-status';
    const data = Object.fromEntries(new FormData(form));
    if (!data.name?.trim() || !data.message?.trim() || !/^\S+@\S+\.\S+$/.test(data.email || '')) { status.textContent = T.invalid; status.classList.add('err'); return; }
    status.textContent = T.sending;
    const btn = $('button[type="submit"]', form);
    btn.disabled = true;
    try { await api('contact', data); status.textContent = T.sent; form.reset(); }
    catch { status.textContent = T.error; status.classList.add('err'); }
    finally { btn.disabled = false; }
});

/* ---------------- Statistics (no cookies) ---------------- */
const params = new URLSearchParams(location.search);
let maxScroll = 0, left = false;
const started = Date.now();
addEventListener('scroll', () => { maxScroll = Math.max(maxScroll, Math.min(100, Math.round((scrollY + innerHeight) / document.documentElement.scrollHeight * 100))); }, { passive: true });
api('track', { type: 'pageview', path: location.pathname, referrer: document.referrer && !document.referrer.startsWith(location.origin) ? document.referrer : '', utm: params.get('utm_source') || params.get('ref') || '', tz: Intl.DateTimeFormat().resolvedOptions().timeZone || '' }).catch(() => {});
const leave = () => { if (left) return; left = true; beacon({ type: 'leave', seconds: Math.round((Date.now() - started) / 1000), scroll: maxScroll }); };
addEventListener('pagehide', leave);
document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') leave(); });
document.addEventListener('click', (e) => { const a = e.target.closest('[data-track]'); if (a) track(a.dataset.track, a.dataset.value || ''); });
$('[data-privacy]').addEventListener('click', () => $('[data-privacy-dialog]').showModal());
console.log('%c Mathieu Moreau %c Spectrum · Ctrl + K → ', 'background:linear-gradient(90deg,#FF3D6E,#FFD21F,#34E089,#1EC8FF,#B65CFF);color:#111;font-weight:700;padding:4px 8px;border-radius:4px 0 0 4px', 'background:#111;color:#F4F1EA;padding:4px 8px;border-radius:0 4px 4px 0');
