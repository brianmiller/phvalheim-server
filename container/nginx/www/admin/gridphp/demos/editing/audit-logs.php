<?php
/**
 * PHP Grid Component
 *
 * @author Abu Ghufran <gridphp@gmail.com> - http://www.phpgrid.org
 * @version 2.0.0
 * @license: see license.txt included in package
 */

/*
-- mysql version
CREATE TABLE `audit_logs` (
	`id` INT(11) NOT NULL AUTO_INCREMENT,
	`user_id` VARCHAR(255) NULL DEFAULT NULL,
	`event` VARCHAR(255) NULL DEFAULT NULL COLLATE 'utf8_general_ci',
	`log_message` TEXT NULL DEFAULT NULL COLLATE 'utf8_general_ci',
	`log_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	`last_value` TEXT NOT NULL COLLATE 'utf8_general_ci',
	`new_value` TEXT NOT NULL COLLATE 'utf8_general_ci',
	`user_agent` TEXT NOT NULL COLLATE 'utf8_general_ci',
	`ip_address` VARCHAR(50) NOT NULL DEFAULT '' COLLATE 'utf8_general_ci',
	PRIMARY KEY (`id`) USING BTREE
)
COLLATE='utf8_general_ci'
ENGINE=InnoDB
AUTO_INCREMENT=1;

-- postgres version
CREATE TABLE audit_logs (
	id SERIAL PRIMARY KEY,
	user_id VARCHAR(255) NULL,
	event VARCHAR(255) NULL,
	log_message TEXT NULL,
	log_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, 
	last_value TEXT NOT NULL, 
	new_value TEXT NOT NULL, 
	user_agent TEXT NOT NULL, 
	ip_address VARCHAR(50) NOT NULL DEFAULT '' );
*/
include_once("../../config.php");

include(PHPGRID_LIBPATH."inc/jqgrid_dist.php");

// Database config file to be passed in phpgrid constructor
$db_conf = array(
					"type" 		=> PHPGRID_DBTYPE,
					"server" 	=> PHPGRID_DBHOST,
					"user" 		=> PHPGRID_DBUSER,
					"password" 	=> PHPGRID_DBPASS,
					"database" 	=> PHPGRID_DBNAME
				);

$g = new jqgrid($db_conf);

$opt["caption"] = "Todo List";
$opt["multiselect"] = true;
$g->set_options($opt);
$g->table = "todos";

$e["on_after_insert"] = array("log_data", null, true);
$e["on_after_update"] = array("log_data", null, true);
$e["on_after_delete"] = array("log_data", null, true);
$g->set_events($e);

$out = $g->render("list1");

function log_data($data)
{
	global $g;

	$oper = $_POST["oper"];
	
	// field OR row - per field entry or row entry
	$log_edit = "field"; 

	if ($oper == "add") $oper_txt = "added";
	else if ($oper == "edit") $oper_txt = "updated";
	else if ($oper == "del") $oper_txt = "deleted";

	$event = $g->table . ".". $oper_txt;

	// logs events base details
	$sql = "INSERT INTO audit_logs (user_id,event,log_message,last_value,new_value,user_agent,ip_address) VALUES (?,?,?,?,?,?,?)";
	$vals = array();
	$vals[] = null; // $_SESSION["user_id"]
	$vals[] = $event;
	$vals[] = ""; // for msg
	$vals[] = ""; // for last value
	$vals[] = ""; // for new value
	$vals[] = $_SERVER["HTTP_USER_AGENT"];
	$vals[] = (!empty($_SERVER["HTTP_X_FORWARDED_FOR"]) ? $_SERVER["HTTP_X_FORWARDED_FOR"] : $_SERVER["REMOTE_ADDR"]);

	$change_str = "";
	$last_value = "";
	$new_value = "";

	// for update
	if ($oper == "edit")
	{
		// changelog track
		$changes = array();

		// if not bulk update
		if (count($data["old"]) == 1)
			$data["old"] = $data["old"][0];

		$first = true;
		$id_str = "";

		if ($log_edit == "field")
		{
			foreach ($data["params"] as $k=>$v)
			{
				// always log first key
				if ($first) { $first = false; $id_str = "$k: $v"; }
				
				foreach ($data["old"] as $ok => $ov)
				{
					if ($k == $ok && $v != $ov)
					{
						$ov	= empty($ov) ? "(blank)" : $ov;
						$v	= empty($v) ? "(blank)" : $v;
						
						// fill event details
						$vals[2] = "$id_str, field: ".$k;
						$vals[3] = $ov;
						$vals[4] = $v;
	
						$g->execute_query($sql,$vals);
					}
				}
			}
		}
		else
		{
			foreach ($data["params"] as $k=>$v)
			{
				// always log first key
				if ($first) { $first = false; $changes[] = "$k: $v"; }
				
				foreach ($data["old"] as $ok => $ov)
					if ($k == $ok && $v != $ov)
					{
						$ov	= empty($ov) ? "(blank)" : $ov;
						$changes[] = "$k: $ov -> $v";
					}
			}
			if (!empty($changes))
				$change_str = implode(", ",$changes);
	
			$last_value = json_encode($data["old"]);
			$new_value = json_encode($data["params"]);

			// fill event details
			$vals[2] = $change_str;
			$vals[3] = $last_value;
			$vals[4] = $new_value;

			$g->execute_query($sql,$vals);
		}
	}
	// for add or del
	else if ($oper == "add" || $oper == "del")
	{
		// in case of add, enclose in array to have same logic
		if (!isset($data["params"][0])) 
		{
			$arr = array();
			$arr[] = $data["params"];
			$data["params"] = $arr;
		}
		
		// loop to check multi-select delete
		foreach($data["params"] as $d)
		{
			// changelog track
			$changes = array();
			$change_str = "";

			foreach ($d as $k=>$v)
				$changes[] = "$k: $v";

			if (!empty($changes))
				$change_str .= implode(", ",$changes)."\n";

			if ($oper == "add")
				$new_value = json_encode($d);
			elseif ($oper == "del")
				$last_value = json_encode($d);
	
			$msg = $change_str;
	
			// fill event details
			$vals[2] = $msg;
			$vals[3] = $last_value;
			$vals[4] = $new_value;
		
			$g->execute_query($sql,$vals);
		}
	}
}

