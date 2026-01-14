-- =========================================
-- MIGRATION: Chatbot IA para GLPI
-- Data: 2026-01-13
-- =========================================

-- Verificar se tabelas já existem
SELECT 'Verificando tabelas existentes...' as status;

-- Criar tabela de conversas do chatbot
CREATE TABLE IF NOT EXISTS `glpi_chatbot_conversations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tickets_id` int unsigned NOT NULL,
  `users_id` int unsigned NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_bot` tinyint(1) NOT NULL DEFAULT 0,
  `suggested_kb_items` text COLLATE utf8mb4_unicode_ci,
  `feedback` tinyint(1) DEFAULT NULL,
  `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `tickets_id` (`tickets_id`),
  KEY `users_id` (`users_id`),
  KEY `date_creation` (`date_creation`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'Tabela glpi_chatbot_conversations criada/verificada' as status;

-- Criar tabela de feedback do chatbot
CREATE TABLE IF NOT EXISTS `glpi_chatbot_feedback` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `conversations_id` int unsigned NOT NULL,
  `was_helpful` tinyint(1) NOT NULL,
  `comment` text COLLATE utf8mb4_unicode_ci,
  `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `conversations_id` (`conversations_id`),
  KEY `was_helpful` (`was_helpful`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'Tabela glpi_chatbot_feedback criada/verificada' as status;

-- Verificar se foreign keys já existem antes de adicionar
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS 
                  WHERE CONSTRAINT_SCHEMA = DATABASE() 
                  AND TABLE_NAME = 'glpi_chatbot_conversations' 
                  AND CONSTRAINT_NAME = 'fk_chatbot_conversations_tickets');

-- Adicionar foreign keys apenas se não existirem
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `glpi_chatbot_conversations`
     ADD CONSTRAINT `fk_chatbot_conversations_tickets` 
       FOREIGN KEY (`tickets_id`) REFERENCES `glpi_tickets` (`id`) ON DELETE CASCADE,
     ADD CONSTRAINT `fk_chatbot_conversations_users` 
       FOREIGN KEY (`users_id`) REFERENCES `glpi_users` (`id`) ON DELETE CASCADE',
    'SELECT "Foreign keys já existem para glpi_chatbot_conversations" as status');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists2 = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS 
                   WHERE CONSTRAINT_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 'glpi_chatbot_feedback' 
                   AND CONSTRAINT_NAME = 'fk_chatbot_feedback_conversations');

SET @sql2 = IF(@fk_exists2 = 0,
    'ALTER TABLE `glpi_chatbot_feedback`
     ADD CONSTRAINT `fk_chatbot_feedback_conversations` 
       FOREIGN KEY (`conversations_id`) REFERENCES `glpi_chatbot_conversations` (`id`) ON DELETE CASCADE',
    'SELECT "Foreign keys já existem para glpi_chatbot_feedback" as status');

PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- Verificar criação
SELECT 'Migration concluída com sucesso!' as status;
SELECT COUNT(*) as total_conversations FROM glpi_chatbot_conversations;
SELECT COUNT(*) as total_feedback FROM glpi_chatbot_feedback;
