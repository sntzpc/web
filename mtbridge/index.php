<?php
/** MikroTik v6 Binary API → HTTP JSON Bridge (v1)
 *  Endpoints (POST/GET):
 *    ?route=ping
 *    ?route=active
 *    ?route=users
 *  Keamanan: tambahkan header X-Bridge-Token: <token di config.php>
 *  Catatan: default koneksi plain (8728). Set $use_tls=true untuk 8729 (butuh stream crypto).
 */
$config = require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Bridge-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$token = $_SERVER['HTTP_X_BRIDGE_TOKEN'] ?? '';
if ($token !== $config['token']) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit; }

$route = $_GET['route'] ?? ($_POST['route'] ?? 'ping');

try{
  switch($route){
    case 'ping':
      echo json_encode(['ok'=>true,'msg'=>'bridge up']); break;

    case 'active': // /ip/hotspot/active/print
      $rows = mt_print(['/ip/hotspot/active/print']);
      echo json_encode(['ok'=>true,'data'=>normalize_active($rows)]); break;

    case 'users':  // /ip/hotspot/user/print
      $rows = mt_print(['/ip/hotspot/user/print']);
      echo json_encode(['ok'=>true,'data'=>normalize_users($rows)]); break;

    default:
      http_response_code(400);
      echo json_encode(['ok'=>false,'error'=>'Unknown route']);
  }
} catch(Throwable $e){
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}

/* =========================
   RouterOS Binary API Core
   ========================= */
function mt_print(array $cmd){
  $conn = mt_connect();
  try{
    mt_login($conn);
    mt_write_sentence($conn, $cmd);
    $r = mt_read_responses($conn);
    mt_close($conn);
    return $r;
  } catch(Throwable $e){
    mt_close($conn);
    throw $e;
  }
}
function mt_connect(){
  global $config;
  $host = $config['host'];
  $port = (int)$config['port'];
  $timeout = (int)$config['timeout'];
  $use_tls = ($port === 8729); // ubah ke true paksa jika perlu TLS meski port bukan 8729

  $ctx = stream_context_create();
  if ($use_tls){
    $ctx = stream_context_create([
      'ssl'=>[
        'verify_peer'=>false,
        'verify_peer_name'=>false,
        'allow_self_signed'=>true
      ]
    ]);
    $fp = stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
  } else {
    $fp = stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);
  }
  if (!$fp) throw new Exception("Connect failed: {$errstr} ({$errno})");
  stream_set_timeout($fp, $timeout);
  return $fp;
}
function mt_close($fp){ if(is_resource($fp)) fclose($fp); }

