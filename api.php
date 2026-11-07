<?php
/*
 * api.php — خادم لوحة تحكم المنيو (PHP 7.3+)
 * ---------------------------------------------------------------
 * يحفظ المنيو في data/menu.json (محمي من الوصول المباشر) ويرفع الصور إلى uploads/.
 * مهم: غيّر PASSWORD قبل الرفع. لكل مطعم فولدر مستقل وكلمة مرور مستقلة.
 */
const PASSWORD = 'ChangeMe123';   // ← كلمة مرور لوحة التحكم (غيّرها)
const SESSION_HOURS = 12;
const TZ = 'Africa/Cairo';        // ← المنطقة الزمنية للمطعم
date_default_timezone_set(TZ);

$secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params(array('lifetime' => SESSION_HOURS * 3600, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax'));
session_name('menu_sess');
session_start();

function out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-LiteSpeed-Cache-Control: no-cache');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function cut($s, $n) {
    $s = trim((string)$s);
    return function_exists('mb_substr') ? mb_substr($s, 0, $n, 'UTF-8') : substr($s, 0, $n);
}
function clean_img($p) {
    $p = (string)$p;
    return preg_match('#^(uploads|images)/[A-Za-z0-9_.\-]+\.(jpg|jpeg|png|webp)$#i', $p) ? $p : '';
}

// ---------- تهيئة المجلدات والملفات ----------
$dataDir = __DIR__ . '/data';
$upDir = __DIR__ . '/uploads';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0755, true);
    @file_put_contents($dataDir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
}
if (!is_dir($upDir)) {
    @mkdir($upDir, 0755, true);
    @file_put_contents($upDir . '/.htaccess', "<FilesMatch \"\\.(php|phtml|phar|pl|cgi)\$\">\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n</FilesMatch>\n");
}
$menuFile = $dataDir . '/menu.json';
if (!file_exists($menuFile) && file_exists(__DIR__ . '/menu.seed.json')) {
    @copy(__DIR__ . '/menu.seed.json', $menuFile);
}

function pw_ok($pw) {
    global $dataDir;
    $f = $dataDir . '/auth.json';
    if (file_exists($f)) {
        $j = json_decode(file_get_contents($f), true);
        if (is_array($j) && isset($j['hash'])) return password_verify($pw, $j['hash']);
    }
    return hash_equals(PASSWORD, $pw);   // كلمة المرور الأصلية من أول الملف (إلى أن تُغيَّر من اللوحة)
}

// =====================================================================
//                       نظام الطلبات (SQLite)
// =====================================================================
class UserErr extends Exception {
    public $en = '';
    public function __construct($msg = '', $en = '') { parent::__construct($msg); $this->en = $en; }
}

function ascii_digits($s) {
    return strtr((string)$s, array('٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','–'=>'-','—'=>'-','،'=>',','؛'=>','));
}
function db() {
    static $pdo = null;
    global $dataDir;
    if ($pdo) return $pdo;
    if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers())) throw new Exception('pdo_sqlite missing');
    $p = new PDO('sqlite:' . $dataDir . '/orders.sqlite');
    $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $p->exec('PRAGMA busy_timeout=8000');
    $p->exec('CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE NOT NULL, hash TEXT NOT NULL, name TEXT NOT NULL, role TEXT NOT NULL, tbls TEXT NOT NULL DEFAULT \'\', active INTEGER NOT NULL DEFAULT 1)');
    $p->exec('CREATE TABLE IF NOT EXISTS orders(id INTEGER PRIMARY KEY AUTOINCREMENT, day TEXT NOT NULL, no INTEGER NOT NULL, token TEXT UNIQUE NOT NULL, ckey TEXT UNIQUE, type TEXT NOT NULL, tbl TEXT NOT NULL DEFAULT \'\', cname TEXT NOT NULL DEFAULT \'\', phone TEXT NOT NULL DEFAULT \'\', addr TEXT NOT NULL DEFAULT \'\', lat REAL, lng REAL, note TEXT NOT NULL DEFAULT \'\', items TEXT NOT NULL, sub REAL NOT NULL, svc REAL NOT NULL, total REAL NOT NULL, status TEXT NOT NULL, owner INTEGER, done_by INTEGER, printed INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL, rev INTEGER NOT NULL, ip TEXT NOT NULL DEFAULT \'\')');
    $cols = array();
    foreach ($p->query('PRAGMA table_info(orders)') as $c) $cols[] = $c['name'];
    if (!in_array('batches', $cols)) $p->exec('ALTER TABLE orders ADD COLUMN batches INTEGER NOT NULL DEFAULT 1');
    $p->exec('CREATE TABLE IF NOT EXISTS addons(ckey TEXT PRIMARY KEY, order_id INTEGER NOT NULL)');
    $p->exec('CREATE INDEX IF NOT EXISTS o_day ON orders(day)');
    $p->exec('CREATE INDEX IF NOT EXISTS o_rev ON orders(rev)');
    $pdo = $p;
    return $pdo;
}
function next_rev($pdo) {
    return 1 + (int)$pdo->query('SELECT COALESCE(MAX(rev),0) FROM orders')->fetchColumn();
}
function role_allowed($role) {
    $all = array('pending', 'new', 'prep', 'ready', 'out', 'done', 'cancel');
    if ($role === 'manager' || $role === 'delivery') return $all;
    if ($role === 'waiter') return array('new', 'prep', 'ready', 'done', 'cancel');
    if ($role === 'kitchen') return array('prep', 'ready');
    return array();
}
function me() {
    if (!empty($_SESSION['uid'])) {
        $st = db()->prepare('SELECT id,name,role,tbls FROM users WHERE id=? AND active=1');
        $st->execute(array((int)$_SESSION['uid']));
        $u = $st->fetch();
        if ($u) return array('id' => (int)$u['id'], 'name' => $u['name'], 'role' => $u['role'], 'tbls' => $u['tbls']);
    }
    if (!empty($_SESSION['adm'])) return array('id' => 0, 'name' => 'الإدارة', 'role' => 'manager', 'tbls' => '');
    return null;
}
function tbl_match($spec, $t) {
    $t = (string)$t;
    if (!ctype_digit($t)) return false;
    $t = (int)$t;
    foreach (preg_split('/[\s,;]+/', ascii_digits($spec)) as $p) {
        if ($p === '') continue;
        if (preg_match('/^(\d+)-(\d+)$/', $p, $m)) { if ($t >= (int)$m[1] && $t <= (int)$m[2]) return true; }
        elseif (ctype_digit($p) && (int)$p === $t) return true;
    }
    return false;
}
function fmt_order($r) {
    $items = json_decode($r['items'], true);
    return array('id' => (int)$r['id'], 'no' => (int)$r['no'], 'day' => $r['day'], 'type' => $r['type'], 'tbl' => $r['tbl'],
        'cname' => $r['cname'], 'phone' => $r['phone'], 'addr' => $r['addr'],
        'lat' => $r['lat'] === null ? null : (float)$r['lat'], 'lng' => $r['lng'] === null ? null : (float)$r['lng'],
        'note' => $r['note'], 'items' => is_array($items) ? $items : array(),
        'sub' => (float)$r['sub'], 'svc' => (float)$r['svc'], 'total' => (float)$r['total'], 'status' => $r['status'],
        'owner' => $r['owner'] === null ? null : (int)$r['owner'], 'owner_name' => isset($r['owner_name']) ? (string)$r['owner_name'] : '',
        'printed' => (int)$r['printed'], 'batches' => isset($r['batches']) ? (int)$r['batches'] : 1, 'created' => (int)$r['created']);
}
function get_order($id) {
    $st = db()->prepare('SELECT o.*, u.name AS owner_name FROM orders o LEFT JOIN users u ON u.id=o.owner WHERE o.id=?');
    $st->execute(array((int)$id));
    $r = $st->fetch();
    return $r ? fmt_order($r) : null;
}
function upd_order($id, $set, $params) {
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $params[] = next_rev($pdo);
        $params[] = (int)$id;
        $pdo->prepare('UPDATE orders SET ' . $set . ', rev=? WHERE id=?')->execute($params);
        $pdo->exec('COMMIT');
    } catch (Exception $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
    return get_order($id);
}

