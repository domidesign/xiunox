<?php

/*

	加载 xiuno.so 能大大提高加密解密的速度，大约 250 倍，并且安全性会得到更大的提升。
	
	xiuno.so 中包含了
	xiuno_key()
	xiuno_encrypt($data, $key)
	xiuno_decrypt($data, $key)
	xiuno_get_cpuid(0);
	
*/

// 获取配置文件中的 key，优先从扩展中获取（比较安全）。 
function xn_key($fromso = TRUE) {
	$conf = _SERVER('conf');
	return ($fromso && function_exists('xiuno_key')) ? xiuno_key() : (isset($conf['auth_key']) ? $conf['auth_key'] : '');
}

// 安全的加密 key，过期时间 100 秒，如果最后 2 位 大于 90，则
// 临时使用，一般用作数据传输和校验
function xn_safe_key() {
	global $conf, $longip, $time, $useragent;
	$conf = _SERVER('conf');
	$longip = _SERVER('longip');
	$time = _SERVER('time');
	$useragent = _SERVER('useragent');
	$key = xn_key();
	$behind = intval(substr($time, -2, 2));
	$t = $behind > 80 ? $time - 20 : ($behind < 20 ? $time - 40 : $time); // 修正范围，防止进位，有效时间窗口
	$front = substr($t, 0, -2);
	$key = md5($key.$useragent.$front);
	return $key;
}

// AES-256-GCM 加密（v2，替代 XXTEA）
// 密钥派生：HKDF-SHA256，info='xiuno-token'，输出 32 字节
// IV：12 字节随机数（GCM 标准）
// 认证标签：16 字节
// 密文格式：base64(iv[12] + ciphertext + tag[16])
function xn_encrypt_v2($key, $data) {
	$derived_key = hash_hkdf('sha256', $key, 0, 'xiuno-token');
	$iv = random_bytes(12);
	$tag = '';
	$ciphertext = openssl_encrypt($data, 'aes-256-gcm', $derived_key, OPENSSL_RAW_DATA, $iv, $tag);
	if($ciphertext === false) return false;
	return base64_encode($iv . $ciphertext . $tag);
}

// AES-256-GCM 解密（v2）
// 失败返回 false；GCM 自带认证标签校验，tag 不匹配则 openssl_decrypt 返回 false
function xn_decrypt_v2($key, $payload) {
	$raw = base64_decode($payload, true);
	if($raw === false) return false;
	// 最小长度：12(IV) + 16(tag) = 28 字节
	if(strlen($raw) < 28) return false;
	$derived_key = hash_hkdf('sha256', $key, 0, 'xiuno-token');
	$iv = substr($raw, 0, 12);
	$tag = substr($raw, -16);
	$ciphertext = substr($raw, 12, -16);
	$plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $derived_key, OPENSSL_RAW_DATA, $iv, $tag);
	if($plaintext === false) return false;
	return $plaintext;
}

// 兼容入口：加密永远用 v2
// 保留原签名 ($txt, $key) 以兼容所有调用方
function xn_encrypt($txt, $key = '') {
	empty($key) AND $key = xn_key();
	return xn_encrypt_v2($key, $txt);
}

// 兼容入口：解密先试 v2，失败回退 XXTEA（迁移期兼容旧令牌）
// 第三个可选参数 &$used_v2：true 表示 v2 解密成功，false 表示 fallback 到 XXTEA
function xn_decrypt($txt, $key = '', &$used_v2 = false) {
	empty($key) AND $key = xn_key();
	// 先尝试 v2（密文为 raw base64，无需 xn_urldecode）
	$v2_result = xn_decrypt_v2($key, $txt);
	if($v2_result !== false) {
		$used_v2 = true;
		return $v2_result;
	}
	// 回退到 XXTEA 旧逻辑（旧令牌经 xn_urlencode 包装，需 xn_urldecode 还原）
	$used_v2 = false;
	$encrypt = base64_decode(xn_urldecode($txt));
	$ret = function_exists('xiuno_decrypt') ? xiuno_decrypt($encrypt, $key) : xxtea_decrypt($encrypt, $key);
	return $ret;
}

