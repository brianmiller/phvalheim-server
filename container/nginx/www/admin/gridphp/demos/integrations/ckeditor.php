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

// Database config file to be passed in phpgrid constructor
$db_conf = array(
					"type" 		=> PHPGRID_DBTYPE,
					"server" 	=> PHPGRID_DBHOST,
					"user" 		=> PHPGRID_DBUSER,
					"password" 	=> PHPGRID_DBPASS,
					"database" 	=> PHPGRID_DBNAME
				);

$g = new jqgrid($db_conf);

$grid["rowNum"] = 10; // by default 20
$grid["sortname"] = 'id'; // by default sort grid by this field
$grid["sortorder"] = "asc"; // ASC or DESC
$grid["caption"] = "Invoice Data"; // caption of grid
$grid["autowidth"] = true; // expand grid to screen width
$grid["multiselect"] = false; // allow you to multi-select through checkboxes
$grid["add_options"] = array('width'=>'620', 'top'=>'10', 'left'=>'200');
$grid["edit_options"] = array('width'=>'620', 'top'=>'10', 'left'=>'200');
$grid["add_options"]["afterShowForm"] = "function(form){
															$('#tr_note>td:eq(1)').attr('colspan', '3');
															$('#tr_note>td:eq(1)>input').css('width', '95%');
														}";

$grid["edit_options"]["afterShowForm"] = $grid["add_options"]["afterShowForm"];

$g->set_options($grid);

$g->set_actions(array(
						"add"=>true, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"rowactions"=>true, // show/hide row wise edit/del/save option
						"search" => "advance", // show single/multi field search condition (e.g. simple or advance)
						"showhidecolumns" => false
					)
				);

// you can provide custom SQL query to display data
$g->select_command = "SELECT * FROM (SELECT i.id, invdate , c.name,
						i.note, i.total, i.closed FROM invheader i
						INNER JOIN clients c ON c.client_id = i.client_id) o";

// this db table will be used for add,edit,delete
$g->table = "invheader";

$col = array();
$col["title"] = "Id"; // caption of column
$col["name"] = "id"; // grid column name, must be exactly same as returned column-name from sql (tablefield or field-alias)
$col["width"] = "10";
$col["editable"] = true; // this column is not editable
$col["formoptions"] = array("rowpos"=>"1", "colpos"=>"1");
$cols[] = $col;

$col = array();
$col["title"] = "Date";
$col["name"] = "invdate";
$col["width"] = "35";
$col["editable"] = true; // this column is editable
$col["formatter"] = "date"; // format as date
$col["formoptions"] = array("rowpos"=>"1", "colpos"=>"2");
$cols[] = $col;

$col = array();
$col["title"] = "Client";
$col["name"] = "name";
$col["width"] = "30";
$col["editable"] = false; // this column is not editable
$col["search"] = false; // this column is not searchable
$col["formoptions"] = array("rowpos"=>"2", "colpos"=>"1");
$cols[] = $col;

$col = array();
$col["title"] = "Note";
$col["name"] = "note";
$col["sortable"] = false; // this column is not sortable
$col["search"] = false; // this column is not searchable
$col["editable"] = true;
$col["edittype"] = "textarea"; // render as textarea on edit
$col["editoptions"] = array("rows"=>2, "cols"=>50); // with these attributes
$col["formoptions"] = array("rowpos"=>"3", "colpos"=>"1");

// allow html, don't strip tags
$col["formatter"] = "html";

// to display fixed height in listing, comment above formatter and use this
// $col["formatter"] = "function(cellval,options,rowdata){ return '<div style=\"height:100px;overflow:hidden;\">'+cellval+'</div>'; }";
// $col["unformat"] = "function(cellval,options,cell){ return jQuery(cell).children('div').html(); }";
// $col["sanitize"] = false;

// connect ckeditor
$col["editoptions"]["dataInit"] = "function(el){ setTimeout(function(){ link_ckeditor(el); },20); }";
$cols[] = $col;

$col = array();
$col["title"] = "Total";
$col["name"] = "total";
$col["width"] = "30";
$col["editable"] = true;
$col["formoptions"] = array("rowpos"=>"2", "colpos"=>"2");
$col["show"]["edit"]=false;
$col["show"]["add"]=true;
$cols[] = $col;

// pass the cooked columns to grid
$g->set_columns($cols);

