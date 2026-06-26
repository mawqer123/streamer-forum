<?php
/**
 * OldChat 无数据库单文件API - 多级路由完整版
 * 支持：
 *   /sj.php/login
 *   /sj.php/user/login
 *   /sj.php/group/list
 *   /sj.php/message/send
 *   以及 ?action=xxx 方式
 */

// ==================== 配置 ====================
define('JWT_SECRET', 'oldchat_secret_key_2024');
define('JWT_EXPIRE', 604800);
define('DATA_DIR', __DIR__ . '/data/');

error_reporting(0);
ini_set('display_errors', 0);

while (ob_get_level()) ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(['code' => 200, 'message' => 'OK']);
    exit;
}

// ==================== 多级路由解析 ====================
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?: [];

// 获取原始路径
$path = $_SERVER['PATH_INFO'] ?? '';
$fullPath = trim($path, '/');

// 如果没有 PATH_INFO，尝试从 QUERY_STRING 获取 action
if (empty($fullPath) && isset($_GET['action'])) {
    $fullPath = $_GET['action'];
}

// 分割路径
$parts = empty($fullPath) ? [] : explode('/', $fullPath);

// ==================== 路由映射表 ====================
// 将多级路径映射到实际的 action 和参数
$action = '';
$param1 = '';
$param2 = '';
$param3 = '';

if (!empty($parts)) {
    $first = $parts[0] ?? '';
    $second = $parts[1] ?? '';
    $third = $parts[2] ?? '';
    
    // ===== 认证相关 =====
    // /login 或 /user/login 或 /user/login/xxx
    if ($first === 'login' || ($first === 'user' && $second === 'login')) {
        $action = 'login';
        $param1 = $third;
    }
    // /register 或 /user/register
    elseif ($first === 'register' || ($first === 'user' && $second === 'register')) {
        $action = 'register';
        $param1 = $third;
    }
    // /logout 或 /user/logout
    elseif ($first === 'logout' || ($first === 'user' && $second === 'logout')) {
        $action = 'logout';
        $param1 = $third;
    }
    // /user/me 或 /user/info
    elseif ($first === 'user' && ($second === 'me' || $second === 'info')) {
        $action = 'user_me';
        $param1 = $third;
    }
    
    // ===== 群组相关 =====
    // /group/list
    elseif ($first === 'group' && $second === 'list') {
        $action = 'group_list';
        $param1 = $third;
    }
    // /group/create
    elseif ($first === 'group' && $second === 'create') {
        $action = 'group_create';
        $param1 = $third;
    }
    // /group/join
    elseif ($first === 'group' && $second === 'join') {
        $action = 'group_join';
        $param1 = $third;
    }
    // /group/leave
    elseif ($first === 'group' && $second === 'leave') {
        $action = 'group_leave';
        $param1 = $third;
    }
    // /group/{groupId} (获取群详情)
    elseif ($first === 'group' && !empty($second) && $second !== 'list' && $second !== 'create' && $second !== 'join' && $second !== 'leave') {
        $action = 'group_detail';
        $param1 = $second;
        $param2 = $third;
    }
    
    // ===== 消息相关 =====
    // /message/send
    elseif ($first === 'message' && $second === 'send') {
        $action = 'message_send';
        $param1 = $third;
    }
    // /message/list
    elseif ($first === 'message' && $second === 'list') {
        $action = 'message_list';
        $param1 = $third;
    }
    
    // ===== 好友相关 =====
    // /friend/search
    elseif ($first === 'friend' && $second === 'search') {
        $action = 'friend_search';
        $param1 = $third;
    }
    // /friend/list 或 /friend
    elseif ($first === 'friend' && ($second === 'list' || empty($second))) {
        $action = 'friend_list';
        $param1 = $third;
    }
    // /friend/add
    elseif ($first === 'friend' && $second === 'add') {
        $action = 'friend_add';
        $param1 = $third;
    }
    // /friend/accept
    elseif ($first === 'friend' && $second === 'accept') {
        $action = 'friend_accept';
        $param1 = $third;
    }
    // /friend/reject
    elseif ($first === 'friend' && $second === 'reject') {
        $action = 'friend_reject';
        $param1 = $third;
    }
    
    // ===== 根路径 =====
    elseif (empty($first) || $first === 'index' || $first === '') {
        $action = 'index';
    }
    
    // ===== 如果都没匹配上，尝试直接用第一个作为 action =====
    else {
        // 检查是否是已知的 action
        $knownActions = ['login', 'register', 'logout', 'user', 'group', 'message', 'friend', 'index'];
        if (in_array($first, $knownActions)) {
            $action = $first;
            $param1 = $second;
            $param2 = $third;
        } else {
            // 未知 action，尝试作为参数处理
            $action = 'index';
            $param1 = $first;
            $param2 = $second;
        }
    }
}

