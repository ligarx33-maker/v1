<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../xato.log');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    logSuccess("CORS preflight request handled");
    exit(0);
}

try {
    logSuccess("API request started", [
        'method' => $_SERVER['REQUEST_METHOD'],
        'action' => $_GET['action'] ?? 'none',
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
    session_start();
    require_once 'config.php';
    require_once 'email-verification.php';

    $db = new Database();
    $pdo = $db->getConnection();
    $emailVerification = new EmailVerification($pdo);

    $method = $_SERVER['REQUEST_METHOD'];
    $request = $_GET['action'] ?? '';
    
    logSuccess("Processing request", ['action' => $request, 'method' => $method]);

    switch ($request) {
        case 'register':
            if ($method === 'POST') {
                logSuccess("Register request received");
                $data = json_decode(file_get_contents('php://input'), true);
                
                if (!$data) {
                    logError("Invalid JSON data received for registration");
                    jsonResponse(['success' => false, 'message' => 'Noto\'g\'ri ma\'lumot formati']);
                }
                
                $username = sanitizeInput($data['username']);
                $email = sanitizeInput($data['email']);
                $password = $data['password'];
                $captcha = $data['captcha'];
                
                logSuccess("Registration data processed", ['username' => $username, 'email' => $email]);
                
                // Validate captcha
                if (!validateCaptcha($captcha, $_SESSION['captcha'] ?? '')) {
                    logError("Captcha validation failed", ['provided' => $captcha, 'expected' => $_SESSION['captcha'] ?? '']);
                    jsonResponse(['success' => false, 'message' => 'Captcha noto\'g\'ri']);
                }
                
                // Validate email
                if (!validateEmail($email)) {
                    logError("Email validation failed", ['email' => $email]);
                    jsonResponse(['success' => false, 'message' => 'Faqat @gmail.com manzillari qabul qilinadi']);
                }
                
                // Check if user exists
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
                $stmt->execute([$username, $email]);
                if ($stmt->fetch()) {
                    logError("User already exists", ['username' => $username, 'email' => $email]);
                    jsonResponse(['success' => false, 'message' => 'Foydalanuvchi yoki email allaqachon mavjud']);
                }
                
                // Send verification email
                $result = $emailVerification->sendVerificationEmail($email, 'registration');
                if ($result['success']) {
                    // Store user data temporarily
                    $_SESSION['temp_user'] = [
                        'username' => $username,
                        'email' => $email,
                        'password' => password_hash($password, PASSWORD_DEFAULT)
                    ];
                    logSuccess("Registration email sent", ['email' => $email]);
                    jsonResponse(['success' => true, 'message' => 'Tasdiqlash kodi emailingizga yuborildi']);
                } else {
                    logError("Email sending failed", $result);
                    jsonResponse(['success' => false, 'message' => $result['message']]);
                }
            }
            break;
            
        case 'verify-email':
            if ($method === 'POST') {
                logSuccess("Email verification request received");
                $data = json_decode(file_get_contents('php://input'), true);
                
                if (!$data) {
                    logError("Invalid JSON data for email verification");
                    jsonResponse(['success' => false, 'message' => 'Noto\'g\'ri ma\'lumot formati']);
                }
                
                $code = $data['code'];
                $email = $_SESSION['temp_user']['email'] ?? '';
                
                if (!$email) {
                    logError("No temp user email found in session");
                    jsonResponse(['success' => false, 'message' => 'Sessiya muddati tugagan']);
                }
                
                $result = $emailVerification->verifyCode($email, $code, 'registration');
                if ($result['success']) {
                    // Create user account
                    $tempUser = $_SESSION['temp_user'];
                    $stmt = $pdo->prepare("INSERT INTO users (username, email, password, is_verified) VALUES (?, ?, ?, 1)");
                    $stmt->execute([$tempUser['username'], $tempUser['email'], $tempUser['password']]);
                    
                    unset($_SESSION['temp_user']);
                    logSuccess("User registration completed", ['email' => $email]);
                    jsonResponse(['success' => true, 'message' => 'Ro\'yxatdan o\'tish muvaffaqiyatli yakunlandi!']);
                } else {
                    logError("Email verification failed", $result);
                    jsonResponse(['success' => false, 'message' => $result['message']]);
                }
            }
            break;
            
        case 'login':
            if ($method === 'POST') {
                logSuccess("Login request received");
                $data = json_decode(file_get_contents('php://input'), true);
                
                if (!$data) {
                    logError("Invalid JSON data for login");
                    jsonResponse(['success' => false, 'message' => 'Noto\'g\'ri ma\'lumot formati']);
                }
                
                $email = sanitizeInput($data['email']);
                $password = $data['password'];
                $captcha = $data['captcha'];
                
                logSuccess("Login attempt", ['email' => $email]);
                
                // Validate captcha
                if (!validateCaptcha($captcha, $_SESSION['captcha'] ?? '')) {
                    logError("Login captcha validation failed", ['provided' => $captcha, 'expected' => $_SESSION['captcha'] ?? '']);
                    jsonResponse(['success' => false, 'message' => 'Captcha noto\'g\'ri']);
                }
                
                $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_verified = 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                
                if ($user && password_verify($password, $user['password'])) {
                    // Create session
                    $sessionToken = generateSecureToken();
                    $expiresAt = date('Y-m-d H:i:s', time() + 86400); // 24 hours
                    
                    $stmt = $pdo->prepare("INSERT INTO user_sessions (user_id, session_token, expires_at) VALUES (?, ?, ?)");
                    $stmt->execute([$user['id'], $sessionToken, $expiresAt]);
                    
                    unset($user['password']);
                    logSuccess("User logged in successfully", ['user_id' => $user['id'], 'email' => $email]);
                    jsonResponse([
                        'success' => true,
                        'message' => 'Muvaffaqiyatli tizimga kirdingiz!',
                        'user' => $user,
                        'token' => $sessionToken
                    ]);
                } else {
                    logError("Login failed - invalid credentials", ['email' => $email]);
                    jsonResponse(['success' => false, 'message' => 'Email yoki parol noto\'g\'ri']);
                }
            }
            break;
            
        case 'posts':
            if ($method === 'GET') {
                logSuccess("Posts request received");
                $page = (int)($_GET['page'] ?? 1);
                $limit = (int)($_GET['limit'] ?? 10);
                $search = $_GET['search'] ?? '';
                $category = $_GET['category'] ?? '';
                $offset = ($page - 1) * $limit;
                
                logSuccess("Posts query parameters", ['page' => $page, 'limit' => $limit, 'search' => $search, 'category' => $category]);
                
                $whereClause = "WHERE 1=1";
                $params = [];
                
                if ($search) {
                    $whereClause .= " AND (p.title LIKE ? OR p.content LIKE ? OR p.hashtags LIKE ?)";
                    $searchTerm = "%$search%";
                    $params[] = $searchTerm;
                    $params[] = $searchTerm;
                    $params[] = $searchTerm;
                }
                
                if ($category) {
                    $whereClause .= " AND p.hashtags LIKE ?";
                    $params[] = "%$category%";
                }
                
                $sql = "SELECT p.*, u.username, u.avatar,
                               COALESCE((SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id), 0) as like_count,
                               COALESCE((SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id), 0) as comment_count
                        FROM posts p 
                        JOIN users u ON p.author_id = u.id 
                        $whereClause
                        ORDER BY p.created_at DESC 
                        LIMIT $limit OFFSET $offset";
                
                logSuccess("Executing posts query", ['sql' => $sql, 'params' => $params]);
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $posts = $stmt->fetchAll();
                
                logSuccess("Posts retrieved successfully", ['count' => count($posts)]);
                jsonResponse(['success' => true, 'posts' => $posts]);
            }
            break;
            
        case 'post':
            if ($method === 'GET') {
                $id = $_GET['id'] ?? 0;
                logSuccess("Single post request", ['id' => $id]);
                
                $stmt = $pdo->prepare("SELECT p.*, u.username, u.avatar,
                                             COALESCE((SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id), 0) as like_count,
                                             COALESCE((SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id), 0) as comment_count
                                      FROM posts p 
                                      JOIN users u ON p.author_id = u.id 
                                      WHERE p.id = ?");
                $stmt->execute([$id]);
                $post = $stmt->fetch();
                
                if ($post) {
                    // Increment views
                    $stmt = $pdo->prepare("UPDATE posts SET views = COALESCE(views, 0) + 1 WHERE id = ?");
                    $stmt->execute([$id]);
                    
                    logSuccess("Post retrieved and view incremented", ['post_id' => $id, 'title' => $post['title']]);
                    jsonResponse(['success' => true, 'post' => $post]);
                } else {
                    logError("Post not found", ['id' => $id]);
                    jsonResponse(['success' => false, 'message' => 'Post topilmadi'], 404);
                }
            }
            break;
            
        case 'like':
            if ($method === 'POST') {
                logSuccess("Like request received");
                $data = json_decode(file_get_contents('php://input'), true);
                
                if (!$data) {
                    logError("Invalid JSON data for like");
                    jsonResponse(['success' => false, 'message' => 'Noto\'g\'ri ma\'lumot formati']);
                }
                
                $token = $data['token'];
                $postId = $data['post_id'];
                
                logSuccess("Like request data", ['post_id' => $postId]);
                
                // Verify user session
                $stmt = $pdo->prepare("SELECT user_id FROM user_sessions WHERE session_token = ? AND expires_at > NOW()");
                $stmt->execute([$token]);
                $session = $stmt->fetch();
                
                if (!$session) {
                    logError("Invalid session for like", ['token' => substr($token, 0, 10) . '...']);
                    jsonResponse(['success' => false, 'message' => 'Tizimga kiring'], 401);
                }
                
                $userId = $session['user_id'];
                
                // Check if already liked
                $stmt = $pdo->prepare("SELECT id FROM likes WHERE user_id = ? AND post_id = ?");
                $stmt->execute([$userId, $postId]);
                $existingLike = $stmt->fetch();
                
                if ($existingLike) {
                    // Unlike
                    $stmt = $pdo->prepare("DELETE FROM likes WHERE user_id = ? AND post_id = ?");
                    $stmt->execute([$userId, $postId]);
                    $action = 'unliked';
                } else {
                    // Like
                    $stmt = $pdo->prepare("INSERT INTO likes (user_id, post_id) VALUES (?, ?)");
                    $stmt->execute([$userId, $postId]);
                    $action = 'liked';
                }
                
                // Get updated like count
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM likes WHERE post_id = ?");
                $stmt->execute([$postId]);
                $likeCount = $stmt->fetch()['count'];
                
                logSuccess("Like action completed", ['action' => $action, 'post_id' => $postId, 'user_id' => $userId, 'like_count' => $likeCount]);
                jsonResponse(['success' => true, 'action' => $action, 'like_count' => $likeCount]);
            }
            break;
            
        case 'comments':
            if ($method === 'GET') {
                $postId = $_GET['post_id'] ?? 0;
                logSuccess("Comments request", ['post_id' => $postId]);
                
                $stmt = $pdo->prepare("SELECT c.*, u.username, u.avatar 
                                      FROM comments c 
                                      JOIN users u ON c.user_id = u.id 
                                      WHERE c.post_id = ? 
                                      ORDER BY c.created_at DESC");
                $stmt->execute([$postId]);
                $comments = $stmt->fetchAll();
                
                logSuccess("Comments retrieved", ['post_id' => $postId, 'count' => count($comments)]);
                jsonResponse(['success' => true, 'comments' => $comments]);
            } elseif ($method === 'POST') {
                logSuccess("Add comment request received");
                $data = json_decode(file_get_contents('php://input'), true);
                
                if (!$data) {
                    logError("Invalid JSON data for comment");
                    jsonResponse(['success' => false, 'message' => 'Noto\'g\'ri ma\'lumot formati']);
                }
                
                $token = $data['token'];
                $postId = $data['post_id'];
                $content = sanitizeInput($data['content']);
                
                logSuccess("Comment data", ['post_id' => $postId, 'content_length' => strlen($content)]);
                
                // Verify user session
                $stmt = $pdo->prepare("SELECT user_id FROM user_sessions WHERE session_token = ? AND expires_at > NOW()");
                $stmt->execute([$token]);
                $session = $stmt->fetch();
                
                if (!$session) {
                    logError("Invalid session for comment", ['token' => substr($token, 0, 10) . '...']);
                    jsonResponse(['success' => false, 'message' => 'Tizimga kiring'], 401);
                }
                
                $stmt = $pdo->prepare("INSERT INTO comments (post_id, user_id, content) VALUES (?, ?, ?)");
                $stmt->execute([$postId, $session['user_id'], $content]);
                
                logSuccess("Comment added", ['post_id' => $postId, 'user_id' => $session['user_id']]);
                jsonResponse(['success' => true, 'message' => 'Izoh qo\'shildi']);
            }
            break;
            
        case 'contact':
            if ($method === 'POST') {
                logSuccess("Contact request received");
                $data = json_decode(file_get_contents('php://input'), true);
                
                if (!$data) {
                    logError("Invalid JSON data for contact");
                    jsonResponse(['success' => false, 'message' => 'Noto\'g\'ri ma\'lumot formati']);
                }
                
                $name = sanitizeInput($data['name']);
                $email = sanitizeInput($data['email']);
                $message = sanitizeInput($data['message']);
                $captcha = $data['captcha'];
                
                logSuccess("Contact form data", ['name' => $name, 'email' => $email]);
                
                // Validate captcha
                if (!validateCaptcha($captcha, $_SESSION['captcha'] ?? '')) {
                    logError("Contact captcha validation failed", ['provided' => $captcha, 'expected' => $_SESSION['captcha'] ?? '']);
                    jsonResponse(['success' => false, 'message' => 'Captcha noto\'g\'ri']);
                }
                
                $stmt = $pdo->prepare("INSERT INTO contact_messages (name, email, message) VALUES (?, ?, ?)");
                $stmt->execute([$name, $email, $message]);
                
                logSuccess("Contact message saved", ['name' => $name, 'email' => $email]);
                jsonResponse(['success' => true, 'message' => 'Sizning habaringiz yuborildi']);
            }
            break;

        case 'chat-users':
            if ($method === 'GET') {
                logSuccess("Chat users request received");
                $token = $_GET['token'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
                $token = str_replace('Bearer ', '', $token);
                $search = $_GET['search'] ?? '';
                
                logSuccess("Chat users request data", ['search' => $search]);
                
                // Verify user session
                $stmt = $pdo->prepare("SELECT user_id FROM user_sessions WHERE session_token = ? AND expires_at > NOW()");
                $stmt->execute([$token]);
                $session = $stmt->fetch();
                
                if (!$session) {
                    logError("Invalid session for chat users", ['token' => substr($token, 0, 10) . '...']);
                    jsonResponse(['success' => false, 'message' => 'Tizimga kiring'], 401);
                }
                
                $currentUserId = $session['user_id'];
                
                $whereClause = "WHERE u.id != ? AND u.is_admin = 0";
                $params = [$currentUserId];
                
                if ($search) {
                    $whereClause .= " AND u.username LIKE ?";
                    $params[] = "%$search%";
                }
                
                $sql = "SELECT u.id, u.username, u.avatar,
                               (SELECT cm.message FROM chat_messages cm 
                                WHERE (cm.sender_id = u.id AND cm.receiver_id = ?) 
                                   OR (cm.sender_id = ? AND cm.receiver_id = u.id)
                                ORDER BY cm.created_at DESC LIMIT 1) as last_message,
                               (SELECT cm.created_at FROM chat_messages cm 
                                WHERE (cm.sender_id = u.id AND cm.receiver_id = ?) 
                                   OR (cm.sender_id = ? AND cm.receiver_id = u.id)
                                ORDER BY cm.created_at DESC LIMIT 1) as last_message_time,
                               (SELECT COUNT(*) FROM chat_messages cm 
                                WHERE cm.sender_id = u.id AND cm.receiver_id = ? AND cm.is_read = 0) as unread_count
                        FROM users u 
                        $whereClause
                        ORDER BY last_message_time DESC, u.username ASC";
                
                $params = array_merge([$currentUserId, $currentUserId, $currentUserId, $currentUserId, $currentUserId], $params);
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $users = $stmt->fetchAll();
                
                logSuccess("Chat users retrieved", ['count' => count($users), 'current_user_id' => $currentUserId]);
                jsonResponse(['success' => true, 'users' => $users]);
            }
            break;
            
        case 'chat-messages':
            if ($method === 'GET') {
                logSuccess("Chat messages request received");
                $token = $_GET['token'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
                $token = str_replace('Bearer ', '', $token);
                $userId = $_GET['user_id'] ?? 0;
                
                logSuccess("Chat messages request data", ['user_id' => $userId]);
                
                // Verify user session
                $stmt = $pdo->prepare("SELECT user_id FROM user_sessions WHERE session_token = ? AND expires_at > NOW()");
                $stmt->execute([$token]);
                $session = $stmt->fetch();
                
                if (!$session) {
                    logError("Invalid session for chat messages", ['token' => substr($token, 0, 10) . '...']);
                    jsonResponse(['success' => false, 'message' => 'Tizimga kiring'], 401);
                }
                
                $currentUserId = $session['user_id'];
                
                $stmt = $pdo->prepare("SELECT cm.*, u.username as sender_username, u.avatar as sender_avatar
                                      FROM chat_messages cm
                                      JOIN users u ON cm.sender_id = u.id
                                      WHERE (cm.sender_id = ? AND cm.receiver_id = ?) 
                                         OR (cm.sender_id = ? AND cm.receiver_id = ?)
                                      ORDER BY cm.created_at ASC");
                $stmt->execute([$currentUserId, $userId, $userId, $currentUserId]);
                $messages = $stmt->fetchAll();
                
                // Mark messages as read
                $stmt = $pdo->prepare("UPDATE chat_messages SET is_read = 1 
                                      WHERE sender_id = ? AND receiver_id = ? AND is_read = 0");
                $stmt->execute([$userId, $currentUserId]);
                
                logSuccess("Chat messages retrieved", ['count' => count($messages), 'between_users' => [$currentUserId, $userId]]);
                jsonResponse(['success' => true, 'messages' => $messages]);
            }
            break;
            
        case 'send-message':
            if ($method === 'POST') {
                logSuccess("Send message request received");
                $data = json_decode(file_get_contents('php://input'), true);
                
                if (!$data) {
                    logError("Invalid JSON data for send message");
                    jsonResponse(['success' => false, 'message' => 'Noto\'g\'ri ma\'lumot formati']);
                }
                
                $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
                $token = str_replace('Bearer ', '', $token);
                $receiverId = $data['receiver_id'];
                $message = sanitizeInput($data['message']);
                
                logSuccess("Send message data", ['receiver_id' => $receiverId, 'message_length' => strlen($message)]);
                
                // Verify user session
                $stmt = $pdo->prepare("SELECT user_id FROM user_sessions WHERE session_token = ? AND expires_at > NOW()");
                $stmt->execute([$token]);
                $session = $stmt->fetch();
                
                if (!$session) {
                    logError("Invalid session for send message", ['token' => substr($token, 0, 10) . '...']);
                    jsonResponse(['success' => false, 'message' => 'Tizimga kiring'], 401);
                }
                
                $stmt = $pdo->prepare("INSERT INTO chat_messages (sender_id, receiver_id, message) VALUES (?, ?, ?)");
                $stmt->execute([$session['user_id'], $receiverId, $message]);
                
                logSuccess("Message sent", ['sender_id' => $session['user_id'], 'receiver_id' => $receiverId]);
                jsonResponse(['success' => true, 'message' => 'Xabar yuborildi']);
            }
            break;
            
        case 'profile':
            if ($method === 'GET') {
                $username = $_GET['username'] ?? '';
                logSuccess("Profile request", ['username' => $username]);
                
                if (!$username) {
                    logError("Username not provided for profile");
                    jsonResponse(['success' => false, 'message' => 'Username kerak'], 400);
                }
                
                $stmt = $pdo->prepare("SELECT u.*, 
                                             (SELECT COUNT(*) FROM posts p WHERE p.author_id = u.id) as post_count,
                                             (SELECT COUNT(*) FROM comments c WHERE c.user_id = u.id) as comment_count,
                                             (SELECT COUNT(*) FROM likes l JOIN posts p ON l.post_id = p.id WHERE p.author_id = u.id) as like_count
                                      FROM users u 
                                      WHERE u.username = ?");
                $stmt->execute([$username]);
                $profile = $stmt->fetch();
                
                if ($profile) {
                    // Remove sensitive data
                    unset($profile['password']);
                    logSuccess("Profile retrieved", ['username' => $username, 'user_id' => $profile['id']]);
                    jsonResponse(['success' => true, 'profile' => $profile]);
                } else {
                    logError("Profile not found", ['username' => $username]);
                    jsonResponse(['success' => false, 'message' => 'Foydalanuvchi topilmadi'], 404);
                }
            }
            break;
            
        case 'newsletter-subscribe':
            if ($method === 'POST') {
                logSuccess("Newsletter subscribe request received");
                $data = json_decode(file_get_contents('php://input'), true);
                
                if (!$data) {
                    logError("Invalid JSON data for newsletter");
                    jsonResponse(['success' => false, 'message' => 'Noto\'g\'ri ma\'lumot formati']);
                }
                
                $email = sanitizeInput($data['email']);
                
                logSuccess("Newsletter subscription attempt", ['email' => $email]);
                
                // Validate email format
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    logError("Invalid email format for newsletter", ['email' => $email]);
                    jsonResponse(['success' => false, 'message' => 'Noto\'g\'ri email format']);
                }
                
                // Check if already subscribed
                $stmt = $pdo->prepare("SELECT id FROM newsletter_subscribers WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    logError("Email already subscribed", ['email' => $email]);
                    jsonResponse(['success' => false, 'message' => 'Bu email allaqachon obuna bo\'lgan']);
                }
                
                // Add to newsletter
                $stmt = $pdo->prepare("INSERT INTO newsletter_subscribers (email, created_at) VALUES (?, NOW())");
                $stmt->execute([$email]);
                
                logSuccess("Newsletter subscription successful", ['email' => $email]);
                jsonResponse(['success' => true, 'message' => 'Muvaffaqiyatli obuna bo\'ldingiz! 🎉']);
            }
            break;

        case 'admin-newsletter':
            if ($method === 'GET') {
                logSuccess("Admin newsletter request received");
                // Verify admin session
                $token = $_GET['token'] ?? '';
                $stmt = $pdo->prepare("SELECT u.* FROM users u 
                                      JOIN user_sessions s ON u.id = s.user_id 
                                      WHERE s.session_token = ? AND s.expires_at > NOW() AND u.is_admin = 1");
                $stmt->execute([$token]);
                $admin = $stmt->fetch();
                
                if (!$admin) {
                    logError("Unauthorized admin newsletter access", ['token' => substr($token, 0, 10) . '...']);
                    jsonResponse(['success' => false, 'message' => 'Admin huquqi kerak'], 401);
                }
                
                $stmt = $pdo->query("SELECT * FROM newsletter_subscribers ORDER BY created_at DESC");
                $subscribers = $stmt->fetchAll();
                
                logSuccess("Admin newsletter data retrieved", ['count' => count($subscribers)]);
                jsonResponse(['success' => true, 'subscribers' => $subscribers]);
            }
            break;

        case 'test':
            logSuccess("Test endpoint called");
            jsonResponse(['success' => true, 'message' => 'API ishlamoqda', 'timestamp' => date('Y-m-d H:i:s')]);
            break;

        default:
            logError("Unknown API action", ['action' => $request, 'method' => $method]);
            jsonResponse(['success' => false, 'message' => 'Noto\'g\'ri so\'rov'], 404);
    }

} catch (Exception $e) {
    $errorDetails = [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
        'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'action' => $_GET['action'] ?? '',
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    logError("CRITICAL API ERROR", $errorDetails);
    jsonResponse(['success' => false, 'message' => 'Server xatosi yuz berdi'], 500);
}
?>
