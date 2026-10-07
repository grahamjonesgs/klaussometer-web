<?php
// auth.php - Include this at the top of any protected page
// Credentials (AUTH_USERNAME, AUTH_PASSWORD_HASH) are defined in vars.php
require_once 'vars.php';

// Login rate limiting
define('AUTH_MAX_ATTEMPTS', 5);
define('AUTH_LOCKOUT_SECONDS', 900);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();

// Failed attempts are tracked per client IP in a file outside the web root,
// so clearing cookies does not reset the counter
function attemptsFile() {
    return sys_get_temp_dir() . '/watsonia_login_' . md5($_SERVER['REMOTE_ADDR'] ?? '') . '.json';
}

function getAttempts() {
    $file = attemptsFile();
    if (!file_exists($file)) {
        return ['count' => 0, 'first' => time()];
    }
    $data = json_decode(file_get_contents($file), true);
    if (!$data || time() - $data['first'] > AUTH_LOCKOUT_SECONDS) {
        return ['count' => 0, 'first' => time()];
    }
    return $data;
}

function recordFailedAttempt() {
    $data = getAttempts();
    $data['count']++;
    file_put_contents(attemptsFile(), json_encode($data), LOCK_EX);
}

function clearAttempts() {
    @unlink(attemptsFile());
}

// Check if user is already logged in
function isLoggedIn() {
    return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (getAttempts()['count'] >= AUTH_MAX_ATTEMPTS) {
        $loginError = 'Too many failed attempts. Try again later.';
    } elseif ($username === AUTH_USERNAME && password_verify($password, AUTH_PASSWORD_HASH)) {
        clearAttempts();
        // New session ID on login to prevent session fixation
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['username'] = $username;
        $_SESSION['login_time'] = time();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } else {
        recordFailedAttempt();
        $loginError = 'Invalid username or password';
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// If not logged in, show login form
if (!isLoggedIn()) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Login Required</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                background-color: #eef2f5;
                display: flex;
                justify-content: center;
                align-items: center;
                height: 100vh;
                margin: 0;
            }
            
            .login-container {
                background: white;
                padding: 40px;
                border-radius: 12px;
                box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
                width: 100%;
                max-width: 400px;
            }
            
            .login-container h2 {
                margin: 0 0 20px 0;
                color: #333;
                text-align: center;
            }
            
            .form-group {
                margin-bottom: 20px;
            }
            
            .form-group label {
                display: block;
                margin-bottom: 5px;
                color: #555;
                font-weight: bold;
            }
            
            .form-group input {
                width: 100%;
                padding: 10px;
                border: 1px solid #ccc;
                border-radius: 5px;
                font-size: 16px;
                box-sizing: border-box;
            }
            
            .form-group input:focus {
                outline: none;
                border-color: #007bff;
            }
            
            .login-button {
                width: 100%;
                padding: 12px;
                background-color: #007bff;
                color: white;
                border: none;
                border-radius: 5px;
                font-size: 16px;
                cursor: pointer;
                transition: background-color 0.3s ease;
            }
            
            .login-button:hover {
                background-color: #0056b3;
            }
            
            .error-message {
                background-color: #f8d7da;
                color: #721c24;
                padding: 10px;
                border-radius: 5px;
                margin-bottom: 20px;
                text-align: center;
            }
        </style>
    </head>
    <body>
        <div class="login-container">
            <h2>🔒 Login Required</h2>
            
            <?php if (isset($loginError)): ?>
                <div class="error-message"><?php echo htmlspecialchars($loginError); ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required autofocus>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                
                <button type="submit" name="login" class="login-button">Login</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Optional: Session timeout (30 minutes)
if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > 1800)) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Update last activity time
$_SESSION['login_time'] = time();
?>