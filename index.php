<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Database configuration
$dbHost = "localhost";
$dbUser = "root";
$dbPassword = "";
$dbName = "tryi";

// Connect to database
$conn = mysqli_connect($dbHost, $dbUser, $dbPassword, $dbName);
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Get current user information
$userId = $_SESSION['user_id'];
$userQuery = "SELECT * FROM users WHERE id = ?";
$stmt = $conn->prepare($userQuery);
$stmt->bind_param("i", $userId);
$stmt->execute();
$userResult = $stmt->get_result();
$currentUser = $userResult->fetch_assoc();
$stmt->close();

// Handle search query
$searchResults = [];
$searchTerm = "";
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $searchTerm = $_GET['search'];
    $searchQuery = "SELECT id, display_name, email, profile_image, last_active 
                   FROM users 
                   WHERE (display_name LIKE ? OR email LIKE ?) 
                   AND id != ? 
                   LIMIT 20";
    $stmt = $conn->prepare($searchQuery);
    $searchParam = "%" . $searchTerm . "%";
    $stmt->bind_param("ssi", $searchParam, $searchParam, $userId);
    $stmt->execute();
    $searchResults = $stmt->get_result();
    $stmt->close();
}

// Get recent conversations
$conversationsQuery = "SELECT c.id, c.last_message_time, c.last_message,
                      u.id as user_id, u.display_name, u.profile_image, u.last_active
                      FROM conversations c
                      JOIN conversation_participants cp ON c.id = cp.conversation_id
                      JOIN users u ON cp.user_id = u.id
                      WHERE c.id IN (
                          SELECT conversation_id FROM conversation_participants 
                          WHERE user_id = ?
                      )
                      AND u.id != ?
                      ORDER BY c.last_message_time DESC
                      LIMIT 20";
$stmt = $conn->prepare($conversationsQuery);
$stmt->bind_param("ii", $userId, $userId);
$stmt->execute();
$conversations = $stmt->get_result();
$stmt->close();

// Update user's last active time
$updateLastActive = "UPDATE users SET last_active = NOW() WHERE id = ?";
$stmt = $conn->prepare($updateLastActive);
$stmt->bind_param("i", $userId);
$stmt->execute();
$stmt->close();

// Load selected conversation if any
$selectedConversation = null;
$chatMessages = [];
if (isset($_GET['conversation_id']) && !empty($_GET['conversation_id'])) {
    $conversationId = $_GET['conversation_id'];
    
    // Check if user is part of this conversation
    $checkAccessQuery = "SELECT COUNT(*) as count FROM conversation_participants 
                        WHERE conversation_id = ? AND user_id = ?";
    $stmt = $conn->prepare($checkAccessQuery);
    $stmt->bind_param("ii", $conversationId, $userId);
    $stmt->execute();
    $accessResult = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($accessResult['count'] > 0) {
        // Get conversation details
        $conversationQuery = "SELECT c.*, u.display_name, u.profile_image, u.last_active, u.id as other_user_id
                             FROM conversations c
                             JOIN conversation_participants cp ON c.id = cp.conversation_id
                             JOIN users u ON cp.user_id = u.id
                             WHERE c.id = ? AND u.id != ?";
        $stmt = $conn->prepare($conversationQuery);
        $stmt->bind_param("ii", $conversationId, $userId);
        $stmt->execute();
        $selectedConversation = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        // Load messages
        $messagesQuery = "SELECT m.*, u.display_name, u.profile_image 
                         FROM messages m
                         JOIN users u ON m.sender_id = u.id
                         WHERE m.conversation_id = ?
                         ORDER BY m.created_at ASC";
        $stmt = $conn->prepare($messagesQuery);
        $stmt->bind_param("i", $conversationId);
        $stmt->execute();
        $chatMessages = $stmt->get_result();
        $stmt->close();
        
        // Mark messages as read
        $markReadQuery = "UPDATE messages 
                         SET is_read = 1 
                         WHERE conversation_id = ? AND sender_id != ? AND is_read = 0";
        $stmt = $conn->prepare($markReadQuery);
        $stmt->bind_param("ii", $conversationId, $userId);
        $stmt->execute();
        $stmt->close();
    }
}

