<?php
/**
 * Query PHP-FPM pm.status_path over FastCGI on 127.0.0.1:9000.
 * Run inside the php / php-admin container:
 *   docker compose exec -T php php - < scripts/fpm-status.php
 * Prints: active=N idle=N listen_queue=N max_active=N slow=N
 */
declare(strict_types=1);

function fcgi_header(int $type, int $len, int $pad = 0): string {
	return pack('CCnnCC', 1, $type, 1, $len, $pad, 0);
}

function fcgi_record(int $type, string $body): string {
	$len = strlen($body);
	$pad = (8 - ($len % 8)) % 8;
	return fcgi_header($type, $len, $pad) . $body . ($pad ? str_repeat("\0", $pad) : '');
}

function fcgi_nv(string $name, string $value): string {
	$n = strlen($name);
	$v = strlen($value);
	$h  = $n < 128 ? chr($n) : pack('N', $n | 0x80000000);
	$h .= $v < 128 ? chr($v) : pack('N', $v | 0x80000000);
	return $h . $name . $value;
}

$params = [
	'SCRIPT_NAME'        => '/-/fpm-status',
	'SCRIPT_FILENAME'    => '/-/fpm-status',
	'REQUEST_METHOD'     => 'GET',
	'QUERY_STRING'       => 'json',
	'REQUEST_URI'        => '/-/fpm-status?json',
	'DOCUMENT_URI'       => '/-/fpm-status',
	'GATEWAY_INTERFACE'  => 'CGI/1.1',
	'SERVER_SOFTWARE'    => 'fpm-status.php',
	'SERVER_NAME'        => 'localhost',
	'REMOTE_ADDR'        => '127.0.0.1',
	'SERVER_PROTOCOL'    => 'HTTP/1.1',
];

$param_body = '';
foreach ($params as $k => $v) {
	$param_body .= fcgi_nv($k, $v);
}

$sock = @fsockopen('127.0.0.1', 9000, $errno, $errstr, 2.0);
if ($sock === false) {
	fwrite(STDERR, "fcgi connect failed: $errstr ($errno)\n");
	echo "active=-1 idle=-1 listen_queue=-1 max_active=-1 slow=-1\n";
	exit(1);
}
stream_set_timeout($sock, 2);

fwrite($sock, fcgi_record(1, pack('nC', 1, 0) . str_repeat("\0", 5))); // BEGIN_REQUEST responder
fwrite($sock, fcgi_record(4, $param_body));
fwrite($sock, fcgi_record(4, ''));
fwrite($sock, fcgi_record(5, ''));

$stdout = '';
while (!feof($sock)) {
	$hdr = fread($sock, 8);
	if ($hdr === false || strlen($hdr) < 8) {
		break;
	}
	$h = unpack('Cver/Ctype/nreqid/nlen/Cpad/Creserved', $hdr);
	$data = $h['len'] > 0 ? fread($sock, $h['len']) : '';
	if ($h['pad'] > 0) {
		fread($sock, $h['pad']);
	}
	if ($h['type'] === 6) { // STDOUT
		$stdout .= $data;
	}
	if ($h['type'] === 3) { // END_REQUEST
		break;
	}
}
fclose($sock);

$json_start = strpos($stdout, '{');
$decoded    = $json_start === false ? null : json_decode(substr($stdout, $json_start), true);

if (!is_array($decoded)) {
	echo "active=-1 idle=-1 listen_queue=-1 max_active=-1 slow=-1\n";
	exit(1);
}

printf(
	"active=%d idle=%d listen_queue=%d max_active=%d slow=%d\n",
	(int) ($decoded['active processes'] ?? -1),
	(int) ($decoded['idle processes'] ?? -1),
	(int) ($decoded['listen queue'] ?? -1),
	(int) ($decoded['max active processes'] ?? -1),
	(int) ($decoded['slow requests'] ?? -1)
);
