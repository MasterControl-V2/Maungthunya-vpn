<?php
/**
 * EVT VPN Bot - VLESS Manager + Auto Inbound Creator
 * Developer: @evtvpn143
 * @version 14.0 - Auto Create Inbound | Port & Path Select
 */

// ==================== BOT CONFIGURATION ====================
define('BOT_TOKEN', '8954095695:AAFwWLcdvCpcb723rZB4-D4HHeDYigd6fCU');
define('BOT_USERNAME', '@Zero_Free_Vpn');
define('ADMIN_ID', 7576434717);

// User Default GB
define('DEFAULT_USER_GB', 100);

// Transform Ports
$TRANSFORM_PORTS = ['443', '2053', '2083', '2087', '2096', '8443'];

// Bug Domains
$BUG_DOMAINS = [
    '172.67.133.97', 'mpt.com.mm', 'ceir.gov.mm', 'uab.com.mm',
    'yomabank.com', 'wavemoney.com.mm', 'developers.cloudflare.com',
    'support.cloudflare.com', 'cloudflare.com', 'cdn.cloudflare.com'
];

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// ==================== MAIN PROCESSING ====================
$method = $_SERVER['REQUEST_METHOD'];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$input = file_get_contents('php://input');

if ($method === 'POST' && strpos($contentType, 'application/json') !== false) {
    $update = json_decode($input, true);
    if ($update) {
        if (isset($update['message'])) handleTelegramUpdate($update);
        elseif (isset($update['callback_query'])) handleCallbackQuery($update['callback_query']);
        exit;
    }
}

if ($method === 'GET') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'active', 'version' => '14.0', 'dev' => '@evtvpn143']);
    exit;
}

// ==================== TELEGRAM HANDLER ====================