function create_order($in) {
    global $menuFile;
    if (!file_exists($menuFile)) throw new UserErr('المنيو غير متاح حالياً', 'The menu is currently unavailable');
    $m = json_decode(file_get_contents($menuFile), true);
    if (!is_array($m) || !isset($m['config'], $m['items'])) throw new UserErr('المنيو غير متاح حالياً', 'The menu is currently unavailable');
    $cfg = $m['config'];
    $type = (isset($in['mode']) && $in['mode'] === 'h') ? 'h' : 'd';
    if ($type === 'd' && empty($cfg['dine'])) throw new UserErr('الطلب داخل المطعم غير متاح حالياً', 'Dine-in ordering is currently unavailable');
    if ($type === 'h' && empty($cfg['delivery'])) throw new UserErr('التوصيل غير متاح حالياً', 'Delivery is currently unavailable');

    $ckey = substr(preg_replace('/[^A-Za-z0-9]/', '', isset($in['key']) ? (string)$in['key'] : ''), 0, 40);
    if (strlen($ckey) < 12) throw new UserErr('طلب غير صالح، أعد تحميل الصفحة', 'Invalid request, please reload the page');

    $pdo = db();
    $st = $pdo->prepare('SELECT token,no,status,type FROM orders WHERE ckey=?');
    $st->execute(array($ckey));
    if ($r = $st->fetch()) return array('ok' => true, 'no' => (int)$r['no'], 'token' => $r['token'], 'status' => $r['status'], 'type' => $r['type']);
    $st = $pdo->prepare('SELECT o.token,o.no,o.status,o.type FROM addons a JOIN orders o ON o.id=a.order_id WHERE a.ckey=?');
    $st->execute(array($ckey));
    if ($r = $st->fetch()) return array('ok' => true, 'no' => (int)$r['no'], 'token' => $r['token'], 'status' => $r['status'], 'type' => $r['type'], 'addon' => true);

    // الأصناف والأسعار من المنيو على الخادم (لا نثق بالأسعار القادمة من المتصفح)
    $menuItems = array();
    foreach ($m['items'] as $it) { if (is_array($it) && isset($it['id'])) $menuItems[(int)$it['id']] = $it; }
    if (!isset($in['items']) || !is_array($in['items']) || count($in['items']) === 0) throw new UserErr('السلة فارغة', 'Your cart is empty');
    $qty = array();
    foreach ($in['items'] as $l) {
        if (!is_array($l)) continue;
        $id = isset($l['id']) ? (int)$l['id'] : 0;
        $q = isset($l['q']) ? (int)$l['q'] : 0;
        if ($id <= 0 || $q <= 0) continue;
        $qty[$id] = (isset($qty[$id]) ? $qty[$id] : 0) + $q;
    }
    if (count($qty) === 0 || count($qty) > 60) throw new UserErr('السلة غير صالحة', 'Invalid cart');
    $lines = array();
    $sub = 0.0;
    foreach ($qty as $id => $q) {
        if (!isset($menuItems[$id])) throw new UserErr('أحد الأصناف لم يعد موجوداً، أعد تحميل المنيو', 'An item is no longer available, please reload the menu');
        $it = $menuItems[$id];
        if (empty($it['avail'])) throw new UserErr('الصنف «' . $it['ar'] . '» غير متاح حالياً', '"' . (isset($it['en']) && $it['en'] !== '' ? $it['en'] : $it['ar']) . '" is currently unavailable');
        if ($q > 99) throw new UserErr('الكمية كبيرة جداً', 'Quantity is too large');
        $p = (float)$it['price'];
        $lines[] = array('id' => $id, 'n' => (string)$it['ar'], 'q' => $q, 'p' => $p, 'b' => 0, 't' => time());
        $sub += $p * $q;
    }
    $dec = max(0, min(3, (int)(isset($cfg['dec']) ? $cfg['dec'] : 0)));
    $sub = round($sub, $dec);
    $svc = round($sub * max(0, min(100, (float)(isset($cfg['service']) ? $cfg['service'] : 0))) / 100, $dec);
    $total = round($sub + $svc, 3);

    $tbl = ''; $cname = ''; $addr = ''; $lat = null; $lng = null;
    $phone = preg_replace('/\D/', '', ascii_digits(isset($in['phone']) ? $in['phone'] : ''));
    if (strlen($phone) < 7 || strlen($phone) > 15) throw new UserErr('رقم الهاتف (واتساب) مطلوب وبصيغة صحيحة', 'A valid WhatsApp phone number is required');
    if ($type === 'd') {
        $tbl = substr(preg_replace('/[^0-9A-Za-z\-]/', '', ascii_digits(isset($in['table']) ? $in['table'] : '')), 0, 8);
        if ($tbl === '') throw new UserErr('رقم الطاولة مطلوب', 'Table number is required');
    } else {
        $cname = cut(isset($in['name']) ? $in['name'] : '', 60);
        $addr = cut(isset($in['address']) ? $in['address'] : '', 300);
        if ($cname === '' || $addr === '') throw new UserErr('الاسم والعنوان مطلوبان', 'Name and address are required');
        if (isset($in['lat'], $in['lng']) && is_numeric($in['lat']) && is_numeric($in['lng'])) {
            $la = (float)$in['lat']; $lo = (float)$in['lng'];
            if ($la >= -90 && $la <= 90 && $lo >= -180 && $lo <= 180) { $lat = $la; $lng = $lo; }
        }
    }
    $note = cut(isset($in['note']) ? $in['note'] : '', 300);
    $ip = isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : '';
    $now = time();

    // حماية من الإغراق (حد مرتفع لأن زبائن المطعم قد يشتركون في نفس شبكة الواي فاي)
    $st = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE ip=? AND created>?');
    $st->execute(array($ip, $now - 600));
    if ((int)$st->fetchColumn() >= 40) throw new UserErr('عدد الطلبات كبير، حاول بعد قليل', 'Too many orders, please try again shortly');
    if ($type === 'h') {
        $st = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE phone=? AND type='h' AND status='pending' AND created>?");
        $st->execute(array($phone, $now - 900));
        if ((int)$st->fetchColumn() >= 3) throw new UserErr('لديك طلبات بانتظار التأكيد، سيتصل بك الموظف قريباً', 'You have orders awaiting confirmation, staff will call you shortly');
    }

    // توجيه طلب الصالة للويتر المربوط بالطاولة
    $owner = null;
    if ($type === 'd') {
        foreach ($pdo->query("SELECT id,tbls FROM users WHERE role='waiter' AND active=1 ORDER BY id") as $w) {
            if (tbl_match($w['tbls'], $tbl)) { $owner = (int)$w['id']; break; }
        }
    }
    $status = $type === 'h' ? 'pending' : 'new';
    $token = bin2hex(random_bytes(12));
    $day = date('Y-m-d');

    $pct = max(0, min(100, (float)(isset($cfg['service']) ? $cfg['service'] : 0)));
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        // طلب الصالة: لو يوجد طلب لنفس الطاولة ما زال قيد التجهيز (جديد/تحضير) يُضاف الطلب الجديد كملحق عليه
        $cur = false;
        if ($type === 'd') {
            $st = $pdo->prepare("SELECT * FROM orders WHERE type='d' AND tbl=? AND status IN ('new','prep') AND created>? ORDER BY id DESC LIMIT 1");
            $st->execute(array($tbl, $now - 6 * 3600));
            $cur = $st->fetch();
        }
        if ($cur) {
            $all = json_decode($cur['items'], true);
            if (!is_array($all)) $all = array();
            $b = (int)$cur['batches'];
            foreach ($lines as $l) { $l['b'] = $b; $l['t'] = $now; $all[] = $l; }
            $s2 = 0.0;
            foreach ($all as $l) $s2 += (float)$l['p'] * (int)$l['q'];
            $s2 = round($s2, $dec);
            $v2 = round($s2 * $pct / 100, $dec);
            $t2 = round($s2 + $v2, 3);
            $n2 = (string)$cur['note'];
            if ($note !== '') $n2 = substr($n2 . ($n2 !== '' ? "\n" : '') . 'ملحق: ' . $note, 0, 900);
            $rev = next_rev($pdo);
            $pdo->prepare('UPDATE orders SET items=?, sub=?, svc=?, total=?, note=?, batches=?, rev=? WHERE id=?')
                ->execute(array(json_encode($all, JSON_UNESCAPED_UNICODE), $s2, $v2, $t2, $n2, $b + 1, $rev, (int)$cur['id']));
            $pdo->prepare('INSERT INTO addons(ckey,order_id) VALUES(?,?)')->execute(array($ckey, (int)$cur['id']));
            $res = array('ok' => true, 'no' => (int)$cur['no'], 'token' => $cur['token'], 'status' => $cur['status'], 'type' => 'd', 'addon' => true);
        } else {
            $st = $pdo->prepare('SELECT COALESCE(MAX(no),0)+1 FROM orders WHERE day=?');
            $st->execute(array($day));
            $no = (int)$st->fetchColumn();
            $rev = next_rev($pdo);
            $pdo->prepare('INSERT INTO orders(day,no,token,ckey,type,tbl,cname,phone,addr,lat,lng,note,items,sub,svc,total,status,owner,created,rev,ip) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute(array($day, $no, $token, $ckey, $type, $tbl, $cname, $phone, $addr, $lat, $lng, $note, json_encode($lines, JSON_UNESCAPED_UNICODE), $sub, $svc, $total, $status, $owner, $now, $rev, $ip));
            $res = array('ok' => true, 'no' => $no, 'token' => $token, 'status' => $status, 'type' => $type);
        }
        $pdo->exec('COMMIT');
    } catch (Exception $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
    return $res;
}

function make_stats($from, $to) {
    $st = db()->prepare('SELECT * FROM orders WHERE day>=? AND day<=?');
    $st->execute(array($from, $to));
    $names = array();
    foreach (db()->query('SELECT id,name,role FROM users') as $u) $names[(int)$u['id']] = $u;
    $tot = array('orders' => 0, 'sales' => 0.0, 'cancel' => 0);
    $by = array('d' => array('n' => 0, 'sales' => 0.0), 'h' => array('n' => 0, 'sales' => 0.0));
    $stat = array(); $top = array(); $staff = array(); $daily = array();
    foreach ($st->fetchAll() as $r) {
        $s = $r['status'];
        $stat[$s] = (isset($stat[$s]) ? $stat[$s] : 0) + 1;
        if ($s === 'cancel') { $tot['cancel']++; continue; }
        $t = (float)$r['total'];
        $tot['orders']++; $tot['sales'] += $t;
        $by[$r['type']]['n']++; $by[$r['type']]['sales'] += $t;
        $d = $r['day'];
        if (!isset($daily[$d])) $daily[$d] = array('n' => 0, 'sales' => 0.0);
        $daily[$d]['n']++; $daily[$d]['sales'] += $t;
        $items = json_decode($r['items'], true);
        if (is_array($items)) foreach ($items as $i) {
            $k = isset($i['id']) ? (int)$i['id'] : 0;
            if (!isset($top[$k])) $top[$k] = array('n' => (string)$i['n'], 'q' => 0, 'rev' => 0.0);
            $top[$k]['q'] += (int)$i['q'];
            $top[$k]['rev'] += (float)$i['p'] * (int)$i['q'];
        }
        if ($s === 'done') {
            $o = $r['owner'] === null ? 0 : (int)$r['owner'];
            if (!isset($staff[$o])) $staff[$o] = array('id' => $o, 'name' => $o && isset($names[$o]) ? $names[$o]['name'] : 'بدون موظف', 'role' => $o && isset($names[$o]) ? $names[$o]['role'] : '', 'n' => 0, 'sales' => 0.0);
            $staff[$o]['n']++; $staff[$o]['sales'] += $t;
        }
    }
    $days = array();
    for ($t = strtotime($from . ' 12:00:00'), $end = strtotime($to . ' 12:00:00'); $t <= $end; $t += 86400) {
        $d = date('Y-m-d', $t);
        $days[] = array('d' => $d, 'n' => isset($daily[$d]) ? $daily[$d]['n'] : 0, 'sales' => isset($daily[$d]) ? round($daily[$d]['sales'], 3) : 0);
    }
    usort($top, function ($a, $b) { return $b['q'] - $a['q']; });
    $staff = array_values($staff);
    usort($staff, function ($a, $b) { return $b['sales'] > $a['sales'] ? 1 : ($b['sales'] < $a['sales'] ? -1 : 0); });
    foreach ($top as &$x) $x['rev'] = round($x['rev'], 3);
    unset($x);
    foreach ($staff as &$x) $x['sales'] = round($x['sales'], 3);
    unset($x);
    return array('ok' => true, 'from' => $from, 'to' => $to,
        'total' => array('orders' => $tot['orders'], 'sales' => round($tot['sales'], 3), 'cancel' => $tot['cancel'],
            'avg' => $tot['orders'] ? round($tot['sales'] / $tot['orders'], 3) : 0),
        'dine' => array('n' => $by['d']['n'], 'sales' => round($by['d']['sales'], 3)),
        'delivery' => array('n' => $by['h']['n'], 'sales' => round($by['h']['sales'], 3)),
        'status' => $stat, 'top' => array_slice($top, 0, 10), 'staff' => $staff, 'daily' => $days);
}

function orders_api($action, $in, $isJsonPost) {
    switch ($action) {
    case 'order':
        if (!$isJsonPost) out(array('ok' => false, 'error' => 'bad request'), 415);
        if (!empty($in['hp'])) out(array('ok' => true, 'no' => 0, 'token' => '', 'status' => 'new', 'type' => 'd'));   // فخ للبوتات
        out(create_order($in));

    case 'track':
        $t = isset($_GET['t']) ? preg_replace('/[^a-f0-9]/', '', (string)$_GET['t']) : '';
        if (strlen($t) < 10) out(array('ok' => false, 'error' => 'not found'), 404);
        $st = db()->prepare('SELECT no,type,status FROM orders WHERE token=?');
        $st->execute(array($t));
        $r = $st->fetch();
        if (!$r) out(array('ok' => false, 'error' => 'not found'), 404);
        out(array('ok' => true, 'no' => (int)$r['no'], 'type' => $r['type'], 'status' => $r['status']));

    case 'staff_login':
        if (!$isJsonPost) out(array('ok' => false, 'error' => 'bad request'), 415);
        $un = strtolower(trim(isset($in['username']) ? (string)$in['username'] : ''));
        $pw = isset($in['password']) ? (string)$in['password'] : '';
        $st = db()->prepare('SELECT * FROM users WHERE username=? AND active=1');
        $st->execute(array($un));
        $u = $st->fetch();
        if ($u && password_verify($pw, $u['hash'])) {
            session_regenerate_id(true);
            unset($_SESSION['adm']);
            $_SESSION['uid'] = (int)$u['id'];
            out(array('ok' => true, 'user' => array('id' => (int)$u['id'], 'name' => $u['name'], 'role' => $u['role'], 'tbls' => $u['tbls'])));
        }
        // حساب المدير العام: اسم المستخدم admin + كلمة مرور لوحة التحكم (لا يُحذف أبداً)
        if ($un === 'admin' && pw_ok($pw)) {
            session_regenerate_id(true);
            unset($_SESSION['uid']);
            $_SESSION['adm'] = 1;
            out(array('ok' => true, 'user' => array('id' => 0, 'name' => 'الإدارة', 'role' => 'manager', 'tbls' => '')));
        }
        sleep(1);
        out(array('ok' => false, 'error' => 'اسم المستخدم أو كلمة المرور غير صحيحة'));

    case 'staff_logout':
        unset($_SESSION['uid']);
        unset($_SESSION['adm']);
        out(array('ok' => true));

    case 'me':
        $u = me();
        out(array('ok' => $u !== null, 'user' => $u));
    }

    // ---- ما بعده يحتاج دخول موظف أو مدير ----
    $u = me();
    // إدارة الموظفين تتم بجلسة لوحة التحكم (admin.html) وليست بحساب موظف
    if (!$u && !empty($_SESSION['ok']) && in_array($action, array('users', 'user_save', 'user_del'), true)) {
        $u = array('id' => 0, 'name' => 'الإدارة', 'role' => 'manager', 'tbls' => '');
    }
    if (!$u) out(array('ok' => false, 'error' => 'auth'), 401);
    $role = $u['role'];

    switch ($action) {
    case 'orders':
        $since = isset($_GET['since']) ? (int)$_GET['since'] : 0;
        $pdo = db();
        $max = (int)$pdo->query('SELECT COALESCE(MAX(rev),0) FROM orders')->fetchColumn();
        $w = "o.rev>:s AND o.rev<=:m AND (o.status NOT IN ('done','cancel') OR o.day>=:d)";
        $p = array(':s' => $since, ':m' => $max, ':d' => date('Y-m-d'));
        if ($role === 'waiter') { $w .= ' AND o.owner=:u'; $p[':u'] = $u['id']; }
        elseif ($role === 'delivery') $w .= " AND o.type='h'";
        elseif ($role === 'kitchen') $w .= " AND o.status<>'pending'";
        $st = $pdo->prepare('SELECT o.*, u.name AS owner_name FROM orders o LEFT JOIN users u ON u.id=o.owner WHERE ' . $w . ' ORDER BY o.id DESC LIMIT 400');
        $st->execute($p);
        $list = array();
        foreach ($st->fetchAll() as $r) $list[] = fmt_order($r);
        $res = array('ok' => true, 'rev' => $max, 'orders' => $list);
        if ($role === 'manager') {
            $res['staff'] = array();
            foreach ($pdo->query("SELECT id,name,role FROM users WHERE active=1 AND role IN ('waiter','delivery') ORDER BY name") as $s) $res['staff'][] = array('id' => (int)$s['id'], 'name' => $s['name'], 'role' => $s['role']);
        }
        out($res);

    case 'order_set':
        if (!$isJsonPost) out(array('ok' => false, 'error' => 'bad request'), 415);
        $id = isset($in['id']) ? (int)$in['id'] : 0;
        $s = isset($in['status']) ? (string)$in['status'] : '';
        if (!in_array($s, array('pending', 'new', 'prep', 'ready', 'out', 'done', 'cancel'), true)) throw new UserErr('حالة غير صحيحة');
        $o = get_order($id);
        if (!$o) throw new UserErr('الطلب غير موجود');
        if (!in_array($s, role_allowed($role), true)) throw new UserErr('غير مسموح لك بهذا الإجراء');
        $ok = $role === 'manager'
            || ($role === 'waiter' && $o['type'] === 'd' && $o['owner'] === $u['id'])
            || ($role === 'delivery' && $o['type'] === 'h')
            || ($role === 'kitchen' && in_array($o['status'], array('new', 'prep', 'ready'), true));
        if (!$ok) throw new UserErr('هذا الطلب ليس من طلباتك');
        if ($o['type'] === 'd' && ($s === 'pending' || $s === 'out')) throw new UserErr('حالة غير مناسبة لطلب الصالة');
        $owner = $o['owner'];
        if ($owner === null && $role === 'delivery') $owner = $u['id'];
        $doneBy = ($s === 'done' && $u['id'] > 0) ? $u['id'] : null;
        out(array('ok' => true, 'order' => upd_order($id, 'status=?, owner=?, done_by=?', array($s, $owner, $doneBy))));

    case 'order_assign':
        if (!$isJsonPost) out(array('ok' => false, 'error' => 'bad request'), 415);
        if ($role !== 'manager') throw new UserErr('للمدير فقط');
        $id = isset($in['id']) ? (int)$in['id'] : 0;
        $to = isset($in['owner']) ? (int)$in['owner'] : 0;
        if (!get_order($id)) throw new UserErr('الطلب غير موجود');
        if ($to > 0) {
            $st = db()->prepare("SELECT id FROM users WHERE id=? AND active=1 AND role IN ('waiter','delivery')");
            $st->execute(array($to));
            if (!$st->fetch()) throw new UserErr('الموظف غير موجود');
        } else $to = null;
        out(array('ok' => true, 'order' => upd_order($id, 'owner=?', array($to))));

    case 'print_claim':
        if (!$isJsonPost) out(array('ok' => false, 'error' => 'bad request'), 415);
        if ($role !== 'kitchen' && $role !== 'manager') throw new UserErr('غير مسموح');
        $id = isset($in['id']) ? (int)$in['id'] : 0;
        $n = isset($in['n']) ? max(1, (int)$in['n']) : 1;
        $st = db()->prepare('UPDATE orders SET printed=? WHERE id=? AND printed=? AND batches>=?');
        $st->execute(array($n, $id, $n - 1, $n));
        $claimed = $st->rowCount() > 0;
        $o = $claimed ? upd_order($id, 'printed=?', array($n)) : get_order($id);
        out(array('ok' => true, 'claimed' => $claimed, 'order' => $o));

    case 'stats':
        if ($role !== 'manager') throw new UserErr('للمدير فقط');
        $from = isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from']) ? $_GET['from'] : date('Y-m-d');
        $to = isset($_GET['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']) ? $_GET['to'] : date('Y-m-d');
        if ($from > $to) { $x = $from; $from = $to; $to = $x; }
        if ((strtotime($to) - strtotime($from)) / 86400 > 400) $from = date('Y-m-d', strtotime($to) - 400 * 86400);
        out(make_stats($from, $to));

    // ---- إدارة الموظفين (بكلمة مرور لوحة التحكم فقط) ----
    case 'users':
    case 'user_save':
    case 'user_del':
        if (empty($_SESSION['ok'])) out(array('ok' => false, 'error' => 'auth'), 401);
        $pdo = db();
        if ($action === 'users') {
            $list = array();
            foreach ($pdo->query('SELECT id,username,name,role,tbls,active FROM users ORDER BY role,name') as $r) {
                $list[] = array('id' => (int)$r['id'], 'username' => $r['username'], 'name' => $r['name'], 'role' => $r['role'], 'tbls' => $r['tbls'], 'active' => (int)$r['active']);
            }
            out(array('ok' => true, 'users' => $list));
        }
        if (!$isJsonPost) out(array('ok' => false, 'error' => 'bad request'), 415);
        if ($action === 'user_del') {
            $pdo->prepare('DELETE FROM users WHERE id=?')->execute(array(isset($in['id']) ? (int)$in['id'] : 0));
            out(array('ok' => true));
        }
        $id = isset($in['id']) ? (int)$in['id'] : 0;
        $name = cut(isset($in['name']) ? $in['name'] : '', 40);
        $un = strtolower(trim(isset($in['username']) ? (string)$in['username'] : ''));
        $r = isset($in['role']) ? (string)$in['role'] : '';
        $tb = cut(ascii_digits(isset($in['tbls']) ? $in['tbls'] : ''), 200);
        $active = !empty($in['active']) ? 1 : 0;
        $pw = isset($in['password']) ? (string)$in['password'] : '';
        if ($name === '') throw new UserErr('الاسم مطلوب');
        if (!preg_match('/^[a-z0-9_.\-]{3,30}$/', $un)) throw new UserErr('اسم المستخدم: 3 إلى 30 حرفاً إنجليزياً أو أرقاماً بدون مسافات');
        if (!in_array($r, array('manager', 'waiter', 'delivery', 'kitchen'), true)) throw new UserErr('الدور غير صحيح');
        if ($pw !== '' && (strlen($pw) < 6 || strlen($pw) > 100)) throw new UserErr('كلمة المرور يجب ألا تقل عن 6 أحرف');
        try {
            if ($id > 0) {
                $pdo->prepare('UPDATE users SET username=?, name=?, role=?, tbls=?, active=? WHERE id=?')->execute(array($un, $name, $r, $tb, $active, $id));
                if ($pw !== '') $pdo->prepare('UPDATE users SET hash=? WHERE id=?')->execute(array(password_hash($pw, PASSWORD_DEFAULT), $id));
            } else {
                if ($pw === '') throw new UserErr('كلمة المرور مطلوبة للموظف الجديد');
                $pdo->prepare('INSERT INTO users(username,hash,name,role,tbls,active) VALUES(?,?,?,?,?,?)')->execute(array($un, password_hash($pw, PASSWORD_DEFAULT), $name, $r, $tb, $active));
            }
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) throw new UserErr('اسم المستخدم مستخدم من قبل');
            throw $e;
        }
        out(array('ok' => true));
    }
    out(array('ok' => false, 'error' => 'unknown action'), 400);
}
function run_orders($action, $in, $isJsonPost) {
    try {
        orders_api($action, $in, $isJsonPost);
    } catch (UserErr $e) {
        out(array('ok' => false, 'error' => (isset($in['lang']) && $in['lang'] === 'en' && $e->en !== '') ? $e->en : $e->getMessage()), 400);
    } catch (Throwable $e) {
        error_log('orders api: ' . $e->getMessage());
        out(array('ok' => false, 'code' => 'db', 'error' => 'تعذر الوصول لقاعدة بيانات الطلبات (تأكد من تفعيل SQLite وصلاحيات الكتابة على فولدر data)'), 500);
    }
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

// ---------- المنيو العام (للزبائن) ----------
if ($action === 'menu') {
    if (!file_exists($menuFile)) out(array('ok' => false, 'error' => 'no menu'), 404);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-LiteSpeed-Cache-Control: no-cache');
    readfile($menuFile);
    exit;
}

$in = array();
$isJsonPost = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ct = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
    if (stripos($ct, 'application/json') !== false) {
        $isJsonPost = true;
        $dec = json_decode(file_get_contents('php://input'), true);
        if (is_array($dec)) $in = $dec;
    }
}