// Handle new message submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $message = trim($_POST['message_text']);
    $conversationId = $_POST['conversation_id'];
    
    if (!empty($message)) {
        // Insert message
        $insertQuery = "INSERT INTO messages (conversation_id, sender_id, message, created_at) 
                       VALUES (?, ?, ?, NOW())";
        $stmt = $conn->prepare($insertQuery);
        $stmt->bind_param("iis", $conversationId, $userId, $message);
        $stmt->execute();
        $stmt->close();
        
        // Update conversation last message
        $updateConversationQuery = "UPDATE conversations 
                                  SET last_message = ?, last_message_time = NOW() 
                                  WHERE id = ?";
        $stmt = $conn->prepare($updateConversationQuery);
        $stmt->bind_param("si", $message, $conversationId);
        $stmt->execute();
        $stmt->close();
        
        // Redirect to avoid form resubmission
        header("Location: home.php?conversation_id=" . $conversationId);
        exit();
    }
}

// Start new conversation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_conversation'])) {
    $otherUserId = $_POST['user_id'];
    
    // Check if conversation already exists
    $checkQuery = "SELECT c.id FROM conversations c
                  JOIN conversation_participants cp1 ON c.id = cp1.conversation_id AND cp1.user_id = ?
                  JOIN conversation_participants cp2 ON c.id = cp2.conversation_id AND cp2.user_id = ?
                  LIMIT 1";
    $stmt = $conn->prepare($checkQuery);
    $stmt->bind_param("ii", $userId, $otherUserId);
    $stmt->execute();
    $existingConversation = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($existingConversation) {
        // Redirect to existing conversation
        header("Location: home.php?conversation_id=" . $existingConversation['id']);
        exit();
    } else {
        // Create new conversation
        $createConversationQuery = "INSERT INTO conversations (created_at, last_message_time) VALUES (NOW(), NOW())";
        $stmt = $conn->prepare($createConversationQuery);
        $stmt->execute();
        $newConversationId = $stmt->insert_id;
        $stmt->close();
        
        // Add participants
        $addParticipantsQuery = "INSERT INTO conversation_participants (conversation_id, user_id) VALUES (?, ?)";
        $stmt = $conn->prepare($addParticipantsQuery);
        $stmt->bind_param("ii", $newConversationId, $userId);
        $stmt->execute();
        $stmt->bind_param("ii", $newConversationId, $otherUserId);
        $stmt->execute();
        $stmt->close();
        
        // Redirect to new conversation
        header("Location: home.php?conversation_id=" . $newConversationId);
        exit();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat Application</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: rgb(0, 216, 255);
            --primary-hover: rgb(112, 224, 255);
            --background-dark: #111;
            --sidebar-bg: rgba(18, 18, 18, 0.95);
            --chat-bg: rgba(18, 18, 18, 0.9);
            --message-sent: rgba(0, 216, 255, 0.2);
            --message-received: rgba(255, 255, 255, 0.1);
            --text-color: #fff;
            --text-muted: rgba(255, 255, 255, 0.6);
            --border-color: rgba(255, 255, 255, 0.1);
            --online-color: #2ed573;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            color: var(--text-color);
            background-color: var(--background-dark);
            background: linear-gradient(135deg, #0f0f0f, #1a1a1a);
            overflow: hidden;
            height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        /* Header Styles */
        .header {
            height: 70px;
            background-color: rgba(0, 0, 0, 0.3);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            z-index: 100;
        }
        
        .logo {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary-color);
            display: flex;
            align-items: center;
        }
        
        .logo i {
            margin-right: 10px;
        }
        
        .user-menu {
            display: flex;
            align-items: center;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            cursor: pointer;
            padding: 8px 15px;
            border-radius: 24px;
            background-color: rgba(255, 255, 255, 0.05);
            transition: all 0.3s;
        }
        
        .user-profile:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        .profile-img {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background-color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 10px;
            font-weight: 600;
        }
        
        .profile-img img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }
        
        .username {
            margin-right: 10px;
            font-weight: 500;
        }
        
        .dropdown-menu {
            position: absolute;
            top: 65px;
            right: 20px;
            background-color: rgba(30, 30, 30, 0.95);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 8px 0;
            min-width: 180px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            z-index: 1000;
            display: none;
        }
        
        .dropdown-menu.show {
            display: block;
            animation: fadeIn 0.2s;
        }
        
        .dropdown-item {
            padding: 10px 15px;
            display: flex;
            align-items: center;
            color: var(--text-color);
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .dropdown-item:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        .dropdown-item i {
            margin-right: 10px;
            width: 16px;
            text-align: center;
        }
        
        /* Main Content */
        .main-container {
            display: flex;
            flex: 1;
            overflow: hidden;
            position: relative;
        }
        
        /* Sidebar Styles */
        .sidebar {
            width: 320px;
            background-color: var(--sidebar-bg);
            border-right: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        
        .sidebar-tabs {
            display: flex;
            border-bottom: 1px solid var(--border-color);
        }
        
        .tab {
            flex: 1;
            padding: 15px 0;
            text-align: center;
            font-weight: 600;
            color: var(--text-muted);
            border-bottom: 2px solid transparent;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .tab.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }
        
        .search-bar {
            padding: 15px;
            position: relative;
        }
        
        .search-input {
            width: 100%;
            padding: 12px 40px 12px 15px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background-color: rgba(255, 255, 255, 0.05);
            color: var(--text-color);
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .search-input:focus {
            outline: none;
            border-color: var(--primary-color);
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        .search-icon {
            position: absolute;
            right: 25px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            pointer-events: none;
        }
        
        .tab-content {
            flex: 1;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: var(--primary-color) transparent;
        }
        
        .tab-content::-webkit-scrollbar {
            width: 4px;
        }
        
        .tab-content::-webkit-scrollbar-thumb {
            background-color: var(--primary-color);
            border-radius: 4px;
        }
        
        .tab-content::-webkit-scrollbar-track {
            background-color: transparent;
        }
        
        .conversation-list, .search-results {
            display: none;
        }
        
        .conversation-list.active, .search-results.active {
            display: block;
        }
        
        .conversation-item, .search-result-item {
            padding: 15px;
            display: flex;
            align-items: center;
            border-bottom: 1px solid var(--border-color);
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            color: var(--text-color);
        }
        
        .conversation-item:hover, .search-result-item:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        
        .conversation-item.active {
            background-color: rgba(0, 216, 255, 0.1);
        }
        
        .conversation-avatar, .search-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            margin-right: 15px;
            position: relative;
            flex-shrink: 0;
            background-color: rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .conversation-avatar img, .search-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }
        
        .online-indicator {
            position: absolute;
            width: 12px;
            height: 12px;
            background-color: var(--online-color);
            border-radius: 50%;
            bottom: 0;
            right: 0;
            border: 2px solid var(--sidebar-bg);
        }
        
        .conversation-info, .search-info {
            flex: 1;
            min-width: 0;
        }
        
        .conversation-name, .search-name {
            font-weight: 600;
            margin-bottom: 5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .last-message, .search-email {
            font-size: 13px;
            color: var(--text-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .conversation-meta {
            text-align: right;
            min-width: 50px;
        }
        
        .message-time {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 5px;
        }
        
        .unread-count {
            background-color: var(--primary-color);
            color: black;
            font-size: 12px;
            font-weight: 600;
            min-width: 20px;
            height: 20px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 6px;
        }
        
        .no-results, .empty-state {
            padding: 30px;
            text-align: center;
            color: var(--text-muted);
        }
        
        .empty-state i {
            font-size: 50px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        /* Chat Area Styles */
        .chat-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            background-color: var(--chat-bg);
            position: relative;
        }
        
        .chat-header {
            padding: 15px 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .chat-user-info {
            display: flex;
            align-items: center;
        }
        
        .chat-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            margin-right: 15px;
            position: relative;
            background-color: rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .chat-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }
        
        .chat-user-name {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .chat-status {
            font-size: 12px;
            color: var(--text-muted);
        }
        
        .chat-actions {
            display: flex;
        }
        
        .action-btn {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            background-color: transparent;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            margin-left: 5px;
        }
        
        .action-btn:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: var(--text-color);
        }
        
        .chat-messages {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: var(--primary-color) transparent;
            display: flex;
            flex-direction: column;
        }
        
        .chat-messages::-webkit-scrollbar {
            width: 4px;
        }
        
        .chat-messages::-webkit-scrollbar-thumb {
            background-color: var(--primary-color);
            border-radius: 4px;
        }
        
        .chat-messages::-webkit-scrollbar-track {
            background-color: transparent;
        }
        
        .message {
            max-width: 70%;
            padding: 12px 15px;
            border-radius: 16px;
            margin-bottom: 15px;
            position: relative;
            font-size: 14px;
            animation: fadeIn 0.3s;
        }
        
        .message.received {
            background-color: var(--message-received);
            align-self: flex-start;
            border-bottom-left-radius: 4px;
        }
        
        .message.sent {
            background-color: var(--message-sent);
            align-self: flex-end;
            border-bottom-right-radius: 4px;
        }
        
        .message-sender {
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--primary-color);
        }
        
        .message-time-stamp {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 5px;
            text-align: right;
        }
        
        .message-status {
            margin-left: 5px;
        }
        
        .message-input-container {
            padding: 15px 20px;
            border-top: 1px solid var(--border-color);
            background-color: rgba(18, 18, 18, 0.98);
            display: flex;
            align-items: center;
        }
        
        .message-input-wrapper {
            display: flex;
            align-items: center;
            flex: 1;
            background-color: rgba(255, 255, 255, 0.05);
            border-radius: 24px;
            overflow: hidden;
            transition: all 0.3s;
            border: 1px solid var(--border-color);
        }
        
        .message-input-wrapper:focus-within {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(0, 216, 255, 0.1);
        }
        
        .message-input {
            flex: 1;
            padding: 12px 15px;
            border: none;
            background-color: transparent;
            color: var(--text-color);
            font-size: 14px;
        }
        
        .message-input:focus {
            outline: none;
        }
        
        .message-input::placeholder {
            color: var(--text-muted);
        }
        
        .message-actions {
            display: flex;
            align-items: center;
        }
        
        .message-action-btn {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            background-color: transparent;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .message-action-btn:hover {
            color: var(--primary-color);
        }
        
        .send-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: black;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            cursor: pointer;
            margin-left: 10px;
            transition: all 0.2s;
        }
        
        .send-btn:hover {
            background-color: var(--primary-hover);
            transform: scale(1.05);
        }
        
        .empty-chat {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            text-align: center;
            padding: 30px;
        }
        
        .empty-chat i {
            font-size: 70px;
            margin-bottom: 20px;
            opacity: 0.3;
        }
        
        .empty-chat h3 {
            margin-bottom: 10px;
            font-size: 24px;
            font-weight: 600;
        }
        
        .empty-chat p {
            max-width: 400px;
            margin-bottom: 20px;
        }
        
        /* Mobile responsiveness */
        @media (max-width: 768px) {
            .sidebar {
                position: absolute;
                left: -320px;
                top: 0;
                bottom: 0;
                z-index: 10;
                transition: left 0.3s;
            }
            
            .sidebar.open {
                left: 0;
            }
            
            .mobile-menu-toggle {
                display: block;
                margin-right: 15px;
            }
            
            .chat-area {
                width: 100%;
            }
            
            .message {
                max-width: 85%;
            }
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="logo">
            <i class="fas fa-comments"></i> ChatApp
        </div>
        
        <div class="user-menu">
            <div class="user-profile" id="userProfileDropdown">
                <div class="profile-img">
                    <?php if (!empty($currentUser['profile_image'])): ?>
                        <img src="<?php echo htmlspecialchars($currentUser['profile_image']); ?>" alt="Profile">
                    <?php else: ?>
                        <?php echo strtoupper(substr(htmlspecialchars($currentUser['display_name']), 0, 1)); ?>
                    <?php endif; ?>
                </div>
                <span class="username"><?php echo htmlspecialchars($currentUser['display_name']); ?></span>
                <i class="fas fa-chevron-down"></i>
            </div>
            
            <div class="dropdown-menu" id="userDropdown">
                <a href="profile.php" class="dropdown-item">
                    <i class="fas fa-user"></i> Profile
                </a>
                <a href="settings.php" class="dropdown-item">
                    <i class="fas fa-cog"></i> Settings
                </a>
                <a href="index.php?logout=1" class="dropdown-item">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </header>
    
    <!-- Main Content -->
    <div class="main-container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-tabs">
                <div class="tab active" data-tab="conversations">
                    <i class="fas fa-comments"></i> Chats
                </div>
                <div class="tab" data-tab="search">
                    <i class="fas fa-search"></i> Search
                </div>
            </div>
            
            <!-- Conversations Tab -->
            <div class="tab-content">
                <div class="conversation-list active" id="conversationsTab">
                    <?php if ($conversations && $conversations->num_rows > 0): ?>
                        <?php while ($conversation = $conversations->fetch_assoc()): ?>
                            <?php
                                // Calculate online status
                                $isOnline = false;
                                $lastActiveTime = strtotime($conversation['last_active']);
                                $currentTime = time();
                                $minutesSinceActive = ($currentTime - $lastActiveTime) / 60;
                                if ($minutesSinceActive < 5) {
                                    $isOnline = true;
                                }
                                
                                // Format relative time
                                $lastMessageTime = strtotime($conversation['last_message_time']);
                                $timeDiff = $currentTime - $lastMessageTime;
                                
                                if ($timeDiff < 60) {
                                    $timeStr = "Just now";
                                } elseif ($timeDiff < 3600) {
                                    $timeStr = floor($timeDiff / 60) . "m ago";
                                } elseif ($timeDiff < 86400) {
                                    $timeStr = floor($timeDiff / 3600) . "h ago";
                                } else {
                                    $timeStr = date("M j", $lastMessageTime);
                                }
                                
                                // Check if current conversation is selected
                                $isActive = isset($selectedConversation) && $selectedConversation['id'] == $conversation['id'];
                            ?>
                            <a href="?conversation_id=<?php echo htmlspecialchars($conversation['id']); ?>" class="conversation-item <?php echo $isActive ? 'active' : ''; ?>">
                                <div class="conversation-avatar">
                                    <?php if (!empty($conversation['profile_image'])): ?>
                                        <img src="<?php echo htmlspecialchars($conversation['profile_image']); ?>" alt="Profile">
                                    <?php else: ?>
                                        <?php echo strtoupper(substr(htmlspecialchars($conversation['display_name']), 0, 1)); ?>
                                    <?php endif; ?>
                                    <?php if ($isOnline): ?>
                                        <span class="online-indicator"></span>
                                    <?php endif; ?>
                                </div>
                                <div class="conversation-info">
                                    <div class="conversation-name"><?php echo htmlspecialchars($conversation['display_name']); ?></div>
                                    <div class="last-message"><?php echo htmlspecialchars($conversation['last_message']); ?></div>
                                </div>
                                <div class="conversation-meta">
                                    <div class="message-time"><?php echo $timeStr; ?></div>
                                    <!-- Add unread count if needed -->
                                </div>
                            </a>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="no-results">No conversations found.</div>
                    <?php endif; ?>
                </div>
                
                <!-- Search Tab -->
                <div class="search-results" id="searchTab">
                    <div class="search-bar">
                        <input type="text" class="search-input" placeholder="Search..." value="<?php echo htmlspecialchars($searchTerm); ?>">
                        <i class="fas fa-search search-icon"></i>
                    </div>
                    <?php if ($searchResults && $searchResults->num_rows > 0): ?>
                        <?php while ($result = $searchResults->fetch_assoc()): ?>
                            <a href="profile.php?user_id=<?php echo htmlspecialchars($result['id']); ?>" class="search-result-item">
                                <div class="search-avatar">
                                    <?php if (!empty($result['profile_image'])): ?>
                                        <img src="<?php echo htmlspecialchars($result['profile_image']); ?>" alt="Profile">
                                    <?php else: ?>
                                        <?php echo strtoupper(substr(htmlspecialchars($result['display_name']), 0, 1)); ?>
                                    <?php endif; ?>
                                </div>
                                <div class="search-info">
                                    <div class="search-name"><?php echo htmlspecialchars($result['display_name']); ?></div>
                                    <div class="search-email"><?php echo htmlspecialchars($result['email']); ?></div>
                                </div>
                            </a>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="no-results">No users found.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Chat Area -->
        <div class="chat-area">
            <?php if ($selectedConversation): ?>
                <div class="chat-header">
                    <div class="chat-user-info">
                        <div class="chat-avatar">
                            <?php if (!empty($selectedConversation['profile_image'])): ?>
                                <img src="<?php echo htmlspecialchars($selectedConversation['profile_image']); ?>" alt="Profile">
                            <?php else: ?>
                                <?php echo strtoupper(substr(htmlspecialchars($selectedConversation['display_name']), 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="chat-user-name"><?php echo htmlspecialchars($selectedConversation['display_name']); ?></div>
                            <div class="chat-status"><?php echo $isOnline ? 'Online' : 'Offline'; ?></div>
                        </div>
                    </div>
                    <div class="chat-actions">
                        <!-- Add any chat actions if needed -->
                    </div>
                </div>
                
                <div class="chat-messages">
                    <?php if ($chatMessages && $chatMessages->num_rows > 0): ?>
                        <?php while ($message = $chatMessages->fetch_assoc()): ?>
                            <div class="message <?php echo $message['sender_id'] == $userId ? 'sent' : 'received'; ?>">
                                <div class="message-sender"><?php echo htmlspecialchars($message['display_name']); ?></div>
                                <div class="message-content"><?php echo htmlspecialchars($message['message']); ?></div>
                                <div class="message-time-stamp"><?php echo date("H:i", strtotime($message['created_at'])); ?></div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-chat">
                            <i class="fas fa-comments"></i>
                            <h3>No messages yet</h3>
                            <p>Start the conversation by sending a message.</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="message-input-container">
                    <form method="POST" action="">
                        <input type="hidden" name="conversation_id" value="<?php echo htmlspecialchars($selectedConversation['id']); ?>">
                        <div class="message-input-wrapper">
                            <input type="text" name="message_text" class="message-input" placeholder="Type a message...">
                            <button type="submit" name="send_message" class="send-btn"><i class="fas fa-paper-plane"></i></button>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <div class="empty-chat">
                    <i class="fas fa-comments"></i>
                    <h3>Select a conversation</h3>
                    <p>Choose a conversation from the list or start a new one.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // JavaScript to handle dropdown and tab switching
        document.getElementById('userProfileDropdown').addEventListener('click', function() {
            document.getElementById('userDropdown').classList.toggle('show');
        });

        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content > div').forEach(tc => tc.classList.remove('active'));
                this.classList.add('active');
                document.getElementById(this.getAttribute('data-tab') + 'Tab').classList.add('active');
            });
        });
    </script>
</body>
</html>