function handleTelegramUpdate($update) {
    $msg = $update['message'];
    $cid = $msg['chat']['id'];
    $text = trim($msg['text'] ?? '');
    $fname = $msg['from']['first_name'] ?? 'User';
    $fid = $msg['from']['id'];
    $uname = $msg['from']['username'] ?? '';
    $isAdmin = ($fid == ADMIN_ID);

    registerUser($fid, $fname, $uname, $isAdmin);
    debugLog("Chat: $cid - $fid - $text");

    $state = getUserState($cid);

    if (strpos($text, '/') === 0) {
        handleCommand($cid, $text, $fname, $isAdmin, $fid);
        return;
    }

    // ==================== TEXT INPUT STATES ====================
    
    if ($state === 'awaiting_bug_domain_text') {
        saveTempData($cid, 'bug_domain', $text);
        setUserState($cid, 'awaiting_port_select');
        sendMessage($cid, "🐛 <b>{$text}</b>\n\n🔌 <b>Select Port:</b>", 'HTML');
        showPortKeyboard($cid);
        return;
    }
    
    // Path input after port select
    if ($state === 'awaiting_custom_path') {
        $path = trim($text);
        if ($path === '') $path = '/';
        if (!str_starts_with($path, '/')) $path = '/' . $path;
        saveTempData($cid, 'custom_path', $path);
        clearUserState($cid);
        sendMessage($cid, "📁 Path: <b>{$path}</b>\n\n🔄 Creating VLESS...", 'HTML');
        createVlessFinal($cid);
        return;
    }

    // Edit Panel flows (keep original)
    if ($state === 'awaiting_edit_panel_name') {
        $idx = getTempData($cid, 'edit_panel_idx');
        if ($text !== '-') {
            updatePanelField($cid, $idx, 'name', $text);
            sendMessage($cid, "✅ Name updated to: <b>{$text}</b>", 'HTML');
        }
        saveTempData($cid, 'edit_panel_idx', $idx);
        setUserState($cid, 'awaiting_edit_panel_url');
        sendMessage($cid, "🔗 Enter new URL (or send '-' to skip):", 'HTML');
        return;
    }
    
    if ($state === 'awaiting_edit_panel_url') {
        $idx = getTempData($cid, 'edit_panel_idx');
        if ($text !== '-') {
            $url = filter_var($text, FILTER_VALIDATE_URL) ? $text : 'https://' . $text;
            updatePanelField($cid, $idx, 'url', rtrim($url, '/'));
            sendMessage($cid, "✅ URL updated", 'HTML');
        }
        saveTempData($cid, 'edit_panel_idx', $idx);
        setUserState($cid, 'awaiting_edit_panel_username');
        sendMessage($cid, "👤 Enter new Username (or send '-' to skip):", 'HTML');
        return;
    }
    
    if ($state === 'awaiting_edit_panel_username') {
        $idx = getTempData($cid, 'edit_panel_idx');
        if ($text !== '-') {
            updatePanelField($cid, $idx, 'username', $text);
            sendMessage($cid, "✅ Username updated", 'HTML');
        }
        saveTempData($cid, 'edit_panel_idx', $idx);
        setUserState($cid, 'awaiting_edit_panel_password');
        sendMessage($cid, "🔑 Enter new Password (or send '-' to skip):", 'HTML');
        return;
    }
    
    if ($state === 'awaiting_edit_panel_password') {
        $idx = getTempData($cid, 'edit_panel_idx');
        if ($text !== '-') {
            updatePanelField($cid, $idx, 'password', $text);
            sendMessage($cid, "✅ Password updated", 'HTML');
        }
        saveTempData($cid, 'edit_panel_idx', $idx);
        setUserState($cid, 'awaiting_edit_panel_location');
        sendMessage($cid, "📍 Enter new Location (or send '-' to skip):", 'HTML');
        return;
    }
    
    if ($state === 'awaiting_edit_panel_location') {
        $idx = getTempData($cid, 'edit_panel_idx');
        if ($text !== '-') {
            updatePanelField($cid, $idx, 'location', $text);
            sendMessage($cid, "✅ Location updated to: <b>{$text}</b>", 'HTML');
        }
        saveTempData($cid, 'edit_panel_idx', $idx);
        setUserState($cid, 'awaiting_edit_panel_domain');
        sendMessage($cid, "🌐 Enter new Domain/SNI (or send '-' to skip):", 'HTML');
        return;
    }
    
    if ($state === 'awaiting_edit_panel_domain') {
        $idx = getTempData($cid, 'edit_panel_idx');
        if ($text !== '-') {
            $domain = str_replace(['http://', 'https://'], '', $text);
            updatePanelField($cid, $idx, 'domain', $domain);
            sendMessage($cid, "✅ Domain updated to: <b>{$domain}</b>", 'HTML');
        }
        clearUserState($cid); clearTempData($cid);
        sendMessage($cid, "✅ <b>Panel Edit Complete!</b>\n\nUse <code>/panels</code> to view changes.", 'HTML');
        return;
    }

    // ==================== ADD PANEL FLOW ====================
    
    if ($state === 'awaiting_panel_name') {
        saveTempData($cid, 'pn_name', $text);
        setUserState($cid, 'awaiting_panel_url');
        sendMessage($cid, "✅ Name: <b>{$text}</b>\n\n🔗 Panel URL:\n<code>https://host.com/path</code>", 'HTML');
        return;
    }
    if ($state === 'awaiting_panel_url') {
        $url = filter_var($text, FILTER_VALIDATE_URL) ? $text : 'https://' . $text;
        saveTempData($cid, 'pn_url', rtrim($url, '/'));
        setUserState($cid, 'awaiting_panel_username');
        sendMessage($cid, "✅ URL saved\n\n👤 Username:", 'HTML');
        return;
    }
    if ($state === 'awaiting_panel_username') {
        saveTempData($cid, 'pn_username', $text);
        setUserState($cid, 'awaiting_panel_password');
        sendMessage($cid, "✅ User saved\n\n🔑 Password:", 'HTML');
        return;
    }
    if ($state === 'awaiting_panel_password') {
        saveTempData($cid, 'pn_password', $text);
        setUserState($cid, 'awaiting_panel_location');
        sendMessage($cid, "✅ Password saved\n\n📍 Location:\n<code>Singapore</code>", 'HTML');
        return;
    }
    if ($state === 'awaiting_panel_location') {
        saveTempData($cid, 'pn_location', $text);
        setUserState($cid, 'awaiting_panel_domain');
        sendMessage($cid, "✅ Location: <b>{$text}</b>\n\n🌐 Original Domain (SNI):\n<code>my-vps.com</code>", 'HTML');
        return;
    }
    if ($state === 'awaiting_panel_domain') {
        $domain = str_replace(['http://', 'https://'], '', $text);
        saveTempData($cid, 'pn_domain', $domain);
        
        $pnData = [
            'url' => getTempData($cid, 'pn_url'),
            'username' => getTempData($cid, 'pn_username'),
            'password' => getTempData($cid, 'pn_password'),
            'location' => getTempData($cid, 'pn_location'),
            'domain' => $domain,
            'type' => 'Premium'
        ];
        $pnName = getTempData($cid, 'pn_name');
        addPanelToFile($pnName, $pnData, $cid);
        
        clearUserState($cid); clearTempData($cid);
        sendMessage($cid, "✅ <b>Panel Added!</b>\n\n📡 <b>{$pnName}</b>\n📍 {$pnData['location']}\n🌐 {$domain}\n\nCommands:\n<code>/create name days</code>\n<code>/panels</code>", 'HTML');
        return;
    }

    // ==================== CREATE FLOW ====================
    
    if ($state === 'awaiting_name') {
        saveTempData($cid, 'name', trim($text));
        setUserState($cid, 'awaiting_days');
        sendMessage($cid, "✅ Name: <b>{$text}</b>\n\n📅 Enter Days:\n<code>30</code>", 'HTML');
        return;
    }
    if ($state === 'awaiting_days') {
        $days = (int)$text;
        if ($days <= 0) { sendMessage($cid, "❌ Days > 0!"); return; }
        saveTempData($cid, 'days', $days);
        setUserState($cid, 'awaiting_panel_select');
        sendMessage($cid, "✅ {$days} days\n\n📡 <b>Select Panel:</b>", 'HTML');
        showPanelKeyboard($cid);
        return;
    }
    if ($state === 'awaiting_admin_name') {
        saveTempData($cid, 'name', trim($text));
        setUserState($cid, 'awaiting_admin_gb');
        sendMessage($cid, "✅ Name: <b>{$text}</b>\n\n📊 Enter GB:", 'HTML');
        return;
    }
    if ($state === 'awaiting_admin_gb') {
        $gb = (int)$text;
        if ($gb <= 0) { sendMessage($cid, "❌ GB > 0!"); return; }
        saveTempData($cid, 'gb', $gb);
        setUserState($cid, 'awaiting_admin_days');
        sendMessage($cid, "✅ {$gb}GB\n\n📅 Days:", 'HTML');
        return;
    }
    if ($state === 'awaiting_admin_days') {
        $days = (int)$text;
        if ($days <= 0) { sendMessage($cid, "❌ Days > 0!"); return; }
        saveTempData($cid, 'days', $days);
        setUserState($cid, 'awaiting_panel_select');
        sendMessage($cid, "✅ {$days} days\n\n📡 <b>Select Panel:</b>", 'HTML');
        showPanelKeyboard($cid);
        return;
    }

    // Admin credit flow
    if ($state === 'awaiting_addcredit_user') {
        saveTempData($cid, 'au', $text);
        setUserState($cid, 'awaiting_addcredit_amount');
        sendMessage($cid, "✅ User: {$text}\n💰 Amount:", 'HTML');
        return;
    }
    if ($state === 'awaiting_addcredit_amount') {
        $nb = addUserCredit(getTempData($cid, 'au'), (float)$text);
        clearUserState($cid); clearTempData($cid);
        sendMessage($cid, "✅ Balance: <b>{$nb}cr</b>", 'HTML');
        return;
    }
    if ($state === 'awaiting_delcredit_user') {
        saveTempData($cid, 'du', $text);
        setUserState($cid, 'awaiting_delcredit_amount');
        sendMessage($cid, "✅ User: {$text}\n💰 Amount:", 'HTML');
        return;
    }
    if ($state === 'awaiting_delcredit_amount') {
        $nb = deductUserCredit(getTempData($cid, 'du'), (float)$text);
        clearUserState($cid); clearTempData($cid);
        sendMessage($cid, "✅ Balance: <b>{$nb}cr</b>", 'HTML');
        return;
    }

    sendMessage($cid, "❌ Unknown. Type /help for commands", 'HTML');
}

// ==================== CALLBACK QUERY HANDLER ====================