// ---------- الدخول والخروج ----------
if ($action === 'login') {
    $pw = isset($in['password']) ? (string)$in['password'] : '';
    if (pw_ok($pw)) {
        session_regenerate_id(true);
        $_SESSION['ok'] = 1;
        out(array('ok' => true));
    }
    sleep(1);
    out(array('ok' => false, 'error' => 'كلمة المرور غير صحيحة'));
}
if ($action === 'logout') {
    $_SESSION = array();
    session_destroy();
    out(array('ok' => true));
}
if ($action === 'whoami') {
    out(array('ok' => !empty($_SESSION['ok'])));
}
// ---------- نظام الطلبات: عمليات الزبون والموظفين (قبل بوابة المدير) ----------
if (in_array($action, array('order', 'track', 'staff_login', 'staff_logout', 'me', 'orders', 'order_set', 'order_assign', 'print_claim', 'stats'), true)) run_orders($action, $in, $isJsonPost);
if (empty($_SESSION['ok'])) out(array('ok' => false, 'error' => 'auth'), 401);
if (in_array($action, array('users', 'user_save', 'user_del'), true)) run_orders($action, $in, $isJsonPost);

// ---------- التحقق من بيانات المنيو قبل الحفظ ----------
function clean_menu($m) {
    if (!is_array($m) || !isset($m['config'], $m['cats'], $m['items'])) return null;
    if (!is_array($m['config']) || !is_array($m['cats']) || !is_array($m['items'])) return null;
    $c = $m['config'];
    $color = isset($c['color']) ? (string)$c['color'] : '#7f2220';
    $color2 = isset($c['color2']) ? (string)$c['color2'] : '#de832b';
    $bg = isset($c['bg']) ? (string)$c['bg'] : '';
    $tcol = isset($c['text']) ? (string)$c['text'] : '';
    $ccol = isset($c['card']) ? (string)$c['card'] : '';
    $hcol = isset($c['htext']) ? (string)$c['htext'] : '';
    $ml = isset($c['meals']) && is_array($c['meals']) ? $c['meals'] : array();
    $cfg = array(
        'name' => cut(isset($c['name']) ? $c['name'] : '', 80),
        'sub' => cut(isset($c['sub']) ? $c['sub'] : '', 160),
        'name_en' => cut(isset($c['name_en']) ? $c['name_en'] : '', 80),
        'sub_en' => cut(isset($c['sub_en']) ? $c['sub_en'] : '', 160),
        'wa' => preg_replace('/\D/', '', isset($c['wa']) ? (string)$c['wa'] : ''),
        'cur' => cut(isset($c['cur']) ? $c['cur'] : '', 12),
        'dec' => max(0, min(3, (int)(isset($c['dec']) ? $c['dec'] : 0))),
        'service' => max(0, min(100, (float)(isset($c['service']) ? $c['service'] : 0))),
        'dine' => !empty($c['dine']),
        'delivery' => !empty($c['delivery']),
        'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#7f2220',
        'logo' => clean_img(isset($c['logo']) ? $c['logo'] : ''),
        'color2' => preg_match('/^#[0-9a-fA-F]{6}$/', $color2) ? $color2 : '#de832b',
        'bg' => preg_match('/^#[0-9a-fA-F]{6}$/', $bg) ? $bg : '',
        'text' => preg_match('/^#[0-9a-fA-F]{6}$/', $tcol) ? $tcol : '',
        'card' => preg_match('/^#[0-9a-fA-F]{6}$/', $ccol) ? $ccol : '',
        'htext' => preg_match('/^#[0-9a-fA-F]{6}$/', $hcol) ? $hcol : '',
        'meals' => array('on' => !empty($ml['on']), 'b' => cut(isset($ml['b']) ? $ml['b'] : 'الإفطار', 40), 'bt' => cut(isset($ml['bt']) ? $ml['bt'] : '', 40),
            'l' => cut(isset($ml['l']) ? $ml['l'] : 'الغداء والعشاء', 40), 'lt' => cut(isset($ml['lt']) ? $ml['lt'] : '', 40),
            'be' => cut(isset($ml['be']) ? $ml['be'] : '', 40), 'bte' => cut(isset($ml['bte']) ? $ml['bte'] : '', 40), 'le' => cut(isset($ml['le']) ? $ml['le'] : '', 40), 'lte' => cut(isset($ml['lte']) ? $ml['lte'] : '', 40)),
    );
    if ($cfg['name'] === '' || $cfg['wa'] === '') return null;
    if (!$cfg['dine'] && !$cfg['delivery']) $cfg['dine'] = true;

    $cats = array();
    $catIds = array();
    foreach ($m['cats'] as $c) {
        if (!is_array($c) || count($cats) >= 100) continue;
        $id = isset($c['id']) ? (int)$c['id'] : 0;
        $ar = cut(isset($c['ar']) ? $c['ar'] : '', 80);
        if ($id <= 0 || $ar === '' || isset($catIds[$id])) continue;
        $catIds[$id] = true;
        $cats[] = array('id' => $id, 'ar' => $ar, 'en' => cut(isset($c['en']) ? $c['en'] : '', 80), 'meal' => (isset($c['meal']) && ($c['meal'] === 'b' || $c['meal'] === 'l')) ? $c['meal'] : '', 'note' => cut(isset($c['note']) ? $c['note'] : '', 200), 'note_en' => cut(isset($c['note_en']) ? $c['note_en'] : '', 200), 'img' => clean_img(isset($c['img']) ? $c['img'] : ''));
    }
    $items = array();
    $itemIds = array();
    foreach ($m['items'] as $it) {
        if (!is_array($it) || count($items) >= 3000) continue;
        $id = isset($it['id']) ? (int)$it['id'] : 0;
        $cat = isset($it['cat']) ? (int)$it['cat'] : 0;
        $ar = cut(isset($it['ar']) ? $it['ar'] : '', 120);
        if ($id <= 0 || $ar === '' || isset($itemIds[$id]) || !isset($catIds[$cat])) continue;
        $itemIds[$id] = true;
        $price = isset($it['price']) && is_numeric($it['price']) ? round((float)$it['price'], 3) : 0;
        if ($price < 0) $price = 0;
        $items[] = array('id' => $id, 'cat' => $cat, 'ar' => $ar, 'en' => cut(isset($it['en']) ? $it['en'] : '', 120),
            'desc' => cut(isset($it['desc']) ? $it['desc'] : '', 400), 'desc_en' => cut(isset($it['desc_en']) ? $it['desc_en'] : '', 400), 'price' => $price,
            'img' => clean_img(isset($it['img']) ? $it['img'] : ''), 'avail' => !empty($it['avail']), 'new' => !empty($it['new']));
    }
    if (count($cats) === 0) return null;
    $res = array('config' => $cfg, 'cats' => $cats, 'items' => $items);
    if (isset($m['info']) && is_array($m['info']) && isset($m['info']['cols'], $m['info']['rows']) && is_array($m['info']['cols']) && is_array($m['info']['rows'])) {
        $icols = array();
        foreach ($m['info']['cols'] as $cc) { if (count($icols) < 8) $icols[] = cut(is_scalar($cc) ? $cc : '', 40); }
        if (count($icols) > 0) {
            $irows = array();
            foreach ($m['info']['rows'] as $rr) {
                if (!is_array($rr) || count($irows) >= 300) continue;
                $row = array();
                for ($k = 0; $k < count($icols); $k++) { $row[] = cut(isset($rr[$k]) && is_scalar($rr[$k]) ? $rr[$k] : '', 120); }
                $irows[] = $row;
            }
            $res['info'] = array('title' => cut(isset($m['info']['title']) ? $m['info']['title'] : '', 80), 'cols' => $icols, 'rows' => $irows);
        }
    }
    return $res;
}

