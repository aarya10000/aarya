<?php
// Load environment variables
$botToken = getenv('BOT_TOKEN') ?: 'Place_Your_Token_Here';
$webhookUrl = getenv('WEBHOOK_URL') ?: '';

// Bot configuration
define('BOT_TOKEN', $botToken);
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('USERS_FILE', __DIR__ . '/users.json');
define('ERROR_LOG', __DIR__ . '/error.log');
define('WEBHOOK_URL', $webhookUrl);

// Error logging
function logError($message) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(ERROR_LOG, "[$timestamp] $message\n", FILE_APPEND);
}

// Data management
function loadUsers() {
    try {
        if (!file_exists(USERS_FILE)) {
            file_put_contents(USERS_FILE, json_encode([]));
            chmod(USERS_FILE, 0666);
        }
        $data = file_get_contents(USERS_FILE);
        if ($data === false) {
            throw new Exception("Failed to read users file");
        }
        return json_decode($data, true) ?: [];
    } catch (Exception $e) {
        logError("Load users failed: " . $e->getMessage());
        return [];
    }
}

function saveUsers($users) {
    try {
        $result = file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT));
        if ($result === false) {
            throw new Exception("Failed to write to users file");
        }
        return true;
    } catch (Exception $e) {
        logError("Save users failed: " . $e->getMessage());
        return false;
    }
}

// Message sending with inline keyboard
function sendMessage($chat_id, $text, $keyboard = null) {
    try {
        $params = [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];
        
        if ($keyboard) {
            $params['reply_markup'] = json_encode([
                'inline_keyboard' => $keyboard
            ]);
        }
        
        $url = API_URL . 'sendMessage?' . http_build_query($params);
        $response = file_get_contents($url);
        if ($response === false) {
            throw new Exception("Failed to send message to Telegram API");
        }
        return true;
    } catch (Exception $e) {
        logError("Send message failed: " . $e->getMessage());
        return false;
    }
}

// Main keyboard
function getMainKeyboard() {
    return [
        [['text' => '💰 Earn', 'callback_data' => 'earn'], ['text' => '💳 Balance', 'callback_data' => 'balance']],
        [['text' => '🏆 Leaderboard', 'callback_data' => 'leaderboard'], ['text' => '👥 Referrals', 'callback_data' => 'referrals']],
        [['text' => '🏧 Withdraw', 'callback_data' => 'withdraw'], ['text' => '❓ Help', 'callback_data' => 'help']]
    ];
}

