-- Fix database structure and add missing tables
USE stacknro_blog;

-- Ensure all tables have proper structure and default values
ALTER TABLE posts 
MODIFY COLUMN content LONGTEXT NOT NULL,
MODIFY COLUMN image VARCHAR(255) DEFAULT '',
MODIFY COLUMN hashtags TEXT DEFAULT '',
MODIFY COLUMN views INT DEFAULT 0,
MODIFY COLUMN status ENUM('draft', 'published') DEFAULT 'published';

-- Update existing posts to have default values where NULL
UPDATE posts SET 
    image = COALESCE(image, ''),
    hashtags = COALESCE(hashtags, ''),
    views = COALESCE(views, 0),
    status = COALESCE(status, 'published')
WHERE image IS NULL OR hashtags IS NULL OR views IS NULL OR status IS NULL;

-- Ensure users table has proper defaults
ALTER TABLE users 
MODIFY COLUMN avatar VARCHAR(255) DEFAULT 'default-avatar.png',
MODIFY COLUMN bio TEXT DEFAULT '',
ADD COLUMN IF NOT EXISTS location VARCHAR(100) DEFAULT '',
ADD COLUMN IF NOT EXISTS website VARCHAR(255) DEFAULT '';

-- Update existing users to have default values
UPDATE users SET 
    avatar = COALESCE(avatar, 'default-avatar.png'),
    bio = COALESCE(bio, ''),
    location = COALESCE(location, ''),
    website = COALESCE(website, '')
WHERE avatar IS NULL OR bio IS NULL OR location IS NULL OR website IS NULL;

-- Create newsletter table if not exists
CREATE TABLE IF NOT EXISTS newsletter_subscribers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) UNIQUE NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ensure all tables have proper indexes for performance
CREATE INDEX IF NOT EXISTS idx_posts_created_at ON posts(created_at);
CREATE INDEX IF NOT EXISTS idx_posts_author ON posts(author_id);
CREATE INDEX IF NOT EXISTS idx_posts_status ON posts(status);
CREATE INDEX IF NOT EXISTS idx_likes_post_user ON likes(post_id, user_id);
CREATE INDEX IF NOT EXISTS idx_comments_post ON comments(post_id);
CREATE INDEX IF NOT EXISTS idx_comments_user ON comments(user_id);
CREATE INDEX IF NOT EXISTS idx_chat_sender ON chat_messages(sender_id);
CREATE INDEX IF NOT EXISTS idx_chat_receiver ON chat_messages(receiver_id);
CREATE INDEX IF NOT EXISTS idx_chat_read ON chat_messages(is_read);
CREATE INDEX IF NOT EXISTS idx_user_sessions_token ON user_sessions(session_token);
CREATE INDEX IF NOT EXISTS idx_user_sessions_expires ON user_sessions(expires_at);
CREATE INDEX IF NOT EXISTS idx_newsletter_email ON newsletter_subscribers(email);
CREATE INDEX IF NOT EXISTS idx_newsletter_active ON newsletter_subscribers(is_active);

-- Clean up expired sessions
DELETE FROM user_sessions WHERE expires_at < NOW();

-- Clean up expired email verifications
DELETE FROM email_verifications WHERE expires_at < NOW();