// ---------- تغيير كلمة المرور ----------
if ($action === 'setpw') {
    if (!$isJsonPost) out(array('ok' => false, 'error' => 'bad request'), 415);
    $old = isset($in['old']) ? (string)$in['old'] : '';
    $new = isset($in['new']) ? (string)$in['new'] : '';
    if (!pw_ok($old)) { sleep(1); out(array('ok' => false, 'error' => 'كلمة المرور الحالية غير صحيحة')); }
    if (strlen($new) < 6 || strlen($new) > 100) out(array('ok' => false, 'error' => 'كلمة المرور الجديدة يجب ألا تقل عن 6 أحرف'));
    if (file_put_contents($dataDir . '/auth.json', json_encode(array('hash' => password_hash($new, PASSWORD_DEFAULT))), LOCK_EX) === false) {
        out(array('ok' => false, 'error' => 'تعذر الحفظ (راجع صلاحيات فولدر data)'), 500);
    }
    out(array('ok' => true));
}

// ---------- حفظ المنيو ----------
if ($action === 'save') {
    if (!$isJsonPost) out(array('ok' => false, 'error' => 'bad request'), 415);
    $m = clean_menu(isset($in['menu']) ? $in['menu'] : null);
    if ($m === null) out(array('ok' => false, 'error' => 'بيانات المنيو غير صحيحة (الاسم ورقم واتساب وقسم واحد على الأقل مطلوبة)'), 400);
    if (file_exists($menuFile)) @copy($menuFile, $dataDir . '/menu.bak.json');
    $tmp = $menuFile . '.tmp';
    if (file_put_contents($tmp, json_encode($m, JSON_UNESCAPED_UNICODE), LOCK_EX) === false || !rename($tmp, $menuFile)) {
        out(array('ok' => false, 'error' => 'تعذر الكتابة على الخادم (راجع صلاحيات فولدر data)'), 500);
    }
    out(array('ok' => true, 'items' => count($m['items'])));
}

