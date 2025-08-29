<!DOCTYPE html>
<html lang="en">
<body style="background:#FCFDFD">
<link rel="stylesheet" href="//maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css">
<div class="progress">
  <div class="progress-bar" id="progressbar" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
</div>
<?php
/**
 * PHP Grid Component
 *
 * @author Abu Ghufran <gridphp@gmail.com> - http://www.phpgrid.org
 * @version 2.0.0
 * @license: see license.txt included in package
 */
set_time_limit(0);

extract($_POST);

$c = 0;
$insert_fields = array();
$skip_fields = array();
$null_fields = array();
$errors = array();

foreach($fields as $k=>$v)
{
	if ($v == "0") 
	{
		$skip_fields[] = $k;
		continue;
	}
	$insert_fields[$k] = $v;

	if ($this->get_column($v,"isnull") == true)
		$null_fields[] = $k;
}

$_SESSION["fields_map"] = $insert_fields;
$data = unserialize($_SESSION["import_str"]);
// remove header
array_shift($data);

// if update existing rows based on first column
if ($_POST["import_mode"] == "3")
{
	foreach($data as $d)
	{
		foreach($skip_fields as $v)
			unset($d[$v]);

		$update_str = array();
		$val_arr = array();
		foreach($d as $k=>$v)
		{
			if ($k == 0) continue;
			$update_str[] = $insert_fields[$k]." = ?";
			// trim in case of quoted text from excel
			$val_arr[] = trim($v,'"'); 
		}
		
		$update_str = implode(",",$update_str);
		$where_str = "$insert_fields[0] = ?";
		$val_arr[] = $d[0];
		
		$sql = "UPDATE ".$this->table." SET $update_str WHERE $where_str";
		$res = $this->con->execute($sql,$val_arr);

		if (!$res) $errors[] = $this->con->errormsg();
		if ($res) $c++;
	}
}
else
{
	// delete and replace mode (otherwise append)
	if ($_POST["import_mode"] == "2")
	{
		$sql = "DELETE FROM $this->table";
		$res = $this->con->execute($sql);
	}
	
	$count=0;
	foreach($data as $d)
	{
		foreach($skip_fields as $v)
			unset($d[$v]);
	
		foreach($null_fields as $v)
			if(empty($d[$v]))
			{
				$d[$v] = null;
			}
	
		// fill blank for empty cells but with headers
		$left = count($insert_fields)-count($d);
		for($i=0; $i<$left; $i++)
			$d[] = "";
		
		// remove enclosed "" or ''
		foreach($d as &$v)
		{
			if ($v == null) continue;
			
			$v = preg_replace("/\'(.*)\'/","\\1",$v);
			$v = preg_replace("/\"(.*)\"/","\\1",$v);
			$v = trim($v);
		}
	
		$event_name = "on_import";
		if (empty($this->events[$event_name]))
			$event_name = "on_insert";
	
		// perform on_insert for import function
		$do_insert = true;
		if (!empty($this->events[$event_name]))
		{
			// map key/value array
			foreach($insert_fields as $i=>$val)
				$row[$insert_fields[$i]] = $d[$i];
	
			$func = $this->events[$event_name][0];
			$obj = $this->events[$event_name][1];
			$continue = $this->events[$event_name][2];
	
			if ($obj)
				call_user_func(array($obj,$func),array("from"=>"import","params" => &$row, "msg" => &$custom_msg));
			else
				call_user_func($func,array("from"=>"import","params" => &$row, "msg" => &$custom_msg));
	
			$count++;
			
			if ($continue)
			{
				$_fields = array_keys($row);
				$_data = array_values($row);
				$_ques = array_fill(0,count($insert_fields),"?");
	
				$sql = "INSERT INTO ".$this->table." (".implode(",",$_fields).") VALUES (".implode(",",$_ques).")";
				$res = $this->con->execute($sql,$_data);
	
				if (!$res) $errors[] = $this->con->errormsg();
			}
	
			$do_insert = false;
		}
		
		if ($do_insert)
		{
			$q = array_fill(0,count($insert_fields),"?");
	
			$sql = "INSERT INTO ".$this->table." (".implode(",",$insert_fields).") VALUES (".implode(",",$q).")";
			$res = $this->con->execute($sql,$d);
	
			if (!$res) $errors[] = $this->con->errormsg();
			if ($res) $count++;
		}
		
		// progress bar for importing sql
		$percent = ceil(($count / count($data)) * 100);
		echo "<script>
				document.getElementById('progressbar').setAttribute('aria-valuenow',$percent);
				document.getElementById('progressbar').style.width = '$percent%';
				document.getElementById('progressbar').innerHTML = '$percent%';
			 </script>";
		flush();
	}
}


if (empty($custom_msg))
{
	if ($count == 0)
	{
		$msg = "Nothing imported. Please recheck the data and try again.";
		if ($this->debug_sql && count($errors))
			$msg .= "<h5>Debug Log:</h5> <li>".implode("<li>",$errors); 
	}
	else
		$msg = "$count rows imported successfully!";
}
else
{
	$msg = $custom_msg;
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
		<meta charset="utf-8">
		<title>CSV Import - Finished</title>
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<meta name="description" content="">
		<meta name="author" content="">
		<link rel="stylesheet" href="//maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css">
		<script src="//code.jquery.com/jquery-1.12.4.min.js"></script>
	</head>
	
	<body style="background:#FCFDFD">
		<div class="container">
		<div class="row" style="padding:10px">
			<legend>CSV Import - Finished</legend>

			<div class="well"><?php echo $msg?></div>
			<input type="button" class="btn btn-default" value="Close" onclick="closeIt();">
			<script>
			function closeIt()
			{ 
				$('.ui-dialog-titlebar-close',window.parent.document).click();
			}
			</script>
		</div>
		</div>
	</body>
</html>