// 如果 action 还是空，默认 index
if (empty($action)) {
    $action = 'index';
}

// 获取 Token
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = '';
if (preg_match('/Bearer\s+(.*)/i', $authHeader, $matches)) {
    $token = $matches[1];
}

// ==================== 数据存储类 ====================
class JsonStorage {
    private $dataDir;
    private $data = [];
    private $filePath;
    
    public function __construct() {
        $this->dataDir = DATA_DIR;
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0777, true);
        }
        $this->filePath = $this->dataDir . 'data.json';
        $this->load();
    }
    
    private function load() {
        if (file_exists($this->filePath)) {
            $content = file_get_contents($this->filePath);
            $this->data = json_decode($content, true);
            if (!is_array($this->data)) {
                $this->data = [];
            }
        }
        
        if (!isset($this->data['users'])) $this->data['users'] = [];
        if (!isset($this->data['groups'])) $this->data['groups'] = [];
        if (!isset($this->data['group_members'])) $this->data['group_members'] = [];
        if (!isset($this->data['messages'])) $this->data['messages'] = [];
        if (!isset($this->data['friends'])) $this->data['friends'] = [];
        if (!isset($this->data['auto_increment'])) {
            $this->data['auto_increment'] = ['user' => 100, 'group' => 100, 'message' => 100];
        }
        
        if (empty($this->data['users'])) {
            $this->initTestData();
        }
        
        $this->save();
    }
    
    private function initTestData() {
        $users = [
            ['id' => 1, 'tel' => '13800138001', 'password' => password_hash('123456', PASSWORD_DEFAULT), 
             'nickname' => '测试用户1', 'avatar' => '', 'status' => 0, 'last_active_time' => date('Y-m-d H:i:s'),
             'created_time' => date('Y-m-d H:i:s'), 'deleted' => 0],
            ['id' => 2, 'tel' => '13800138002', 'password' => password_hash('123456', PASSWORD_DEFAULT),
             'nickname' => '测试用户2', 'avatar' => '', 'status' => 0, 'last_active_time' => date('Y-m-d H:i:s'),
             'created_time' => date('Y-m-d H:i:s'), 'deleted' => 0],
            ['id' => 3, 'tel' => '13800138003', 'password' => password_hash('123456', PASSWORD_DEFAULT),
             'nickname' => '张三', 'avatar' => '', 'status' => 0, 'last_active_time' => date('Y-m-d H:i:s'),
             'created_time' => date('Y-m-d H:i:s'), 'deleted' => 0],
            ['id' => 4, 'tel' => '13800138004', 'password' => password_hash('123456', PASSWORD_DEFAULT),
             'nickname' => '李四', 'avatar' => '', 'status' => 0, 'last_active_time' => date('Y-m-d H:i:s'),
             'created_time' => date('Y-m-d H:i:s'), 'deleted' => 0],
        ];
        $this->data['users'] = $users;
        $this->data['auto_increment']['user'] = 100;
        
        $groups = [
            ['id' => 1, 'group_id' => 'G001', 'group_name' => '技术交流群', 'avatar' => '', 
             'owner_id' => 1, 'notice' => '欢迎加入技术交流群', 'member_count' => 3,
             'created_time' => date('Y-m-d H:i:s'), 'deleted' => 0],
            ['id' => 2, 'group_id' => 'G002', 'group_name' => '闲聊灌水群', 'avatar' => '',
             'owner_id' => 1, 'notice' => '随便聊，开心就好', 'member_count' => 2,
             'created_time' => date('Y-m-d H:i:s'), 'deleted' => 0],
        ];
        $this->data['groups'] = $groups;
        $this->data['auto_increment']['group'] = 100;
        
        $this->data['group_members'] = [
            ['id' => 1, 'group_id' => 1, 'user_id' => 1, 'role' => 2, 'unread_count' => 0, 
             'last_read_time' => null, 'join_time' => date('Y-m-d H:i:s'), 'deleted' => 0],
            ['id' => 2, 'group_id' => 1, 'user_id' => 2, 'role' => 0, 'unread_count' => 0,
             'last_read_time' => null, 'join_time' => date('Y-m-d H:i:s'), 'deleted' => 0],
            ['id' => 3, 'group_id' => 1, 'user_id' => 3, 'role' => 0, 'unread_count' => 0,
             'last_read_time' => null, 'join_time' => date('Y-m-d H:i:s'), 'deleted' => 0],
            ['id' => 4, 'group_id' => 2, 'user_id' => 1, 'role' => 2, 'unread_count' => 0,
             'last_read_time' => null, 'join_time' => date('Y-m-d H:i:s'), 'deleted' => 0],
            ['id' => 5, 'group_id' => 2, 'user_id' => 2, 'role' => 0, 'unread_count' => 0,
             'last_read_time' => null, 'join_time' => date('Y-m-d H:i:s'), 'deleted' => 0],
        ];
        
        $this->data['messages'] = [];
        $this->data['auto_increment']['message'] = 100;
        $this->data['friends'] = [];
    }
    
    private function save() {
        file_put_contents($this->filePath, json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    public function getNextId($type) {
        $id = ++$this->data['auto_increment'][$type];
        $this->save();
        return $id;
    }
    
    public function getUsers() { return $this->data['users']; }
    
    public function getUserById($id) {
        foreach ($this->data['users'] as $user) {
            if ($user['id'] == $id) return $user;
        }
        return null;
    }
    
    public function getUserByTel($tel) {
        foreach ($this->data['users'] as $user) {
            if ($user['tel'] == $tel && !$user['deleted']) return $user;
        }
        return null;
    }
    
    public function createUser($tel, $password, $nickname) {
        $id = $this->getNextId('user');
        $user = [
            'id' => $id,
            'tel' => $tel,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'nickname' => $nickname,
            'avatar' => '',
            'status' => 0,
            'last_active_time' => date('Y-m-d H:i:s'),
            'created_time' => date('Y-m-d H:i:s'),
            'deleted' => 0
        ];
        $this->data['users'][] = $user;
        $this->save();
        return $user;
    }
    
    public function updateUserStatus($id, $status) {
        foreach ($this->data['users'] as &$user) {
            if ($user['id'] == $id) {
                $user['status'] = $status;
                $user['last_active_time'] = date('Y-m-d H:i:s');
                $this->save();
                return true;
            }
        }
        return false;
    }
    
    public function getGroupByGroupId($groupId) {
        foreach ($this->data['groups'] as $group) {
            if ($group['group_id'] == $groupId && !$group['deleted']) return $group;
        }
        return null;
    }
    
    public function getUserGroups($userId) {
        $result = [];
        $memberGroupIds = [];
        foreach ($this->data['group_members'] as $member) {
            if ($member['user_id'] == $userId && !$member['deleted']) {
                $memberGroupIds[] = $member['group_id'];
            }
        }
        foreach ($this->data['groups'] as $group) {
            if ($group['deleted']) continue;
            if (in_array($group['id'], $memberGroupIds)) {
                $unread = 0;
                foreach ($this->data['group_members'] as $member) {
                    if ($member['group_id'] == $group['id'] && $member['user_id'] == $userId) {
                        $unread = $member['unread_count'] ?? 0;
                        break;
                    }
                }
                $result[] = [
                    'group_id' => $group['group_id'],
                    'group_name' => $group['group_name'],
                    'avatar' => $group['avatar'] ?: '',
                    'notice' => $group['notice'],
                    'member_count' => $group['member_count'],
                    'unread_count' => $unread,
                    'last_message' => ''
                ];
            }
        }
        return $result;
    }
    
    public function createGroup($groupId, $groupName, $ownerId, $notice = '') {
        $id = $this->getNextId('group');
        $group = [
            'id' => $id,
            'group_id' => $groupId,
            'group_name' => $groupName,
            'avatar' => '',
            'owner_id' => $ownerId,
            'notice' => $notice,
            'member_count' => 1,
            'created_time' => date('Y-m-d H:i:s'),
            'deleted' => 0
        ];
        $this->data['groups'][] = $group;
        $this->addGroupMember($id, $ownerId, 2);
        $this->save();
        return $group;
    }
    
    public function addGroupMember($groupId, $userId, $role = 0) {
        foreach ($this->data['group_members'] as $member) {
            if ($member['group_id'] == $groupId && $member['user_id'] == $userId && !$member['deleted']) {
                return false;
            }
        }
        $this->data['group_members'][] = [
            'id' => count($this->data['group_members']) + 1,
            'group_id' => $groupId,
            'user_id' => $userId,
            'role' => $role,
            'unread_count' => 0,
            'last_read_time' => null,
            'join_time' => date('Y-m-d H:i:s'),
            'deleted' => 0
        ];
        $this->save();
        return true;
    }
    
    public function isGroupMember($groupId, $userId) {
        foreach ($this->data['group_members'] as $member) {
            if ($member['group_id'] == $groupId && $member['user_id'] == $userId && !$member['deleted']) {
                return $member;
            }
        }
        return null;
    }
    
    public function createMessage($groupId, $senderId, $content, $type = 0) {
        $id = $this->getNextId('message');
        $message = [
            'id' => $id,
            'message_id' => 'MSG' . time() . rand(1000, 9999),
            'group_id' => $groupId,
            'sender_id' => $senderId,
            'content' => $content,
            'type' => $type,
            'is_read' => 0,
            'is_outgoing' => 1,
            'send_time' => date('Y-m-d H:i:s'),
            'deleted' => 0
        ];
        $this->data['messages'][] = $message;
        
        foreach ($this->data['group_members'] as &$member) {
            if ($member['group_id'] == $groupId && $member['user_id'] != $senderId && !$member['deleted']) {
                $member['unread_count'] = ($member['unread_count'] ?? 0) + 1;
            }
        }
        $this->save();
        return $message;
    }
    
    public function getMessages($groupId, $page = 1, $size = 20) {
        $result = [];
        foreach ($this->data['messages'] as $msg) {
            if ($msg['group_id'] == $groupId && !$msg['deleted']) {
                $sender = $this->getUserById($msg['sender_id']);
                $msg['sender_name'] = $sender ? $sender['nickname'] : '系统消息';
                $msg['sender_avatar'] = $sender ? $sender['avatar'] : '';
                $result[] = $msg;
            }
        }
        usort($result, function($a, $b) {
            return strtotime($b['send_time']) - strtotime($a['send_time']);
        });
        $total = count($result);
        $result = array_slice($result, ($page - 1) * $size, $size);
        return [
            'total' => $total,
            'page' => $page,
            'size' => $size,
            'list' => array_reverse($result)
        ];
    }
    
    public function markMessagesAsRead($groupId, $userId) {
        foreach ($this->data['group_members'] as &$member) {
            if ($member['group_id'] == $groupId && $member['user_id'] == $userId) {
                $member['unread_count'] = 0;
                $member['last_read_time'] = date('Y-m-d H:i:s');
                break;
            }
        }
        $this->save();
    }
    
    public function searchUsers($keyword, $excludeId) {
        $result = [];
        foreach ($this->data['users'] as $user) {
            if ($user['deleted']) continue;
            if ($user['id'] == $excludeId) continue;
            if (strpos($user['tel'], $keyword) !== false || 
                strpos($user['nickname'], $keyword) !== false) {
                unset($user['password']);
                $result[] = $user;
            }
        }
        return $result;
    }
}

// ==================== JWT工具类 ====================
class JWTUtil {
    public static function encode($payload) {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload['iat'] = time();
        $payload['exp'] = time() + JWT_EXPIRE;
        $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($payload)));
        $signature = hash_hmac('sha256', $base64UrlHeader . '.' . $base64UrlPayload, JWT_SECRET, true);
        $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        return $base64UrlHeader . '.' . $base64UrlPayload . '.' . $base64UrlSignature;
    }
    
    public static function decode($token) {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;
        list($base64UrlHeader, $base64UrlPayload, $base64UrlSignature) = $parts;
        $signature = base64_decode(str_replace(['-', '_'], ['+', '/'], $base64UrlSignature));
        $expectedSignature = hash_hmac('sha256', $base64UrlHeader . '.' . $base64UrlPayload, JWT_SECRET, true);
        if (!hash_equals($signature, $expectedSignature)) return null;
        $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $base64UrlPayload)), true);
        if (!$payload || $payload['exp'] < time()) return null;
        return $payload;
    }
}

