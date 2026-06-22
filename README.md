<a id="top"></a>
# newspace.live · SSA Visor + NEO Watch

**Language / Idioma:  [🇬🇧 English](#english) · [🇪🇸 Español](#espanol)**

---

<a id="english"></a>
# 🇬🇧 English — Full system documentation
## (architecture, components, capabilities and considerations)

This document describes the space situational awareness (SSA) and near-Earth object (NEO) tool suite of newspace.live. It is written so that someone outside the development can understand what the system does, how it is built, what it can and cannot do, and how to deploy and operate it.

The system has a deliberate dual nature. It is an operational tool for the OALM observatory (Los Molinos, MPC code 844, Uruguay) and, at the same time, a public educational layer inside the newspace.live hub. That is why many pieces work both with a database shared across operators and in single-browser local mode, with no server required.

### Index

1. [Overview](#en-overview)
2. [Architecture and data flow](#en-arch)
3. [File inventory](#en-files)
4. [Front-end components](#en-front)
5. [Shared engines](#en-engines)
6. [Backend (PHP / MySQL)](#en-backend)
7. [The incident model](#en-incidents)
8. [Severity per domain](#en-severity)
9. [Users, roles and permissions](#en-roles)
10. [Data and CDM ingestion](#en-ingest)
11. [Collision probability (Pc)](#en-pc)
12. [Capabilities](#en-cap)
13. [Honest considerations and caveats](#en-caveats)
14. [Security](#en-security)
15. [Deployment guide](#en-deploy)
16. [Post-deployment checklist](#en-check)
17. [Daily operation](#en-ops)
18. [Validation status](#en-valid)
19. [Pending items and roadmap](#en-roadmap)

---

<a id="en-overview"></a>
### 1. Overview
[↑](#top)

The suite is made of two main web tools and an administration panel, supported by an optional backend.

**SSA Visor** (`ssa_visor.html`) is the satellite and in-orbit situational awareness viewer. It lets you place an observer in thirty countries or any city in the world, see orbiting objects propagated in real time, detect conjunctions, reentries, overflights and anomalies, and manage them as incidents.

**NEO Watch** (`neo_watch.html`) is the near-Earth object and planetary defense tool. It brings the close approaches, the impact risk (Torino and Palermo scales) and the observability from OALM, all organized in a priority view called "today's picture", plus an approaches calendar and the telescope observation tasking.

**Admin** (`admin.html`) is the login and user management panel, with roles and an activity log.

The two tools share a common design system (hub color tokens, Space Grotesk, Inter and JetBrains Mono typefaces), are bilingual with a flag language switch, and share a single incident engine so decisions are made with the same logic in both.

---

<a id="en-arch"></a>
### 2. Architecture and data flow
[↑](#top)

The system has three layers.

The **presentation layer** are self-contained HTML pages that run entirely in the browser. They need no build step or framework. They load libraries from a CDN (satellite.js for SGP4, the Google Fonts typefaces) and the shared engines as local files.

The **shared logic layer** are three pure JavaScript modules, with no interface, that both tools load: the incident engine, the 3D context renderer and the collision probability engine.

The **backend layer** is optional and runs on a PHP 8.3 server with MySQL (designed for cPanel). It provides authentication, users and roles, shared incident persistence with auditing, TLE and Space-Track CDM ingestion, and the Telegram and email alert relays.

Data flow, in text:

```
CelesTrak (TLE) ───► ingest.php (cron) ──► MySQL (tle_history)
                                            └► tle.php (read)
NASA/JPL/ESA (NEO) ─► neo.php (cached proxy) ──► NEO Watch
Space-Track (CDM) ──► cdm_ingest.php (cron) ──► MySQL (app_incident) ──► Telegram
                                                      ▲
Front (SSA / NEO) ── incident engine ── upsert/transition ──┘ (if signed in)
                  └─ localStorage (if not signed in)
admin.html ◄──► auth.php / users.php ◄──► MySQL (app_user, app_activity)
```

Key design point, the **dual mode**. Each tool detects on load whether there is a session by querying the backend. If there is, the incident queue reads and writes to the database and is shared across all signed-in operators. If there is not, it falls back to the browser's local storage and works the same, but isolated to that browser. Severity, priority and recommended action are always computed on the client with the shared engine. The database owns what matters for teamwork, which is the incident state, the owner, the timestamps and the audit trail.

---

<a id="en-files"></a>
### 3. File inventory
[↑](#top)

| File | Layer | Function |
|---|---|---|
| `ssa_visor.html` | Front | SSA satellite and incident viewer |
| `neo_watch.html` | Front | NEO Watch, approaches, risk and tasking |
| `admin.html` | Front | Login, user management and activity log |
| `incident_engine.js` | Engine | Severity, priority, recommended action, lifecycle |
| `context3d.js` | Engine | 3D context renderer (canvas, no external library) |
| `pc_engine.js` | Engine | 2D collision probability (Foster) in JavaScript |
| `config.sample.php` | Backend | Config template with secrets (copy to `config.php`) |
| `db.php` | Backend | PDO connection to MySQL |
| `lib_auth.php` | Backend | Session, roles, CSRF, login rate limit, auditing, lifecycle |
| `auth.php` | Backend | First-admin bootstrap, login, logout, csrf |
| `users.php` | Backend | User creation and management, activity endpoint (admin only) |
| `incidents.php` | Backend | Shared incident API with RBAC and auditing |
| `pc_lib.php` | Backend | 2D collision probability (Foster) in PHP, for verification |
| `cdm_ingest.php` | Backend | Space-Track CDM ingestion worker (cron) |
| `ingest.php` | Backend | CelesTrak TLE ingestion worker (cron) |
| `tle.php` | Backend | TLE history and maneuver read API |
| `neo.php` | Backend | Cached proxy for NEO sources and alert relay |
| `.htaccess` | Backend | Blocks web access to config and sensitive files |
| `schema.sql` | Backend | TLE history and ingest-run tables |
| `schema_auth.sql` | Backend | Users, incidents, audit and activity tables |

The four files that must always sit together for the front to work fully are `incident_engine.js`, `context3d.js`, `ssa_visor.html` and `neo_watch.html`. The front uses `pc_engine.js` only as a computation and verification utility.

---

<a id="en-front"></a>
### 4. Front-end components
[↑](#top)

**SSA Visor.** Propagates orbiting objects with SGP4 from TLE and shows them on a map and a canvas. The observer is chosen by country, the Global option, or by typing any city, which is geocoded with Open-Meteo. It detects and classifies events: conjunctions with their miss distance and time to closest approach, reentries, overflights of interest and anomalies. Those events feed the incident triage queue. It includes the OODA stage bar, the alert console with time-based escalation, the case console, the triage queue with the incident detail, the 3D context, bilingual quick help, the flag language switch and the session chip in the header.

**NEO Watch.** Brings the close approaches and risk objects and organizes them decision-first. The central element is "today's picture", a board that ranks objects by a composite priority and shows, per object, the recommended action prominently, the impact risk separated from observability, and the OALM tasking with local time, darkness marker, estimated magnitude and pass distance. It adds an approaches calendar grouped by day with the OALM darkness window per night, the per-object card with a plain-language summary, the triage queue, the 3D context and the alert console. It starts in real-time monitoring by default. The speed selector remains available to look at future approaches.

**Admin.** The authentication entry point. The first time, since the database has no users, it offers creating the first administrator. After that it is normal login. An administrator sees the user table with role, status and last login, can create users, change roles, activate or deactivate and reset passwords, and sees the activity log. If whoever signs in is not an administrator, their session still starts so they can use the tools, but they do not see management.

---

<a id="en-engines"></a>
### 5. Shared engines
[↑](#top)

**`incident_engine.js`** is the core of the logic and was built once for both modules. It exposes `window.IncidentEngine`. It computes severity per domain, a composite priority to order the queue, the recommended action, validates the lifecycle transitions, builds the audit trail and decides the time-based auto-escalation. The priority convention is that a lower number is more urgent.

**`context3d.js`** is an in-house 3D renderer on canvas, with no external library. It exposes `window.Context3D`. It draws a wireframe Earth, orbits, rings, markers and vectors, with orthographic projection and depth sorting. You rotate by dragging and zoom with the wheel. It opens as a context layer from the incident detail, never as the home screen.

**`pc_engine.js` and `pc_lib.php`** are the same collision probability computation by the Foster 2D method, one in JavaScript and one in PHP, so the number is identical on the client and on the server. They receive the miss distance and the covariance already projected onto the encounter plane, plus the combined hard-body radius, and integrate the relative-position Gaussian over the disk. They also carry the Pc severity bands.

---

<a id="en-backend"></a>
### 6. Backend (PHP / MySQL)
[↑](#top)

Designed for cPanel with PHP 8.3 (ea-php83) and MySQL. Secrets live only in `config.php`, which never reaches the browser and is blocked by `.htaccess`.

`db.php` opens the PDO connection. `lib_auth.php` is the backend's shared layer; it handles the session, the current user, the role hierarchy, the CSRF token, the login rate limit, the activity log, the JSON response helpers and CORS, and contains the same incident lifecycle as the JavaScript engine.

`auth.php` resolves the first-administrator bootstrap, which only works while the database has no users, plus login, logout and CSRF token delivery. `users.php` is user management, admin only, and the activity-log endpoint. `incidents.php` is the shared incident API: list and detail for any signed-in user, create or update and state transition only for those who can work incidents, all audited.

`ingest.php` is the TLE worker; it downloads CelesTrak groups, versions the history in `tle_history`, heuristically detects maneuvers and builds a tamper-evident hash chain. `tle.php` reads that history. `neo.php` is the cached proxy for NEO sources and the Telegram and email alert relay. `cdm_ingest.php` is the Space-Track worker, described below.

---

<a id="en-incidents"></a>
### 7. The incident model
[↑](#top)

The base idea is to separate two things that used to be mixed.

A **signal** is an automatic detection that a rule fired. It is ephemeral, deduplicated and grouped by object, so an object is one thread and not fifty notifications.

An **incident or case** is something a person decided to manage. It has an owner, a lifecycle, analysis, tasking, closure and an audit trail. A signal is promoted to an incident automatically if it crosses a severity threshold, or by hand.

The lifecycle is mapped to the OODA loop and each transition is written to the audit with who, when and a note.

```
new ─► acknowledged ─► in_analysis ─► escalated ─► tasked ─► resolved ─► closed
  │          │              │            │                       ▲
  └ escalate └ resolve      └ resolve    └ resolve        reopen ┘ (resolved ─► new)
```

Every row in the triage queue answers five questions at a glance: what it is, how bad, how much time, what to do and who owns it. The queue orders by the composite priority, which combines severity, time pressure and the object's size or relevance.

Time-based auto-escalation raises a critical signal that is not acknowledged within a window of minutes. In local browser mode, auto-escalation and auto-resolve run on the client. In database mode, meaningful auto-escalation is done by the server-side worker, which is the right place for an escalation that must be seen across several users.

---

<a id="en-severity"></a>
### 8. Severity per domain
[↑](#top)

There is one severity scale per domain, because each one drives different actions. Four levels with consistent color: critical, high, elevated and routine.

**SSA (collision, proximity, state)**

| Level | Trigger (starting thresholds, tunable) |
|---|---|
| Critical | Pc ≥ 1e-4, or miss distance < 1 km with time to TCA < 24 h, or confirmed reentry < 24 h over the area of interest |
| High | miss < 5 km with TCA < 48 h, or maneuver detected on a tracked asset, or reentry candidate < 7 days |
| Elevated | miss < 25 km within 7 days, or new or lost object, or element anomaly |
| Routine | everything else that passed the filter |

Real Pc needs covariance. While it is not wired, the front's SSA severity uses miss distance plus time to TCA as a proxy and labels it as such.

**NEO (two independent axes)**

Impact risk and observability drive different actions, so they are never silently collapsed into a single number.

| Risk axis (Torino / Palermo) | Observability axis (from OALM) |
|---|---|
| Critical: Torino ≥ 5 or Palermo ≥ 0 | Tonight: observable now, window closing in under 24 h |
| High: Torino 2 to 4 or Palermo between −2 and 0 | This week: observable soon |
| Elevated: Torino 1 or Palermo between −4 and −2 | Not now: below horizon, daylight, too faint or window passed |
| Routine: Torino 0 or Palermo < −4 | |

The queue shows a composite priority for ordering, but the row always shows both badges. An object with no impact risk but observable tonight recommends "observe", because for the observatory it is an astrometry opportunity.

**Pc bands (collision engine)**

| Band | Pc threshold |
|---|---|
| Critical | Pc ≥ 1e-4 |
| High | Pc ≥ 1e-5 |
| Elevated | Pc ≥ 1e-7 |
| Routine | Pc < 1e-7 |

---

<a id="en-roles"></a>
### 9. Users, roles and permissions
[↑](#top)

| Role | Manage users | Work incidents | View |
|---|---|---|---|
| admin | Yes | Yes | Yes |
| analyst | No | Yes | Yes |
| operator | No | Yes | Yes |
| viewer | No | No | Yes |

Working incidents means acknowledge, analyze, escalate, assign tasking and resolve. The first administrator is created with the bootstrap, which only works if the database is empty. An administrator cannot demote or deactivate themselves, so the system never ends up without an administrator. Passwords are stored with bcrypt hashing, with a minimum of ten characters, and login attempts are rate-limited by IP and email.

---

<a id="en-ingest"></a>
### 10. Data and CDM ingestion
[↑](#top)

**TLE (CelesTrak).** `ingest.php` runs by cron, downloads the configured groups, versions each TLE in `tle_history`, computes differences against the same object's previous one and flags possible maneuvers with a heuristic detector based on inclination and mean-motion jumps. It chains a hash per record so the history is tamper-evident. Maneuver detection needs at least two runs to have something to compare.

**CDM (Space-Track).** `cdm_ingest.php` runs by cron, logs into Space-Track with the `config.php` credentials, downloads the CDMs of upcoming conjunctions ordered by TCA, and for each one creates or updates an incident in the database. It takes the official Pc from the 18th Space Defense Squadron, assigns the severity band, computes the priority and stores TCA, miss distance and Pc. If the Pc reaches critical and the incident is still in the "new" state, it auto-escalates server-side, logs the activity and notifies via Telegram. The incident id groups the CDMs of the same conjunction by object pair and TCA date, so a CDM update updates the same incident.

---

<a id="en-pc"></a>
### 11. Collision probability (Pc)
[↑](#top)

It is important to understand where the Pc comes from in each case.

The **public CDMs** from Space-Track, the `cdm_public` class, carry the Pc already computed by the 18th SDS, which is the authoritative one, along with the TCA and miss distance, but they do **not** carry the full covariance matrices. That is why the worker uses that official Pc directly and does not recompute it.

The **operator CDMs**, the `cdm` class, carry the full state and covariance, but you only receive them as the operator of one of the objects. With that covariance you could recompute the Pc.

The Foster engine, in `pc_engine.js` and `pc_lib.php`, is built and tested for that second case, as an independent verification, and receives the covariance and the miss distance already projected onto the encounter plane. Projecting from the raw three-dimensional covariance in the RTN frame onto the two-dimensional encounter plane is a separate step that requires validation against reference cases and that, on purpose, was not wired into the worker so as not to deliver unverified numbers.

---

<a id="en-cap"></a>
### 12. Capabilities
[↑](#top)

The system can, today, do the following.

Propagate and visualize orbiting objects with SGP4 from any observer in the world, and detect conjunctions, reentries, overflights and anomalies. Bring near-Earth object approaches, their impact risk and their observability from OALM, and prioritize them in a decision view. Manage all of that as incidents with an audited lifecycle, a triage queue that answers the five questions, composite priority and recommended action. Open a real 3D context for SSA conjunctions and a schematic scale one for NEO. Ingest Space-Track CDMs with official Pc and auto-escalate with Telegram notification. Operate in multi-user mode with login, roles, shared persistence and activity log, or in single-browser local mode for public and educational use. Produce observation tasking for the C18 telescope with local time, darkness window and export.

---

<a id="en-caveats"></a>
### 13. Honest considerations and caveats
[↑](#top)

These are the system's real limitations. Reading them is part of operating it well.

**Collision probability.** With public CDMs the official Pc is used. Recomputing the Pc from raw covariance requires operator CDMs or a commercial feed, and the projection from the RTN frame to the encounter plane is not implemented because it could not be validated against real data.

**Front-end Pc proxy.** The browser's SSA severity uses miss distance and time to TCA as a proxy for Pc, and labels it as such. It is not a real Pc.

**CDM distance units.** The worker assumes the miss-distance field is in kilometers. This must be verified against a real sample from the account before operating.

**Maneuver detection.** It is heuristic, based on inclination and mean-motion jumps, and needs at least two ingestion runs to have a reference.

**TLE age.** SGP4 accuracy degrades as the TLE ages. The SSA 3D context is real geometry propagated from the TLEs, but it is context, not a conjunction screening.

**Reentry windows.** They are coarse approximations.

**NEO 3D context.** It is schematic. The distance to Earth, measured in lunar distances, is real, but the direction is not. The true approach vector needs the JPL Horizons ephemeris.

**OALM darkness window.** It is a necessary condition to observe, not a pointing solution. When the object is actually above the horizon and where to point in altitude and azimuth needs the JPL Horizons ephemeris.

**Country footprints.** The territorial footprints of countries other than Uruguay are bounding-box approximations.

**Persistence.** Without a session, incidents live in the browser and are isolated. With a session, they are in the database and shared. The client's automatic auto-escalation and auto-resolve run only in local mode. In database mode, meaningful automatic escalation is done by the server-side worker.

**Typefaces and flags.** Typefaces depend on Google Fonts, like the hub. Flag emoji may render as letters on some Windows systems, due to a system-font limitation.

**Activity log.** It covers meaningful human actions and logins; on purpose it does not record every front-end sync so as not to bury the log in noise.

---

<a id="en-security"></a>
### 14. Security
[↑](#top)

Secrets, namely the database credentials, the Telegram token, the ingest token and the Space-Track credentials, live only in `config.php`. That file never reaches the browser and the included `.htaccess` blocks its web access, along with `db.php` and the `.sql` and `.cache` files.

Credentials should never be pasted in chats or repositories. If a credential was ever exposed, it must be rotated at the provider before use. In particular, if the Space-Track password passed through an insecure channel, change it at Space-Track and put the new one only in `config.php`.

Authentication uses bcrypt hashing, passwords of at least ten characters, login rate limiting by IP and email, a CSRF token on state-changing actions, and a session cookie with the httponly and samesite flags. The post-login return only accepts a filename from the same folder, to avoid redirects to external sites.

As for cPanel permissions, files should be 644 and directories 755. suexec rejects permissions that are too open or too closed, so avoid 666, 777 and 600.

---

<a id="en-deploy"></a>
### 15. Deployment guide
[↑](#top)

Requirements: cPanel hosting, PHP 8.3 (ea-php83) and MySQL.

1. **Upload the files** to the domain root, usually `public_html`, all in the same folder.
2. **Create the database and user** in cPanel, under MySQL Databases, and grant all privileges. cPanel usually prefixes the name with your username.
3. **Import the schema** in phpMyAdmin, first `schema.sql` and then `schema_auth.sql`. Re-importing is safe because everything uses conditional creation and does not touch existing tables.
4. **Create `config.php`** by copying `config.sample.php` and filling in the database, the Telegram block if you use it, the ingest token, and the `space_track` block with your identity and the already-rotated password.
5. **Set permissions**, files to 644 and directories to 755, and confirm the `.htaccess` is present.
6. **Create the first administrator** by going to `admin.html`, which while the database is empty offers the initial admin creation.
7. **Create the remaining users** with their roles from the panel.
8. **Set up the cron jobs**:

```
# TLE ingestion, four times a day
0 0,6,12,18 * * * /usr/local/bin/ea-php83 /home/USER/public_html/ingest.php >/dev/null 2>&1

# Space-Track CDM ingestion, four times a day
0 1,7,13,19 * * * /usr/local/bin/ea-php83 /home/USER/public_html/cdm_ingest.php >/dev/null 2>&1
```

Replace `USER` with your cPanel username. Use explicit hours as in the example and avoid expressions like slash-six inside PHP comments, because they close the comment.

---

<a id="en-check"></a>
### 16. Post-deployment checklist
[↑](#top)

Test each piece and look at the JSON it returns.

| Test | How | Expected |
|---|---|---|
| NEO proxy | open `neo.php?src=tle` | returns TLE |
| TLE ingest | `ingest.php` by cron or with its token | `{run, fetched, inserted, maneuvers}` |
| TLE read | `tle.php?op=stats` | statistics |
| Alert relay | POST to `neo.php?src=alert` | `{ok, channels}` |
| Session | `auth.php?action=csrf` | `{csrf, user, needs_bootstrap}` |
| CDM ingest | `cdm_ingest.php?token=YOUR_TOKEN` | `{ok, fetched, upserted, escalated}` |
| Login | from the SSA or NEO chip | returns to the originating module |
| Database mode | open the triage queue signed in | the header shows shared DB |

If something fails, the endpoints return the error as JSON, which makes diagnosis easier. Also verify, against a real sample from your Space-Track account, that the CDM miss distance is in the units the worker assumes.

---

<a id="en-ops"></a>
### 17. Daily operation
[↑](#top)

The operator opens SSA Visor or NEO Watch. If the header shows the empty circle and the word "sign in", it is best to log in to work in shared mode, which takes you to the panel and returns you to the tool after login. With a session, the chip shows the name in green and the role.

In NEO Watch, "today's picture" concentrates what is actionable, with the recommended action on top and the OALM tasking per object. The calendar shows what is coming by day with the darkness window. In SSA, the triage queue, opened with the square button in the header, lists incidents by priority. Clicking a row opens the detail with the five answers, the audited timeline and the valid transition buttons, plus the button to open the 3D context.

The conjunctions ingested by the Space-Track worker appear in the SSA queue as incidents with their real Pc, and the critical ones arrive already escalated and with a Telegram notification. The administrator can review the activity log in the panel to see logins, management, transitions and automatic escalations.

---

<a id="en-valid"></a>
### 18. Validation status
[↑](#top)

The pure-logic engines are tested by running them. The incident engine passed its unit tests for severity, priority, action and lifecycle. The 3D renderer geometry passed its tests. The collision probability engine passed the same analytic tests in JavaScript and PHP, giving identical results. The server-side authentication, roles and lifecycle logic passed its tests in PHP.

The integrations were validated structurally, that is compilation without errors, parity of translations between English and Spanish, and consistency of identifiers and keys. All PHP passes the syntax check.

What the operator must verify in their environment, because it cannot be tested from outside the server, is the visual rendering in the browser, the real login against the database, the full queue cycle in shared mode, and the real connection and download against Space-Track.

---

<a id="en-roadmap"></a>
### 19. Pending items and roadmap
[↑](#top)

The only real pending item, and due to an external dependency and not to code, is recomputing the collision probability from raw covariance. That waits on getting operator CDMs or a commercial feed that provides covariance. The Foster engine is already built and tested on both sides, so when the source exists, the work is to implement and validate the projection from the RTN frame to the encounter plane and wire it in.

Other possible improvements, not urgent, are retiring the previous alert and case consoles once the triage queue is fully validated in operation, and, if one wishes to also audit the front's automatic syncs, adding them to the activity log with a separate filter so as not to bury the human actions.

---

[↑ Top](#top) · [🇪🇸 Español](#espanol)

---
---

<a id="espanol"></a>
# 🇪🇸 Español — Documentación integral del sistema
## (arquitectura, componentes, capacidades y consideraciones)

Este documento describe el conjunto de herramientas de conciencia situacional espacial (SSA) y de defensa planetaria (NEO) de newspace.live. Está escrito para que una persona externa al desarrollo pueda entender qué hace el sistema, cómo está armado, qué puede y qué no puede hacer, y cómo desplegarlo y operarlo.

El sistema tiene una doble naturaleza deliberada. Es una herramienta operativa para el observatorio OALM (Los Molinos, código MPC 844, Uruguay) y, a la vez, una capa divulgativa pública dentro del hub newspace.live. Por eso muchas piezas funcionan tanto con base de datos compartida entre operadores como en modo local de un solo navegador, sin necesidad de servidor.

### Índice

1. [Visión general](#es-vision)
2. [Arquitectura y flujo de datos](#es-arquitectura)
3. [Inventario de archivos](#es-inventario)
4. [Componentes del front](#es-front)
5. [Motores compartidos](#es-motores)
6. [Backend (PHP / MySQL)](#es-backend)
7. [El modelo de incidentes](#es-incidentes)
8. [Severidad por dominio](#es-severidad)
9. [Usuarios, roles y permisos](#es-roles)
10. [Ingesta de datos y de CDM](#es-ingesta)
11. [Probabilidad de colisión (Pc)](#es-pc)
12. [Capacidades del sistema](#es-capacidades)
13. [Consideraciones y salvedades honestas](#es-salvedades)
14. [Seguridad](#es-seguridad)
15. [Guía de despliegue](#es-despliegue)
16. [Lista de verificación post-despliegue](#es-verificacion)
17. [Operación diaria](#es-operacion)
18. [Estado de validación](#es-validacion)
19. [Pendientes y hoja de ruta](#es-pendientes)

---

<a id="es-vision"></a>
### 1. Visión general
[↑](#top)

El conjunto se compone de dos herramientas web principales y un panel de administración, apoyados en un backend opcional.

**SSA Visor** (`ssa_visor.html`) es el visor de satélites y conciencia situacional en órbita. Permite elegir un observador en treinta países o cualquier ciudad del mundo, ver objetos en órbita propagados en tiempo real, detectar conjunciones, reentradas, sobrevuelos y anomalías, y gestionarlas como incidentes.

**NEO Watch** (`neo_watch.html`) es la herramienta de objetos cercanos a la Tierra y defensa planetaria. Trae las aproximaciones cercanas, el riesgo de impacto (escalas de Torino y Palermo) y la observabilidad desde OALM, todo organizado en una vista de prioridad llamada la foto de hoy, más un calendario de aproximaciones y el tasking de observación del telescopio.

**Admin** (`admin.html`) es el panel de login y gestión de usuarios, con roles y un registro de actividad.

Las dos herramientas comparten un sistema de diseño común (tokens de color del hub, tipografías Space Grotesk, Inter y JetBrains Mono), son bilingües con un selector de idioma por banderas, y comparten un mismo motor de incidentes para que las decisiones se tomen con la misma lógica en ambas.

---

<a id="es-arquitectura"></a>
### 2. Arquitectura y flujo de datos
[↑](#top)

El sistema tiene tres capas.

La **capa de presentación** son páginas HTML autocontenidas que corren enteras en el navegador. No necesitan compilación ni framework. Cargan librerías por CDN (satellite.js para SGP4, las tipografías de Google Fonts) y los motores compartidos como archivos locales.

La **capa de lógica compartida** son tres módulos JavaScript puros, sin interfaz, que ambas herramientas cargan: el motor de incidentes, el renderer de contexto 3D y el motor de probabilidad de colisión.

La **capa de backend** es opcional y corre en un servidor PHP 8.3 con MySQL (pensado para cPanel). Aporta autenticación, usuarios y roles, persistencia compartida de incidentes con auditoría, la ingesta de TLE y de CDM de Space-Track, y los relays de aviso por Telegram y correo.

El flujo de datos, en texto:

```
CelesTrak (TLE) ───► ingest.php (cron) ──► MySQL (tle_history)
                                            └► tle.php (lectura)
NASA/JPL/ESA (NEO) ─► neo.php (proxy con cache) ──► NEO Watch
Space-Track (CDM) ──► cdm_ingest.php (cron) ──► MySQL (app_incident) ──► Telegram
                                                      ▲
Front (SSA / NEO) ── motor de incidentes ── upsert/transicion ──┘ (si hay sesion)
                  └─ localStorage (si no hay sesion)
admin.html ◄──► auth.php / users.php ◄──► MySQL (app_user, app_activity)
```

Punto clave del diseño, el **modo dual**. Cada herramienta detecta al cargar si hay una sesión iniciada consultando el backend. Si la hay, la cola de incidentes lee y escribe en la base de datos y es compartida entre todos los operadores logueados. Si no la hay, cae al almacenamiento local del navegador y funciona igual, pero aislada en ese navegador. El cálculo de severidad, prioridad y acción recomendada siempre ocurre en el cliente con el motor compartido. La base de datos manda en lo que importa para el trabajo en equipo, que es el estado del incidente, el responsable, las marcas de tiempo y la auditoría.

---

<a id="es-inventario"></a>
### 3. Inventario de archivos
[↑](#top)

| Archivo | Capa | Función |
|---|---|---|
| `ssa_visor.html` | Front | Visor SSA de satélites e incidentes |
| `neo_watch.html` | Front | NEO Watch, aproximaciones, riesgo y tasking |
| `admin.html` | Front | Login, gestión de usuarios y registro de actividad |
| `incident_engine.js` | Motor | Severidad, prioridad, acción recomendada, ciclo de vida |
| `context3d.js` | Motor | Renderer 3D de contexto (canvas, sin librería externa) |
| `pc_engine.js` | Motor | Probabilidad de colisión 2D (Foster) en JavaScript |
| `config.sample.php` | Backend | Plantilla de configuración con secretos (copiar a `config.php`) |
| `db.php` | Backend | Conexión PDO a MySQL |
| `lib_auth.php` | Backend | Sesión, roles, CSRF, límite de intentos, auditoría, ciclo de vida |
| `auth.php` | Backend | Bootstrap del primer admin, login, logout, csrf |
| `users.php` | Backend | Alta y gestión de usuarios y endpoint de actividad (solo admin) |
| `incidents.php` | Backend | API de incidentes compartidos con RBAC y auditoría |
| `pc_lib.php` | Backend | Probabilidad de colisión 2D (Foster) en PHP, para verificación |
| `cdm_ingest.php` | Backend | Worker de ingesta de CDM de Space-Track (cron) |
| `ingest.php` | Backend | Worker de ingesta de TLE de CelesTrak (cron) |
| `tle.php` | Backend | API de lectura de historia de TLE y maniobras |
| `neo.php` | Backend | Proxy con caché de fuentes NEO y relay de alertas |
| `.htaccess` | Backend | Bloquea el acceso web a config y a archivos sensibles |
| `schema.sql` | Backend | Tablas de historia de TLE y de corridas de ingesta |
| `schema_auth.sql` | Backend | Tablas de usuarios, incidentes, auditoría y actividad |

Los cuatro archivos que deben estar siempre juntos para que el front funcione completo son `incident_engine.js`, `context3d.js`, `ssa_visor.html` y `neo_watch.html`. El `pc_engine.js` lo usa el front solo como utilidad de cálculo y verificación.

---

<a id="es-front"></a>
### 4. Componentes del front
[↑](#top)

**SSA Visor.** Propaga objetos en órbita con SGP4 a partir de TLE y los muestra sobre un mapa y un lienzo. El observador se elige por país, por la opción Global, o tipeando cualquier ciudad, que se geocodifica con Open-Meteo. Detecta y clasifica eventos, conjunciones con su distancia mínima y tiempo al máximo acercamiento, reentradas, sobrevuelos de interés y anomalías. Esos eventos alimentan la cola de triage. Incluye la barra de fases OODA, la consola de alertas con escalado por tiempo, la consola de casos, la cola de triage con el detalle de incidente, el contexto 3D, la ayuda rápida bilingüe, el selector de idioma por banderas y el chip de sesión en el header.

**NEO Watch.** Trae las aproximaciones cercanas y los objetos de riesgo y los organiza decisión primero. El elemento central es la foto de hoy, un tablero que rankea los objetos por una prioridad compuesta y muestra, por cada uno, la acción recomendada de forma prominente, el riesgo de impacto separado de la observabilidad, y el tasking de OALM con la hora local, la marca de oscuridad, la magnitud estimada y la distancia de paso. Suma un calendario de aproximaciones agrupado por día con la ventana de oscuridad de OALM por noche, la ficha por objeto con un resumen en lenguaje claro, la cola de triage, el contexto 3D y la consola de alertas. Por defecto arranca el monitoreo en tiempo real. El selector de aceleración sigue disponible para mirar aproximaciones futuras.

**Admin.** Es el punto de entrada de la autenticación. La primera vez, como la base no tiene usuarios, ofrece crear el primer administrador. Después es login normal. Un administrador ve la tabla de usuarios con su rol, estado y último ingreso, puede crear usuarios, cambiar roles, activar o desactivar y resetear contraseñas, y ve el registro de actividad. Si quien ingresa no es administrador, su sesión queda iniciada igual para usar las herramientas, pero no ve la gestión.

---

<a id="es-motores"></a>
### 5. Motores compartidos
[↑](#top)

**`incident_engine.js`** es el corazón de la lógica y se construyó una sola vez para los dos módulos. Expone `window.IncidentEngine`. Calcula la severidad por dominio, una prioridad compuesta para ordenar la cola, la acción recomendada, valida las transiciones del ciclo de vida, arma la traza de auditoría y decide el auto-escalado por tiempo. La convención de la prioridad es que un número menor es más urgente.

**`context3d.js`** es un renderer 3D propio sobre canvas, sin librería externa. Expone `window.Context3D`. Dibuja una Tierra de alambre, órbitas, anillos, marcadores y vectores, con proyección ortográfica y ordenamiento por profundidad. Se rota arrastrando y se hace zoom con la rueda. Se abre como una capa de contexto desde el detalle de un incidente, nunca como pantalla principal.

**`pc_engine.js` y `pc_lib.php`** son el mismo cálculo de probabilidad de colisión por el método de Foster en dos dimensiones, uno en JavaScript y otro en PHP, para que el número sea idéntico del lado cliente y del lado servidor. Reciben la distancia de fallo y la covarianza ya proyectadas al plano de encuentro, más el radio combinado de cuerpo duro, e integran la gaussiana de posición relativa sobre el disco. Traen también las bandas de severidad por Pc.

---

<a id="es-backend"></a>
### 6. Backend (PHP / MySQL)
[↑](#top)

Diseñado para cPanel con PHP 8.3 (ea-php83) y MySQL. Los secretos viven solo en `config.php`, que nunca llega al navegador y queda bloqueado por `.htaccess`.

`db.php` abre la conexión PDO. `lib_auth.php` es la capa compartida del backend, maneja la sesión, el usuario actual, la jerarquía de roles, el token CSRF, el límite de intentos de login, el registro de actividad, los helpers de respuesta JSON y el CORS, y contiene el mismo ciclo de vida del incidente que el motor JavaScript.

`auth.php` resuelve el bootstrap del primer administrador, que solo funciona mientras la base no tiene usuarios, más login, logout y la entrega del token CSRF. `users.php` es la gestión de usuarios, solo para administradores, y el endpoint del registro de actividad. `incidents.php` es la API de incidentes compartidos, lista y detalle para cualquier usuario logueado, alta o actualización y transición de estado solo para quien puede trabajar incidentes, todo con auditoría.

`ingest.php` es el worker de TLE, baja los grupos de CelesTrak, versiona la historia en `tle_history`, detecta maniobras de forma heurística y arma una cadena de hash a prueba de manipulación. `tle.php` es la lectura de esa historia. `neo.php` es el proxy con caché de las fuentes NEO y el relay de alertas a Telegram y correo. `cdm_ingest.php` es el worker de Space-Track, descrito más abajo.

---

<a id="es-incidentes"></a>
### 7. El modelo de incidentes
[↑](#top)

La idea base es separar dos cosas que antes estaban mezcladas.

Una **señal** es una detección automática de que una regla disparó. Es efímera, deduplicada y agrupada por objeto, de modo que un objeto es un hilo y no cincuenta notificaciones.

Un **incidente o caso** es algo que una persona decidió gestionar. Tiene responsable, ciclo de vida, análisis, tasking, cierre y traza de auditoría. Una señal se promueve a incidente automáticamente si cruza un umbral de severidad, o a mano.

El ciclo de vida está mapeado al OODA y cada transición se escribe en la auditoría con quién, cuándo y una nota.

```
nuevo ─► reconocido ─► en analisis ─► escalado ─► tasking ─► resuelto ─► cerrado
   │           │            │            │                       ▲
   └ escalado  └ resuelto   └ resuelto   └ resuelto       reabrir ┘ (resuelto ─► nuevo)
```

Toda fila de la cola de triage contesta cinco preguntas de un vistazo: qué es, qué tan grave, cuánto tiempo hay, qué hacer y quién lo tiene. La cola ordena por la prioridad compuesta, que combina la severidad, la urgencia temporal y el tamaño o relevancia del objeto.

El auto-escalado por tiempo eleva una señal crítica que no se reconoce dentro de una ventana de minutos. En modo local del navegador, el auto-escalado y el auto-resuelto corren en el cliente. En modo base de datos, el auto-escalado significativo lo realiza el worker del lado servidor, que es el lugar correcto para una escalada que debe verse entre varios usuarios.

---

<a id="es-severidad"></a>
### 8. Severidad por dominio
[↑](#top)

Hay una escala de severidad por dominio, porque cada uno dispara acciones distintas. Cuatro niveles con color consistente: crítico, alto, elevado y rutina.

**SSA (colisión, proximidad, estado)**

| Nivel | Disparador (umbrales de partida, ajustables) |
|---|---|
| Crítico | Pc ≥ 1e-4, o distancia mínima < 1 km con tiempo al TCA < 24 h, o reentrada confirmada < 24 h sobre el área de interés |
| Alto | distancia < 5 km con TCA < 48 h, o maniobra detectada en un activo seguido, o candidato a reentrada < 7 días |
| Elevado | distancia < 25 km dentro de 7 días, o objeto nuevo o perdido, o anomalía de elementos |
| Rutina | el resto de lo que entró al filtro |

La Pc real necesita covarianza. Mientras no esté cableada, la severidad SSA del front usa distancia mínima más tiempo al TCA como proxy y lo etiqueta como tal.

**NEO (dos ejes independientes)**

El riesgo de impacto y la observabilidad disparan acciones diferentes, así que nunca se colapsan en un solo número en silencio.

| Eje de riesgo (Torino / Palermo) | Eje de observabilidad (desde OALM) |
|---|---|
| Crítico: Torino ≥ 5 o Palermo ≥ 0 | Esta noche: observable ahora, ventana cerrando en menos de 24 h |
| Alto: Torino 2 a 4 o Palermo entre −2 y 0 | Esta semana: observable pronto |
| Elevado: Torino 1 o Palermo entre −4 y −2 | No ahora: bajo el horizonte, de día, muy débil o ventana pasada |
| Rutina: Torino 0 o Palermo < −4 | |

La cola muestra una prioridad compuesta para ordenar, pero la fila siempre muestra los dos distintivos. Un objeto sin riesgo de impacto pero observable esta noche recomienda observar, porque para el observatorio es una oportunidad de astrometría.

**Bandas por Pc (motor de colisión)**

| Banda | Umbral de Pc |
|---|---|
| Crítico | Pc ≥ 1e-4 |
| Alto | Pc ≥ 1e-5 |
| Elevado | Pc ≥ 1e-7 |
| Rutina | Pc < 1e-7 |

---

<a id="es-roles"></a>
### 9. Usuarios, roles y permisos
[↑](#top)

| Rol | Gestionar usuarios | Trabajar incidentes | Ver |
|---|---|---|---|
| admin | Sí | Sí | Sí |
| analyst | No | Sí | Sí |
| operator | No | Sí | Sí |
| viewer | No | No | Sí |

Trabajar incidentes significa reconocer, analizar, escalar, asignar tasking y resolver. El primer administrador se crea con el bootstrap, que solo funciona si la base está vacía. Un administrador no puede bajarse de rol ni desactivarse a sí mismo, para que el sistema nunca se quede sin administrador. Las contraseñas se guardan con hash bcrypt, con un mínimo de diez caracteres, y los intentos de login están limitados por IP y correo.

---

<a id="es-ingesta"></a>
### 10. Ingesta de datos y de CDM
[↑](#top)

**TLE (CelesTrak).** `ingest.php` corre por cron, baja los grupos configurados, versiona cada TLE en `tle_history`, calcula diferencias contra el anterior del mismo objeto y marca posibles maniobras con un detector heurístico basado en saltos de inclinación y de movimiento medio. Encadena un hash por registro para que la historia sea a prueba de manipulación. La detección de maniobras necesita al menos dos corridas para tener con qué comparar.

**CDM (Space-Track).** `cdm_ingest.php` corre por cron, loguea en Space-Track con las credenciales del `config.php`, baja los CDM de conjunciones próximas ordenados por TCA, y por cada uno crea o actualiza un incidente en la base. Toma la Pc oficial del 18th Space Defense Squadron, le asigna la banda de severidad, calcula la prioridad y guarda TCA, distancia mínima y Pc. Si la Pc llega a crítica y el incidente sigue en estado nuevo, lo auto-escala del lado servidor, registra la actividad y avisa por Telegram. El identificador del incidente agrupa los CDM de una misma conjunción por par de objetos y fecha de TCA, así una actualización del CDM actualiza el mismo incidente.

---

<a id="es-pc"></a>
### 11. Probabilidad de colisión (Pc)
[↑](#top)

Es importante entender de dónde sale la Pc en cada caso.

Los **CDM públicos** de Space-Track, la clase `cdm_public`, traen la Pc ya calculada por el 18th SDS, que es la autoritativa, junto con el TCA y la distancia mínima, pero **no** traen las matrices de covarianza completas. Por eso el worker usa esa Pc oficial directamente y no la recalcula.

Los **CDM de operador**, la clase `cdm`, traen el estado y la covarianza completos, pero solo los recibís como operador de uno de los objetos. Con esa covarianza sí se podría recalcular la Pc.

El motor de Foster, en `pc_engine.js` y `pc_lib.php`, está construido y probado para ese segundo caso, como verificación independiente, y recibe la covarianza y la distancia de fallo ya proyectadas al plano de encuentro. La proyección desde la covarianza tridimensional cruda en el marco RTN hacia el plano de encuentro de dos dimensiones es un paso aparte que requiere validación contra casos de referencia y que, a propósito, no se cableó al worker para no entregar números sin verificar.

---

<a id="es-capacidades"></a>
### 12. Capacidades del sistema
[↑](#top)

El sistema puede, hoy, hacer lo siguiente.

Propagar y visualizar objetos en órbita con SGP4 desde cualquier observador del mundo, y detectar conjunciones, reentradas, sobrevuelos y anomalías. Traer las aproximaciones de objetos cercanos, su riesgo de impacto y su observabilidad desde OALM, y priorizarlas en una vista de decisión. Gestionar todo eso como incidentes con un ciclo de vida auditado, una cola de triage que contesta las cinco preguntas, prioridad compuesta y acción recomendada. Abrir un contexto 3D real para conjunciones SSA y uno esquemático de escala para NEO. Ingerir CDM de Space-Track con Pc oficial y auto-escalar con aviso por Telegram. Operar en modo multiusuario con login, roles, persistencia compartida y registro de actividad, o en modo local de un solo navegador para uso público y educativo. Producir tasking de observación para el telescopio C18 con hora local, ventana de oscuridad y exportación.

---

<a id="es-salvedades"></a>
### 13. Consideraciones y salvedades honestas
[↑](#top)

Estas son las limitaciones reales del sistema. Leerlas es parte de operarlo bien.

**Probabilidad de colisión.** Con CDM públicos se usa la Pc oficial. Recalcular la Pc desde covarianza cruda requiere CDM de operador o un feed comercial, y la proyección del marco RTN al plano de encuentro no está implementada porque no se pudo validar contra datos reales.

**Proxy de Pc en el front.** La severidad SSA del navegador usa distancia mínima y tiempo al TCA como proxy de la Pc, y lo etiqueta como tal. No es una Pc real.

**Unidades de distancia del CDM.** El worker asume que el campo de distancia mínima viene en kilómetros. Hay que verificarlo contra una muestra real de la cuenta antes de operar.

**Detección de maniobras.** Es heurística, basada en saltos de inclinación y movimiento medio, y necesita al menos dos corridas de ingesta para tener referencia.

**Edad de los TLE.** La precisión de SGP4 se degrada a medida que el TLE envejece. El contexto 3D del SSA es geometría real propagada de los TLE, pero es contexto, no un screening de conjunción.

**Ventanas de reentrada.** Son aproximaciones gruesas.

**Contexto 3D de NEO.** Es esquemático. La distancia a la Tierra, medida en distancias lunares, es real, pero la dirección no lo es. El vector verdadero de acercamiento necesita la efeméride de JPL Horizons.

**Ventana de oscuridad de OALM.** Es una condición necesaria para observar, no una solución de apuntado. Cuándo el objeto está realmente sobre el horizonte y hacia dónde apuntar en altura y azimut necesita la efeméride de JPL Horizons.

**Huellas de países.** Las huellas territoriales de países distintos a Uruguay son aproximaciones por caja envolvente.

**Persistencia.** Sin sesión iniciada, los incidentes viven en el navegador y son aislados. Con sesión, están en la base y son compartidos. El auto-escalado y el auto-resuelto automáticos del cliente solo corren en modo local. En modo base, la escalada automática significativa la hace el worker del lado servidor.

**Tipografías y banderas.** Las tipografías dependen de Google Fonts, igual que el hub. Los emoji de bandera pueden verse como letras en algunos Windows, por limitación de la fuente del sistema.

**Registro de actividad.** Cubre las acciones humanas significativas y los logins, a propósito no registra cada sincronización del front para no tapar el log con ruido.

---

<a id="es-seguridad"></a>
### 14. Seguridad
[↑](#top)

Los secretos, que son las credenciales de base de datos, el token de Telegram, el token de ingesta y las credenciales de Space-Track, viven únicamente en `config.php`. Ese archivo nunca llega al navegador y el `.htaccess` incluido bloquea su acceso por web, junto con el de `db.php` y los archivos `.sql` y `.cache`.

Nunca se deben pegar credenciales en chats ni en repositorios. Si una credencial se expuso alguna vez, hay que rotarla en el proveedor antes de usarla. En particular, si la contraseña de Space-Track pasó por algún canal inseguro, cambiala en Space-Track y poné la nueva solo en `config.php`.

La autenticación usa hash bcrypt, contraseñas de al menos diez caracteres, límite de intentos de login por IP y correo, token CSRF en las acciones que cambian estado, y cookie de sesión con las marcas httponly y samesite. El retorno después del login solo acepta un nombre de archivo de la misma carpeta, para evitar redirecciones a sitios externos.

En cuanto a permisos en cPanel, los archivos deben quedar en 644 y los directorios en 755. El suexec rechaza permisos demasiado abiertos o demasiado cerrados, así que evitá 666, 777 y 600.

---

<a id="es-despliegue"></a>
### 15. Guía de despliegue
[↑](#top)

Requisitos: hosting con cPanel, PHP 8.3 (ea-php83) y MySQL.

1. **Subir los archivos** a la raíz del dominio, normalmente `public_html`, todos en la misma carpeta.
2. **Crear la base y el usuario** en cPanel, en MySQL Databases, y asignarle todos los permisos. cPanel suele prefijar el nombre con tu usuario.
3. **Importar el esquema** en phpMyAdmin, primero `schema.sql` y después `schema_auth.sql`. Es seguro reimportar porque todo usa creación condicional y no toca tablas existentes.
4. **Crear `config.php`** copiando `config.sample.php` y completando la base de datos, el bloque de Telegram si lo usás, el token de ingesta, y el bloque `space_track` con tu identidad y la contraseña ya rotada.
5. **Ajustar permisos**, archivos en 644 y directorios en 755, y verificar que el `.htaccess` esté presente.
6. **Crear el primer administrador** entrando a `admin.html`, que al estar la base vacía ofrece el alta del administrador inicial.
7. **Crear el resto de usuarios** con sus roles desde el panel.
8. **Configurar los cron**:

```
# Ingesta de TLE, cuatro veces al dia
0 0,6,12,18 * * * /usr/local/bin/ea-php83 /home/USUARIO/public_html/ingest.php >/dev/null 2>&1

# Ingesta de CDM de Space-Track, cuatro veces al dia
0 1,7,13,19 * * * /usr/local/bin/ea-php83 /home/USUARIO/public_html/cdm_ingest.php >/dev/null 2>&1
```

Reemplazá `USUARIO` por tu usuario de cPanel. Usá horas explícitas como en el ejemplo y evitá expresiones del tipo barra seis dentro de comentarios de PHP, porque cierran el comentario.

---

<a id="es-verificacion"></a>
### 16. Lista de verificación post-despliegue
[↑](#top)

Probá cada pieza y mirá el JSON que devuelve.

| Prueba | Cómo | Esperado |
|---|---|---|
| Proxy NEO | abrir `neo.php?src=tle` | devuelve TLE |
| Ingesta TLE | `ingest.php` por cron o con su token | `{run, fetched, inserted, maneuvers}` |
| Lectura TLE | `tle.php?op=stats` | estadísticas |
| Relay de alerta | POST a `neo.php?src=alert` | `{ok, channels}` |
| Sesión | `auth.php?action=csrf` | `{csrf, user, needs_bootstrap}` |
| Ingesta CDM | `cdm_ingest.php?token=TU_TOKEN` | `{ok, fetched, upserted, escalated}` |
| Login | desde el chip de SSA o NEO | vuelve al módulo de origen |
| Modo base | abrir la cola de triage logueado | el header muestra BD compartido |

Si algo falla, los endpoints devuelven el error como JSON, lo que facilita el diagnóstico. Verificá también, contra una muestra real de tu cuenta de Space-Track, que la distancia mínima de los CDM esté en las unidades que asume el worker.

---

<a id="es-operacion"></a>
### 17. Operación diaria
[↑](#top)

El operador entra a SSA Visor o a NEO Watch. Si el header muestra el círculo vacío y la palabra ingresar, conviene loguearse para trabajar en modo compartido, lo que lleva al panel y devuelve a la herramienta tras el login. Con sesión iniciada, el chip muestra el nombre en verde y el rol.

En NEO Watch, la foto de hoy concentra lo accionable, con la acción recomendada arriba y el tasking de OALM por objeto. El calendario muestra qué viene por día con la ventana de oscuridad. En el SSA, la cola de triage, que se abre con el botón del cuadrito en el header, lista los incidentes por prioridad. Al hacer clic en una fila se abre el detalle con las cinco respuestas, la línea de tiempo auditada y los botones de transición válidos, más el botón para abrir el contexto 3D.

Las conjunciones que ingiere el worker de Space-Track aparecen en la cola del SSA como incidentes con su Pc real, y las críticas llegan ya escaladas y con aviso por Telegram. El administrador puede revisar el registro de actividad en el panel para ver logins, gestiones, transiciones y escalados automáticos.

---

<a id="es-validacion"></a>
### 18. Estado de validación
[↑](#top)

Los motores de lógica pura están probados ejecutándolos. El motor de incidentes pasó sus pruebas unitarias de severidad, prioridad, acción y ciclo de vida. La geometría del renderer 3D pasó sus pruebas. El motor de probabilidad de colisión pasó las mismas pruebas analíticas en JavaScript y en PHP, dando resultados idénticos. La lógica de autenticación, roles y ciclo de vida del lado servidor pasó sus pruebas en PHP.

Las integraciones se validaron de forma estructural, que es compilación sin errores, paridad de traducciones entre inglés y español, y consistencia de identificadores y claves. Todo el PHP pasa el chequeo de sintaxis.

Lo que debe verificar el operador en su entorno, porque no se puede probar desde fuera del servidor, es el renderizado visual en el navegador, el login real contra la base de datos, el ciclo completo de la cola en modo compartido, y la conexión y descarga reales contra Space-Track.

---

<a id="es-pendientes"></a>
### 19. Pendientes y hoja de ruta
[↑](#top)

El único pendiente real, y por dependencia externa y no de código, es recalcular la probabilidad de colisión desde covarianza cruda. Eso espera a conseguir CDM de operador o un feed comercial que provea covarianza. El motor de Foster ya está construido y probado en los dos lados, así que cuando exista la fuente, el trabajo es implementar y validar la proyección del marco RTN al plano de encuentro y enchufarla.

Otras mejoras posibles, no urgentes, son retirar las consolas anteriores de alertas y casos una vez que la cola de triage esté plenamente validada en operación, y, si se desea auditar también las sincronizaciones automáticas del front, agregarlas al registro de actividad con un filtro aparte para no tapar las acciones humanas.

---

[↑ Inicio](#top) · [🇬🇧 English](#english)

*OALM, Los Molinos, Uruguay · código MPC 844 · telescopio Astroworks Centurion 18 (457 mm) · latitud −34.7553, longitud −56.1881*