function handleCallbackQuery($cb) {
    $data = $cb['data'];
    $cid = $cb['message']['chat']['id'];
    $mid = $cb['message']['message_id'];
    $state = getUserState($cid);
    
    answerCallbackQuery($cb['id']);

    // Panel management
    if ($data === 'panel_settings') {
        showPanelManagement($cid, $mid);
        return;
    }

    if (strpos($data, 'edit_panel_') === 0) {
        $idx = (int)substr($data, 11);
        $panels = getUserPanels($cid);
        $panel = $panels[$idx] ?? null;
        if (!$panel) { editMessageText($cid, $mid, "❌ Panel not found!"); return; }
        
        saveTempData($cid, 'edit_panel_idx', $idx);
        setUserState($cid, 'awaiting_edit_panel_name');
        editMessageText($cid, $mid, "✏️ <b>Edit Panel: {$panel['name']}</b>\n\nEnter new name (or send <code>-</code> to skip):\nCurrent: <b>{$panel['name']}</b>\n\nType /cancel to abort.");
        return;
    }

    if (strpos($data, 'delete_panel_') === 0) {
        $idx = (int)substr($data, 13);
        $panels = getUserPanels($cid);
        $panel = $panels[$idx] ?? null;
        if (!$panel) { editMessageText($cid, $mid, "❌ Panel not found!"); return; }
        
        $kb = [
            [['text' => '✅ Yes, Delete', 'callback_data' => 'confirm_delete_' . $idx]],
            [['text' => '❌ Cancel', 'callback_data' => 'panel_settings']]
        ];
        editMessageText($cid, $mid, "⚠️ <b>Delete Panel?</b>\n\n📡 <b>{$panel['name']}</b>\n📍 {$panel['location']}\n🌐 {$panel['domain']}\n\nThis cannot be undone!", 'HTML', json_encode(['inline_keyboard' => $kb]));
        return;
    }

    if (strpos($data, 'confirm_delete_') === 0) {
        $idx = (int)substr($data, 15);
        $result = deletePanel($cid, $idx);
        if ($result) {
            editMessageText($cid, $mid, "✅ Panel deleted successfully!");
            sendMessage($cid, "📡 Panel removed. Use <code>/panels</code> to view remaining.", 'HTML');
        } else {
            editMessageText($cid, $mid, "❌ Failed to delete panel!");
        }
        return;
    }

    // CREATE FLOW CALLBACKS
    if ($state === 'awaiting_panel_select' && strpos($data, 'panel_') === 0) {
        $idx = (int)substr($data, 6);
        $panels = getUserPanels($cid);
        $selected = $panels[$idx] ?? null;
        if (!$selected) { editMessageText($cid, $mid, "❌ Invalid panel!"); return; }
        
        saveTempData($cid, 'selected_panel', $selected);
        setUserState($cid, 'awaiting_bug_domain');
        
        $name = getTempData($cid, 'name');
        $days = getTempData($cid, 'days');
        $gb = getTempData($cid, 'gb') ?: DEFAULT_USER_GB;
        
        editMessageText($cid, $mid, "📡 <b>{$selected['name']}</b> - {$selected['location']}\n\n🐛 <b>Select Bug Domain:</b>");
        showBugDomainKeyboard($cid);
        return;
    }

    if ($state === 'awaiting_bug_domain') {
        if ($data === 'custom_bug') {
            setUserState($cid, 'awaiting_bug_domain_text');
            editMessageText($cid, $mid, "✏️ Type your custom Bug Domain/IP:\n\nType /cancel to abort.");
            return;
        }
        if (strpos($data, 'bug_') === 0) {
            $bd = substr($data, 4);
            saveTempData($cid, 'bug_domain', $bd);
            setUserState($cid, 'awaiting_port_select');
            editMessageText($cid, $mid, "🐛 Bug: <b>{$bd}</b>\n\n🔌 <b>Select Transform Port:</b>");
            showPortKeyboard($cid);
            return;
        }
    }

    // Port select -> Ask for path
    if ($state === 'awaiting_port_select' && strpos($data, 'port_') === 0) {
        $port = substr($data, 5);
        saveTempData($cid, 'transform_port', $port);
        setUserState($cid, 'awaiting_custom_path');
        editMessageText($cid, $mid, "🔌 Port: <b>{$port}</b>\n\n📁 Enter WebSocket Path:\n(Example: <code>/</code> or <code>/ray</code> or <code>/wss</code>)\n\nSend path or <code>/</code> for default", 'HTML');
        return;
    }

    // Normal buttons
    if ($data === 'custom_bug') {
        setUserState($cid, 'awaiting_bug_domain_text');
        editMessageText($cid, $mid, "✏️ Type your custom Bug Domain/IP:");
        return;
    }
    
    if (strpos($data, 'bug_') === 0) {
        $bd = substr($data, 4);
        saveUserSetting($cid, 'bug_domain', $bd, 'default');
        editMessageText($cid, $mid, "✅ Default Bug Domain: <b>{$bd}</b>");
        return;
    }

    if ($data === 'create_account') {
        handleCreateCommand($cid, $cid);
        return;
    }
    if ($data === 'add_panel') {
        setUserState($cid, 'awaiting_panel_name');
        sendMessage($cid, "📡 <b>Add New Panel</b>\n\nEnter Panel Name:\nExample: <code>🇸🇬 Singapore #1</code>", 'HTML');
        return;
    }
    if ($data === 'check_balance') {
        $c = getUserCredit($cid);
        sendMessage($cid, "💰 <b>{$c} credit</b>", 'HTML');
        return;
    }
    if ($data === 'my_panels') {
        listUserPanels($cid);
        return;
    }
    if ($data === 'get_qr') {
        $lc = getLastConfig($cid);
        if ($lc) generateAndSendQR($cid, $lc);
        else sendMessage($cid, "❌ No config yet!");
        return;
    }
}

// ==================== COMMAND HANDLER ====================

