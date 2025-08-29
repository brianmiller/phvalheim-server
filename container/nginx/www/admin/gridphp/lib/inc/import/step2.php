<?php
/**
 * PHP Grid Component
 *
 * @author Abu Ghufran <gridphp@gmail.com> - http://www.phpgrid.org
 * @version 2.0.0
 * @license: see license.txt included in package
 */

// for mac/linux/win files newline detection in fgetcsv()
ini_set("auto_detect_line_endings", "1"); 
set_time_limit(0);

$data = array();
$full_data = array();

function check_utf8($data)
{
    if (!mb_check_encoding($data, 'UTF-8'))
	    return utf8_encode($data);
    else
	    return $data;
}

function get_delimiter($str, $checkLines = 2)
{
	$delimiters = array(
	  ',',
	  '\t',
	  ';',
	  '|'
	);

	// set if user-defined
	if (in_array($_POST["delimiter"],$delimiters))
		return $_POST["delimiter"];
	
	$results = array();
	$i = 0;
	$lines = explode("\n",$str);
	 while($i <= $checkLines){
		$line = $lines[$i];
		foreach ($delimiters as $delimiter){
			$regExp = '/['.$delimiter.']/';
			$fields = preg_split($regExp, $line);
			
			if(count($fields) > 1){
				$results[$delimiter] += count($fields);
			} else {
				$results[$delimiter] += 0;
			}
		}
	   $i++;
	}

	$results = array_keys($results, max($results));
	return $results[0];
}

// if file is uploaded
if (!empty($_FILES["csv_file"]["tmp_name"]))
{
	$f = fopen($_FILES["csv_file"]["tmp_name"], "r");
	
	$str = "";
	for($x=0;$x<5;$x++)
		$str .= fgets($f);
	$delim =  get_delimiter($str);

	fseek($f,0);

	$i=0;
	$delim = str_replace("\\t","\t",$delim);
	while( ($line = fgetcsv($f,0,"$delim") ) !== false)
	{
		$line = array_map("check_utf8",$line);

		$i++;
		if ($i <= 5) 
			$data[] = $line;

		$full_data[] = $line;
	}
}
else
{
	$str = $_POST["csv_str"];

	$delim =  get_delimiter($str);
	$delim = str_replace("\\t","\t",$delim);
	
	// split lines excluding inside of double quotes
	$arr = preg_split('/[\r\n]{1,2}(?=(?:[^\"]*\"[^\"]*\")*(?![^\"]*\"))/',$str);

	$i=0;
	foreach($arr as $r)
	{
		// commented trim to include whitespace cell value
		// $r = trim($r);

		if (empty($r)) continue;
		
		$i++;
		if ($i <= 5) 
			$data[] = explode($delim,$r);

		$full_data[] = explode($delim,$r);
	}
}

$header = array_shift($data);

$rows = array();
foreach($this->options["colModel"] as $c)
{
	// remove unwanted field in import mapping
	if (isset($this->options["import"]["hidefields"]))
		if (in_array($c["name"],$this->options["import"]["hidefields"])) 
			continue;
	
	$rows[] = array("field_name" => $c["name"], "title" => $c["title"]);	
}

array_unshift($rows,array("field_name"=>0,"title"=>"--none--"));

$html = "<select class='fields' name='fields[]'>";
foreach($rows as $r)
{
	$html .= "<option {{select_{$r["field_name"]}}} value='{$r["field_name"]}'>{$r["title"]}</option>";
}	
$html .= "</select>";

$selection = array();
foreach($header as $d)
{
	$h = $html;
	$sel = "";
	$field = "";
	$matches = 0;
	foreach($rows as $r)
	{
		$desired_percent = 80;

		// if desired match percent given 70,80,90 etc
		if (isset($this->options["import"]["match"]))
			$desired_percent = intval($this->options["import"]["match"]);				

		similar_text(strtolower($d),strtolower($r["title"]),$percent);
		$match = $percent > $desired_percent;		

		if ($match)
		{
			$sel = "selected";
			$field = $r["field_name"];
			$matches++;
			break;
		}
	}
	$h = str_replace("{{select_$field}}",$sel,$h);
	$selection[] = $h;	
}