// generate grid output, with unique grid name as 'list1'
$out = $g->render("list1");
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

	<script src="//cdnjs.cloudflare.com/ajax/libs/ckeditor/4.10.0/ckeditor.js"></script>

</head>
<body>
	<style>
	/* form element positioning to top */
	.ui-jqgrid tr.jqgrow td
	{
	    white-space: normal !important;
	}
	.ui-jqdialog-content .CaptionTD
	{
		vertical-align: top;
		padding-top: 6px;
	}

	.ui-jqdialog-content .form-view-data
	{
		white-space: normal;
	}
	.jqrow td div
	{
		white-space:inherit;
	}
	</style>
	<div style="margin:10px">
	<?php echo $out?>
	</div>

	<script type="text/javascript">
	function link_ckeditor(el)
	{
		CKEDITOR.config.toolbar = [
			{ name: 'document', groups: [ 'mode', 'document', 'doctools' ], items: [ 'Source', '-', 'Save', 'NewPage', 'Preview', 'Print', '-', 'Templates' ] },
			{ name: 'clipboard', groups: [ 'clipboard', 'undo' ], items: [ 'Cut', 'Copy', 'Paste', 'PasteText', 'PasteFromWord', '-', 'Undo', 'Redo' ] },
			{ name: 'editing', groups: [ 'find', 'selection', 'spellchecker' ], items: [ 'Find', 'Replace', '-', 'SelectAll', '-', 'Scayt' ] },
			{ name: 'forms', items: [ 'Form', 'Checkbox', 'Radio', 'TextField', 'Textarea', 'Select', 'Button', 'ImageButton', 'HiddenField' ] },
			'/',
			{ name: 'basicstyles', groups: [ 'basicstyles', 'cleanup' ], items: [ 'Bold', 'Italic', 'Underline', 'Strike', 'Subscript', 'Superscript', '-', 'CopyFormatting', 'RemoveFormat' ] },
			{ name: 'paragraph', groups: [ 'list', 'indent', 'blocks', 'align', 'bidi' ], items: [ 'NumberedList', 'BulletedList', '-', 'Outdent', 'Indent', '-', 'Blockquote', 'CreateDiv', '-', 'JustifyLeft', 'JustifyCenter', 'JustifyRight', 'JustifyBlock', '-', 'BidiLtr', 'BidiRtl', 'Language' ] },
			{ name: 'links', items: [ 'Link', 'Unlink', 'Anchor' ] },
			{ name: 'insert', items: [ 'Image', 'Flash', 'Table', 'HorizontalRule', 'Smiley', 'SpecialChar', 'PageBreak', 'Iframe' ] },
			'/',
			{ name: 'styles', items: [ 'Styles', 'Format', 'Font', 'FontSize' ] },
			{ name: 'colors', items: [ 'TextColor', 'BGColor' ] },
			{ name: 'tools', items: [ 'Maximize', 'ShowBlocks' ] },
			{ name: 'others', items: [ '-' ] },
			{ name: 'about', items: [ 'About' ] }
		];

		// Limited Set
		// CKEDITOR.config.toolbar = [
		// 	{ name: 'styles', items: [ 'Format' ] },
		// 	{ name: 'clipboard', groups: [ 'clipboard', 'undo' ], items: [ 'Cut', 'Copy', 'Paste'] },
		// 	{ name: 'basicstyles', groups: [ 'basicstyles', 'cleanup' ], items: [ 'Bold', 'Italic', 'Underline'] },
		// 	{ name: 'paragraph', groups: [ 'list', 'indent', 'blocks', 'align', 'bidi' ], items: [ 'NumberedList', 'BulletedList', '-',  'JustifyLeft', 'JustifyCenter', 'JustifyRight', 'JustifyBlock'] },
		// 	{ name: 'links', items: [ 'Link', 'Unlink' ] },
		// 	{ name: 'insert', items: [ 'Image', 'Table'] },
		// ];

		// remove nbsp; from start of textarea
		if(el.previousSibling) el.parentNode.removeChild(el.previousSibling);
		$(el).parent().css('padding-left','6px');

		var editor = CKEDITOR.replace( el, {
			extraPlugins: 'colorbutton,colordialog',
			removeButtons: '',
			on: {
				change: function(){ jQuery(el).val(editor.getData()); }
			}
		});
		
		// unblock typing in ckeditor dialog - events tracked with firebug > script > global pause > stack
		jQuery(document).unbind('keypress').unbind('keydown').unbind('mousedown');
	}
	</script>

</body>
</html>