function handleCommand($cid, $text, $fname, $isAdmin, $fid) {
    $cmd = strtolower(explode(' ', $text)[0]);
    $params = trim(substr($text, strlen($cmd)));
    $parts = explode(' ', $params);

    if ($cmd === '/create' && count($parts) >= 2) {
        $name = $parts[0];
        
        if ($isAdmin && count($parts) >= 3) {
            $gb = (int)$parts[1];
            $days = (int)$parts[2];
            if ($gb <= 0 || $days <= 0) {
                sendMessage($cid, "❌ Format: <code>/create name gb days</code>\nExample: <code>/create sai 200 30</code>", 'HTML');
                return;
            }
            saveTempData($cid, 'name', $name);
            saveTempData($cid, 'gb', $gb);
            saveTempData($cid, 'days', $days);
            setUserState($cid, 'awaiting_panel_select');
            
            $msg = "📝 <b>Admin Create VLESS</b>\n\n👤 {$name}\n📊 {$gb}GB\n📅 {$days} days\n\n📡 <b>Select Panel:</b>";
            sendMessage($cid, $msg, 'HTML');
            showPanelKeyboard($cid);
            return;
        }
        
        $days = (int)$parts[1];
        if ($days <= 0) {
            sendMessage($cid, "❌ Format: <code>/create name days</code>\nExample: <code>/create sai 30</code>", 'HTML');
            return;
        }
        
        $panels = getUserPanels($cid);
        if (empty($panels)) {
            sendMessage($cid, "⚠️ No panels configured!\nUse <code>/addpanel</code> first.", 'HTML');
            return;
        }
        
        saveTempData($cid, 'name', $name);
        saveTempData($cid, 'gb', DEFAULT_USER_GB);
        saveTempData($cid, 'days', $days);
        setUserState($cid, 'awaiting_panel_select');
        
        $msg = "📝 <b>Create VLESS</b>\n\n👤 {$name}\n📊 " . DEFAULT_USER_GB . "GB (Fixed)\n📅 {$days} days\n\n📡 <b>Select Panel:</b>";
        sendMessage($cid, $msg, 'HTML');
        showPanelKeyboard($cid);
        return;
    }

    if ($cmd === '/create' && empty($params)) {
        handleCreateCommand($cid, $fid);
        return;
    }
    
    switch ($cmd) {
        case '/start':
            $cr = getUserCredit($cid);
            $adm = ($fid == ADMIN_ID || isAdmin($fid));
            $pnCount = count(getUserPanels($cid));

            $msg = "🚀 <b>EVT VPN Bot</b> v14.0 (Auto Inbound)\n\n";
            $msg .= "👋 Hello <b>{$fname}</b>!\n";
            $msg .= "🆔 <code>{$fid}</code>\n";
            $msg .= "💰 <b>{$cr} credit</b>\n";
            $msg .= "🏷️ " . ($adm ? "👑 Admin" : "👤 User") . "\n";
            $msg .= "📡 Panels: <b>{$pnCount}</b>\n\n";

            if ($pnCount == 0) {
                $msg .= "⚠️ <b>No Panels!</b>\nUse <code>/addpanel</code> to add server.\n\n";
            }

            $msg .= "📌 <b>New Feature:</b> Bot auto-creates inbound with your port & path!\n\n";
            $msg .= "🔗 <b>Commands:</b>\n";
            $msg .= "<code>/addpanel</code> - Add server\n";
            $msg .= "<code>/create name days</code> - Create VLESS\n";
            $msg .= "<code>/panels</code> - View & manage panels\n";
            $msg .= "<code>/balance</code> | <code>/bug</code> | <code>/qr</code>\n";
            $msg .= "<code>/traffic email</code> | <code>/delete email</code>\n";

            if ($adm) {
                $msg .= "\n👑 <b>Admin:</b>\n";
                $msg .= "<code>/create name gb days</code>\n";
                $msg .= "<code>/addcredit</code> | <code>/delcredit</code>\n";
                $msg .= "<code>/users</code> | <code>/broadcast</code>\n";
            }

            $kb = [
                [['text' => '📡 Add Panel', 'callback_data' => 'add_panel']],
                [['text' => '📝 Create VLESS', 'callback_data' => 'create_account']],
                [['text' => '⚙️ Manage Panels', 'callback_data' => 'panel_settings']],
                [['text' => '💰 Balance', 'callback_data' => 'check_balance']]
            ];
            sendMessage($cid, $msg, 'HTML', json_encode(['inline_keyboard' => $kb]));
            break;

        case '/addpanel':
            setUserState($cid, 'awaiting_panel_name');
            sendMessage($cid, "📡 <b>Add New Panel</b>\n\nStep 1/6: Panel Name:\nExample: <code>🇸🇬 Singapore #1</code>", 'HTML');
            break;

        case '/panels':
            showPanelManagement($cid);
            break;

        case '/balance':
            $c = getUserCredit($cid);
            sendMessage($cid, "💰 <b>{$c} credit</b>", 'HTML');
            break;

        case '/bug':
            showBugDomainKeyboard($cid);
            break;

        case '/traffic':
            if (empty($params)) { sendMessage($cid, "❌ <code>/traffic email</code>", 'HTML'); return; }
            checkTraffic($cid, $params);
            break;

        case '/delete':
            if (empty($params)) { sendMessage($cid, "❌ <code>/delete email</code>", 'HTML'); return; }
            deleteAccount($cid, $params);
            break;

        case '/qr':
            $lc = getLastConfig($cid);
            if ($lc) generateAndSendQR($cid, $lc);
            else sendMessage($cid, "❌ Create account first!");
            break;

        case '/history':
            showHistory($cid);
            break;

        case '/help':
            showHelp($cid, $fid);
            break;

        case '/cancel':
            clearUserState($cid); clearTempData($cid);
            sendMessage($cid, "❌ Cancelled.");
            break;

        case '/addcredit':
            if ($fid != ADMIN_ID && !isAdmin($fid)) { sendMessage($cid, "⛔ Admin only!"); return; }
            setUserState($cid, 'awaiting_addcredit_user');
            sendMessage($cid, "💰 <b>Add Credit</b>\n\nEnter User ID:", 'HTML');
            break;

        case '/delcredit':
            if ($fid != ADMIN_ID && !isAdmin($fid)) { sendMessage($cid, "⛔ Admin only!"); return; }
            setUserState($cid, 'awaiting_delcredit_user');
            sendMessage($cid, "💰 <b>Deduct Credit</b>\n\nEnter User ID:", 'HTML');
            break;

        case '/users':
            if ($fid != ADMIN_ID && !isAdmin($fid)) { sendMessage($cid, "⛔ Admin only!"); return; }
            showAllUsers($cid);
            break;

        case '/addadmin':
            if ($fid != ADMIN_ID) return;
            if (empty($params)) { sendMessage($cid, "❌ <code>/addadmin user_id</code>", 'HTML'); return; }
            addAdmin($params);
            sendMessage($cid, "✅ User <code>{$params}</code> is now Admin!", 'HTML');
            break;

        case '/deladmin':
            if ($fid != ADMIN_ID) return;
            if (empty($params)) { sendMessage($cid, "❌ <code>/deladmin user_id</code>", 'HTML'); return; }
            removeAdmin($params);
            sendMessage($cid, "✅ Removed from Admin.", 'HTML');
            break;

        case '/broadcast':
            if ($fid != ADMIN_ID && !isAdmin($fid)) { sendMessage($cid, "⛔ Admin only!"); return; }
            if (empty($params)) { sendMessage($cid, "❌ <code>/broadcast message</code>", 'HTML'); return; }
            broadcastMessage($cid, $params);
            break;

        default:
            sendMessage($cid, "❌ Unknown. Type /help", 'HTML');
    }
}

// ==================== CREATE VLESS (UPDATED) ====================

