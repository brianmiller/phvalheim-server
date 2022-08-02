<?php
include "includes/config.php";


#$1=prefix. E.g., "2.2.20.0/24"
function netbox_getPrefixID($NETBOX_PREFIX,$NETBOX_TOKEN,$NETBOX_URL) {
	return exec("curl -s -k -H \"Authorization: Token $NETBOX_TOKEN\" -H \"Accept: application/json; indent=4\" \"$NETBOX_URL/api/ipam/prefixes/?q=$NETBOX_PREFIX\"|jq '.results[].id'");
}


function netbox_getIPID($NETBOX_IP,$NETBOX_TOKEN,$NETBOX_URL) {
        return exec("curl -s -k -H \"Authorization: Token $NETBOX_TOKEN\" -H \"Accept: application/json; indent=4\" \"$NETBOX_URL/api/ipam/ip-addresses/?q=$NETBOX_IP\"|jq '.results[].id'");
}


function netbox_GetNextAvailableIP($netbox_getPrefixID,$NETBOX_TOKEN,$NETBOX_URL,$NETBOX_IP_EXCLUDES) {
	$output = shell_exec("curl -k -H \"Authorization: Token $NETBOX_TOKEN\" -H \"Accept: application/json; indent=4\" \"$NETBOX_URL/api/ipam/prefixes/$netbox_getPrefixID/available-ips/?limit=10000000\"|jq 'to_entries[].value.address'");
	$output = explode("\n",$output);
	
	#Exclude IPs listed in config.php
	foreach($NETBOX_IP_EXCLUDES as $NETBOX_IP_EXCLUDE){
		$key_to_exclude = array_search("\"$NETBOX_IP_EXCLUDE\"",$output);
		if(!empty($key_to_exclude)){
			unset($output[$key_to_exclude]);
		}
	}

	$nextIP = trim($output[0],'"');
	#$nextIP = substr($nextIP, 0, strpos($nextIP, "/"));

	return $nextIP;
}


function netbox_ReserveIP($ip,$world_name,$NETBOX_TOKEN,$NETBOX_URL){
	return shell_exec("curl -s -k -X POST -H \"Authorization: Token $NETBOX_TOKEN\" -H \"Content-Type: application/json\" $NETBOX_URL/api/ipam/ip-addresses/ --data \"{\\\"address\\\": \\\"$ip\\\", \\\"dns_name\\\": \\\"$world_name\\\", \\\"status\\\": \\\"active\\\", \\\"custom_fields\\\": {\\\"publish_dns_location\\\": [\\\"Internal\\\"], \\\"publish_dns_ptr_zone\\\": \\\"phospher.com\\\", \\\"publish_dns_ttl\\\": \\\"86400\\\", \\\"publish_dns_zones\\\": [\\\"phospher.com\\\"], \\\"publish_external_cname_destination\\\": \\\"37648.phospher.com\\\", \\\"publish_external_cname_zone\\\": \\\"phospher.com\\\"}}\"|jq '.'");
}


function netbox_DeleteIP($ipID,$NETBOX_TOKEN,$NETBOX_URL){
	return exec("curl -s -k -X DELETE -H \"Authorization: Token $NETBOX_TOKEN\" -H \"Accept: application/json; indent=4\" $NETBOX_URL/api/ipam/ip-addresses/$ipID/");
}
?>