if(!function_exists('xxtea_encrypt')) {
	// 兼容 3.0，自定打开
	/*function encrypt($txt, $key = 'abcd9667676effff') {
		return xn_encrypt($txt, $key);
	}
	
	
	function decrypt($txt, $key = 'abcd9667676effff') {
		return xn_decrypt($txt, $key);
	}
	*/
	// ---------------------> encrypt function
	function xxtea_long2str($v, $w) {
		$len = count($v);
		$n = ($len - 1) << 2;
		if ($w) {
			$m = $v[$len - 1];
			if (($m < $n - 3) || ($m > $n)) return FALSE;
			$n = $m;
		}
		$s = array();
		for ($i = 0; $i < $len; $i++) {
			$s[$i] = pack("V", $v[$i]);
		}
		if ($w) {
			return substr(join('', $s), 0, $n);
		}
		else {
			return join('', $s);
		}
	}
	
	function xxtea_str2long($s, $w) {
		$v = unpack("V*", $s. str_repeat("\0", (4 - strlen($s) % 4) & 3));
		$v = array_values($v);
		if ($w) {
			$v[count($v)] = strlen($s);
		}
		return $v;
	}
	
	function xxtea_int32($n) {
		while ($n >= 2147483648) $n -= 4294967296;
		while ($n <= -2147483649) $n += 4294967296;
		return (int)$n;
	}
	
	function xxtea_encrypt($str, $key) {
		if($str == '') return '';
		$v = xxtea_str2long($str, TRUE);
		$k = xxtea_str2long($key, FALSE);
		if (count($k) < 4) {
			for ($i = count($k); $i < 4; $i++) {
				$k[$i] = 0;
			}
		}
		$n = count($v) - 1;
	
		$z = $v[$n];
		$y = $v[0];
		$delta = 0x9E3779B9;
		$q = floor(6 + 52 / ($n + 1));
		$sum = 0;
		while (0 < $q--) {
			$sum = xxtea_int32($sum + $delta);
			$e = $sum >> 2 & 3;
			for ($p = 0; $p < $n; $p++) {
				$y = $v[$p + 1];
				$mx = xxtea_int32((($z >> 5 & 0x07ffffff) ^ $y << 2) + (($y >> 3 & 0x1fffffff) ^ $z << 4)) ^ xxtea_int32(($sum ^ $y) + ($k[$p & 3 ^ $e] ^ $z));
				$z = $v[$p] = xxtea_int32($v[$p] + $mx);
			}
			$y = $v[0];
			$mx = xxtea_int32((($z >> 5 & 0x07ffffff) ^ $y << 2) + (($y >> 3 & 0x1fffffff) ^ $z << 4)) ^ xxtea_int32(($sum ^ $y) + ($k[$p & 3 ^ $e] ^ $z));
			$z = $v[$n] = xxtea_int32($v[$n] + $mx);
		}
		return xxtea_long2str($v, FALSE);
	}
	
	function xxtea_decrypt($str, $key) {
		if($str == '') return '';
		$v = xxtea_str2long($str, FALSE);
		$k = xxtea_str2long($key, FALSE);
		if(count($k) < 4) {
			for ($i = count($k); $i < 4; $i++) {
				$k[$i] = 0;
			}
		}
		$n = count($v) - 1;
	
		$z = $v[$n];
		$y = $v[0];
		$delta = 0x9E3779B9;
		$q = floor(6 + 52 / ($n + 1));
		$sum = xxtea_int32($q * $delta);
		while ($sum != 0) {
			$e = $sum >> 2 & 3;
			for ($p = $n; $p > 0; $p--) {
				$z = $v[$p - 1];
				$mx = xxtea_int32((($z >> 5 & 0x07ffffff) ^ $y << 2) + (($y >> 3 & 0x1fffffff) ^ $z << 4)) ^ xxtea_int32(($sum ^ $y) + ($k[$p & 3 ^ $e] ^ $z));
				$y = $v[$p] = xxtea_int32($v[$p] - $mx);
			}
			$z = $v[$n];
			$mx = xxtea_int32((($z >> 5 & 0x07ffffff) ^ $y << 2) + (($y >> 3 & 0x1fffffff) ^ $z << 4)) ^ xxtea_int32(($sum ^ $y) + ($k[$p & 3 ^ $e] ^ $z));
			$y = $v[0] = xxtea_int32($v[0] - $mx);
			$sum = xxtea_int32($sum - $delta);
		}
		return xxtea_long2str($v, TRUE);
	}
}

?>