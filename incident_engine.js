/* incident_engine.js — newspace.live shared incident engine (Phase 1)
   Pure logic, no DOM. Used by ssa_visor.html and neo_watch.html.
   Exposes window.IncidentEngine. Single source of truth for:
   severity tiers, composite priority, recommended action, lifecycle + audit.
   Motor de incidentes compartido, lógica pura, sin DOM. */
(function (global) {
  "use strict";

  // 4 tiers, lower index = more severe. Colors live in CSS (--ir/--warm/--cold/--muted).
  var TIER = { critical: 0, high: 1, elevated: 2, routine: 3 };

  // ---- SSA severity (collision / proximity / state) ----
  // sig: {missKm, tcaMs(abs), pc, kind, reentryHrs, overAOI, trackedAsset, nowMs}
  function severitySSA(sig) {
    var now = sig.nowMs || Date.now();
    var tcaH = sig.tcaMs != null ? (sig.tcaMs - now) / 3.6e6 : null;
    if (sig.pc != null && sig.pc >= 1e-4) return "critical";
    if (sig.missKm != null && sig.missKm < 1 && tcaH != null && tcaH < 24) return "critical";
    if (sig.kind === "reentry" && sig.reentryHrs != null && sig.reentryHrs < 24 && sig.overAOI) return "critical";
    if (sig.missKm != null && sig.missKm < 5 && tcaH != null && tcaH < 48) return "high";
    if (sig.kind === "maneuver" && sig.trackedAsset) return "high";
    if (sig.kind === "reentry" && sig.reentryHrs != null && sig.reentryHrs < 168) return "high";
    if (sig.missKm != null && sig.missKm < 25 && tcaH != null && tcaH < 168) return "elevated";
    if (sig.kind === "newlost" || sig.kind === "anomaly") return "elevated";
    if (sig.missKm != null || sig.kind === "overflight" || sig.kind === "zone") return "routine";
    return "routine";
  }

  // ---- NEO: two independent axes ----
  function neoRisk(sig) { // torino, palermo
    var T = sig.torino, P = sig.palermo;
    if ((T != null && T >= 5) || (P != null && P >= 0)) return "critical";
    if ((T != null && T >= 2) || (P != null && P >= -2)) return "high";
    if ((T != null && T >= 1) || (P != null && P >= -4)) return "elevated";
    return "routine";
  }
  function neoObservability(sig) { // observable(bool), windowH(hours to window close)
    if (sig.observable && sig.windowH != null && sig.windowH < 24) return "tonight";
    if (sig.observable) return "soon";
    return "not_now";
  }

  function severity(sig) {
    return sig.domain === "neo" ? neoRisk(sig) : severitySSA(sig);
  }

  // ---- Composite priority (lower sorts first / more urgent) ----
  function compositePriority(inc) {
    var s = inc.signal || inc;
    var sev = inc.severity != null ? inc.severity : severity(s);
    var base = (TIER[sev] != null ? TIER[sev] : 3) * 1000;
    var now = s.nowMs || Date.now();
    var tHours;
    if (s.domain === "neo") {
      var obs = neoObservability(s);
      tHours = obs === "tonight" ? 1 : (obs === "soon" ? 72 : 9999);
    } else {
      tHours = s.tcaMs != null ? Math.max(0, (s.tcaMs - now) / 3.6e6) : null;
    }
    var timeScore = tHours == null ? 500 : Math.min(500, tHours); // sooner -> smaller
    var bump = 0;
    if (s.diamM != null) bump -= Math.min(50, s.diamM / 20);
    if (s.trackedAsset || s.reg) bump -= 30;
    return base + timeScore + bump;
  }

  // ---- Recommended action ----
  function recommendedAction(inc) {
    var s = inc.signal || inc;
    var sev = inc.severity != null ? inc.severity : severity(s);
    if (s.domain === "neo") {
      var obs = neoObservability(s);
      if (sev === "critical" || sev === "high") return obs === "not_now" ? "escalate" : "observe";
      if (sev === "elevated") return obs === "tonight" ? "observe" : "monitor";
      return obs === "tonight" ? "observe" : "ignore"; // routine: opportunistic astrometry if up tonight
    }
    if (sev === "critical") return "escalate";
    if (sev === "high") return s.observable ? "observe" : "escalate";
    if (sev === "elevated") return "monitor";
    return "ignore";
  }

  // ---- Lifecycle + audit ----
  var STATES = ["new", "acknowledged", "in_analysis", "escalated", "tasked", "resolved", "closed"];
  var TRANSITIONS = {
    new: ["acknowledged", "escalated", "resolved"],
    acknowledged: ["in_analysis", "escalated", "resolved"],
    in_analysis: ["escalated", "tasked", "resolved"],
    escalated: ["tasked", "resolved"],
    tasked: ["resolved"],
    resolved: ["closed", "new"],
    closed: []
  };
  function canTransition(from, to) { return (TRANSITIONS[from] || []).indexOf(to) >= 0; }
  function applyTransition(inc, to, actor, note, nowMs) {
    var now = nowMs || Date.now();
    if (!canTransition(inc.state, to)) return false;
    inc.audit = inc.audit || [];
    inc.audit.push({ ts: now, actor: actor || "system", from: inc.state, to: to, note: note || "" });
    inc.state = to;
    if (to === "acknowledged" && !inc.ackUtc) inc.ackUtc = now;
    if (to === "resolved") inc.resolvedUtc = now;
    if (to === "closed") inc.closedUtc = now;
    if (to === "new" && inc.resolvedUtc) { inc.reopenedUtc = now; inc.resolvedUtc = null; }
    return true;
  }

  // Auto-escalation by time: critical not acknowledged within escMin minutes
  function shouldAutoEscalate(inc, escMin, nowMs) {
    var now = nowMs || Date.now();
    if (inc.state !== "new") return false;
    if (inc.severity !== "critical") return false;
    return (now - (inc.openedUtc || now)) / 60000 >= (escMin || 15);
  }

  global.IncidentEngine = {
    TIER: TIER, STATES: STATES, TRANSITIONS: TRANSITIONS,
    severity: severity, severitySSA: severitySSA, neoRisk: neoRisk, neoObservability: neoObservability,
    compositePriority: compositePriority, recommendedAction: recommendedAction,
    canTransition: canTransition, applyTransition: applyTransition, shouldAutoEscalate: shouldAutoEscalate
  };
})(typeof window !== "undefined" ? window : globalThis);