function handleCreateCommand($cid, $fid) {
    $isAdmin = ($fid == ADMIN_ID || isAdmin($fid));
    $panels = getUserPanels($cid);
    
    if (empty($panels)) {
        sendMessage($cid, "⚠️ No panels!\nUse <code>/addpanel</code> first", 'HTML');
        return;
    }

    if ($isAdmin) {
        setUserState($cid, 'awaiting_admin_name');
        sendMessage($cid, "📝 <b>Admin Create VLESS</b>\n\nEnter account name:", 'HTML');
    } else {
        setUserState($cid, 'awaiting_name');
        sendMessage($cid, "📝 <b>Create VLESS</b>\n\nEnter account name:\n📊 GB: <b>" . DEFAULT_USER_GB . "GB</b> (Fixed)", 'HTML');
    }
}

function createVlessFinal($cid) {
    $name = getTempData($cid, 'name');
    $gb = getTempData($cid, 'gb') ?: DEFAULT_USER_GB;
    $days = getTempData($cid, 'days');
    $panel = getTempData($cid, 'selected_panel');
    $bugDomain = getTempData($cid, 'bug_domain');
    $port = getTempData($cid, 'transform_port');
    $customPath = getTempData($cid, 'custom_path') ?: '/';

    if (!$name || !$days || !$panel) {
        sendMessage($cid, "❌ Session expired. Use /create again.");
        clearTempData($cid);
        return;
    }

    sendMessage($cid, "🔄 <b>Creating VLESS on {$panel['name']}...</b>\n🔌 Port: {$port}\n📁 Path: {$customPath}", 'HTML');

    $cookie = apiLoginDirect($panel['url'], $panel['username'], $panel['password']);
    if (!$cookie) {
        sendMessage($cid, "❌ Panel login failed!\nCheck credentials in <code>/panels</code>", 'HTML');
        clearTempData($cid);
        return;
    }

    // Call api.php with auto inbound creation
    $apiUrl = rtrim($panel['url'], '/') . '/api.php';
    $query = http_build_query([
        'api_key' => 't.me/evtvpn143',
        'panel' => 1,
        'name' => $name,
        'gb' => $gb,
        'exp' => $days,
        'port' => $port,
        'path' => $customPath
    ]);
    
    $fullUrl = $apiUrl . '?' . $query;
    
    $ch = curl_init($fullUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER => ["Cookie: $cookie", "Accept: application/json"]
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$response) {
        sendMessage($cid, "❌ API request failed (HTTP {$httpCode})", 'HTML');
        clearTempData($cid);
        return;
    }
    
    $result = json_decode($response, true);
    
    if (!$result || !isset($result['success']) || !$result['success']) {
        $errorMsg = $result['msg'] ?? 'Unknown error from panel';
        sendMessage($cid, "❌ Create failed!\n{$errorMsg}", 'HTML');
        clearTempData($cid);
        return;
    }
    
    // Build final config with bug domain
    $panelHost = parse_url($panel['url'], PHP_URL_HOST);
    $sni = $panel['domain'] ?? $panelHost;
    $bd = $bugDomain ?: 'mpt.com.mm';
    $pt = $port ?: '443';
    $pathRaw = $customPath;
    $uuid = $result['uuid'];
    
    $finalConfig = "vless://{$uuid}@{$bd}:{$pt}?type=ws&security=tls&encryption=none&host=" . urlencode($sni) . "&path=" . urlencode($pathRaw) . "&sni=" . urlencode($sni) . "&alpn=http/1.1&fp=chrome#EVT-{$name}";
    
    saveVlessHistory($cid, $name, $gb, $days, $uuid, $finalConfig);
    saveLastConfig($cid, $finalConfig);
    if ($bugDomain) saveUserSetting($cid, 'bug_domain', $bugDomain, 'default');
    
    $msg = "✅ <b>VLESS Created (Auto Inbound)!</b>\n\n";
    $msg .= "👤 <code>{$name}</code>\n";
    $msg .= "🔑 <code>{$uuid}</code>\n";
    $msg .= "📊 {$gb}GB | 📅 {$days}d\n";
    $msg .= "📡 {$panel['name']}\n";
    $msg .= "🔌 Port: <code>{$pt}</code>\n";
    $msg .= "📁 Path: <code>{$pathRaw}</code>\n";
    $msg .= "🐛 Bug: <code>{$bd}</code>\n\n";
    $msg .= "📎 <b>Config:</b>\n";
    $msg .= "<code>{$finalConfig}</code>\n\n";
    $msg .= "👨‍💻 @evtvpn143";

    clearTempData($cid);

    $kb = [
        [['text' => '📱 QR Code', 'callback_data' => 'get_qr']],
        [['text' => '📝 Create Another', 'callback_data' => 'create_account']]
    ];
    sendMessage($cid, $msg, 'HTML', json_encode(['inline_keyboard' => $kb]));
}

// ==================== PANEL API FUNCTIONS ====================

function apiLoginDirect($url, $user, $pass) {
    $ch = curl_init(rtrim($url, '/') . '/login');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['username' => $user, 'password' => $pass]),
        CURLOPT_HEADER => true
    ]);
    $res = curl_exec($ch);
    if (curl_errno($ch)) { curl_close($ch); return false; }
    curl_close($ch);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $res, $m);
    return !empty($m[1]) ? implode('; ', $m[1]) : false;
}

function apiCallDirect($url, $cookie, $endpoint, $data = [], $method = "POST") {
    $fullUrl = rtrim($url, '/') . '/' . ltrim($endpoint, '/');
    $ch = curl_init($fullUrl);
    $opts = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER => ["Cookie: $cookie", "Content-Type: application/json", "Accept: application/json"],
        CURLOPT_CUSTOMREQUEST => $method
    ];
    if ($method === "POST" && !empty($data)) $opts[CURLOPT_POSTFIELDS] = json_encode($data);
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    if (curl_errno($ch)) { curl_close($ch); return ['success' => false, 'msg' => curl_error($ch)]; }
    curl_close($ch);
    return json_decode($res, true) ?: $res;
}

function generateUUID() {
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

function formatBytes($bytes, $p = 2) {
    $u = ['B','KB','MB','GB','TB']; $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024)); $pow = min($pow, count($u)-1);
    return round($bytes/pow(1024,$pow), $p) . ' ' . $u[$pow];
}

// ==================== TRAFFIC / DELETE ====================