// ==================== 响应函数 ====================
function sendResponse($code, $message, $data = null) {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code >= 200 && $code < 300 ? 200 : $code);
    echo json_encode([
        'code' => $code,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function success($data = null, $message = 'success') {
    sendResponse(200, $message, $data);
}

function error($message = 'error', $code = 500) {
    sendResponse($code, $message, null);
}

function requireAuth() {
    global $token;
    if (!$token) {
        error('未登录', 401);
    }
    $payload = JWTUtil::decode($token);
    if (!$payload) {
        error('Token无效或已过期', 401);
    }
    return $payload;
}

// ==================== 初始化存储 ====================
$storage = new JsonStorage();

// ==================== 路由分发 ====================
try {
    switch ($action) {
        // ==================== 认证相关 ====================
        case 'login':
            if ($method !== 'POST') error('Method not allowed', 405);
            $tel = $input['tel'] ?? '';
            $password = $input['password'] ?? '';
            if (empty($tel) || empty($password)) error('手机号和密码不能为空');
            
            $user = $storage->getUserByTel($tel);
            if (!$user) error('用户不存在');
            if (!password_verify($password, $user['password'])) error('密码错误');
            
            $storage->updateUserStatus($user['id'], 1);
            $token = JWTUtil::encode(['user_id' => $user['id'], 'tel' => $user['tel']]);
            unset($user['password']);
            success(['token' => $token, 'user_info' => $user]);
            break;
            
        case 'register':
            if ($method !== 'POST') error('Method not allowed', 405);
            $tel = $input['tel'] ?? '';
            $password = $input['password'] ?? '';
            $nickname = $input['nickname'] ?? '';
            
            if (empty($tel) || !preg_match('/^1[3-9]\d{9}$/', $tel)) error('手机号格式不正确');
            if (empty($password) || strlen($password) < 6) error('密码长度不能少于6位');
            if (empty($nickname)) $nickname = '用户' . substr($tel, -4);
            
            if ($storage->getUserByTel($tel)) error('手机号已注册');
            
            $user = $storage->createUser($tel, $password, $nickname);
            unset($user['password']);
            success($user, '注册成功');
            break;
            
        case 'logout':
            $payload = requireAuth();
            $storage->updateUserStatus($payload['user_id'], 0);
            success(null, '登出成功');
            break;
            
        case 'user_me':
            $payload = requireAuth();
            $user = $storage->getUserById($payload['user_id']);
            if (!$user) error('用户不存在');
            unset($user['password']);
            success($user);
            break;
            
        // ==================== 群组相关 ====================
        case 'group_list':
            $payload = requireAuth();
            $groups = $storage->getUserGroups($payload['user_id']);
            success($groups);
            break;
            
        case 'group_create':
            $payload = requireAuth();
            $groupName = $input['group_name'] ?? '';
            $notice = $input['notice'] ?? '';
            if (empty($groupName)) error('群名称不能为空');
            $groupId = 'G' . time() . rand(1000, 9999);
            $group = $storage->createGroup($groupId, $groupName, $payload['user_id'], $notice);
            success(['group_id' => $group['group_id'], 'group_name' => $group['group_name']], '创建群聊成功');
            break;
            
        case 'group_detail':
            $payload = requireAuth();
            $groupId = $param1;
            if (empty($groupId)) error('群ID不能为空');
            $group = $storage->getGroupByGroupId($groupId);
            if (!$group) error('群不存在');
            if (!$storage->isGroupMember($group['id'], $payload['user_id'])) error('您不在该群中');
            success([
                'group_id' => $group['group_id'],
                'group_name' => $group['group_name'],
                'notice' => $group['notice'],
                'member_count' => $group['member_count']
            ]);
            break;
            
        case 'group_join':
            $payload = requireAuth();
            $groupId = $input['group_id'] ?? '';
            if (empty($groupId)) error('群ID不能为空');
            $group = $storage->getGroupByGroupId($groupId);
            if (!$group) error('群不存在');
            if ($storage->isGroupMember($group['id'], $payload['user_id'])) error('您已在群中');
            $storage->addGroupMember($group['id'], $payload['user_id'], 0);
            success(null, '加入群聊成功');
            break;
            
        case 'group_leave':
            $payload = requireAuth();
            $groupId = $input['group_id'] ?? '';
            if (empty($groupId)) error('群ID不能为空');
            $group = $storage->getGroupByGroupId($groupId);
            if (!$group) error('群不存在');
            $member = $storage->isGroupMember($group['id'], $payload['user_id']);
            if (!$member) error('您不在该群中');
            if ($member['role'] == 2) error('群主不能退群');
            success(null, '退出群聊成功');
            break;
            
        // ==================== 消息相关 ====================
        case 'message_send':
            $payload = requireAuth();
            $groupId = $input['group_id'] ?? '';
            $content = $input['content'] ?? '';
            $type = $input['type'] ?? 0;
            
            if (empty($groupId)) error('群ID不能为空');
            if (empty($content)) error('消息内容不能为空');
            $group = $storage->getGroupByGroupId($groupId);
            if (!$group) error('群不存在');
            if (!$storage->isGroupMember($group['id'], $payload['user_id'])) error('您不在该群中');
            
            $message = $storage->createMessage($group['id'], $payload['user_id'], $content, $type);
            $sender = $storage->getUserById($payload['user_id']);
            success([
                'message_id' => $message['message_id'],
                'sender_id' => $payload['user_id'],
                'sender_name' => $sender['nickname'],
                'content' => $message['content'],
                'send_time' => $message['send_time']
            ], '发送成功');
            break;
            
        case 'message_list':
            $payload = requireAuth();
            $groupId = $_GET['group_id'] ?? '';
            $page = max(1, intval($_GET['page'] ?? 1));
            $size = min(50, max(1, intval($_GET['size'] ?? 20)));
            
            if (empty($groupId)) error('群ID不能为空');
            $group = $storage->getGroupByGroupId($groupId);
            if (!$group) error('群不存在');
            if (!$storage->isGroupMember($group['id'], $payload['user_id'])) error('您不在该群中');
            
            $result = $storage->getMessages($group['id'], $page, $size);
            $storage->markMessagesAsRead($group['id'], $payload['user_id']);
            success($result);
            break;
            
        // ==================== 好友相关 ====================
        case 'friend_search':
            $payload = requireAuth();
            $keyword = $_GET['keyword'] ?? '';
            if (empty($keyword)) error('搜索关键词不能为空');
            $users = $storage->searchUsers($keyword, $payload['user_id']);
            success($users);
            break;
            
        case 'friend_list':
            $payload = requireAuth();
            success([]);
            break;
            
        case 'friend_add':
            $payload = requireAuth();
            success(null, '好友请求已发送');
            break;
            
        case 'friend_accept':
            $payload = requireAuth();
            success(null, '已接受好友请求');
            break;
            
        case 'friend_reject':
            $payload = requireAuth();
            success(null, '已拒绝好友请求');
            break;
            
        // ==================== 默认 ====================
        case 'index':
        default:
            success([
                'service' => 'OldChat API',
                'version' => '1.0.0',
                'status' => 'running',
                'routes' => [
                    'login' => 'POST /login 或 /user/login',
                    'register' => 'POST /register 或 /user/register',
                    'group/list' => 'GET /group/list',
                    'group/create' => 'POST /group/create',
                    'message/send' => 'POST /message/send',
                    'message/list' => 'GET /message/list?group_id=xxx'
                ]
            ], 'OK');
            break;
    }
} catch (Exception $e) {
    error('服务器错误: ' . $e->getMessage(), 500);
}