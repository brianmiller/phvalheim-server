<?php 
/**
 * PHP Grid Component
 *
 * @author Abu Ghufran <gridphp@gmail.com> - http://www.phpgrid.org
 * @version 3.0.0
 * @license: see license.txt included in package
 */

// Setup: This create table query should be executed on desired database 
// and database credentials need to be set for grid in below code $db_conf. 

/*
DROP TABLE IF EXISTS `todos`;
CREATE TABLE IF NOT EXISTS `todos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `description` text NOT NULL,
  `category` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci DEFAULT 'General',
  `priority` varchar(50) NOT NULL DEFAULT '0',
  `status` tinyint(4) NOT NULL DEFAULT '1',
  `roworder` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
*/
 
// include db config
include_once("../../config.php");

// include and create object
include(PHPGRID_LIBPATH."inc/jqgrid_dist.php");

// Database config file to be passed in phpgrid constructor
$db_conf = array(   
                    "type"      => PHPGRID_DBTYPE, 
                    "server"    => PHPGRID_DBHOST,
                    "user"      => PHPGRID_DBUSER,
                    "password"  => PHPGRID_DBPASS,
                    "database"  => PHPGRID_DBNAME
                );

$priority = array("Red","Blue","Green","Orange");                

$g = new jqgrid($db_conf);

$grid["rowNum"] = 100; // by default 20
$grid["sortname"] = 'date'; // by default sort grid by this field
$grid["sortorder"] = ''; // by default sort grid by this field
$grid["caption"] = "✓ Todo List - Grid 4 PHP Framework"; // caption of grid
$grid["autowidth"] = true; // expand grid to screen width
$grid["hotkeys"] = true; // a,e,d,v for add,edit,del,view
$grid["height"] = 400;

$grid["sortrows"] = array("sortfield"=>"roworder", "position"=>"first");

if (isset($_GET["fullscreen"]))
    $grid["fullscreen"] = true; // expand grid to screen width

$grid["add_options"]["width"] = 700; 
$grid["edit_options"]["width"] = 700;
$grid["view_options"]["width"] = 700;

// export PDF file
$grid["export"] = array("format"=>"excel", "range"=>"filtered");

$g->set_options($grid);

// remove from toolbar
$g->navgrid["param"]["del"] = false;
$g->navgrid["param"]["view"] = true;
$g->navgrid["param"]["search"] = false;
$g->navgrid["param"]["refresh"] = false;

$g->set_actions(array(  
                        "add"=>true, // allow/disallow add
                        "edit"=>true, // allow/disallow edit
                        "delete"=>true, // allow/disallow delete
                        "rowactions"=>true, // show/hide row wise edit/del/save option
                        "export"=>true, // show/hide export to excel option
                        "autofilter" => false, // show/hide autofilter for search
                    ) 
                );

$default_filter = "";
if (!isset($_GET["filters"]))
    $default_filter = "WHERE status = 1";

// you can provide custom SQL query to display data
$g->select_command = "SELECT id, description, date, priority, category, status from todos $default_filter";

// this db table will be used for add,edit,delete
$g->table = "todos";

$col = array();
$col["title"] = "Id"; // caption of column
$col["name"] = "id"; // grid column name, must be exactly same as returned column-name from sql (tablefield or field-alias)
$col["width"] = "15";
$col["hidden"] = true;
$col["export"] = true;
$cols[] = $col;     

$col = array();
$col["title"] = " ";
$col["name"] = "box"; 
$col["fixed"] = true;
$col["width"] = "20";
$col["editable"] = false;
$col["search"] = false;
$col["export"] = false;
$col["show"]["view"] = false;
$col["visible"] = "xs+";
$cols[] = $col;

$col = array();
$col["title"] = "Due";
$col["name"] = "date"; 
$col["fixed"] = true;
$col["width"] = "125";
$col["editable"] = true; // this column is editable
$col["editoptions"] = array("size"=>20, "defaultValue"=>date("d M, Y"));
$col["editrules"] = array("required"=>true);
$col["formatter"] = "date"; // format as date
$col["formatoptions"] = array("srcformat"=>'Y-m-d',"newformat"=>'d M, Y');
$cols[] = $col;


$col = array();
$col["title"] = "Details";
$col["name"] = "description";
$col["editable"] = true;
$col["width"] = "200";
$col["edittype"] = "textarea";
$col["visible"] = "xs+";
$col["editoptions"] = array("rows"=>3, "cols"=>80); // with these attributes
$cols[] = $col;

$col = array();
$col["title"] = "Category";
$col["name"] = "category";
$col["editable"] = true;
$col["width"] = "200";
$col["visible"] = "xs+";
$col["edittype"] = "select"; // render as select
$str = $g->get_dropdown_values("select distinct category as k, category as v from todos");
$col["editoptions"] = array("value"=>$str);
$col["saerchoptions"] = array("value"=>$str);