function checkTraffic($cid, $email) {
    $panels = getUserPanels($cid);
    if (empty($panels)) { sendMessage($cid, "❌ No panels!", 'HTML'); return; }
    
    $panel = $panels[0];
    $cookie = apiLoginDirect($panel['url'], $panel['username'], $panel['password']);
    if (!$cookie) { sendMessage($cid, "❌ Login failed!"); return; }
    
    $r = apiCallDirect($panel['url'], $cookie, "xui/API/inbounds/getClientTraffics/{$email}", [], "GET");
    if (!isset($r['obj'])) { sendMessage($cid, "❌ Not found: <code>{$email}</code>", 'HTML'); return; }
    
    $t = $r['obj'];
    $msg = "📊 <b>{$email}</b>\n";
    $msg .= "⬆️ " . formatBytes($t['up']) . " ⬇️ " . formatBytes($t['down']) . "\n";
    $msg .= "📈 " . formatBytes($t['up']+$t['down']) . " / " . ($t['total']>0 ? formatBytes($t['total']) : 'Unlimited') . "\n";
    $msg .= "⏰ " . ($t['expiryTime']>0 ? date('Y-m-d H:i:s', (int)($t['expiryTime']/1000)) : 'Never');
    sendMessage($cid, $msg, 'HTML');
}

function deleteAccount($cid, $email) {
    $panels = getUserPanels($cid);
    if (empty($panels)) { sendMessage($cid, "❌ No panels!", 'HTML'); return; }
    
    $panel = $panels[0];
    $cookie = apiLoginDirect($panel['url'], $panel['username'], $panel['password']);
    if (!$cookie) { sendMessage($cid, "❌ Login failed!"); return; }
    
    $list = apiCallDirect($panel['url'], $cookie, "xui/API/inbounds/", [], "GET");
    foreach ($list['obj'] as $ib) {
        $s = json_decode($ib['settings'], true);
        if (isset($s['clients'])) {
            foreach ($s['clients'] as $c) {
                if ($c['email'] === $email) {
                    $r = apiCallDirect($panel['url'], $cookie, "xui/API/inbounds/{$ib['id']}/delClient/{$c['id']}", [], "POST");
                    sendMessage($cid, $r['success'] ? "✅ Deleted: <code>{$email}</code>" : "❌ Failed", 'HTML');
                    return;
                }
            }
        }
    }
    sendMessage($cid, "❌ Not found: <code>{$email}</code>", 'HTML');
}

// ==================== UI FUNCTIONS ====================

function showPanelKeyboard($cid) {
    $panels = getUserPanels($cid);
    if (empty($panels)) {
        sendMessage($cid, "📭 No panels.\n<code>/addpanel</code>", 'HTML');
        return;
    }
    
    $kb = [];
    foreach ($panels as $i => $p) {
        $kb[] = [['text' => "{$p['name']} ({$p['location']})", 'callback_data' => 'panel_' . $i]];
    }
    sendMessage($cid, "📡 <b>Choose Panel:</b>", 'HTML', json_encode(['inline_keyboard' => $kb]));
}

function showPortKeyboard($cid) {
    global $TRANSFORM_PORTS;
    $kb = []; $row = [];
    foreach ($TRANSFORM_PORTS as $p) {
        $row[] = ['text' => "Port {$p}", 'callback_data' => 'port_' . $p];
        if (count($row) == 3) { $kb[] = $row; $row = []; }
    }
    if (!empty($row)) $kb[] = $row;
    sendMessage($cid, "🔌 <b>Select Port:</b>", 'HTML', json_encode(['inline_keyboard' => $kb]));
}

function showBugDomainKeyboard($cid) {
    global $BUG_DOMAINS;
    $kb = []; $row = [];
    foreach ($BUG_DOMAINS as $d) {
        $row[] = ['text' => $d, 'callback_data' => 'bug_' . $d];
        if (count($row) == 2) { $kb[] = $row; $row = []; }
    }
    if (!empty($row)) $kb[] = $row;
    $kb[] = [['text' => '✏️ Custom Domain/IP', 'callback_data' => 'custom_bug']];
    sendMessage($cid, "🐛 <b>Select Bug Domain:</b>", 'HTML', json_encode(['inline_keyboard' => $kb]));
}

// ==================== PANEL MANAGEMENT FUNCTIONS (Keep original) ====================

function showPanelManagement($cid, $mid = null) {
    $panels = getUserPanels($cid);
    
    if (empty($panels)) {
        $msg = "📭 <b>No panels configured!</b>\n\nUse <code>/addpanel</code> to add a server.";
        if ($mid) editMessageText($cid, $mid, $msg);
        else sendMessage($cid, $msg, 'HTML');
        return;
    }
    
    $msg = "⚙️ <b>Panel Management</b>\n\n";
    $msg .= "Total: <b>" . count($panels) . "</b> panels\n\n";
    $msg .= "Select a panel to edit or delete:\n";
    
    $kb = [];
    foreach ($panels as $i => $p) {
        $msg .= ($i+1) . ". <b>{$p['name']}</b> - {$p['location']}\n";
        $kb[] = [
            ['text' => "✏️ {$p['name']}", 'callback_data' => "edit_panel_{$i}"],
            ['text' => "🗑️", 'callback_data' => "delete_panel_{$i}"]
        ];
    }
    $kb[] = [['text' => '📡 Add New Panel', 'callback_data' => 'add_panel']];
    $kb[] = [['text' => '🔙 Back', 'callback_data' => 'my_panels']];
    
    if ($mid) {
        editMessageText($cid, $mid, $msg, 'HTML', json_encode(['inline_keyboard' => $kb]));
    } else {
        sendMessage($cid, $msg, 'HTML', json_encode(['inline_keyboard' => $kb]));
    }
}

function updatePanelField($cid, $idx, $field, $value) {
    $file = __DIR__ . "/user_{$cid}.json";
    if (!file_exists($file)) return false;
    
    $data = json_decode(file_get_contents($file), true);
    if (!isset($data['panels'][$idx])) return false;
    
    $data['panels'][$idx][$field] = $value;
    file_put_contents($file, json_encode($data));
    syncPanelsToFile($cid);
    return true;
}

function deletePanel($cid, $idx) {
    $file = __DIR__ . "/user_{$cid}.json";
    if (!file_exists($file)) return false;
    
    $data = json_decode(file_get_contents($file), true);
    if (!isset($data['panels'][$idx])) return false;
    
    unset($data['panels'][$idx]);
    $data['panels'] = array_values($data['panels']);
    file_put_contents($file, json_encode($data));
    syncPanelsToFile($cid);
    return true;
}

