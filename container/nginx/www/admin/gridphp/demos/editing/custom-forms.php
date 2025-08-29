<?php
/**
 * PHP Grid Component
 *
 * @author Abu Ghufran <gridphp@gmail.com> - http://www.phpgrid.org
 * @version 2.0.0
 * @license: see license.txt included in package
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

$grid["caption"] = "Sample Grid";
$grid["autowidth"] = true;

// fill external form on select row
$grid["onSelectRow"] = "function(){ load_form(); }";

$g->set_options($grid);
$g->table = "clients";

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
</head>
<body>
	<style>
	body { font-family: arial; }
	</style>
	
	<div style="float:left; width:70%">
	<?php echo $out?>
	</div>
	<div style="float:left; width:25%; padding: 0 10px;">

		<form method="post" id="client_form_add" action="" title='' style="margin:0px;"> <fieldset> 
		<legend>Custom Add Form</legend> 
			<table> 
			<tbody> 
			<tr> 
				<td> Name:</td> 
				<td><input type="text" name="name" /></td> 
			</tr>  
			<tr> 
				<td> Gender:</td> 
				<td><input type="text" name="gender" /></td> 
			</tr>    
			<tr> 
				<td> Company:</td> 
				<td><input type="text" name="company" /></td> 
			</tr>  
			<tr> 
				<td>&nbsp;</td> 
				<td><input type="button" id="insertdata" value="Add" /></td> 
			</tr> 
			</tbody> 
			</table> 
		</fieldset> 
		</form>	
		
		<br>
		
		<form method="post" id="client_form" action="" title='' style="margin:0px;"> <fieldset> 
		<legend>Custom Edit Form</legend> 
			<table class="edit-form"> 
			<tbody> 
			<tr> 
				<td> Client Id:</td> 
				<td><input type="text" name="client_id" readonly=true id="client_id"/></td> 
			</tr> 
			<tr> 
				<td> Name:</td> 
				<td><input type="text" name="name" /></td> 
			</tr> 
			<tr> 
				<td> Gender:</td> 
				<td><input type="text" name="gender" /></td> 
			</tr>  
			<tr> 
				<td> Company:</td> 
				<td><input type="text" name="company" /></td> 
			</tr>  
			<tr> 
				<td>&nbsp;</td> 
				<td><input type="button" id="savedata" value="Save" /></td> 
			</tr> 
			</tbody> 
			</table> 
		</fieldset> 
		</form>	
		
	</div>

	<script src="//cdnjs.cloudflare.com/ajax/libs/jquery.mask/0.9.0/jquery.mask.min.js"></script>
		
	<script type="text/javascript">
	// load grid row in form
	var load_form = function ()
					{ 
						var row = jQuery("#list1").jqGrid('getGridParam','selrow'); 
						if(row)
						{ 
							jQuery("#list1").jqGrid('GridToForm',row,"#client_form"); 
	
							if ($(".edit-form input[name=gender]").val() == "male")
								$(".edit-form input[name=gender]").css({'background-color':'lightgreen'});
						
							if ($(".edit-form input[name=gender]").val() == "female")
								$(".edit-form input[name=gender]").css({'background-color':'lightyellow'});
							
							// jQuery(".edit-form input[name=company]").mask("000.00"); 
						} 
						else 
						{ 
							alert("Please select Row") 
						} 
					} 
	
	// save form data to database using grid api
	jQuery("#savedata").click(function() 
							{ 
								var id = jQuery("#client_id").val(); 
								if(id) 
								{ 
									var grid = jQuery("#list1");
									
									grid.jqGrid('FormToGrid',id,"#client_form"); 
									
									// call ajax to update date in db
									var request = '';
									request = $('#client_form').serialize();
									request += '&oper=edit&id='+id;
									
									jQuery.ajax({
										url: grid.jqGrid('getGridParam','url'),
										dataType: 'html',
										data: request,
										type: 'POST',
										error: function(res, status) {
											jQuery.jgrid.info_dialog(jQuery.jgrid.errors.errcap,'<div class=\"ui-state-error\">'+ res.responseText +'</div>', 
													jQuery.jgrid.edit.bClose,{buttonalign:'right'});
										},
										success: function( data ) {
											// reload grid for data changes
											grid.jqGrid().trigger('reloadGrid',[{jqgrid_page:1}]);
										}
									});
									
									
								} 
							});
								
	// save form data to database using grid api
	jQuery("#insertdata").click(function() 
							{ 
								var grid = jQuery("#list1");
								
								// call ajax to update date in db
								var request = '';
								request = $('#client_form_add').serialize();
								request += '&oper=add';
								
								jQuery.ajax({
									url: grid.jqGrid('getGridParam','url'),
									dataType: 'html',
									data: request,
									type: 'POST',
									error: function(res, status) {
										jQuery.jgrid.info_dialog(jQuery.jgrid.errors.errcap,'<div class=\"ui-state-error\">'+ res.responseText +'</div>', 
												jQuery.jgrid.edit.bClose,{buttonalign:'right'});
									},
									success: function( data ) {
										// reload grid for data changes
										grid.jqGrid().trigger('reloadGrid',[{jqgrid_page:1}]);
									}
								});
							});
							
	</script>
	
</body>
</html>