### HISTORY ###

$grid_hist = new jqgrid($db_conf);

$opt = array();
$opt["caption"] = "Audit Logs";
$opt["readonly"] = true;
$opt["rowNum"] = "50";
$opt["sortname"] = "log_date";
$opt["sortorder"] = "desc, id desc";
$grid_hist->set_options($opt);

$grid_hist->table = "audit_logs";
$grid_hist->select_command = "SELECT id, event, log_message, last_value, new_value, date_format(log_date,'%b %e, %Y %H:%i (%a)') as log_date FROM audit_logs";

$cols = array();

$col = array();
$col["title"] = "Details";
$col["name"] = "log_message";
$col["width"] = "400";
$cols[] = $col;

$grid_hist->set_columns($cols,true);

$out_grid_history = $grid_hist->render("list_history");

?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">
<html>
<head>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/themes/redmond/jquery-ui.custom.css" />
	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/jqgrid/css/ui.jqgrid.css" />

	<script src="../../lib/js/jquery.min.js" type="text/javascript"></script>
	<script src="../../lib/js/jqgrid/js/i18n/grid.locale-en.js" type="text/javascript"></script>
	<script src="../../lib/js/jqgrid/js/jquery.jqGrid.min.js" type="text/javascript"></script>
	<script src="../../lib/js/themes/jquery-ui.custom.min.js" type="text/javascript"></script>

	<!-- Add fancyBox main JS and CSS files -->
	<link type="text/css" rel="stylesheet" href="../../lib/js/integration/fancybox/jquery.fancybox.css" />
	<script type="text/javascript" src="../../lib/js/integration/fancybox/jquery.fancybox.js"></script>

</head>
<body>
	<script>
	jQuery("document").ready(function(){

		setTimeout(()=>{
			// custom button task log grid
			jQuery('#list1').jqGrid('navButtonAdd', '#list1_pager',
			{
				'caption'      : 'Logs',
				'buttonicon'   : 'ui-icon-extlink',
				'onClickButton': function()
				{
					let grid = jQuery("#list_history");

					// f = {groupOp:"AND",rules:[]};
					// f.rules.push({field:"event",op:"cn",data:"todos"});
					// grid[0].p.search = true;
					// jQuery.extend(grid[0].p.postData,{filters:JSON.stringify(f)});					

					grid.trigger("reloadGrid",[{page:1}]);
					jQuery.fancybox.open({href: "#div_history", width:'80%'});

				},
				'position': 'last'
			});
		},200);
		
	});
	</script>	
	<div>
	<?php echo $out?>
	</div>
	<div id="div_history" style="display:none">
	<?php echo $out_grid_history?>
	</div>
</body>
</html>
