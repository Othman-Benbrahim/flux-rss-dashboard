-- schema.sql — structure complète de la base.
-- Aucun identifiant, aucun contenu : ce fichier est versionnable.
-- Import : mysql -h <hote> -u <utilisateur> -p <base> < schema.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(50)  NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at`    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `user_settings` (
  `id`                   INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`              INT(11)      NOT NULL,
  `background_image_url` VARCHAR(255) DEFAULT NULL,
  `background_color`     VARCHAR(32)  DEFAULT NULL,
  `custom_colors`        TEXT         DEFAULT NULL,
  `created_at`           TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  CONSTRAINT `fk_user_settings` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tabs` (
  `id`        INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`   INT(11)      NOT NULL,
  `title`     VARCHAR(255) NOT NULL,
  `tab_order` INT(11)      NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `fk_user_tab` (`user_id`),
  CONSTRAINT `fk_user_tab` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `widgets` (
  `id`         INT(11)     NOT NULL AUTO_INCREMENT,
  `user_id`    INT(11)     NOT NULL,
  `tab_id`     INT(11)     DEFAULT NULL,
  `type`       VARCHAR(30) NOT NULL,
  `x`          INT(11)     NOT NULL DEFAULT 0,
  `y`          INT(11)     NOT NULL DEFAULT 0,
  `w`          INT(11)     NOT NULL DEFAULT 2,
  `h`          INT(11)     NOT NULL DEFAULT 2,
  `settings`   LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
               CHECK (json_valid(`settings`)),
  `created_at` TIMESTAMP   NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP   NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_user_widget` (`user_id`),
  KEY `idx_widget_tab` (`tab_id`),
  CONSTRAINT `fk_user_widget` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tab_widget` FOREIGN KEY (`tab_id`) REFERENCES `tabs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `rss_cache` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `feed_url`     VARCHAR(255) NOT NULL,
  `content_json` LONGTEXT     NOT NULL,
  `last_fetched` TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `feed_url` (`feed_url`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `article_previews_cache` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `article_url`  VARCHAR(255) NOT NULL,
  `title`        VARCHAR(255) DEFAULT NULL,
  `summary`      LONGTEXT     DEFAULT NULL,
  `image_url`    VARCHAR(255) DEFAULT NULL,
  `last_fetched` TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `article_url` (`article_url`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `read_articles` (
  `id`       INT(11)  NOT NULL AUTO_INCREMENT,
  `user_id`  INT(11)  NOT NULL,
  `url_hash` CHAR(64) NOT NULL,
  `read_at`  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_url` (`user_id`, `url_hash`),
  KEY `idx_read_at` (`read_at`),
  CONSTRAINT `fk_user_read` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
