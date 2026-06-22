-- schema.sql  -  Historia de TLE versionada + log de ingesta.
-- Importar en cPanel -> phpMyAdmin -> (seleccionar la BD) -> Importar.

CREATE TABLE IF NOT EXISTS tle_history (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  norad         INT UNSIGNED NOT NULL,
  name          VARCHAR(64)  NULL,
  epoch         DATETIME     NOT NULL,
  mean_motion   DOUBLE       NOT NULL,   -- rev/día
  inclination   DOUBLE       NOT NULL,   -- grados
  raan          DOUBLE       NOT NULL,   -- grados
  ecc           DOUBLE       NOT NULL,
  argp          DOUBLE       NOT NULL,   -- grados
  mean_anomaly  DOUBLE       NOT NULL,   -- grados
  bstar         DOUBLE       NOT NULL,
  ndot          DOUBLE       NOT NULL,   -- primera derivada del mov. medio
  rev           INT UNSIGNED NULL,
  line1         VARCHAR(80)  NOT NULL,
  line2         VARCHAR(80)  NOT NULL,
  dt_days       DOUBLE       NULL,       -- días desde el TLE anterior del mismo objeto
  d_inc         DOUBLE       NULL,       -- |Δ inclinación| vs anterior
  d_mm          DOUBLE       NULL,       -- Δ mov. medio vs anterior (con signo)
  maneuver      TINYINT(1)   NOT NULL DEFAULT 0,
  maneuver_score DOUBLE      NULL,
  reason        VARCHAR(160) NULL,
  source        VARCHAR(40)  NOT NULL DEFAULT 'celestrak',
  fetched_at    DATETIME     NOT NULL,
  prev_hash     CHAR(64)     NULL,       -- cadena de auditoría (tamper-evident)
  hash          CHAR(64)     NOT NULL,
  UNIQUE KEY uq_norad_epoch (norad, epoch),
  KEY idx_norad (norad),
  KEY idx_fetched (fetched_at),
  KEY idx_maneuver (maneuver, fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ingest_run (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  started_at  DATETIME NOT NULL,
  finished_at DATETIME NULL,
  groups      VARCHAR(160) NULL,
  fetched     INT DEFAULT 0,
  inserted    INT DEFAULT 0,
  maneuvers   INT DEFAULT 0,
  status      VARCHAR(40) DEFAULT 'ok',
  note        VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
