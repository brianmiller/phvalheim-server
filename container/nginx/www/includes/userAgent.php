<?php
$userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';

if($userAgent) {
	if (preg_match('/linux/i', $userAgent)) {
		$operatingSystem = 'Linux';
	} elseif (preg_match('/macintosh|mac os x|mac_powerpc/i', $userAgent)) {
		$operatingSystem = 'Mac';
	} elseif (preg_match('/windows|win32|win98|win95|win16/i', $userAgent)) {
		$operatingSystem = 'Windows';
	}
}

?>
