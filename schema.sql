-- ipwho.am Database Schema
-- MariaDB 10.6+

CREATE DATABASE IF NOT EXISTS ipwhoam CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ipwhoam;

-- Every HTTP request gets logged here
CREATE TABLE IF NOT EXISTS visits (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip          VARCHAR(45)  NOT NULL,
    ip_decimal  BIGINT UNSIGNED NULL,
    endpoint    VARCHAR(255) NOT NULL DEFAULT '/',
    method      VARCHAR(10)  NOT NULL DEFAULT 'GET',
    user_agent  TEXT,
    referer     VARCHAR(2048),
    is_browser  TINYINT(1)   NOT NULL DEFAULT 0,  -- 1 = browser, 0 = cli/api
    is_api      TINYINT(1)   NOT NULL DEFAULT 0,  -- 1 = explicit JSON/API request
    country_code CHAR(2),
    country_name VARCHAR(100),
    city        VARCHAR(100),
    region      VARCHAR(100),
    org         VARCHAR(255),
    asn         VARCHAR(20),
    latitude    DECIMAL(9,6),
    longitude   DECIMAL(9,6),
    timezone    VARCHAR(60),
    response_ms SMALLINT UNSIGNED,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created  (created_at),
    INDEX idx_ip       (ip),
    INDEX idx_country  (country_code),
    INDEX idx_endpoint (endpoint),
    INDEX idx_browser  (is_browser),
    INDEX idx_api      (is_api)
) ENGINE=InnoDB;

-- Geo-data cache so we don't hammer ipapi.co
CREATE TABLE IF NOT EXISTS geo_cache (
    ip          VARCHAR(45)  PRIMARY KEY,
    data        JSON         NOT NULL,
    hits        INT UNSIGNED NOT NULL DEFAULT 1,
    cached_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at  DATETIME     NOT NULL,
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB;

-- Daily aggregates (filled by a nightly job / on-the-fly)
CREATE TABLE IF NOT EXISTS daily_stats (
    stat_date       DATE         PRIMARY KEY,
    web_hits        INT UNSIGNED NOT NULL DEFAULT 0,
    api_hits        INT UNSIGNED NOT NULL DEFAULT 0,
    cli_hits        INT UNSIGNED NOT NULL DEFAULT 0,
    unique_ips      INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
