/* context3d.js — newspace.live shared 3D context view (Phase 3)
   Self-contained canvas renderer (no external 3D lib). Used by ssa_visor.html
   and neo_watch.html as LINKED CONTEXT opened from an incident (never home).
   Exposes window.Context3D. Pure geometry helpers are exported for testing.
   Orthographic projection + painter's z-sort. Drag to rotate, wheel to zoom. */
(function (global) {
  "use strict";
  var doc = global.document;

  /* ---- pure geometry (testable) ---- */
  function rot(p, yaw, pitch) {
    var cy = Math.cos(yaw), sy = Math.sin(yaw), cx = Math.cos(pitch), sx = Math.sin(pitch);
    var x = p[0], y = p[1], z = p[2];
    var x1 = cy * x + sy * z, z1 = -sy * x + cy * z, y1 = y;       // yaw about Y
    var y2 = cx * y1 - sx * z1, z2 = sx * y1 + cx * z1;            // pitch about X
    return [x1, y2, z2];
  }
  function sphereWire(rKm, nLat, nLon) {
    var lines = [], i, j, seg;
    for (i = 1; i < nLat; i++) {
      var lat = (-Math.PI / 2) + Math.PI * i / nLat, rr = rKm * Math.cos(lat), yy = rKm * Math.sin(lat);
      seg = []; for (j = 0; j <= 48; j++) { var lo = 2 * Math.PI * j / 48; seg.push([rr * Math.cos(lo), yy, rr * Math.sin(lo)]); }
      lines.push(seg);
    }
    for (i = 0; i < nLon; i++) {
      var lon0 = Math.PI * i / nLon; seg = [];
      for (j = 0; j <= 48; j++) { var la = -Math.PI / 2 + Math.PI * j / 48, r2 = rKm * Math.cos(la); seg.push([r2 * Math.cos(lon0), rKm * Math.sin(la), r2 * Math.sin(lon0)]); }
      lines.push(seg);
    }
    return lines;
  }
  function ring(rKm, n) { var pl = [], i; n = n || 96; for (i = 0; i <= n; i++) { var a = 2 * Math.PI * i / n; pl.push([rKm * Math.cos(a), 0, rKm * Math.sin(a)]); } return pl; }
  function maxRadius(spec) {
    var m = spec.earthRadiusKm || 6371, scan = function (p) { var d = Math.sqrt(p[0] * p[0] + p[1] * p[1] + p[2] * p[2]); if (d > m) m = d; };
    (spec.orbits || []).forEach(function (o) { (o.points || []).forEach(scan); });
    (spec.markers || []).forEach(function (mk) { scan(mk.pos); });
    (spec.rings || []).forEach(function (r) { if (r.radiusKm > m) m = r.radiusKm; });
    (spec.vectors || []).forEach(function (v) { scan(v.from); scan(v.to); });
    return m;
  }

  /* ---- DOM / rendering ---- */
  var ov, cv, cx, titleEl, subEl, legEl, noteEl, raf = null;
  var S = { spec: null, yaw: 0.6, pitch: -0.5, scale: 1, baseScale: 1, drag: false, px: 0, py: 0 };

  function cssVar(name, fb) { try { var v = getComputedStyle(doc.documentElement).getPropertyValue(name).trim(); return v || fb; } catch (e) { return fb; } }

  function build() {
    if (ov) return;
    ov = doc.createElement("div");
    ov.id = "ctx3d-overlay";
    ov.style.cssText = "position:fixed;inset:0;z-index:9999;display:none;background:rgba(3,5,12,.92);backdrop-filter:blur(2px)";
    ov.innerHTML =
      '<div style="position:absolute;top:0;left:0;right:0;display:flex;align-items:center;gap:10px;padding:12px 16px;font-family:var(--display,system-ui)">' +
        '<div style="flex:1;min-width:0"><div id="ctx3d-title" style="font-weight:700;font-size:15px;color:var(--text,#eaeefb)"></div>' +
        '<div id="ctx3d-sub" style="font-size:11px;color:var(--text-dim,#93a0c2);font-family:var(--mono,monospace)"></div></div>' +
        '<button id="ctx3d-close" style="background:var(--panel-2,#111a30);color:var(--text,#eaeefb);border:1px solid var(--line,#243353);border-radius:7px;width:30px;height:30px;cursor:pointer;font-size:16px">&times;</button>' +
      '</div>' +
      '<canvas id="ctx3d-canvas" style="position:absolute;inset:0;width:100%;height:100%;touch-action:none;cursor:grab"></canvas>' +
      '<div id="ctx3d-legend" style="position:absolute;left:16px;bottom:42px;display:flex;flex-wrap:wrap;gap:6px 14px;font-size:11px;font-family:var(--mono,monospace);color:var(--text-dim,#93a0c2);max-width:70%"></div>' +
      '<div id="ctx3d-note" style="position:absolute;left:16px;right:16px;bottom:12px;font-size:10px;color:var(--text-faint,#6b769a);font-family:var(--mono,monospace)"></div>';
    doc.body.appendChild(ov);
    cv = ov.querySelector("#ctx3d-canvas"); cx = cv.getContext("2d");
    titleEl = ov.querySelector("#ctx3d-title"); subEl = ov.querySelector("#ctx3d-sub");
    legEl = ov.querySelector("#ctx3d-legend"); noteEl = ov.querySelector("#ctx3d-note");
    ov.querySelector("#ctx3d-close").addEventListener("click", close);
    cv.addEventListener("pointerdown", function (e) { S.drag = true; S.px = e.clientX; S.py = e.clientY; cv.style.cursor = "grabbing"; });
    global.addEventListener("pointerup", function () { S.drag = false; if (cv) cv.style.cursor = "grab"; });
    global.addEventListener("pointermove", function (e) {
      if (!S.drag) return; S.yaw += (e.clientX - S.px) * 0.01; S.pitch += (e.clientY - S.py) * 0.01;
      S.pitch = Math.max(-1.5, Math.min(1.5, S.pitch)); S.px = e.clientX; S.py = e.clientY; draw();
    });
    cv.addEventListener("wheel", function (e) { e.preventDefault(); S.scale *= e.deltaY < 0 ? 1.1 : 0.9; S.scale = Math.max(0.2, Math.min(8, S.scale)); draw(); }, { passive: false });
    global.addEventListener("resize", function () { if (ov && ov.style.display !== "none") { resize(); draw(); } });
    doc.addEventListener("keydown", function (e) { if (e.key === "Escape" && ov && ov.style.display !== "none") close(); });
  }

  function resize() {
    var dpr = global.devicePixelRatio || 1;
    cv.width = ov.clientWidth * dpr; cv.height = ov.clientHeight * dpr;
    cx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }

  function project(p) {
    var r = rot(p, S.yaw, S.pitch);
    return { x: ov.clientWidth / 2 + r[0] * S.scale * S.baseScale, y: ov.clientHeight / 2 - r[1] * S.scale * S.baseScale, z: r[2] };
  }

  function draw() {
    if (!S.spec) return;
    var w = ov.clientWidth, h = ov.clientHeight;
    cx.clearRect(0, 0, w, h);
    var items = []; // {z, render}
    var lineCol = cssVar("--line", "#243353"), faint = cssVar("--text-faint", "#6b769a");
    // Earth wireframe
    sphereWire(S.spec.earthRadiusKm || 6371, 6, 6).forEach(function (seg) {
      var pts = seg.map(project), zavg = pts.reduce(function (s, p) { return s + p.z; }, 0) / pts.length;
      items.push({ z: zavg - 1e9, render: function () { cx.strokeStyle = lineCol; cx.globalAlpha = .5; cx.lineWidth = 1; poly(pts); cx.globalAlpha = 1; } });
    });
    // rings (e.g., Moon distance)
    (S.spec.rings || []).forEach(function (r) {
      var pts = ring(r.radiusKm).map(project), zavg = pts.reduce(function (s, p) { return s + p.z; }, 0) / pts.length;
      items.push({ z: zavg, render: function () { cx.strokeStyle = r.color || faint; cx.globalAlpha = .8; cx.setLineDash([4, 5]); cx.lineWidth = 1; poly(pts); cx.setLineDash([]); cx.globalAlpha = 1; if (r.label) { var L = pts[Math.floor(pts.length * .12)]; label(L.x, L.y, r.label, r.color || faint); } } });
    });
    // orbits
    (S.spec.orbits || []).forEach(function (o) {
      var pts = (o.points || []).map(project); if (pts.length < 2) return;
      var zavg = pts.reduce(function (s, p) { return s + p.z; }, 0) / pts.length;
      items.push({ z: zavg, render: function () { cx.strokeStyle = o.color || "#6f8fd6"; cx.globalAlpha = .95; cx.lineWidth = 1.6; poly(pts); cx.globalAlpha = 1; } });
    });
    // vectors
    (S.spec.vectors || []).forEach(function (v) {
      var a = project(v.from), b = project(v.to);
      items.push({ z: Math.max(a.z, b.z) + 10, render: function () { cx.strokeStyle = v.color || "#e0653f"; cx.lineWidth = 1.6; cx.setLineDash([2, 3]); cx.beginPath(); cx.moveTo(a.x, a.y); cx.lineTo(b.x, b.y); cx.stroke(); cx.setLineDash([]); if (v.label) label((a.x + b.x) / 2, (a.y + b.y) / 2, v.label, v.color || "#e0653f"); } });
    });
    // markers
    (S.spec.markers || []).forEach(function (mk) {
      var p = project(mk.pos), sz = mk.size || 5;
      items.push({ z: p.z + 1e8, render: function () { cx.fillStyle = mk.color || "#fff"; cx.beginPath(); cx.arc(p.x, p.y, sz, 0, 2 * Math.PI); cx.fill(); if (mk.label) label(p.x + sz + 3, p.y, mk.label, mk.color || cssVar("--text", "#eaeefb")); } });
    });
    items.sort(function (a, b) { return a.z - b.z; });
    items.forEach(function (it) { it.render(); });
  }
  function poly(pts) { cx.beginPath(); pts.forEach(function (p, i) { i ? cx.lineTo(p.x, p.y) : cx.moveTo(p.x, p.y); }); cx.stroke(); }
  function label(x, y, txt, col) { cx.font = "11px var(--mono,monospace)"; cx.fillStyle = col; cx.textBaseline = "middle"; cx.fillText(txt, x, y); }

  function open(spec) {
    build(); S.spec = spec; S.yaw = 0.6; S.pitch = -0.5; S.scale = 1;
    var w = global.innerWidth, h = global.innerHeight, minDim = Math.min(w, h);
    S.baseScale = (0.4 * minDim) / maxRadius(spec);
    titleEl.textContent = spec.title || "3D context";
    subEl.textContent = spec.subtitle || "";
    legEl.innerHTML = (spec.legend || []).map(function (l) { return '<span><span style="display:inline-block;width:9px;height:9px;border-radius:2px;background:' + l.color + ';margin-right:5px;vertical-align:middle"></span>' + l.label + '</span>'; }).join("");
    noteEl.textContent = spec.note || "";
    ov.style.display = "block";
    resize(); draw();
  }
  function close() { if (ov) ov.style.display = "none"; if (raf) { cancelAnimationFrame(raf); raf = null; } }
  function isOpen() { return !!(ov && ov.style.display !== "none"); }

  global.Context3D = {
    open: open, close: close, isOpen: isOpen,
    _rot: rot, _sphereWire: sphereWire, _ring: ring, _maxRadius: maxRadius // testing
  };
})(typeof window !== "undefined" ? window : globalThis);
