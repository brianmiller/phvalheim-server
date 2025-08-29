<!doctype html>
<html lang="en" class="h-100">

<head>
	<meta charset="utf-8">
	<meta name="referrer" content="no-referrer"/>
	<meta name="robots" content="noindex"/>	
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="description" content="Grid 4 PHP Framework">
	<meta name="author" content="Abu Ghufran">
	<title>{{app_name}} - {{company}}</title>

	<link type="text/css" rel="stylesheet" href="<?php echo PHPGRID_URL?>lib/js/integration/fancybox/jquery.fancybox.css" />	
	<link rel="stylesheet" type="text/css" media="screen" href="<?php echo PHPGRID_URL?>lib/js/themes/base/jquery-ui.custom.css">
	<!-- Bootstrap core CSS -->
	<link href="theme/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
	<link rel="stylesheet" type="text/css" media="screen" href="<?php echo PHPGRID_URL?>lib/js/jqgrid/css/ui.jqgrid.bs.css">
	<link href="theme/dist/css/app.css" rel="stylesheet" crossorigin="anonymous">
</head>

<body>

	<!-- Begin page content -->
	<div>
		<?php echo $out_grid?>
	</div>

	<script src="<?php echo PHPGRID_URL?>lib/js/jquery.min.js" type="text/javascript"></script>

	<!-- img popup -->
	<script type="text/javascript" src="<?php echo PHPGRID_URL?>lib/js/integration/fancybox/jquery.fancybox.js"></script>

	<script src="<?php echo PHPGRID_URL?>lib/js/jqgrid/js/i18n/grid.locale-en.js" type="text/javascript"></script>
	<script src="<?php echo PHPGRID_URL?>lib/js/jqgrid/js/jquery.jqGrid.min.js" type="text/javascript"></script>
	<script src="<?php echo PHPGRID_URL?>lib/js/themes/jquery-ui.custom.min.js" type="text/javascript"></script>

	<link href="<?php echo PHPGRID_URL?>lib/js/integration/select2/4.0.4/select2.min.css" rel="stylesheet" />
	<script src="<?php echo PHPGRID_URL?>lib/js/integration/select2/4.0.4/select2.min.js"></script>

	<script src="theme/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>

	<script>
	function gridLoad()
	{
		// glitch fix for frozen columns display - initially hidden then inherit
		setTimeout(()=>{$(".frozen-div,.frozen-bdiv").css("visibility","inherit");},400);

		// connect fancybox to grid images 
		$(document).click(function(e){
			if($(e.target).parent().hasClass('img-gridcell'))
			{
				e.preventDefault();
				let a = $(e.target).parent();
				a.addClass("fancybox");
				jQuery.fancybox.open({"autoScale":true,"content":$("<img id='x'>").attr("src",$(a).attr('href')).prop('outerHTML')});
			}
		});
	}

	// ajax call to fetch generated new max id
	function getNextId(grid,obj)
	{
		var gid = jQuery(grid).attr("id");
		myData = {};
		myData.oper = "getmax";
		myData.colname = jQuery(obj).attr("name");
		myData.grid_id = gid;
		jQuery.ajax({
			url: jQuery("#"+gid).jqGrid('getGridParam', 'url'),
			dataType: 'html',
			data: myData,
			type: 'POST',
			error: function(res, status) {
				jQuery.jgrid.info_dialog(jQuery.jgrid.errors.errcap,'<div class=\"ui-state-error\">'+ res.responseText +'</div>', 
						jQuery.jgrid.edit.bClose,{buttonalign:'right'});
			},
			success: function( data ) {
				// fill colname with ajax return
				data = JSON.parse(data);
				obj.value = data.new;
				jQuery(obj).trigger('click');
			}
		});
	}

	// auto fill certain field realtime based on valuePattern attribute. e.g. {id}-{name} 
	function formCallback(o,e)
	{
		var objVal = "";
		if (e.target.tagName == "SELECT")
			objVal = jQuery(o).find("option:selected").text();
		else
			objVal = jQuery(o).val();

		var result = "";
		var regexPattern = "";

		jQuery("[valuePattern]").each(function(){
			var pattern = jQuery(this).attr("valuePattern");
			
			var map = new Array();
			if (e.type == "loadform")
			{
				if (jQuery(".ui-jqdialog").data("map"))
					map = jQuery(".ui-jqdialog").data("map");

				if (jQuery(this).val() == "")
					jQuery(this).val(pattern);
				
				var current = jQuery(this).val();
				result = current.replace("{"+jQuery(o).attr("name")+"}",objVal);
				jQuery(this).val(result);

				map[jQuery(o).attr("name")] = objVal;
				jQuery(".ui-jqdialog").data("map",map);
			}
			else
			{
				result = pattern;
				map = jQuery(".ui-jqdialog").data("map");
				map[jQuery(o).attr("name")] = objVal;
				for(i in map)
					result = result.replace("{"+i+"}",map[i]);

				jQuery(this).val(result);
			}
		})
	}	

	// add edit button that will invoke normal / bulk edit based on multi-selection
	jQuery("document").ready(function(){
		setTimeout(()=>{

			// skip if no grid, lookupdialog case
			var grid = jQuery('.ui-jqgrid').attr('id').replace('gbox_','');

			// if edit/bulkedit not enabled, don't add custom button
			if (!(jQuery("#edit_"+grid,"#"+grid+"_pager").length && jQuery("#bulkedit_"+grid,"#"+grid+"_pager").length))
				return;

			$("#bulkedit_"+grid).hide();
			$("#edit_"+grid).hide();

			jQuery("#"+grid).jqGrid('navButtonAdd',"#"+grid+"_pager",{caption:"",title:"Edit", position:2, buttonicon :'ui-icon-pencil',
				'onClickButton':function(){
					var rows = '';
					if (jQuery('#'+grid).jqGrid('getGridParam','multiselect'))
					{
						rows = jQuery('#'+grid).jqGrid('getGridParam','selarrrow');
						if (rows.length>1)
							$("#bulkedit_"+grid).trigger("click");
						else
							$("#edit_"+grid).trigger("click");
					}
					else
						$("#edit_"+grid).trigger("click");
				}
			});
		},200);
	});

	/* global bootstrap: false */
	(function() {
		'use strict'
		var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
		tooltipTriggerList.forEach(function(tooltipTriggerEl) {
			new bootstrap.Tooltip(tooltipTriggerEl)
		})
	})()
	</script>
		
</body>

</html>