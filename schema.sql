-- Run this once in cPanel → phpMyAdmin → autoseo_db → SQL tab

CREATE TABLE IF NOT EXISTS clients (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(255) UNIQUE NOT NULL,
    password_hash   VARCHAR(255) DEFAULT NULL,
    first_name      VARCHAR(100),
    last_name       VARCHAR(100),
    phone           VARCHAR(50),
    brand_name      VARCHAR(255),
    website_url     VARCHAR(500),
    location        VARCHAR(255),
    service_area    VARCHAR(50),
    keyphrase       VARCHAR(500),
    summary         TEXT,
    offers          JSON,
    competitors     JSON,
    wp_url          VARCHAR(500),
    wp_username     VARCHAR(255),
    wp_app_password VARCHAR(500),
    plan            VARCHAR(50)  DEFAULT 'trial',
    status          VARCHAR(50)  DEFAULT 'active',
    plan_tier              VARCHAR(50)  DEFAULT 'trial',
    max_keyphrases         TINYINT      DEFAULT 1,
    tracked_keyphrases     JSON         DEFAULT NULL,
    stripe_customer_id     VARCHAR(100) DEFAULT NULL,
    stripe_subscription_id VARCHAR(100) DEFAULT NULL,
    login_token     VARCHAR(64)  DEFAULT NULL,
    token_expires   DATETIME     DEFAULT NULL,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration (run if clients table already exists):
-- ALTER TABLE clients
--   ADD COLUMN stripe_customer_id VARCHAR(100) DEFAULT NULL AFTER plan,
--   ADD COLUMN stripe_subscription_id VARCHAR(100) DEFAULT NULL AFTER stripe_customer_id,
--   ADD COLUMN plan_tier VARCHAR(50) DEFAULT 'trial' AFTER plan,
--   ADD COLUMN max_keyphrases TINYINT DEFAULT 1 AFTER plan_tier,
--   ADD COLUMN tracked_keyphrases JSON DEFAULT NULL AFTER max_keyphrases;

CREATE TABLE IF NOT EXISTS articles (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    client_id      INT NOT NULL,
    title          VARCHAR(500),
    content        LONGTEXT,
    keyphrase      VARCHAR(500),
    meta_desc      VARCHAR(300),
    status         ENUM('draft','approved','published','failed') DEFAULT 'draft',
    scheduled_date DATE DEFAULT NULL,
    approved_at    DATETIME DEFAULT NULL,
    image_url      VARCHAR(500) DEFAULT NULL,
    wp_post_id     INT DEFAULT NULL,
    wp_post_url    VARCHAR(500) DEFAULT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    published_at   TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Run this if the articles table already exists (migration):
-- ALTER TABLE articles
--   ADD COLUMN scheduled_date DATE DEFAULT NULL AFTER meta_desc,
--   ADD COLUMN approved_at DATETIME DEFAULT NULL AFTER scheduled_date,
--   MODIFY COLUMN status ENUM('draft','approved','published','failed') DEFAULT 'draft';

CREATE TABLE IF NOT EXISTS keyphrases (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    client_id  INT NOT NULL,
    keyphrase  VARCHAR(500) NOT NULL,
    used       TINYINT(1)   DEFAULT 0,
    article_id INT          DEFAULT NULL,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration (run if tables already exist):
-- CREATE TABLE IF NOT EXISTS keyphrases (
--     id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NOT NULL,
--     keyphrase VARCHAR(500) NOT NULL, used TINYINT(1) DEFAULT 0,
--     article_id INT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
--     FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rankings (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    client_id  INT NOT NULL,
    keyphrase  VARCHAR(500) NOT NULL,
    position   SMALLINT     DEFAULT NULL,
    checked_at DATE         NOT NULL,
    UNIQUE KEY unique_check (client_id, keyphrase(200), checked_at),
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration (if rankings table doesn't exist yet):
-- CREATE TABLE IF NOT EXISTS rankings ( ... as above ... )

CREATE TABLE IF NOT EXISTS reports (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    client_id      INT NOT NULL,
    month          VARCHAR(7),
    summary        TEXT,
    keywords_json  JSON,
    articles_count INT DEFAULT 0,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