// ---------- رفع صورة ----------
if ($action === 'upload') {
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) out(array('ok' => false, 'error' => 'لم يصل الملف'), 400);
    $f = $_FILES['file'];
    if ($f['size'] > 8 * 1024 * 1024) out(array('ok' => false, 'error' => 'الصورة أكبر من 8 ميجا'), 400);
    $info = @getimagesize($f['tmp_name']);
    if (!$info) out(array('ok' => false, 'error' => 'الملف ليس صورة صالحة'), 400);
    $type = $info[2];
    $rel = 'uploads/' . date('ymd_His') . '_' . bin2hex(random_bytes(3)) . '.jpg';
    $dest = __DIR__ . '/' . $rel;
    if (function_exists('imagecreatetruecolor')) {
        $src = false;
        if ($type === IMAGETYPE_JPEG) $src = @imagecreatefromjpeg($f['tmp_name']);
        elseif ($type === IMAGETYPE_PNG) $src = @imagecreatefrompng($f['tmp_name']);
        elseif ($type === 18 && function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($f['tmp_name']);
        if (!$src) out(array('ok' => false, 'error' => 'صيغة الصورة غير مدعومة (استخدم JPG أو PNG)'), 400);
        $w = imagesx($src); $h = imagesy($src);
        $r = min(1, 900 / max($w, $h));
        $nw = max(1, (int)round($w * $r)); $nh = max(1, (int)round($h * $r));
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagejpeg($dst, $dest, 82);
    } else {
        if ($type !== IMAGETYPE_JPEG) out(array('ok' => false, 'error' => 'GD غير مفعّل على الاستضافة: ارفع صورة JPG'), 400);
        move_uploaded_file($f['tmp_name'], $dest);
    }
    if (!file_exists($dest)) out(array('ok' => false, 'error' => 'تعذر حفظ الصورة (راجع صلاحيات فولدر uploads)'), 500);
    out(array('ok' => true, 'path' => $rel));
}

out(array('ok' => false, 'error' => 'unknown action'), 400);
