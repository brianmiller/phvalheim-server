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
include_once(PHPGRID_LIBPATH."inc/jqgrid_dist.php");

// Database config file to be passed in phpgrid constructor
$db_conf = array( 	
					"type" 		=> PHPGRID_DBTYPE, 
					"server" 	=> PHPGRID_DBHOST,
					"user" 		=> PHPGRID_DBUSER,
					"password" 	=> PHPGRID_DBPASS,
					"database" 	=> PHPGRID_DBNAME
				);
				
// first grid
$grid = new jqgrid($db_conf);
$opt = array();
$opt["caption"] = "Clients Data";
$grid->set_options($opt);
$grid->table = "clients";
// generate grid output, with unique grid name as 'list1'
$out_master = $grid->render("list1");

// second grid
$grid = new jqgrid($db_conf);

$opt = array();
$opt["sortname"] = 'id'; // by default sort grid by this field
$opt["sortorder"] = "desc"; // ASC or DESC
$opt["height"] = ""; // autofit height of subgrid
$opt["caption"] = "Invoice Data"; // caption of grid
$opt["multiselect"] = true; // allow you to multi-select through checkboxes
$opt["export"] = array("filename"=>"my-file", "sheetname"=>"test"); // export to excel parameters
$grid->set_options($opt);

$grid->set_actions(array(	
						"add"=>true, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"rowactions"=>true, // show/hide row wise edit/del/save option
						"export"=>true, // show/hide export to excel option
						"autofilter" => true, // show/hide autofilter for search
						"search" => "advance" // show single/multi field search condition (e.g. simple or advance)
					) 
				);

// this db table will be used for add,edit,delete
$grid->table = "invheader";
// generate grid output, with unique grid name as 'list2'
$out_detail = $grid->render("list2");


// third grid
$grid = new jqgrid($db_conf);
$opt = array();
$opt["caption"] = "Customers";
$opt["scroll"] = true;
$grid->set_options($opt);
$grid->table = "customers";
// generate grid output, with unique grid name as 'list3'
$out_third = $grid->render("list3");

