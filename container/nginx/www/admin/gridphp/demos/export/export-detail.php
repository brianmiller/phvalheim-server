<?php 
/**
 * PHP Grid Component
 *
 * @author Abu Ghufran <gridphp@gmail.com> - http://www.phpgrid.org
 * @version 2.0.0
 * @license: see license.txt included in package
 */
 
// include db config
include_once("../../config.php");

// include and create object
include(PHPGRID_LIBPATH."inc/jqgrid_dist.php");

// master grid
// Database config file to be passed in phpgrid constructor
$db_conf = array( 	
					"type" 		=> PHPGRID_DBTYPE, 
					"server" 	=> PHPGRID_DBHOST,
					"user" 		=> PHPGRID_DBUSER,
					"password" 	=> PHPGRID_DBPASS,
					"database" 	=> PHPGRID_DBNAME
				);

$grid = new jqgrid($db_conf);

$opt["caption"] = "Clients Data";
$opt["height"] = "150";
$opt["multiselect"] = false;

$opt["detail_grid_id"] = "list2";
$opt["subgridparams"] = "client_id,gender,company";

$opt["export"] = array("filename"=>"my-file", "sheetname"=>"test", "format"=>"pdf");
$opt["export"]["range"] = "filtered";
$opt["export"]["heading"] = "Master Detail PDF";
$opt["export"]["render_type"] = "html";

$grid->set_options($opt);

$grid->set_actions(array(    
                        "add"=>true, // allow/disallow add
                        "edit"=>true, // allow/disallow edit
						"delete"=>false, // allow/disallow delete
                        "export"=>true, // show/hide export to excel option
                    ) 
                );
				
$grid->table = "clients";

$e["on_render_pdf"] = array("set_pdf_format", null);
$grid->set_events($e);

// pdf margins
define("PDF_MARGIN_LEFT",10);
define("PDF_MARGIN_TOP",10);
define("PDF_MARGIN_RIGHT",10);

function set_pdf_format($param)
{
	$grid = $param["grid"];
	$arr = $param["data"];

	$html = "";	
	
	$html = <<<EOF
<!-- EXAMPLE OF CSS STYLE -->
<style>
    td {
        font-size: 12px;
    }
</style>
EOF;

	$html .= "<h1>".$grid->options["export"]["heading"]."</h1>";
	$html .= '<table border="0" cellpadding="4" cellspacing="2">';
	$i = 0;
	foreach($arr as $v)
	{
		$shade = ($i++ % 2) ? 'bgcolor="#efefef"' : 'bgcolor="#cccccc"';
		$html .= '<tr>';
		foreach($v as $k=>$d)
		{
			// bold header
			if  ($i == 1)
			{
				$html .= '<td bgcolor="lightgrey"><strong>'.$d.'</strong></td>';
			}
			else
			{
				if ($k == 'client_id')
				{
					$rowid = $d;
					$html .= '<td class="barcode" '.$shade.'>'.$d.'</td>';
				}
				else
					$html .= '<td '.$shade.'>'.$d.'</td>';
			}
		}
		$html .= '</tr>';
		
		// skip subgrid for header 
		if ($i > 1)
		{
			$sub_arr = $grid->get_all("SELECT id,client_id,invdate,amount,tax,note,total FROM invheader WHERE client_id = $rowid");
			if (count($sub_arr))
			{
				$html .= '<tr>';
				$sub_i = 0;
				$sub_html = '&nbsp;&nbsp;&nbsp;<table width="83%" border="0" cellpadding="4" cellspacing="2">';
				foreach($sub_arr as $sub_v)
				{
					$sub_shade = ($sub_i++ % 2) ? 'bgcolor="#d2d2d2"' : 'bgcolor="#efefef"';
					
					// detail grid header
					if  ($sub_i == 1)
					{
						$sub_html .= '<tr>';	
						foreach($sub_v as $sub_k => $sub_d)
						{
							$sub_html .= '<td bgcolor="lightgrey"><strong>'.ucwords($sub_k).'</strong></td>';
						}
						$sub_html .= '</tr>';	
					}

					// detail grid data
					$sub_html .= '<tr>';	
					foreach($sub_v as $sub_k => $sub_d)
					{
						$sub_html .= '<td '.$sub_shade.'>'.$sub_d.'</td>';
					}
					$sub_html .= '</tr>';
				}
				$sub_html .= '</table>';
				
				$html .= '<td colspan="99"> '.$sub_html.' </td>';
				$html .= '</tr>';
			}
		}
	}
	$html .= '</table>';
	return $html;
}
 
$out_master = $grid->render("list1");

// detail grid
$grid = new jqgrid($db_conf);

$opt = array();	
$opt["datatype"] = "local"; // stop loading detail grid at start
$opt["height"] = ""; // autofit height of subgrid
$opt["caption"] = "Invoice Data"; // caption of grid
$opt["multiselect"] = true; // allow you to multi-select through checkboxes
$opt["reloadedit"] = true; // reload after inline edit
$grid->set_options($opt);

// receive id, selected row of parent grid
$id = intval($_GET["rowid"]);
$company = $_GET["company"];

// and use in sql for filteration
$grid->select_command = "SELECT id,client_id,invdate,amount,tax,note,total,'$company' as 'company' FROM invheader WHERE client_id = $id";

// this db table will be used for add,edit,delete
$grid->table = "invheader";

$out_detail = $grid->render("list2");
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">
<html>
<head>
	<link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/themes/redmond/jquery-ui.custom.css"></link>	
    <link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/jqgrid/css/ui.jqgrid.css"></link>    
    
    <script src="../../lib/js/jquery.min.js" type="text/javascript"></script>
	<script src="../../lib/js/jqgrid/js/i18n/grid.locale-en.js" type="text/javascript"></script>
    <script src="../../lib/js/jqgrid/js/jquery.jqGrid.min.js" type="text/javascript"></script>    
    <script src="../../lib/js/themes/jquery-ui.custom.min.js" type="text/javascript"></script>
</head>
<body>
    <div style="margin:10px">
    Master Detail Grid, on same page with PDF exporting detail grid
    <br>
    <br>
    <?php echo $out_master ?>
    <br>
    <?php echo $out_detail; ?>
    </div>
</body>
</html> 