function syncPanelsToFile($cid) {
    $panels = getUserPanels($cid);
    $pfile = __DIR__ . '/panels.php';
    
    $content = "<?php\ndeclare(strict_types=1);\n\n";
    $content .= "if (basename(\$_SERVER['PHP_SELF']) === 'panels.php') {\n";
    $content .= "    header('Content-Type: application/json');\n}\n\n";
    $content .= "\$panels = [\n";
    
    foreach ($panels as $p) {
        $content .= "    '" . addslashes($p['name']) . "' => [\n";
        $content .= "        'url' => '" . addslashes($p['url']) . "',\n";
        $content .= "        'username' => '" . addslashes($p['username']) . "',\n";
        $content .= "        'password' => '" . addslashes($p['password']) . "',\n";
        $content .= "        'location' => '" . addslashes($p['location']) . "',\n";
        $content .= "        'domain' => '" . addslashes($p['domain']) . "',\n";
        $content .= "        'type' => 'Premium'\n";
        $content .= "    ],\n";
    }
    
    $content .= "];\n\n";
    $content .= "if (basename(\$_SERVER['PHP_SELF']) === 'panels.php') {\n";
    $content .= "    \$formatted = [];\n";
    $content .= "    foreach (\$panels as \$n => \$c) {\n";
    $content .= "        \$formatted[] = ['server_name' => \$n, 'location' => \$c['location'], 'type' => \$c['type']];\n";
    $content .= "    }\n";
    $content .= "    echo json_encode(['status' => 'success', 'panels' => \$formatted], JSON_PRETTY_PRINT);\n";
    $content .= "    exit;\n}\n\n";
    $content .= "return \$panels;\n";
    
    file_put_contents($pfile, $content);
}

function addPanelToFile($name, $data, $userId) {
    $ufile = __DIR__ . "/user_{$userId}.json";
    $udata = file_exists($ufile) ? json_decode(file_get_contents($ufile), true) : [];
    $udata['panels'][] = [
        'name' => $name,
        'url' => $data['url'],
        'username' => $data['username'],
        'password' => $data['password'],
        'location' => $data['location'],
        'domain' => $data['domain']
    ];
    file_put_contents($ufile, json_encode($udata));
    syncPanelsToFile($userId);
}

function listUserPanels($cid) {
    $panels = getUserPanels($cid);
    if (empty($panels)) {
        sendMessage($cid, "📭 No panels.\n<code>/addpanel</code>", 'HTML');
        return;
    }
    $msg = "📡 <b>Your Panels</b>\n\n";
    foreach ($panels as $i => $p) {
        $msg .= ($i+1) . ". <b>{$p['name']}</b>\n";
        $msg .= "   📍 {$p['location']} | 🌐 {$p['domain']}\n\n";
    }
    $msg .= "Use <code>/panels</code> to manage.";
    sendMessage($cid, $msg, 'HTML');
}

function showHistory($cid) {
    $h = getUserHistory($cid);
    if (empty($h)) { sendMessage($cid, "📭 No history"); return; }
    $msg = "📜 <b>History</b>\n\n";
    foreach (array_slice($h, 0, 10) as $i => $item) {
        $msg .= ($i+1) . ". 📝 <b>{$item['name']}</b> ({$item['gb']}GB/{$item['days']}d)\n   {$item['date']}\n\n";
    }
    sendMessage($cid, $msg, 'HTML');
}

function showHelp($cid, $fid) {
    $adm = ($fid == ADMIN_ID || isAdmin($fid));
    $msg = "📚 <b>Help v14.0 (Auto Inbound)</b>\n\n";
    $msg .= "<b>Setup:</b>\n<code>/addpanel</code> - Add server\n<code>/panels</code> - Manage panels\n\n";
    $msg .= "<b>Create VLESS (Auto creates inbound!):</b>\n";
    $msg .= "<code>/create name days</code>\n";
    $msg .= "→ Choose port, then enter path\n";
    $msg .= "→ Bot creates inbound + client automatically\n\n";
    if ($adm) {
        $msg .= "<b>Admin Create:</b>\n<code>/create name gb days</code>\n\n";
        $msg .= "<b>Admin Commands:</b>\n";
        $msg .= "<code>/addcredit</code> | <code>/delcredit</code>\n";
        $msg .= "<code>/users</code> | <code>/broadcast</code>\n";
    }
    $msg .= "\n<b>Other:</b>\n<code>/balance</code> | <code>/bug</code> | <code>/qr</code>\n";
    $msg .= "<code>/traffic email</code> | <code>/delete email</code>\n";
    $msg .= "<code>/history</code>\n\n👨‍💻 @evtvpn143";
    sendMessage($cid, $msg, 'HTML');
}

// ==================== CREDIT SYSTEM ====================

function getUserCredit($uid) { $f = __DIR__.'/credits.json'; return file_exists($f) ? (json_decode(file_get_contents($f), true)[$uid] ?? 0) : 0; }
function addUserCredit($uid, $a) { $f = __DIR__.'/credits.json'; $c = file_exists($f) ? json_decode(file_get_contents($f), true) : []; $c[$uid] = round(($c[$uid]??0)+$a, 3); file_put_contents($f, json_encode($c)); return $c[$uid]; }
function deductUserCredit($uid, $a) { $f = __DIR__.'/credits.json'; $c = file_exists($f) ? json_decode(file_get_contents($f), true) : []; $cur = $c[$uid]??0; if ($cur < $a) return false; $c[$uid] = round($cur-$a, 3); file_put_contents($f, json_encode($c)); return $c[$uid]; }

// ==================== USER MANAGEMENT ====================

function registerUser($uid, $n, $un, $isAdmin = false) { $f = __DIR__.'/users.json'; $u = file_exists($f) ? json_decode(file_get_contents($f), true) : []; if (!isset($u[$uid])) { $u[$uid] = ['id'=>$uid,'name'=>$n,'username'=>$un,'registered'=>date('Y-m-d H:i:s'),'is_admin'=>($uid == ADMIN_ID || $isAdmin)]; file_put_contents($f, json_encode($u)); } }
function getUserInfo($uid) { $f = __DIR__.'/users.json'; return file_exists($f) ? (json_decode(file_get_contents($f), true)[$uid]??null) : null; }
function isAdmin($uid) { $i = getUserInfo($uid); return $i && ($i['is_admin']??false); }
function addAdmin($uid) { $f = __DIR__.'/users.json'; if(!file_exists($f)) return; $u = json_decode(file_get_contents($f), true); if(isset($u[$uid])) { $u[$uid]['is_admin']=true; file_put_contents($f, json_encode($u)); } }
function removeAdmin($uid) { $f = __DIR__.'/users.json'; if(!file_exists($f)) return; $u = json_decode(file_get_contents($f), true); if(isset($u[$uid])) { $u[$uid]['is_admin']=false; file_put_contents($f, json_encode($u)); } }
function showAllUsers($cid) { $u = json_decode(file_get_contents(__DIR__.'/users.json'), true)??[]; $c = json_decode(file_get_contents(__DIR__.'/credits.json'), true)??[]; $msg = "👥 <b>Users</b>\n\n"; $i = 1; foreach($u as $id=>$user) { $cr = $c[$id]??0; $bdg = ($user['is_admin']??false)?'👑':'👤'; $msg .= "{$i}. {$bdg} <b>{$user['name']}</b>\n<code>{$id}</code> | 💳 {$cr}\n\n"; $i++; } sendMessage($cid, $msg, 'HTML'); }
function broadcastMessage($sid, $msg) { $u = json_decode(file_get_contents(__DIR__.'/users.json'), true)??[]; $s = 0; foreach($u as $id=>$user) { if($id!=$sid) { if(sendMessage($id, "📢 {$msg}\n@evtvpn143", 'HTML')) $s++; } } sendMessage($sid, "📢 Sent to {$s} users!", 'HTML'); }

