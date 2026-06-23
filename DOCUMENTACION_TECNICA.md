<a id="top"></a>
# newspace.live · Documentación técnica integral
## SSA / SDA / SST — cobertura, componentes, fuentes, validaciones, controles y consideraciones

Este documento describe el estado del sistema tras el Tramo 1. Está pensado para que un externo, un analista o un operador entienda qué hace cada pieza, de dónde salen los datos, cómo se validó, qué controles y controles cruzados tiene, y cuáles son sus límites de exactitud. Cubre todo el sistema con foco en lo agregado en el Tramo 1.

El sistema cubre la visión de SSA de la ESA en sus tres áreas, vigilancia y seguimiento (SST) con sus tres servicios, objetos cercanos (NEO), y clima espacial (SWE), más una capa de conciencia del dominio espacial (SDA). Todo el Tramo 1 corre sobre datos públicos, sin depender de sensores propios ni de mensajes de conjunción de operador.

---

## Índice

1. [Matriz de cobertura](#cobertura)
2. [Arquitectura en una página](#arq)
3. [Inventario de componentes](#inv)
4. [Fuentes de datos](#fuentes)
5. [Servicios del Tramo 1 en detalle](#servicios)
6. [Validaciones](#validaciones)
7. [Controles y controles cruzados](#controles)
8. [Consideraciones y salvedades honestas](#salvedades)
9. [Despliegue: novedades del Tramo 1](#despliegue)
10. [Licencia y modelo comercial](#licencia)

---

<a id="cobertura"></a>
## 1. Matriz de cobertura
[↑](#top)

| Área ESA / capa | Servicio | Estado tras Tramo 1 | Fuente |
|---|---|---|---|
| SST | Prevención de colisión | Operativo | CelesTrak SOCRATES Plus |
| SST | Análisis de reentrada | Operativo (estimación) | CelesTrak GP |
| SST | Análisis de fragmentación | Operativo (bajo demanda) | Modelo NASA + catálogo GP |
| NEO | Monitoreo de riesgo | Operativo | NeoWs, CAD, Sentry, ESA |
| NEO | Respuesta rápida | Operativo | MPC NEOCP |
| SWE | Clima espacial | Integrado al modelo de incidentes | NOAA SWPC |
| SWE | Pronóstico | Integrador, no generador | Terceros |
| SDA | Caracterización de maniobras | Operativo (indicio) | Historia de TLE |
| SDA | Caracterización física | Pendiente (necesita sensores) | Fotometría futura |

Todo lo marcado operativo se alimenta de datos públicos y aparece como incidente en la cola de triage, con severidad, prioridad, acción recomendada, auditoría y panel de exactitud.

---

<a id="arq"></a>
## 2. Arquitectura en una página
[↑](#top)

Tres capas. Presentación, páginas HTML autocontenidas en el navegador (SSA Visor, NEO Watch, Admin). Lógica compartida, módulos JavaScript puros (motor de incidentes, contexto 3D, motor de probabilidad de colisión). Backend opcional, PHP 8.3 con MySQL en cPanel, que aporta autenticación, roles, persistencia compartida de incidentes con auditoría, los workers de ingesta, y los relays de aviso.

Los workers de ingesta corren por cron, bajan cada fuente, computan severidad y prioridad, y escriben incidentes en la tabla `app_incident`. Las dos herramientas, cuando hay sesión iniciada, leen esa tabla y muestran todo en una cola unificada. Sin sesión, caen a almacenamiento local del navegador para uso público.

```
CelesTrak GP ────► ingest.php / reentry_ingest.php / fragmentation.php ─┐
CelesTrak SOCRATES ► socrates_ingest.php ──────────────────────────────┤
NOAA SWPC ─────────► swe_ingest.php ────────────────────────────────────┼──► MySQL app_incident ──► cola de triage
MPC NEOCP ─────────► neocp_ingest.php ──────────────────────────────────┤        (SSA Visor / NEO Watch)
tle_history ───────► sda_ingest.php ────────────────────────────────────┘            └─► Telegram (escalados)
```

---

<a id="inv"></a>
## 3. Inventario de componentes
[↑](#top)

**Front**
- `ssa_visor.html` visor SSA, cola de triage, contexto 3D, modal de fragmentación, panel de exactitud, chip de sesión, bilingüe.
- `neo_watch.html` NEO Watch, foto de hoy, calendario, respuesta rápida NEOCP, panel de exactitud, bilingüe.
- `admin.html` login, gestión de usuarios, roles, registro de actividad.

**Motores compartidos (JavaScript puro)**
- `incident_engine.js` severidad, prioridad, acción, ciclo de vida, auditoría.
- `context3d.js` renderer 3D de contexto.
- `pc_engine.js` probabilidad de colisión 2D de Foster.

**Backend, núcleo**
- `config.sample.php`, `db.php`, `lib_auth.php`, `auth.php`, `users.php`, `incidents.php`.
- `schema.sql`, `schema_auth.sql`.

**Backend, librerías puras del Tramo 1 (PHP)**
- `pc_lib.php` Pc de Foster en PHP.
- `socrates_lib.php` parseo y mapeo de conjunciones SOCRATES (JSON o CSV).
- `swe_lib.php` escalas NOAA G/R/S a severidad e impacto.
- `reentry_lib.php` perigeo, vida útil, huella, solapamiento con área de interés.
- `fragmentation_lib.php` modelo de ruptura estándar de la NASA.
- `neocp_lib.php` observabilidad por altura de culminación.
- `sda_lib.php` caracterización de maniobras.

**Backend, workers y endpoints del Tramo 1**
- `socrates_ingest.php` prevención de colisión.
- `swe_ingest.php` clima espacial.
- `reentry_ingest.php` reentrada.
- `fragmentation.php` análisis de fragmentación bajo demanda.
- `neocp_ingest.php` respuesta rápida NEO.
- `sda_ingest.php` caracterización de maniobras.
- `ingest.php`, `tle.php`, `neo.php` ingesta y lectura de TLE y proxy NEO, preexistentes.

---

<a id="fuentes"></a>
## 4. Fuentes de datos
[↑](#top)

| Fuente | Qué aporta | Acceso | Usada por |
|---|---|---|---|
| CelesTrak GP (OMM/TLE) | Elementos orbitales del catálogo | Público, sin login | ingest.php, reentry, fragmentación |
| CelesTrak SOCRATES Plus | Conjunciones cribadas sobre elementos públicos, CSV | Público | socrates_ingest.php |
| NOAA SWPC (noaa-scales.json) | Escalas G/R/S de clima espacial | Público | swe_ingest.php |
| MPC NEOCP (neocp.json) | Descubrimientos nuevos sin confirmar | Público | neocp_ingest.php |
| NASA NeoWs, JPL CAD y Sentry, ESA | Aproximaciones y riesgo de impacto NEO | Público, vía proxy neo.php | NEO Watch |
| Historia de TLE (tle_history) | Serie temporal de elementos para detectar maniobras | Interna, construida por ingest.php | sda_ingest.php |
| Open-Meteo | Geocodificación de ciudades para el observador | Público | SSA Visor |
| Telegram Bot API | Relay de avisos de escalado | Token en config | todos los workers |
| satellite.js (SGP4) | Propagación de órbitas en el navegador | CDN | SSA Visor, contexto 3D |
| Space-Track (cdm_public) | CDM oficiales, opcional | Cuenta gratuita | No usada en el camino del Tramo 1 |

La fuente más valiosa, las observaciones propias del observatorio, no se baja de ningún lado y queda para una etapa de nodo sensor. El camino del CDM de operador quedó fuera del Tramo 1 a pedido, y SOCRATES lo sustituye.

---

<a id="servicios"></a>
## 5. Servicios del Tramo 1 en detalle
[↑](#top)

### 5.1 Prevención de colisión (SOCRATES)

Baja el CSV de SOCRATES Plus, que trae once columnas, los dos objetos con su catálogo y nombre, los días desde la época de cada uno (DSE), el TCA, la distancia mínima, la velocidad relativa, la probabilidad máxima y el umbral de dilución. El parser es agnóstico, detecta JSON o CSV y arma cada fila por nombre de columna con tolerancia a variantes. Por cada conjunción crea un incidente con severidad por banda de probabilidad, métrica con distancia, probabilidad máxima, velocidad y DSE, y auto-escala con aviso las que superan el umbral crítico. Un filtro de relevancia, probabilidad mínima o distancia máxima, evita inundar la cola con las miles de conjunciones del CSV crudo. La probabilidad de SOCRATES es la máxima, un techo conservador con la peor orientación de la elipse de covarianza, no la Pc real, y queda rotulada como tal.

### 5.2 Clima espacial (NOAA SWPC)

Baja las escalas G, R y S de NOAA, que van de cero a cinco. Geomagnética por el índice Kp, radiación solar, y apagón de radio por fulguraciones de rayos X. Cada escala vigente desde un umbral configurable crea un incidente con severidad mapeada de la escala y con el impacto operativo traducido, degradación de GNSS, radio HF, arrastre en órbita baja, riesgo para satélites. Auto-escala los eventos severos. El sistema integra y traduce a impacto, no genera modelos ni pronósticos.

### 5.3 Análisis de reentrada (CelesTrak GP)

Baja un grupo GP, calcula el perigeo de cada objeto a partir del movimiento medio y la excentricidad, y para los de perigeo bajo crea un incidente con una banda de vida útil estimada, una huella como banda de latitud según la inclinación, y un indicador de si esa huella cruza el área de interés. La severidad sube por perigeo bajo y por cruce del área de interés. Es estimación de orden de magnitud, no hora ni punto de impacto precisos. La ventana real depende de la densidad atmosférica, la actitud y la relación área masa.

### 5.4 Análisis de fragmentación (modelo NASA)

Implementa el modelo de ruptura estándar de la NASA. Da el número acumulado de fragmentos por tamaño, con la fórmula de explosión y la de colisión, evalúa si una colisión es catastrófica por el umbral de energía sobre masa, calcula la masa característica, y estima la banda de altitud afectada por la dispersión de velocidad. Opcionalmente criba el catálogo para contar los activos cuyo rango de altitud e inclinación cruzan la banda afectada. Se dispara bajo demanda desde un modal en el SSA, y crear el incidente requiere permiso de trabajo. Es un modelo de poblaciones de fragmentos y de la región orbital afectada, no una simulación de cada fragmento ni la propagación de la nube.

### 5.5 Respuesta rápida NEO (MPC NEOCP)

Baja la página de confirmación del MPC, los descubrimientos nuevos sin confirmar. Por cada candidato evalúa si el sitio observador puede verlo con la altura de culminación, le asigna prioridad por el puntaje NEO del MPC, y recomienda observar si es alcanzable con el telescopio o monitorear si no. Avisa cuando aparece un objetivo de alto puntaje observable. La altura de culminación es la altura máxima posible desde el sitio, condición necesaria de observabilidad, no una solución de apuntado ni una ventana horaria.

### 5.6 Caracterización de maniobras (SDA)

Lee el último cambio de cada objeto en la historia de TLE, le descuenta el arrastre usando la derivada del movimiento medio, y caracteriza la maniobra. Estima el delta-v en metros por segundo, el cambio de semieje y de inclinación, clasifica el tipo, subida, bajada, cambio de plano o combinada, y para objetos geoestacionarios calcula la deriva. Un cambio de plano sube la severidad porque es caro y deliberado. Lo inferido de elementos públicos es indicio, no certeza, un cambio puede ser maniobra, error del elemento o inicio de reentrada, y nunca se afirma intención de forma automática.

### 5.7 Panel de exactitud y método

En el detalle de cada incidente, en los dos módulos, un panel desplegable explica según la fuente qué significan los números y cuál es su límite. Es la traducción a lenguaje claro de las salvedades, para que el usuario entienda y el analista decida mejor. Bilingüe.

---

<a id="validaciones"></a>
## 6. Validaciones
[↑](#top)

Las librerías de lógica pura se prueban ejecutándolas con casos de referencia. Las integraciones se validan de forma estructural. Lo que depende de feeds reales y base de datos lo verifica el operador.

**Pruebas unitarias de lógica pura**

| Componente | Pruebas | Qué verifica |
|---|---|---|
| `incident_engine.js` | 36/36 y 10/10 | severidad, prioridad, acción, ciclo de vida |
| `context3d.js` | 7/7 | geometría del renderer |
| `pc_engine.js` (Pc JS) | 6/6 | Foster contra casos analíticos |
| `pc_lib.php` (Pc PHP) | 6/6 | Foster contra los mismos casos |
| `lib_auth.php` | 10/10 | roles, ciclo de vida, CSRF |
| `socrates_lib.php` | 7/7, CSV 5/5, encabezado oficial 8/8 | mapeo, parser, DSE, dilución, filtro |
| `swe_lib.php` | 4/4 | escala a severidad e impacto |
| `reentry_lib.php` | 10/10 | perigeo, vida útil, huella, área de interés |
| `fragmentation_lib.php` | 9/9 | modelo NASA contra valores publicados |
| `neocp_lib.php` | 10/10 | culminación, observabilidad, acción |
| `sda_lib.php` | 10/10 | delta-v, tipo, cambio de plano, deriva GEO |

**Validación estructural**

Todo el PHP pasa el chequeo de sintaxis. Los tres front compilan. Las traducciones tienen paridad exacta entre inglés y español, el SSA con 363 claves parejas. Los identificadores de elementos y las claves de traducción son consistentes. Los workers, corridos sin entorno, fallan limpio en cada chequeo, credenciales, cURL, base de datos.

**Verificación del operador**

Lo que no se puede probar desde fuera del servidor, el renderizado en el navegador, el login real contra la base, el ciclo completo de la cola en modo compartido, y la conexión y descarga reales contra cada feed. Para esto último cada worker de ingesta trae un modo de prueba en seco.

---

<a id="controles"></a>
## 7. Controles y controles cruzados
[↑](#top)

**Controles cruzados de cálculo.** El motor de probabilidad de colisión está en JavaScript y en PHP, y los dos pasan las mismas pruebas analíticas con resultados idénticos, lo que cruza el cálculo del cliente contra el del servidor. En la caracterización de maniobras, el cambio de movimiento medio se cruza contra el arrastre esperado por la derivada del elemento, para separar maniobra de decaimiento.

**Controles de calidad del dato.** SOCRATES expone los días desde la época, DSE, que se muestra en cada incidente como medida de cuán fresca y confiable es la predicción. La banda de severidad usa la probabilidad máxima, rotulada como techo conservador. El filtro de relevancia, probabilidad mínima o distancia máxima, controla el ruido del CSV crudo.

**Controles de verificación previa.** Cada worker de ingesta tiene un modo de prueba en seco que baja y parsea sin escribir en la base, para confirmar el formato real de la fuente antes de operar. Los parsers de fuente externa toleran variantes de nombres de campo y muestran las claves crudas en la prueba en seco.

**Controles de integridad.** La historia de TLE encadena un hash por registro, a prueba de manipulación. En la base, la actualización de un incidente preserva la probabilidad cargada por el worker para que un upsert del front sin probabilidad no la borre. Los identificadores de incidente son idempotentes y agrupan, lo que deduplica.

**Controles de acceso.** Autenticación con hash bcrypt, contraseñas de al menos diez caracteres, límite de intentos de login por IP y correo, token CSRF en las acciones que cambian estado, cookie de sesión con httponly y samesite, y control de acceso por rol, donde crear o transicionar incidentes y crear un escenario de fragmentación requieren rol de trabajo. El retorno tras login solo acepta un archivo de la misma carpeta. Los workers están protegidos por token de ingesta. El archivo de configuración queda bloqueado por reglas del servidor.

**Controles de separación de responsabilidades.** El cliente calcula severidad, prioridad y acción con el motor compartido. La base manda en estado, responsable, marcas de tiempo y auditoría. Cada transición y cada acción significativa queda en la auditoría del incidente y en el registro de actividad.

**Controles físicos de admisión.** La reentrada usa el solapamiento de la huella con el área de interés y un umbral de perigeo. La respuesta rápida NEO usa la altura de culminación como condición de observabilidad. La fragmentación usa una tolerancia de inclinación para cribar activos. Todos rotulados como condiciones necesarias, no soluciones completas.

**Controles de construcción.** El chequeo de sintaxis del PHP, la compilación de los front, y la verificación de paridad de traducciones y de consistencia de identificadores se corren en cada cambio.

---

<a id="salvedades"></a>
## 8. Consideraciones y salvedades honestas
[↑](#top)

El sistema, sin sensores propios ni covarianza de operador, es una capa de fusión, decisión y alojamiento soberano sobre datos públicos. Su exactitud está acotada por esos datos.

La probabilidad de SOCRATES es de cribado y máxima, no una Pc real con covarianza. La severidad de reentrada y la fragmentación son estimaciones de orden de magnitud, con incertidumbre real, no trayectorias ni puntos de impacto. En clima espacial el sistema integra y traduce a impacto, no genera modelos ni pronósticos. La caracterización de maniobras es indicio sobre elementos públicos, no certeza ni intención. La altura de culminación y la ventana de oscuridad son condiciones necesarias de observabilidad, no soluciones de apuntado. La precisión de la propagación se degrada con la edad del elemento. Las huellas de países distintos de Uruguay son aproximaciones por caja. Las tipografías dependen de Google Fonts y los emoji de bandera pueden verse como letras en algunos Windows.

Dos avisos con peso. La caracterización de comportamiento de SDA es la capa más sensible en control de exportaciones, por ser tecnología de doble uso, así que antes de ofrecerla fuera de Uruguay conviene asesoría legal específica. Y CelesTrak deja de proveer formato TLE para objetos nuevos alrededor de mediados de 2026, lo que obligará a migrar la ingesta de TLE al formato GP en CSV u OMM, algo a tener en el radar.

---

<a id="despliegue"></a>
## 9. Despliegue: novedades del Tramo 1
[↑](#top)

No hace falta tocar el esquema de la base, porque todos los servicios nuevos usan la tabla de incidentes que ya existe.

Archivos nuevos a subir, las librerías `pc_lib.php`, `socrates_lib.php`, `swe_lib.php`, `reentry_lib.php`, `fragmentation_lib.php`, `neocp_lib.php`, `sda_lib.php`, los workers `socrates_ingest.php`, `swe_ingest.php`, `reentry_ingest.php`, `neocp_ingest.php`, `sda_ingest.php`, el endpoint `fragmentation.php`, y los front `ssa_visor.html` y `neo_watch.html` actualizados.

En `config.php` se agregan cinco bloques nuevos, `socrates`, `swe`, `reentry`, `neocp` y `sda`. Traen valores por defecto seguros y ningún secreto. No reemplaces tu configuración entera, copiá solo esos bloques desde `config.sample.php` antes del corchete de cierre.

Antes de cada cron, corré la prueba en seco del worker correspondiente para confirmar el formato real de la fuente. Los cron sugeridos están en la cabecera de cada worker, con horas explícitas para no romper los comentarios de PHP.

---

<a id="licencia"></a>
## 10. Licencia y modelo comercial
[↑](#top)

Con licencia MIT el código no es el activo defendible. El valor está en la marca, los servicios de instalación y acreditación, el soporte, la operación y la integración soberana. Para un comprador de defensa, lo que vende es la soberanía del alojamiento, la capa de decisión que unifica todo en una cola auditada, el mapeo a marcos de seguridad, y la transparencia de un sistema abierto y auditable línea por línea. La determinación de órbita independiente con sensores propios y la Pc real con covarianza quedan como extensiones premium futuras, no como requisito del núcleo.

---

[↑ Volver arriba](#top)

*OALM, Los Molinos, Uruguay · código MPC 844 · telescopio Astroworks Centurion 18 (457 mm) · latitud −34.7553, longitud −56.1881*