$col["formatter"] = "badge";
// options: green,orange,blue,red,sky,gray,yellow,purple
$col["badgeoptions"]["colors"] = array("General"=>"green","Home"=>"orange","Work"=>"blue","Other"=>"gray","Travel"=>"red");

$cols[] = $col;

// $col = array();
// $col["title"] = "Attachment";
// $col["name"] = "attachment";
// $col["editable"] = true;
// $col["width"] = "200";
// $col["edittype"] = "file";
// $col["editoptions"] = array("accept"=>"image/*", "capture"=>"camera","multiple"=>"multiple");
// $col["upload_dir"] = "files";
// $col["visible"] = "md+";
// $col["show"]["list"] = false;
// $col["show"]["view"] = false;
// $cols[] = $col;

// $col = array();
// $col["title"] = "Attachment";
// $col["name"] = "show_attachment";
// $col["editable"] = false;
// $col["width"] = "200";
// $col["visible"] = "md+";
// // display none if nothing is uploaded, otherwise make link.
// $col["on_data_display"] = array("render_images","");
// $col["show"]["list"] = false;
// $col["show"]["add"] = false;
// $col["show"]["edit"] = false;
// $cols[] = $col;

// function render_images($row)
// {
//  // get upload folder url for display in grid -- change it as per your upload path
//  $upload_url = explode("/",$_SERVER["REQUEST_URI"]);
//  array_pop($upload_url);
//  $upload_url = implode("/",$upload_url)."/";

//  if ($row["attachment"] == "")
//      return "None";
//  else
//  {
//         $imgs = explode(",",$row["attachment"]);
//         $ret = "<a href='javascript:$(\".i\").toggle();'>View</a>";
//         $ret .= "<div class='i' style='display:none'>";
//      foreach($imgs as $i)
//             $ret .= "<a style='float:left;display:block;margin:0px 5px 5px 0px;' target='_blank' href='$upload_url/$i' target='_blank'><img height='200' src='$upload_url/$i'></a>";
//         $ret .= "</div>";

//      return $ret;
//  }
// }

$col = array();
$col["title"] = "&nbsp;✓";
$col["name"] = "tick";
$col["fixed"] = true;
$col["width"] = "30";
$col["align"] = "center";
$col["sortable"] = false;
$col["export"] = false;
$col["search"] = false;
$col["editable"] = false;
$col["edittype"] = "checkbox";
// custom formatter to show active checkbox
$col["formatter"] = "function(cellvalue, options, rowObject){ return cboxFormatter(cellvalue, options, rowObject); }";
$col["unformat"] = "function(cellvalue, options, cell){ return cboxUnFormat(cellvalue, options, cell);}";
$col["show"]["add"] = false;
$col["show"]["edit"] = false;
$col["show"]["view"] = false;
$col["visible"] = "xs+";
$cols[] = $col;

$col = array();
$col["title"] = "Priority";
$col["name"] = "priority";
$col["width"] = "50";
$col["editable"] = true;
$col["edittype"] = "select";
// make color options
// Red:Red;Blue:Blue;Green:Green;Orange:Orange;
$str = array();
foreach($priority as $p)
    $str[] = "$p:$p";
$str = implode(";",$str);

$col["editoptions"]["value"] = $str; 
$col["editoptions"]["dataInit"] = "function(o){ make_radios(o); }";
$col["show"]["list"] = false;
$cols[] = $col;

$col = array();
$col["title"] = "Status";
$col["name"] = "status";
$col["width"] = "30";
$col["align"] = "center";
$col["fixed"] = true;
$col["editable"] = true;
$col["edittype"] = "select";
$col["formatter"] = "select";
$col["editoptions"]["value"] = "1:Active;0:Done";
$col["show"]["list"] = false;
$cols[] = $col;

// pass the cooked columns to grid
$g->set_columns($cols);

// On delete event for soft delete
$e = array();
$e["on_delete"] = array("delete_task", null, false);
function delete_task($data)
{
    global $g;
    $sql = "UPDATE todos SET `status` = 0 where id = ?";
    $g->execute_query($sql,array($data["id"]));
}
$g->set_events($e);

// Formatting rows
$colors = array();
$colors["red"] = "#ff0000";
$colors["blue"] = "#19adf8";
$colors["green"] = "#2bd045";
$colors["orange"] = "#ff9500";

foreach($priority as $p)
{
    $f = array();
    $f["column"] = "priority";
    $f["op"] = "=";
    $f["value"] = "$p";
    $f["target"] = "box";
    $f["cellcss"] = "'background-color':'{$colors[strtolower($p)]}',color:'white'"; // must use (single quote ') with css attr and value
    $f_conditions[] = $f;
}

