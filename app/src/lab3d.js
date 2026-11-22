// EduVerse 3D lab: a real simple pendulum (three.js).
// Physics: θ'' = -(g/L)·sin θ (RK4 integration), so the period depends on the length and on the planet's gravity.
// The visitor can change L and g, orbit the camera by dragging, and double-click to release the bob again.
// Bundled with esbuild into assets/js/lab3d.js.
import {
    WebGLRenderer, Scene, PerspectiveCamera, Mesh, Group, Color, Vector3, BufferGeometry, Line, LineBasicMaterial, Float32BufferAttribute,
    SphereGeometry, CylinderGeometry, BoxGeometry, PlaneGeometry, MeshStandardMaterial, MeshPhysicalMaterial, ShadowMaterial,
    DirectionalLight, HemisphereLight, PCFSoftShadowMap, ACESFilmicToneMapping, SRGBColorSpace, GridHelper, RingGeometry, MeshBasicMaterial, DoubleSide,
} from 'three';

export function createLab(canvas, { onPeriod, dark = false } = {}) {
    const renderer = new WebGLRenderer({ canvas, antialias: true, alpha: true });
    renderer.setPixelRatio(Math.min(devicePixelRatio, 2));
    renderer.shadowMap.enabled = true;
    renderer.shadowMap.type = PCFSoftShadowMap;
    renderer.toneMapping = ACESFilmicToneMapping;
    renderer.outputColorSpace = SRGBColorSpace;

    const scene = new Scene();
    const camera = new PerspectiveCamera(38, 1, 0.1, 50);
    const hemi = new HemisphereLight(0xffffff, 0x8888aa, 1.4);
    const sun = new DirectionalLight(0xffffff, 2.2);
    sun.position.set(2.5, 5, 3);
    sun.castShadow = true;
    sun.shadow.mapSize.set(1024, 1024);
    sun.shadow.camera.left = -3; sun.shadow.camera.right = 3; sun.shadow.camera.top = 3; sun.shadow.camera.bottom = -3;
    sun.shadow.radius = 6;
    scene.add(hemi, sun);

    // Stand
    const metal = new MeshStandardMaterial({ color: 0x2a2a33, metalness: 0.6, roughness: 0.35 });
    const wood = new MeshStandardMaterial({ color: 0xd9cfbd, roughness: 0.8 });
    const base = new Mesh(new BoxGeometry(2.2, 0.12, 1.1), wood);
    base.position.y = 0.06;
    base.castShadow = base.receiveShadow = true;
    const postL = new Mesh(new CylinderGeometry(0.04, 0.04, 3, 16), metal);
    postL.position.set(-0.95, 1.5, 0);
    const postR = postL.clone();
    postR.position.x = 0.95;
    const beam = new Mesh(new CylinderGeometry(0.04, 0.04, 1.98, 16), metal);
    beam.rotation.z = Math.PI / 2;
    beam.position.y = 3;
    [postL, postR, beam].forEach((m) => { m.castShadow = true; });
    const floor = new Mesh(new PlaneGeometry(12, 12), new ShadowMaterial({ opacity: dark ? 0.35 : 0.18 }));
    floor.rotation.x = -Math.PI / 2;
    floor.receiveShadow = true;
    const grid = new GridHelper(8, 16, 0x7b5cff, 0x7b5cff);
    grid.material.transparent = true;
    grid.material.opacity = 0.12;
    // Protractor arc (shows the amplitude)
    const arc = new Mesh(new RingGeometry(0.42, 0.44, 64, 1, Math.PI * 1.25, Math.PI * 0.5), new MeshBasicMaterial({ color: 0x7b5cff, side: DoubleSide, transparent: true, opacity: 0.6 }));
    arc.position.set(0, 3, 0.02);
    scene.add(base, postL, postR, beam, floor, grid, arc);

    // Pendulum: pivot group rotates around z
    const pivot = new Group();
    pivot.position.set(0, 3, 0);
    scene.add(pivot);
    const stringMat = new MeshStandardMaterial({ color: 0x101014, roughness: 0.5 });
    const string = new Mesh(new CylinderGeometry(0.008, 0.008, 1, 8), stringMat);
    const bobMat = new MeshPhysicalMaterial({ color: 0xc8ff3d, roughness: 0.15, clearcoat: 1, clearcoatRoughness: 0.1 });
    const bob = new Mesh(new SphereGeometry(0.16, 48, 48), bobMat);
    string.castShadow = bob.castShadow = true;
    pivot.add(string, bob);

    // Trail of the bob (last positions)
    const TRAIL = 90;
    const trailPos = new Float32Array(TRAIL * 3);
    const trailGeo = new BufferGeometry();
    trailGeo.setAttribute('position', new Float32BufferAttribute(trailPos, 3));
    const trail = new Line(trailGeo, new LineBasicMaterial({ color: 0x7b5cff, transparent: true, opacity: 0.7 }));
    scene.add(trail);
    let trailCount = 0;

    // State
    const st = { L: 1.2, g: 9.81, theta: 0.5, omega: 0, amp: 0.5 };
    let simTime = 0, lastCross = null, crossings = [], prevTheta = st.theta;
    const setLength = (L) => {
        st.L = L;
        string.scale.y = L;
        string.position.y = -L / 2;
        bob.position.y = -L;
        lastCross = null; crossings = [];
    };
    setLength(st.L);

    // RK4 integration of θ'' = -(g/L) sin θ
    const acc = (th) => -(st.g / st.L) * Math.sin(th);
    const step = (dt) => {
        const { theta: t0, omega: w0 } = st;
        const k1t = w0, k1w = acc(t0);
        const k2t = w0 + k1w * dt / 2, k2w = acc(t0 + k1t * dt / 2);
        const k3t = w0 + k2w * dt / 2, k3w = acc(t0 + k2t * dt / 2);
        const k4t = w0 + k3w * dt, k4w = acc(t0 + k3t * dt);
        st.theta += (dt / 6) * (k1t + 2 * k2t + 2 * k3t + k4t);
        st.omega += (dt / 6) * (k1w + 2 * k2w + 2 * k3w + k4w);
    };

    // Measured period: time between two crossings of θ = 0 in the same direction
    const measure = () => {
        if (prevTheta < 0 && st.theta >= 0) {
            if (lastCross !== null) {
                crossings.push(simTime - lastCross);
                if (crossings.length > 3) crossings.shift();
                const avg = crossings.reduce((a, b) => a + b, 0) / crossings.length;
                onPeriod?.(avg);
            }
            lastCross = simTime;
        }
        prevTheta = st.theta;
    };

    // Camera orbit by drag
    const orbit = { yaw: 0.5, pitch: 0.12, tYaw: 0.5, tPitch: 0.12, dist: 4.4 };
    let drag = null;
    canvas.addEventListener('pointerdown', (e) => { drag = { x: e.clientX, y: e.clientY, yaw: orbit.tYaw, pitch: orbit.tPitch }; canvas.setPointerCapture(e.pointerId); });
    canvas.addEventListener('pointermove', (e) => {
        if (!drag) return;
        orbit.tYaw = drag.yaw - (e.clientX - drag.x) * 0.008;
        orbit.tPitch = Math.max(-0.1, Math.min(0.9, drag.pitch + (e.clientY - drag.y) * 0.005));
    });
    canvas.addEventListener('pointerup', () => { drag = null; });
    canvas.addEventListener('dblclick', () => release());

    const release = (amp = st.amp) => {
        st.theta = amp; st.omega = 0; prevTheta = amp; lastCross = null; crossings = []; trailCount = 0;
        onPeriod?.(null);
    };

    const resize = () => {
        const w = canvas.clientWidth, h = canvas.clientHeight;
        if (!w || !h) return;
        renderer.setSize(w, h, false);
        camera.aspect = w / h;
        camera.updateProjectionMatrix();
    };
    new ResizeObserver(resize).observe(canvas);
    resize();

    let running = false, last = 0, raf = 0;
    const target = new Vector3(0, 1.75, 0);
    const tick = (now) => {
        if (!running) return;
        const dt = Math.min(0.05, (now - last) / 1000 || 0.016);
        last = now;
        // physics in small sub-steps for accuracy
        const sub = 8;
        for (let i = 0; i < sub; i++) { step(dt / sub); simTime += dt / sub; measure(); }
        pivot.rotation.z = st.theta;
        // trail
        const p = new Vector3(0, -st.L, 0).applyAxisAngle(new Vector3(0, 0, 1), st.theta).add(pivot.position);
        trailPos.copyWithin(3, 0, (TRAIL - 1) * 3);
        trailPos[0] = p.x; trailPos[1] = p.y; trailPos[2] = p.z;
        trailCount = Math.min(TRAIL, trailCount + 1);
        trailGeo.setDrawRange(0, trailCount);
        trailGeo.attributes.position.needsUpdate = true;
        // camera
        orbit.yaw += (orbit.tYaw - orbit.yaw) * 0.1;
        orbit.pitch += (orbit.tPitch - orbit.pitch) * 0.1;
        if (!drag) orbit.tYaw += 0.0008;
        camera.position.set(Math.sin(orbit.yaw) * orbit.dist * Math.cos(orbit.pitch), 1.75 + Math.sin(orbit.pitch) * orbit.dist, Math.cos(orbit.yaw) * orbit.dist * Math.cos(orbit.pitch));
        camera.lookAt(target);
        renderer.render(scene, camera);
        raf = requestAnimationFrame(tick);
    };

    return {
        start() { if (running) return; running = true; last = performance.now(); raf = requestAnimationFrame(tick); },
        stop() { running = false; cancelAnimationFrame(raf); },
        setLength(L) { setLength(L); },
        setGravity(g) { st.g = g; lastCross = null; crossings = []; onPeriod?.(null); },
        release,
        setWire(on) { [metal, wood, bobMat, stringMat].forEach((m) => { m.wireframe = on; }); grid.material.opacity = on ? 0.35 : 0.12; },
        setDark(on) { floor.material.opacity = on ? 0.35 : 0.18; stringMat.color = new Color(on ? 0xf2eee5 : 0x101014); },
    };
}