// ==================== TELEGRAM API ====================

function sendMessage($cid, $t, $pm = null, $rm = null) {
    $d = ['chat_id' => $cid, 'text' => $t, 'disable_web_page_preview' => true];
    if ($pm) $d['parse_mode'] = $pm;
    if ($rm) $d['reply_markup'] = $rm;
    $ctx = stream_context_create(['http' => ['header' => "Content-Type: application/json\r\n", 'method' => 'POST', 'content' => json_encode($d), 'ignore_errors' => true, 'timeout' => 30], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    return @file_get_contents("https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage", false, $ctx);
}

function editMessageText($cid, $mid, $t, $pm = 'HTML', $rm = null) {
    $d = ['chat_id' => $cid, 'message_id' => $mid, 'text' => $t, 'parse_mode' => $pm];
    if ($rm) $d['reply_markup'] = $rm;
    $ctx = stream_context_create(['http' => ['header' => "Content-Type: application/json\r\n", 'method' => 'POST', 'content' => json_encode($d)], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    @file_get_contents("https://api.telegram.org/bot" . BOT_TOKEN . "/editMessageText", false, $ctx);
}

function answerCallbackQuery($id) {
    $ctx = stream_context_create(['http' => ['header' => "Content-Type: application/json\r\n", 'method' => 'POST', 'content' => json_encode(['callback_query_id' => $id])], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    @file_get_contents("https://api.telegram.org/bot" . BOT_TOKEN . "/answerCallbackQuery", false, $ctx);
}

function generateAndSendQR($cid, $cfg) {
    $qr = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($cfg);
    $d = ['chat_id' => $cid, 'photo' => $qr, 'caption' => "📱 Scan with VPN client\n👨‍💻 @evtvpn143", 'parse_mode' => 'HTML'];
    $ctx = stream_context_create(['http' => ['header' => "Content-Type: application/json\r\n", 'method' => 'POST', 'content' => json_encode($d)], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    @file_get_contents("https://api.telegram.org/bot" . BOT_TOKEN . "/sendPhoto", false, $ctx);
}

// ==================== USER DATA ====================

function getUserSetting($cid, $k, $t = 'default') { $f = __DIR__."/user_{$cid}.json"; return file_exists($f) ? (json_decode(file_get_contents($f), true)[$t][$k] ?? null) : null; }
function saveUserSetting($cid, $k, $v, $t = 'default') { $f = __DIR__."/user_{$cid}.json"; $d = file_exists($f) ? json_decode(file_get_contents($f), true) : []; $d[$t][$k] = $v; file_put_contents($f, json_encode($d)); }
function getUserState($cid) { $f = __DIR__."/user_{$cid}.json"; return file_exists($f) ? (json_decode(file_get_contents($f), true)['state'] ?? null) : null; }
function setUserState($cid, $s) { $f = __DIR__."/user_{$cid}.json"; $d = file_exists($f) ? json_decode(file_get_contents($f), true) : []; $d['state'] = $s; file_put_contents($f, json_encode($d)); }
function clearUserState($cid) { $f = __DIR__."/user_{$cid}.json"; if(file_exists($f)) { $d = json_decode(file_get_contents($f), true); unset($d['state']); file_put_contents($f, json_encode($d)); } }
function saveTempData($cid, $k, $v) { $f = __DIR__."/user_{$cid}.json"; $d = file_exists($f) ? json_decode(file_get_contents($f), true) : []; $d['temp'][$k] = $v; file_put_contents($f, json_encode($d)); }
function getTempData($cid, $k) { $f = __DIR__."/user_{$cid}.json"; return file_exists($f) ? (json_decode(file_get_contents($f), true)['temp'][$k] ?? null) : null; }
function clearTempData($cid) { $f = __DIR__."/user_{$cid}.json"; if(file_exists($f)) { $d = json_decode(file_get_contents($f), true); unset($d['temp']); file_put_contents($f, json_encode($d)); } }
function saveLastConfig($cid, $cfg) { $f = __DIR__."/user_{$cid}.json"; $d = file_exists($f) ? json_decode(file_get_contents($f), true) : []; $d['last_config'] = $cfg; file_put_contents($f, json_encode($d)); }
function getLastConfig($cid) { $f = __DIR__."/user_{$cid}.json"; return file_exists($f) ? (json_decode(file_get_contents($f), true)['last_config'] ?? null) : null; }
function saveVlessHistory($cid, $n, $gb, $days, $uid, $cfg) {
    $f = __DIR__."/user_{$cid}.json"; $d = file_exists($f) ? json_decode(file_get_contents($f), true) : [];
    $h = $d['history'] ?? [];
    array_unshift($h, ['type'=>'vless','id'=>time(),'name'=>$n,'gb'=>$gb,'days'=>$days,'uuid'=>$uid,'config'=>$cfg,'date'=>date('Y-m-d H:i:s')]);
    $d['history'] = array_slice($h, 0, 50);
    file_put_contents($f, json_encode($d));
}
function getUserHistory($cid) { $f = __DIR__."/user_{$cid}.json"; return file_exists($f) ? (json_decode(file_get_contents($f), true)['history'] ?? []) : []; }
function getUserPanels($cid) { $f = __DIR__."/user_{$cid}.json"; return file_exists($f) ? (json_decode(file_get_contents($f), true)['panels'] ?? []) : []; }
function debugLog($msg) { file_put_contents(__DIR__.'/bot.log', date('Y-m-d H:i:s')." - $msg\n", FILE_APPEND); }
?>