$f = array();
$f["column"] = "status";
$f["op"] = "=";
$f["value"] = "0";
$f["target"] = "description";
$f["cellcss"] = "'text-decoration':'line-through',color:'#cecece'"; // must use (single quote ') with css attr and value
$f_conditions[] = $f;

$g->set_conditional_css($f_conditions);

// generate grid output, with unique grid name as 'list_todoapp'
$out = $g->render("list_todoapp");
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    
    <!-- <link rel="apple-touch-icon-precomposed" href="img/icon.png"/>
    <link rel="apple-touch-icon" href="img/icon.png"/>
    <link rel="apple-touch-startup-image" href="img/splash.png" />
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" /> -->

    <title>Todo List - Grid 4 PHP Framework</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/css/bootstrap.min.css" integrity="sha384-MCw98/SFnGE8fJT3GXwEOngsV7Zt27NXFoaoApmYm81iuXoPkFOJwJ8ERdknLPMO" crossorigin="anonymous">

    <link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/themes/material/jquery-ui.custom.css"></link>  
    <link rel="stylesheet" type="text/css" media="screen" href="../../lib/js/jqgrid/css/ui.jqgrid.bs.css"></link>   
    
    <script src="../../lib/js/jquery.min.js" type="text/javascript"></script>
    <script src="../../lib/js/jqgrid/js/i18n/grid.locale-en.js" type="text/javascript"></script>
    <script src="../../lib/js/jqgrid/js/jquery.jqGrid.min.js" type="text/javascript"></script>  
    <script src="../../lib/js/themes/jquery-ui.custom.min.js" type="text/javascript"></script>

    <style>
    .global-search
    {
        display:none;
    }
    .ui-jqgrid input[type=checkbox] {
        zoom: 1.2;
    }
    .ui-jqgrid .editable
    {
        color: black !important;
    }
    #sidebar-collapse {
        display: none;
    }
    #sidebar {
            min-width: 300px;
            max-width: 300px;
            transition: all 0.3s;
            display:block;
        }
    
    @media (max-width: 768px) {

        #sidebar-collapse {
            display: block;
        }
        #sidebar {
            background: #7386D5;
            color: #fff;
            position:absolute;
            right: -300px;
            top:0px;
            height: 100%;
            display:none;
        }
        #sidebar.active {
            right: 0;
            display:block;
        }
    }
    </style>