/* ==== Wire format helpers (length-encoded words) ==== */
function mt_len_encode($len){
  if ($len < 0x80)    return chr($len);
  if ($len < 0x4000)  return chr(($len>>8) | 0x80) . chr($len & 0xFF);
  if ($len < 0x200000)return chr(($len>>16)|0xC0) . chr(($len>>8)&0xFF) . chr($len&0xFF);
  if ($len < 0x10000000) return chr(($len>>24)|0xE0) . chr(($len>>16)&0xFF) . chr(($len>>8)&0xFF) . chr($len&0xFF);
  return chr(0xF0) . chr(($len>>24)&0xFF) . chr(($len>>16)&0xFF) . chr(($len>>8)&0xFF) . chr($len&0xFF);
}
function mt_write_word($fp, $w){
  $w = (string)$w;
  $len = strlen($w);
  fwrite($fp, mt_len_encode($len) . $w);
}
function mt_write_sentence($fp, array $words){
  foreach($words as $w) mt_write_word($fp, $w);
  mt_write_word($fp, ''); // sentence terminator
}
function mt_len_read($fp){
  $c = ord(fread($fp,1));
  if ($c < 0x80) return $c;
  if (($c & 0xC0) == 0x80){
    $b = ord(fread($fp,1));
    return (($c & 0x3F) << 8) + $b;
  }
  if (($c & 0xE0) == 0xC0){
    $b1 = ord(fread($fp,1)); $b2 = ord(fread($fp,1));
    return (($c & 0x1F)<<16) + ($b1<<8) + $b2;
  }
  if (($c & 0xF0) == 0xE0){
    $b1 = ord(fread($fp,1)); $b2 = ord(fread($fp,1)); $b3 = ord(fread($fp,1));
    return (($c & 0x0F)<<24) + ($b1<<16) + ($b2<<8) + $b3;
  }
  // 5-byte
  $b1 = ord(fread($fp,1)); $b2 = ord(fread($fp,1)); $b3 = ord(fread($fp,1)); $b4 = ord(fread($fp,1));
  return ($b1<<24)+($b2<<16)+($b3<<8)+$b4;
}
function mt_read_word($fp){
  $len = mt_len_read($fp);
  if ($len === 0) return '';
  $data = '';
  while(strlen($data) < $len){
    $chunk = fread($fp, $len - strlen($data));
    if($chunk === false || $chunk === '') break;
    $data .= $chunk;
  }
  return $data;
}
function mt_read_sentence($fp){
  $r = [];
  while (true){
    $w = mt_read_word($fp);
    if ($w === '') break; // sentence end
    $r[] = $w;
  }
  return $r;
}
function mt_read_responses($fp){
  $rows = [];
  while(true){
    $sentence = mt_read_sentence($fp);
    if (empty($sentence)) continue; // keep reading
    $type = $sentence[0] ?? '';
    if ($type === '!re'){
      $obj = [];
      foreach($sentence as $w){
        if (strpos($w,'=') !== false){
          // bentuk: =key=value
          if ($w[0] === '='){
            $parts = explode('=', substr($w,1), 2);
            if (count($parts) === 2){
              [$k, $v] = explode('=', $parts[1], 2);
              $obj[$parts[0]] = $v ?? '';
            }
          }
        }
      }
      $rows[] = $obj;
    } elseif ($type === '!done'){
      break;
    } elseif ($type === '!trap'){
      // error dari router
      $msg = '';
      foreach($sentence as $w){
        if (strpos($w,'=message=') !== false) $msg = substr($w, strlen('=message='));
      }
      throw new Exception('Router error: '.$msg);
    }
  }
  return $rows;
}
function mt_login($fp){
  global $config;
  // v6 modern: plain login.
  mt_write_sentence($fp, ['/login', '=name='.$config['user'], '=password='.$config['pass']]);
  // baca sampai !done
  while(true){
    $s = mt_read_sentence($fp);
    if (empty($s)) continue;
    if ($s[0] === '!done') break;
    if ($s[0] === '!trap') throw new Exception('Login failed');
  }
}

/* ====== Normalizers ====== */
function normalize_active(array $rows){
  // Ambil kolom penting; biarkan kunci lain tetap jika ada.
  $out = [];
  foreach($rows as $r){
    $out[] = [
      '.id'        => $r['.id']        ?? '',
      'user'       => $r['user']       ?? '',
      'address'    => $r['address']    ?? '',
      'mac-address'=> $r['mac-address']?? '',
      'login-time' => $r['login-time'] ?? '',
      'uptime'     => $r['uptime']     ?? '',
      'idle-time'  => $r['idle-time']  ?? '',
      'bytes-in'   => $r['bytes-in']   ?? '',
      'bytes-out'  => $r['bytes-out']  ?? '',
      'comment'    => $r['comment']    ?? '',
      'server'     => $r['server']     ?? '',
    ];
  }
  return $out;
}
function normalize_users(array $rows){
  $out = [];
  foreach($rows as $r){
    $out[] = [
      '.id'      => $r['.id']      ?? '',
      'name'     => $r['name']     ?? '',
      'profile'  => $r['profile']  ?? '',
      'disabled' => $r['disabled'] ?? '',
      'comment'  => $r['comment']  ?? '',
      // password tidak diminta dari router (tidak aman untuk disebar)
    ];
  }
  return $out;
}