// Process commands and callbacks
function processUpdate($update) {
    $users = loadUsers();
    
    if (isset($update['message'])) {
        $message = $update['message'];
        $chat_id = $message['chat']['id'];
        $text = trim($message['text'] ?? '');
        
        // Create new user if doesn't exist
        if (!isset($users[$chat_id])) {
            $users[$chat_id] = [
                'balance' => 0,
                'last_earn' => 0,
                'referrals' => 0,
                'ref_code' => substr(md5($chat_id . time()), 0, 8),
                'referred_by' => null
            ];
        }
        
        if (strpos($text, '/start') === 0) {
            $parts = explode(' ', $text);
            $ref = $parts[1] ?? null;
            
            if ($ref && !$users[$chat_id]['referred_by']) {
                foreach ($users as $id => $user) {
                    if (isset($user['ref_code']) && $user['ref_code'] === $ref && $id != $chat_id) {
                        $users[$chat_id]['referred_by'] = $id;
                        $users[$id]['referrals'] = ($users[$id]['referrals'] ?? 0) + 1;
                        $users[$id]['balance'] = ($users[$id]['balance'] ?? 0) + 50; // Referral bonus
                        sendMessage($id, "🎉 New referral! +50 points bonus!");
                        break;
                    }
                }
            }
            
            $msg = "Welcome to Earning Bot!\n\nEarn points, invite friends, and withdraw your earnings!\n\nYour referral code: <b>{$users[$chat_id]['ref_code']}</b>";
            sendMessage($chat_id, $msg, getMainKeyboard());
        }
        
    } elseif (isset($update['callback_query'])) {
        $callback = $update['callback_query'];
        $chat_id = $callback['message']['chat']['id'];
        $data = $callback['data'];
        
        if (!isset($users[$chat_id])) {
            $users[$chat_id] = [
                'balance' => 0,
                'last_earn' => 0,
                'referrals' => 0,
                'ref_code' => substr(md5($chat_id . time()), 0, 8),
                'referred_by' => null
            ];
        }
        
        switch ($data) {
            case 'earn':
                $time_diff = time() - ($users[$chat_id]['last_earn'] ?? 0);
                if ($time_diff < 60) {
                    $remaining = 60 - $time_diff;
                    $msg = "⏳ Please wait $remaining seconds before earning again!";
                } else {
                    $earn = 10;
                    $users[$chat_id]['balance'] = ($users[$chat_id]['balance'] ?? 0) + $earn;
                    $users[$chat_id]['last_earn'] = time();
                    $msg = "✅ You earned $earn points!\n\nNew balance: {$users[$chat_id]['balance']}";
                }
                break;
                
            case 'balance':
                $msg = "💳 Your Balance\n\nPoints: {$users[$chat_id]['balance']}\nReferrals: {$users[$chat_id]['referrals']}";
                break;
                
            case 'leaderboard':
                $sorted = [];
                foreach ($users as $id => $user) {
                    $sorted[$id] = $user['balance'] ?? 0;
                }
                arsort($sorted);
                $top = array_slice($sorted, 0, 5, true);
                $msg = "🏆 Top Earners\n\n";
                $i = 1;
                foreach ($top as $id => $bal) {
                    $msg .= "$i. User $id: $bal points\n";
                    $i++;
                }
                break;
                
            case 'referrals':
                $msg = "👥 Referral System\n\nYour code: <b>{$users[$chat_id]['ref_code']}</b>\nReferrals: {$users[$chat_id]['referrals']}\n\nInvite link: https://t.me/" . str_replace('bot', '', BOT_TOKEN) . "?start={$users[$chat_id]['ref_code']}\n\n50 points per referral!";
                break;
                
            case 'withdraw':
                $min = 100;
                $balance = $users[$chat_id]['balance'] ?? 0;
                if ($balance < $min) {
                    $msg = "🏧 Withdrawal\n\nMinimum: $min points\nYour balance: $balance\n\nNeed " . ($min - $balance) . " more points!";
                } else {
                    $amount = $balance;
                    $users[$chat_id]['balance'] = 0;
                    $msg = "🏧 Withdrawal of $amount points requested!\n\nOur team will process it soon.";
                }
                break;
                
            case 'help':
                $msg = "❓ Help\n\n💰 Earn: Get 10 points every minute\n👥 Refer: Earn 50 points per referral\n🏧 Withdraw: Minimum 100 points\n\nUse the buttons below to navigate!";
                break;
                
            default:
                $msg = "Unknown command. Please try again.";
                break;
        }
        
        sendMessage($chat_id, $msg, getMainKeyboard());
    }
    
    saveUsers($users);
}

// Handle webhook request
$content = file_get_contents("php://input");
$update = json_decode($content, true);

if ($update) {
    try {
        // Verify this is a legitimate Telegram update
        if (!isset($update['update_id'])) {
            throw new Exception("Invalid update format");
        }
        
        processUpdate($update);
        http_response_code(200);
        echo "OK";
    } catch (Exception $e) {
        logError("Webhook processing failed: " . $e->getMessage());
        http_response_code(500);
        echo "Error processing update";
    }
} else {
    // Handle health checks and webhook setup
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Check if this is a webhook setup request
        if (isset($_GET['setup_webhook']) && WEBHOOK_URL) {
            $url = API_URL . 'setWebhook?url=' . urlencode(WEBHOOK_URL);
            $result = file_get_contents($url);
            header('Content-Type: application/json');
            echo $result ?: json_encode(['ok' => false, 'description' => 'Failed to set webhook']);
            exit;
        }
        
        // Default health check response
        header('Content-Type: text/plain');
        echo "Telegram Bot is running!\n\n";
        echo "Environment:\n";
        echo "BOT_TOKEN: " . (BOT_TOKEN ? 'set' : 'not set') . "\n";
        echo "WEBHOOK_URL: " . (WEBHOOK_URL ? WEBHOOK_URL : 'not set') . "\n";
        echo "USERS_FILE: " . (file_exists(USERS_FILE) ? 'exists' : 'missing') . "\n";
        echo "ERROR_LOG: " . (file_exists(ERROR_LOG) ? 'exists' : 'missing') . "\n";
        
        http_response_code(200);
    } else {
        http_response_code(400);
        echo "Invalid request method";
    }
}