</head>
<body>
    <div class="container-fluid py-3">
    <div class="row">
            <div class="col-md-9 col-12">
            <?php echo $out?>
            </div>
            <div id="sidebar" class="col-md-3">
                <span id="sidebar-collapse" class="float-right py-1"><i class="fa fa-window-close"></i></span>
                <fieldset style="font-family:tahoma; font-size:12px">
                    <legend>Filters</legend>
                    <form>
                    <div class="row py-1">
                    <div class="col-2">From</div>
                    <div class="col-10"><input class="datepicker" type="text" id="datefrom"/></div>  
                    </div>

                    <div class="row py-1">
                    <div class="col-2">To</div>
                    <div class="col-10"><input class="datepicker" type="text" id="dateto"/></div>  
                    </div>

                    <div class="row py-1">
                    <div class="col-2">Status</div>
                    <div class="col-10">
                        <label><input type="radio" name="fstatus" value="1" checked> Active</label>
                        <label><input type="radio" name="fstatus" value="0"> Done</label>
                        <label><input type="radio" name="fstatus" value="-1"> All</label>
                    </div>  
                    </div>

                    <div class="row py-1">
                    <div class="col-2">Text</div>
                    <div class="col-10">
                        <label><input id="query"></label>
                    </div>  
                    </div>

                    <div class="row py-1">
                    <div class="col-2">Priority</div>
                    <div class="col-10">
                        <?php foreach ($priority as $p) { ?>
                            <label style="display:block; padding:5px; width: 80px; color:white; background-color:<?php echo $colors[strtolower($p)] ?>">
                            <input onclick="jQuery('#search_date').click();" checked type="checkbox" name="fpriority" value="<?php echo $p ?>"> <?php echo $p ?>
                            </label>
                        <?php } ?>
                    </div>  
                    </div>

                    <div class="row py-1">
                    <div class="col-2"></div>
                    <div class="col-10">
                    <input class="btn btn-info" type="submit" id="search_date" value="Filter">
                    </div>  
                    </div>
                    
                    </form>
                </fieldset>
            </div>
    </div>

    <script>
    $(document).ready(function () {

        // show buttons only on mobile devices
        if( jQuery(window).innerWidth() > 768) 
            return;

        setTimeout(()=>{
            
            jQuery('#list_todoapp').jqGrid('navButtonAdd', '#list_todoapp_pager',
            {
                'caption'      : 'Filters',
                'buttonicon'   : 'ui-icon-menu',
                'onClickButton': function()
                {
                    $('#sidebar').show().toggleClass('active');

                    if (!$('#sidebar').hasClass("active")) {
                        setTimeout(()=>{ $('#sidebar').hide(); },200);
                    }
                },
                'position': 'last'
            });

            $('#sidebar-collapse').on('click', function () {
                $('#sidebar').toggleClass('active');
                setTimeout(()=>{ $('#sidebar').hide(); },200);
            });

        },200);

    });

    // make dropdown to radio
    function make_radios(o)
    {
        jQuery("option",o).each(function(i, e) {
                (
                    (
                    jQuery("<label style='padding:5px 10px; margin: 5px 5px 5px 0px; color:white; border-radius:20px; background-color:"+this.textContent+"' />")
                    .append(
                        jQuery("<input type='radio' name='color' />")
                        .attr("value", jQuery(this).val())
                        .attr("checked", jQuery(this).val() == jQuery(o).val())
                        .click(function() { jQuery(o).val(jQuery(this).val()); })
                        .add("<span>&nbsp;"+this.textContent+"</span>")
                        )
                    )
                )
                .appendTo(jQuery(o).parent());
            });
        jQuery(o).hide();
    }

    // external search form js api
    jQuery(window).load(function() {
    
        // formats: http://api.jqueryui.com/datepicker/#option-dateFormat
        jQuery(".datepicker").datepicker(
                                {
                                "disabled":false,
                                "dateFormat":"yy-mm-dd",
                                "changeMonth": true,
                                "changeYear": true,
                                "firstDay": 1,
                                "showOn":'both'
                                }
                            ).next('button').button({
                                icons: {
                                    primary: 'ui-icon-calendar'
                                }, text:false
                            }).css({'font-size':'80%', 'margin-left':'2px', 'margin-top':'-5px'});
                            jQuery(".datepicker").width('70%');                                 
    });
    
    jQuery("#search_date").click(function() {
        grid = jQuery("#list_todoapp");

        var main = {groupOp:"AND",rules:[],groups:[]};

        // if both date set, then filter by date
        if (jQuery("#datefrom").val() != '' && jQuery("#dateto").val() != '')
        {
            var f = {groupOp:"AND",rules:[]};
            if (jQuery("#datefrom").val())
            f.rules.push({field:"date",op:"ge",data:jQuery("#datefrom").val()});
            
            if (jQuery("#dateto").val())
            f.rules.push({field:"date",op:"le",data:jQuery("#dateto").val()});
    
            var datefilter = {groupOp:"OR",rules:[],groups:[f]};
            datefilter.rules.push({field:"date",op:"nu",data:''});

            main.groups.push(datefilter);
        }
      
        if (jQuery("#query").val() != '')
        {
            main.rules.push({field:"description",op:"cn",data:jQuery("#query").val()});
        }

        // filter by status 0,1 - not all (-1)
        if (jQuery("[name=fstatus]:checked").val() != -1)
            main.rules.push({field:"status",op:"eq",data:jQuery("[name=fstatus]:checked").val()});
        
        // get comma sep values of color checkboxes
        var colors = jQuery("[name=fpriority]:checked").map(function () {  
                                                                return this.value;
                                                            }).get().join(",");
        // if set, perform IN search
        if (colors)
            main.rules.push({field:"priority",op:"in",data:colors});

        grid[0].p.search = true;
        jQuery.extend(grid[0].p.postData,{filters:JSON.stringify(main)});

        grid.trigger("reloadGrid",[{jqgrid_page:1,current:true}]);

        $('#sidebar').toggleClass('active');
        return false;
    });

    // checkbox + ajax update without edit mode
    function cboxFormatter(cellvalue, options, rowObject)
    {
        if ( rowObject.status == 1 )
            return '<input id="cbox'+options.rowId+'" type="checkbox" name="completed" value="'+options.rowId+'" onclick="updateRow('+options.rowId+',this.checked);"/> ';
        else
            return '<input id="cbox'+options.rowId+'" type="checkbox" name="completed" value="'+options.rowId+'" onclick="updateRow('+options.rowId+',this.checked);" checked /> ';       
    }

    function cboxUnFormat(cellvalue, options, cell)
    {
        return jQuery('input', cell).attr('value');
    }

    // call ajax to update date in db
    function updateRow(id, checked)
    {
        var request = {};
        request['oper'] = 'edit';
        request['id'] = id;
        
        if (checked)
            request['status'] = 0;
        else
            request['status'] = 1;

        var grid = jQuery('#list_todoapp');
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
    </script>    
</body>
</html>