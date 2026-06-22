-- schema_auth.sql  -  Fase 4: usuarios, roles, incidentes persistidos y auditoría.
-- Importar en cPanel -> phpMyAdmin -> (seleccionar la BD) -> Importar.
-- Convive con schema.sql (tle_history, ingest_run).

CREATE TABLE IF NOT EXISTS app_user (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email       VARCHAR(190) NOT NULL,
  name        VARCHAR(120) NOT NULL,
  pass_hash   VARCHAR(255) NOT NULL,
  role        ENUM('admin','analyst','operator','viewer') NOT NULL DEFAULT 'viewer',
  active      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL,
  last_login  DATETIME     NULL,
  UNIQUE KEY uq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS app_login_attempt (
  id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email     VARCHAR(190) NULL,
  ip        VARCHAR(45)  NULL,
  ok        TINYINT(1)   NOT NULL DEFAULT 0,
  ts        DATETIME     NOT NULL,
  KEY idx_email_ts (email, ts),
  KEY idx_ip_ts (ip, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Incidentes compartidos (mismo modelo que incident_engine.js, ahora del lado servidor).
CREATE TABLE IF NOT EXISTS app_incident (
  id            VARCHAR(160) PRIMARY KEY,           -- domain:object
  domain        ENUM('ssa','neo') NOT NULL,
  object        VARCHAR(120) NOT NULL,
  kind          VARCHAR(40)  NULL,
  severity      ENUM('critical','high','elevated','routine') NOT NULL DEFAULT 'routine',
  priority      DOUBLE       NULL,
  recommended_action VARCHAR(20) NULL,
  state         ENUM('new','acknowledged','in_analysis','escalated','tasked','resolved','closed') NOT NULL DEFAULT 'new',
  owner_id      BIGINT UNSIGNED NULL,
  metric        VARCHAR(255) NULL,
  tca_utc       DATETIME     NULL,
  torino        INT          NULL,
  palermo       DOUBLE       NULL,
  obsv          VARCHAR(16)  NULL,
  pc            DOUBLE       NULL,                  -- probabilidad de colisión (cuando hay covarianza)
  opened_utc    DATETIME     NOT NULL,
  ack_utc       DATETIME     NULL,
  resolved_utc  DATETIME     NULL,
  closed_utc    DATETIME     NULL,
  updated_at    DATETIME     NOT NULL,
  KEY idx_state (domain, state),
  KEY idx_priority (priority),
  CONSTRAINT fk_owner FOREIGN KEY (owner_id) REFERENCES app_user(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS app_incident_audit (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  incident_id VARCHAR(160) NOT NULL,
  ts          DATETIME     NOT NULL,
  actor_id    BIGINT UNSIGNED NULL,
  actor_name  VARCHAR(120) NULL,
  from_state  VARCHAR(20)  NULL,
  to_state    VARCHAR(20)  NULL,
  note        VARCHAR(255) NULL,
  KEY idx_incident (incident_id, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Registro de actividad de usuarios (login, gestión, transiciones, escalados).
CREATE TABLE IF NOT EXISTS app_activity (
  id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id   BIGINT UNSIGNED NULL,
  user_name VARCHAR(120) NULL,
  action    VARCHAR(40)  NOT NULL,
  detail    VARCHAR(255) NULL,
  ip        VARCHAR(45)  NULL,
  ts        DATETIME     NOT NULL,
  KEY idx_user_ts (user_id, ts),
  KEY idx_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