// if data header not sent
if ($_POST["first_row_label"] != "1")
{
	array_unshift($data,$header);
	$header = array_fill(0, count($header), 'X.?');
	array_unshift($full_data,$header);
}

// put imported data in session for step3
$_SESSION["import_str"] = serialize($full_data);

// append dropdown for fields
array_unshift($data,$selection);
?>

<!DOCTYPE html>
<html lang="en">
  <head>
		<meta charset="utf-8">
		<title>CSV Import - Step 2</title>
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<meta name="description" content="">
		<meta name="author" content="">
		<link rel="stylesheet" href="//maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css">
		<script src="//code.jquery.com/jquery-1.12.4.min.js"></script>
		<style>
			.table td {
				white-space: nowrap;
				text-overflow: ellipsis;
				max-width: 200px;
				overflow: hidden;
			}
		</style>		
	</head>
	<body style="background:#FCFDFD">
		<div class="container">
		<div class="row" style="padding:10px">
			<legend>CSV Import - Step 2</legend>
			<form method="post">
				<input type="hidden" name="step" value="3">
				<input type="hidden" name="import" value="1">
				<div width="90%" style="overflow:auto; margin-bottom:0px;">
				<?php
				if (empty($data) || empty($data[0]))
				{
					echo "Nothing to import, Recheck data to import and try again.<br><br>";
					$class = "hidden";
				}
				else
				{
				?>
				<table class="table table-striped">
					<caption>Map imported data on fields</caption>
					<thead>
					<tr>
						<?php foreach($header as $v) { ?>
						<th>
							<?php echo $v ?>
							<input type="hidden" name="csv_fields[]" value="<?php echo $v ?>">
						</th>
						<?php } ?>
					</tr>
					</thead>
					<tbody>
					
					<?php foreach($data as $r) { ?>
					<tr>
						<?php foreach($r as $v) { ?>
						<td>
							<?php echo $v ?>
						</td>
						<?php } ?>					
					</tr>
					<?php } ?>					
					</tbody>
				</table>
				<?php 
				} 
				?>
				</div>
				
				<!-- Check -->
				<div style="padding:10px 0px" class="<?php echo $class ?>">
				<?php if ($this->options["import"]["allowreplace"] === true) { ?>
					<div>
					<label style="font-weight:normal;"><input id="append_label" value="1" name="import_mode" type="radio" checked> Append Rows</label>
					</div>
					<div>
					<label style="font-weight:normal;"><input id="append_label" value="2" name="import_mode" type="radio"> Delete & Replace </label>
					</div>
					<div>
					<label style="font-weight:normal;"><input id="append_label" value="3" name="import_mode" type="radio"> Update Rows based on first column </label>
					</div>
				<?php } else { ?>
					<div>Imported rows will be appended</div>
				<?php }  ?>
				</div>
				<a class="btn btn-default" href="?grid_id=<?php echo $this->id ?>&step=1&import=1">Back</a>
				<input type="submit" class="<?php echo $class ?> btn btn-default" value="Next" onclick="if (!check_selection()) return false; this.value = 'Please wait ...'; return true;">
			</form>
		</div>

		</div>
		
		<script>
		// check already selected fields to avoid duplicates on import
		function check_selection(o)
		{
			mapped = 0;
			$('.fields').each(function(i,o){
				if (o.value != 0)
					mapped = 1;
			});
			if (!mapped) 
			{
				alert("Please select field mapping to proceed");
				return false;
			}
			
			flag = 1;
			$('.fields').each(function(i,o){
				$('.fields').not(o).each(function(){
					if ($(this).val() == o.value && o.value != 0)
					{
						alert("'"+ o.options[o.selectedIndex].label + "' is already mapped on other column");
						$(this).val(0);
						$(this).focus();
						flag=0;
						return;
					}
				});
			});
			
			return flag;
		}
		</script>
	</body>
</html>