<?php
// links to show in top menu
$links = array();
$links["transactions"] = array("Transactions","index.php?mod=transactions",false);
$links["accounts"] = array("Accounts","index.php?mod=accounts",false);
$links["report"] = array("Report","index.php?mod=report",false);
$links["categories"] = array("Categories","index.php?mod=categories",false);
$links["users"] = array("Users","index.php?mod=users",true);
$links["settings"] = array("Settings","index.php?mod=settings",true);

?>
<!doctype html>
<html lang="en" class="h-100">

<head>
	<meta charset="utf-8">
	<meta name="referrer" content="no-referrer"/>
	<meta name="robots" content="noindex"/>	
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="description" content="Grid 4 PHP Framework">
	<meta name="author" content="Abu Ghufran">
	<title><?php echo APP_NAME ?> - Grid 4 PHP Framework</title>

	<link type="text/css" rel="stylesheet" href="<?php echo PHPGRID_URL?>lib/js/integration/fancybox/jquery.fancybox.css" />	
	<link rel="stylesheet" type="text/css" media="screen" href="<?php echo PHPGRID_URL?>lib/js/themes/base/jquery-ui.custom.css">
	<!-- Bootstrap core CSS -->
	<link href="theme/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
	<link rel="stylesheet" type="text/css" media="screen" href="<?php echo PHPGRID_URL?>lib/js/jqgrid/css/ui.jqgrid.bs.css">
	<link href="theme/dist/css/app.css" rel="stylesheet" crossorigin="anonymous">
	<style>:root{--bs-dark-rgb: 220,4,59;--tabcolor: #c60435;--tabhover: #b20430;}</style>
</head>

<body class="d-flex flex-column h-100">
	<header>
		<!-- Fixed navbar -->
		<nav class="navbar navbar-expand-md navbar-dark fixed-top bg-dark">
			<div class="container-fluid">
				<div class="container-fluid">
					<div class="row">
						<div class="col-6 col-sm-9"><a class="navbar-brand" style="font-size:x-large" href="index.php"><?php echo APP_NAME ?></a><span class="d-none d-sm-inline" style="font-size:11px;color:white">Made with <a style="text-decoration:none;color:white" href="https://www.gridphp.com/">Grid 4 PHP Framework</a></span> <span class="d-none d-sm-inline" style="font-size:10px;color:transparent">v1.1 - 20/03/2025</span></div>
						<div class="col-6 col-sm-3 d-flex justify-content-end">

							

							<a class="d-flex align-items-center pe-0" href="#" data-bs-toggle="dropdown">
								<svg style="color:ghostwhite" width="30" height="30" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M24 0C10.752 0 0 10.752 0 24C0 37.248 10.752 48 24 48C37.248 48 48 37.248 48 24C48 10.752 37.248 0 24 0ZM12.168 39.072C13.2 36.912 19.488 34.8 24 34.8C28.512 34.8 34.824 36.912 35.832 39.072C32.568 41.664 28.464 43.2 24 43.2C19.536 43.2 15.432 41.664 12.168 39.072ZM39.264 35.592C35.832 31.416 27.504 30 24 30C20.496 30 12.168 31.416 8.736 35.592C6.288 32.376 4.8 28.368 4.8 24C4.8 13.416 13.416 4.8 24 4.8C34.584 4.8 43.2 13.416 43.2 24C43.2 28.368 41.712 32.376 39.264 35.592ZM24 9.6C19.344 9.6 15.6 13.344 15.6 18C15.6 22.656 19.344 26.4 24 26.4C28.656 26.4 32.4 22.656 32.4 18C32.4 13.344 28.656 9.6 24 9.6ZM24 21.6C22.008 21.6 20.4 19.992 20.4 18C20.4 16.008 22.008 14.4 24 14.4C25.992 14.4 27.6 16.008 27.6 18C27.6 19.992 25.992 21.6 24 21.6Z" fill="currentColor"></path></svg>
							</a>
							<ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow profile">
								<?php if (logged_in()) { ?>
								<li class="dropdown-header">
								<div>Hi, <?php echo $_SESSION["name"] ?>!</div>
								<span><?php echo $_SESSION["email"] ?></span>
								</li>
								<li>
								<hr class="dropdown-divider">
								</li>

								<?php if (has_access("manage_users")) { ?>
								<li>
								<a class="dropdown-item d-flex align-items-center" href="?mod=users">
									<i class="bi bi-box-arrow-right"></i>
									<span>Manage Users</span>
								</a>
								</li>																
								<li>
								<a class="dropdown-item d-flex align-items-center" href="?mod=settings">
									<i class="bi bi-box-arrow-right"></i>
									<span>Settings</span>
								</a>
								</li>																
								<?php } ?>

								<li>
								<a class="dropdown-item d-flex align-items-center" href="?logout">
									<i class="bi bi-box-arrow-right"></i>
									<span>Sign Out</span>
								</a>
								</li>
								<?php } else { ?>

								<li>
								<a class="dropdown-item d-flex align-items-center" href="?mod=users">
									<i class="bi bi-box-arrow-right"></i>
									<span>Manage Users</span>
								</a>
								</li>																
								<li>
								<a class="dropdown-item d-flex align-items-center" href="?mod=settings">
									<i class="bi bi-box-arrow-right"></i>
									<span>Settings</span>
								</a>
								</li>																

								<?php } ?>
								<li>
								<hr class="dropdown-divider">
								</li>
								<li>
									<span class="dropdown-item d-flex align-items-center">									
									<i class="bi bi-box-arrow-right"></i>
									<span>Version 1.1</span>
									</span>
								</li>								
							</ul><!-- End Profile Dropdown Items -->
						</div>
					</div>				
				</div>				
			</div>
		</nav>
	</header>

	<!-- Begin page content -->
	<main class="flex-shrink-0">
		
		<div class="container-fluid">
			<ul class="nav nav-tabs d-none d-sm-flex" id="myTab" role="tablist">
				<?php
				if (!empty($links))
				{
					foreach($links as $k=>$v)
					{	
						$m = $v[0]; // label
						$url = $v[1]; // link
						$hidden = $v[2]; // hidden

						$class = "";
						if (get_clean($m) == strtolower($mod))
							$class = "active";
						else if ($hidden)
							$class = "hide";
					?>
					<li class="nav-item" role="presentation">
						<a class="nav-link <?php echo $class?>" href="<?php echo $url?>"><?php echo $m?></a>
					</li>
					<?php
					}
				}
				?>			
			</ul>
			<select class="form-select d-block d-sm-none" id="tab_selector" onchange="location.href=this.value;">
			<?php
				if (!empty($links))
				{
					foreach($links as $k=>$v)
					{
						$m = $v[0]; // label
						$url = $v[1]; // link

						$active = "";
						if (get_clean($m) == strtolower($mod))
						$active = "selected";
					?>
					<option <?php echo $active ?> value="<?php echo $url?>"><?php echo $m?></option>
					<?php
					}
				}
				?>			
			</select>			
			<script>
				var opts = {
					'ondblClickRow': function (id) {
						jQuery(this).jqGrid('editGridRow', id, jQuery(this).jqGrid('getGridParam','edit_options'));
					}
				};
			</script>
			<div class="tab-content" id="myTabContent">
				<div class="tab-pane fade show active <?php echo $tab_class ?>" role="tabpanel">
				<p><?php echo $out_grid?></p>
				</div>
			</div>
		</div>
	</main>

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
				jQuery.fancybox.open({"autoScale":true,"content":$("<img height='100%' id='x'>").attr("src",$(a).attr('href')).prop('outerHTML')});
			}
		});
	}
	
	function afterSave()
	{
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
		
	<?php if (strstr($_SERVER["SERVER_ADDR"],"gridphp.com")!==false && empty($_GET["embed"]))  { ?>
	<!-- Chatra {literal} -->
	<script>
		(function(d, w, c) {
			w.ChatraID = 'de92EMe5e2YEPcdJ3';
			var s = d.createElement('script');
			w[c] = w[c] || function() {
				(w[c].q = w[c].q || []).push(arguments);
			};
			s.async = true;
			s.src = 'https://call.chatra.io/chatra.js';
			if (d.head) d.head.appendChild(s);
		})(document, window, 'Chatra');
	</script>
	<!-- /Chatra {/literal} -->
	<?php } ?>
</body>

</html>