// forth second grid
$grid = new jqgrid($db_conf);
$opt = array();
$opt["caption"] = "Clients";
$grid->set_options($opt);
$grid->table = "clients";
// generate grid output, with unique grid name as 'list4'
$out_forth = $grid->render("list4");
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
	<div id="tabs">
		<ul>
			<li><a href="#tabs-1">Grid Primary</a></li>
			<li><a href="#tabs-2" onclick="jQuery('#gview_list2 .ui-jqgrid-titlebar-close span.ui-icon-circle-triangle-s').click();">Grid Secondary</a></li>
			<li><a href="#tabs-3">Another Grid</a></li>
		</ul>
		<div id="tabs-1">

			<div class="grid_groups group1">
				<ol>
					<li class="placeholder">Drag a column header here to group by that column</li>
				</ol>
			</div>

			<p>Proin elit arcu, rutrum commodo, vehicula tempus, commodo a, risus. Curabitur nec arcu. Donec sollicitudin mi sit amet mauris. Nam elementum quam ullamcorper ante. Etiam aliquet massa et lorem. Mauris dapibus lacus auctor risus. Aenean tempor ullamcorper leo. Vivamus sed magna quis ligula eleifend adipiscing. Duis orci. Aliquam sodales tortor vitae ipsum. Aliquam nulla. Duis aliquam molestie erat. Ut et mauris vel pede varius sollicitudin. Sed ut dolor nec orci tincidunt interdum. Phasellus ipsum. Nunc tristique tempus lectus.</p>
			<?php echo $out_master ?>
		</div>
		<div id="tabs-2">

			<div class="grid_groups group2">
				<ol>
					<li class="placeholder">Drag a column header here to group by that column</li>
				</ol>
			</div>

			<p>Morbi tincidunt, dui sit amet facilisis feugiat, odio metus gravida ante, ut pharetra massa metus id nunc. Duis scelerisque molestie turpis. Sed fringilla, massa eget luctus malesuada, metus eros molestie lectus, ut tempus eros massa ut dolor. Aenean aliquet fringilla sem. Suspendisse sed ligula in ligula suscipit aliquam. Praesent in eros vestibulum mi adipiscing adipiscing. Morbi facilisis. Curabitur ornare consequat nunc. Aenean vel metus. Ut posuere viverra nulla. Aliquam erat volutpat. Pellentesque convallis. Maecenas feugiat, tellus pellentesque pretium posuere, felis lorem euismod felis, eu ornare leo nisi vel felis. Mauris consectetur tortor et purus.</p>
			<?php echo $out_detail?>
		</div>

		<div id="tabs-3">

			<div class="grid_groups group3">
				<ol>
					<li class="placeholder">Drag a column header here to group by that column</li>
				</ol>
			</div>

			<p>Mauris eleifend est et turpis. Duis id erat. Suspendisse potenti. Aliquam vulputate, pede vel vehicula accumsan, mi neque rutrum erat, eu congue orci lorem eget lorem. Vestibulum non ante. Class aptent taciti sociosqu ad litora torquent per conubia nostra, per inceptos himenaeos. Fusce sodales. Quisque eu urna vel enim commodo pellentesque. Praesent eu risus hendrerit ligula tempus pretium. Curabitur lorem enim, pretium nec, feugiat nec, luctus a, lacus.</p>
			<p>Duis cursus. Maecenas ligula eros, blandit nec, pharetra at, semper at, magna. Nullam ac lacus. Nulla facilisi. Praesent viverra justo vitae neque. Praesent blandit adipiscing velit. Suspendisse potenti. Donec mattis, pede vel pharetra blandit, magna ligula faucibus eros, id euismod lacus dolor eget odio. Nam scelerisque. Donec non libero sed nulla mattis commodo. Ut sagittis. Donec nisi lectus, feugiat porttitor, tempor ac, tempor vitae, pede. Aenean vehicula velit eu tellus interdum rutrum. Maecenas commodo. Pellentesque nec elit. Fusce in lacus. Vivamus a libero vitae lectus hendrerit hendrerit.</p>
				
				<div style="margin:0px; display:flex;">
					<div style="width:48%;">
						<?php echo $out_third; ?>
					</div>	
					<div style="width:48%; margin-left:40px;">
						<?php echo $out_forth; ?>
					</div>
				</div>
		</div>
	</div>

	<script>
	jQuery(function() {
		jQuery("#tabs").tabs();
	});
	</script>

	<style type="text/css">
	.grid_groups ol {
		padding: 7px 0;
		margin: 10px 0 5px 0;
		box-shadow: 0px 0px 2px #ccc;
	}
	.grid_groups .ui-sortable li {
		cursor: hand;
		cursor: pointer;
	}		
	
	.grid_groups li, .dragable { 
		background-color: #f4f4f4;
		display: inline-block;
		padding:5px 15px;
		margin-left:10px;
		font-family: tahoma,verdana,"sans serif";
		font-size: 13px;		
	}
	.grid_groups .ui-icon.ui-icon-close{
		display: inline-block;
		top: 1px;
		position: relative;
		left: -3px;
		cursor: pointer;
		font-size: 12px;		
	}
	</style>	
	<script type="text/javascript">
	jQuery(window).load(function($){ 
		var customFormatDisplayField = function (displayValue, value, colModel, index, grp) {

        	// show label instead of ids for select formatter
        	if (colModel.formatter == 'select')
				displayValue = jQuery.fn.fmatter.select(displayValue,{'colModel':colModel});

			return displayValue;
		},
		generateGroupingOptions = function (groupingCount) {
			var i, arr = [];
			for (i = 0; i < groupingCount; i++) {
				arr.push(customFormatDisplayField);
			}
			return {
				formatDisplayField: arr
			}
		},
		getArrayOfNamesOfGroupingColumns = function (grpname) {
			return jQuery("."+grpname+" ol li:not(.placeholder)")
				.map(function() {
					return jQuery(this).attr("data-column");
				}).get()
		};

		initGroupDragDrop = function(gridid,groupid) {
			var $grid = jQuery("#"+gridid);

			jQuery("#gbox_"+gridid+" tr.ui-jqgrid-labels th div").draggable({
				appendTo: "body",
				helper: function( event ) {
					return jQuery( "<div class='dragable'>"+jQuery(this).html()+"</div>" );
				}
			});

			jQuery("."+groupid+" ol").droppable({
				activeClass: "ui-state-default",
				hoverClass: "ui-state-hover",
				accept: ":not(.ui-sortable-helper)",
				drop: function(event, ui) {
					var $this = jQuery(this), groupingNames;
					$this.find(".placeholder").remove();
					var groupingColumn = jQuery("<li></li>").attr("data-column", ui.draggable.attr("id").replace("jqgh_" + $grid[0].id + "_", ""));
					jQuery("<span class='ui-icon ui-icon-close'></span>").click(function() {
						var namesOfGroupingColumns;
						jQuery(this).parent().remove();
						$grid.jqGrid("groupingRemove");
						namesOfGroupingColumns = getArrayOfNamesOfGroupingColumns(groupid);
						$grid.jqGrid("groupingGroupBy", namesOfGroupingColumns);
						if (namesOfGroupingColumns.length === 0) {
							jQuery("<li class='placeholder'>Drop column headers here to group by that column</li>").appendTo($this);
						}
					}).appendTo(groupingColumn);
					groupingColumn.append(ui.draggable.text());
					groupingColumn.appendTo($this);
					$grid.jqGrid("groupingRemove");
					groupingNames = getArrayOfNamesOfGroupingColumns(groupid);
					$grid.jqGrid("groupingGroupBy", groupingNames, generateGroupingOptions(groupingNames.length));
					jQuery(".chngroup").val("clear");
				}
			}).sortable({
					items: "li:not(.placeholder)",
					sort: function() {
						jQuery( this ).removeClass("ui-state-default");
					},
					stop: function() {
						var groupingNames = getArrayOfNamesOfGroupingColumns(groupid);
						$grid.jqGrid("groupingRemove");
						$grid.jqGrid("groupingGroupBy", groupingNames, generateGroupingOptions(groupingNames.length));
					}
			});

		}

		setTimeout(()=>{
			initGroupDragDrop('list1','group1');
			initGroupDragDrop('list2','group2');
			initGroupDragDrop('list3','group3');
		},200);

	});
	</script>

</body>
</html>