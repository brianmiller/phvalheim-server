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

$grid["caption"] = "Invoice Data"; // caption of grid
$grid["autowidth"] = true; // expand grid to screen width
$grid["export"] = array("filename"=>"my-file", "sheetname"=>"test"); // export to excel parameters

// excel visual params
$grid["cellEdit"] = true; // inline cell editing, like spreadsheet
$grid["cellMove"] = "down"; // after save cell move to: next or down or none
// $grid["cellEditMode"] = "click"; // edit cell on single click (old bahavior)

// enable lazy loading
$grid["scroll"] = true;

// lazy loading via keydown
$grid["scrollingRows"] = true;

$grid["rownumbers"] = true;
$grid["responsive"] = true;
// $grid["autoheight"] = true;
$grid["rownumWidth"] = 30;
$grid["sortorder"] = "DESC";

// reload cells after change
// $grid["afterSaveCell"] = "function(){ setTimeout(()=>{ $(this).trigger('reloadGrid', [{current:true}]);},300) }";

// callback for each cell selection
// $grid["onSelectCell"] = "function(rowid, celname, value, iRow, iCol){ alert('onSelectCell'); }";

$grid["pastefromexcel"] = true;
$grid["autowidth"] = false;

// first column is not autoincrement
$grid["autoid"] = false;

// $grid["cmTemplate"] = array("width"=>100);
$g->set_options($grid);

// show edit dialog along with excel view
$g->navgrid["param"]["edit"] = true;

$g->set_actions(array(	
						"inline"=>true, // allow/disallow inline
						"add"=>true, // allow/disallow add
						"edit"=>true, // allow/disallow edit
						"delete"=>true, // allow/disallow delete
						"clone"=>true, // allow/disallow clone
						"export"=>true, // show/hide export to excel option
						"autofilter" => true, // show/hide autofilter for search
						"search" => "advance" // show single/multi field search condition (e.g. simple or advance)
						) 
					);
					
// this db table will be used for add,edit,delete
$g->table = "customers";

$col = array();
$col["title"] = "Id"; // caption of column
$col["name"] = "customer_id"; // grid column name, must be exactly same as returned column-name from sql (tablefield or field-alias)
$col["width"] = "60";
$col["editable"] = true;
$cols[] = $col;

$col = array();
$col["title"] = "Phone"; // caption of column
$col["name"] = "phone"; // grid column name, must be exactly same as returned column-name from sql (tablefield or field-alias)
$col["width"] = "160";
$col["editable"] = true;
// $col["editoptions"]["dataInit"] = 'function() { jQuery("td.edit-cell [name=phone]").mask("000.00"); }';
$cols[] = $col;

$col = array();
$col["title"] = "Region"; // caption of column
$col["name"] = "region"; // grid column name, must be exactly same as returned column-name from sql (tablefield or field-alias)
$col["width"] = "160";
$col["editable"] = true;
$col["editoptions"]["list"] = "client_list"; 

// demo of html datalist
$col["editoptions"]["dataInit"] = "function(o) { 
										var datalist = jQuery('<datalist />',{id: 'client_list'});
										var values = Array('AK','SP','AR','AE', 'AZ');
										for(i in values)
											jQuery(datalist).append('<option>'+values[i]+'</option>');
										jQuery(o).parent().append(datalist); 
									}"; 
$cols[] = $col;

$col = array();
$col["title"] = "Country";
$col["name"] = "country";
$col["width"] = "150";
$col["edittype"] = "select";
$col["visible"] = "sm+";
$col["editoptions"]["value"] = get_country_dropdown();
$col["formatter"] = "function (cellvalue, options, rowObject) {
	return \"<img width='30' height='20' src='../images/country-flags/\"+get_countrycode(cellvalue)+\".png' /> \" + cellvalue;
}";
$col["unformat"] = "function (cellvalue, options) {
	return cellvalue;
}";
$cols[] = $col;

// pass the cooked columns to grid
$g->set_columns($cols,true);

function get_country_dropdown()
{
	$str = array();
	$countries = array('AF' => 'Afghanistan', 'AX' => 'Aland Islands', 'AL' => 'Albania', 'DZ' => 'Algeria', 'AS' => 'American Samoa', 'AD' => 'Andorra', 'AO' => 'Angola', 'AI' => 'Anguilla', 'AQ' => 'Antarctica', 'AG' => 'Antigua And Barbuda', 'AR' => 'Argentina', 'AM' => 'Armenia', 'AW' => 'Aruba', 'AU' => 'Australia', 'AT' => 'Austria', 'AZ' => 'Azerbaijan', 'BS' => 'Bahamas', 'BH' => 'Bahrain', 'BD' => 'Bangladesh', 'BB' => 'Barbados', 'BY' => 'Belarus', 'BE' => 'Belgium', 'BZ' => 'Belize', 'BJ' => 'Benin', 'BM' => 'Bermuda', 'BT' => 'Bhutan', 'BO' => 'Bolivia', 'BA' => 'Bosnia And Herzegovina', 'BW' => 'Botswana', 'BV' => 'Bouvet Island', 'BR' => 'Brazil', 'IO' => 'British Indian Ocean Territory', 'BN' => 'Brunei Darussalam', 'BG' => 'Bulgaria', 'BF' => 'Burkina Faso', 'BI' => 'Burundi', 'KH' => 'Cambodia', 'CM' => 'Cameroon', 'CA' => 'Canada', 'CV' => 'Cape Verde', 'KY' => 'Cayman Islands', 'CF' => 'Central African Republic', 'TD' => 'Chad', 'CL' => 'Chile', 'CN' => 'China', 'CX' => 'Christmas Island', 'CC' => 'Cocos (Keeling) Islands', 'CO' => 'Colombia', 'KM' => 'Comoros', 'CG' => 'Congo', 'CD' => 'Congo, Democratic Republic', 'CK' => 'Cook Islands', 'CR' => 'Costa Rica', 'CI' => 'Cote D\'Ivoire', 'HR' => 'Croatia', 'CU' => 'Cuba', 'CY' => 'Cyprus', 'CZ' => 'Czech Republic', 'DK' => 'Denmark', 'DJ' => 'Djibouti', 'DM' => 'Dominica', 'DO' => 'Dominican Republic', 'EC' => 'Ecuador', 'EG' => 'Egypt', 'SV' => 'El Salvador', 'GQ' => 'Equatorial Guinea', 'ER' => 'Eritrea', 'EE' => 'Estonia', 'ET' => 'Ethiopia', 'FK' => 'Falkland Islands (Malvinas)', 'FO' => 'Faroe Islands', 'FJ' => 'Fiji', 'FI' => 'Finland', 'FR' => 'France', 'GF' => 'French Guiana', 'PF' => 'French Polynesia', 'TF' => 'French Southern Territories', 'GA' => 'Gabon', 'GM' => 'Gambia', 'GE' => 'Georgia', 'DE' => 'Germany', 'GH' => 'Ghana', 'GI' => 'Gibraltar', 'GR' => 'Greece', 'GL' => 'Greenland', 'GD' => 'Grenada', 'GP' => 'Guadeloupe', 'GU' => 'Guam', 'GT' => 'Guatemala', 'GG' => 'Guernsey', 'GN' => 'Guinea', 'GW' => 'Guinea-Bissau', 'GY' => 'Guyana', 'HT' => 'Haiti', 'HM' => 'Heard Island & Mcdonald Islands', 'VA' => 'Holy See (Vatican City State)', 'HN' => 'Honduras', 'HK' => 'Hong Kong', 'HU' => 'Hungary', 'IS' => 'Iceland', 'IN' => 'India', 'ID' => 'Indonesia', 'IR' => 'Iran, Islamic Republic Of', 'IQ' => 'Iraq', 'IE' => 'Ireland', 'IM' => 'Isle Of Man', 'IL' => 'Israel', 'IT' => 'Italy', 'JM' => 'Jamaica', 'JP' => 'Japan', 'JE' => 'Jersey', 'JO' => 'Jordan', 'KZ' => 'Kazakhstan', 'KE' => 'Kenya', 'KI' => 'Kiribati', 'KR' => 'Korea', 'KW' => 'Kuwait', 'KG' => 'Kyrgyzstan', 'LA' => 'Lao People\'s Democratic Republic', 'LV' => 'Latvia', 'LB' => 'Lebanon', 'LS' => 'Lesotho', 'LR' => 'Liberia', 'LY' => 'Libyan Arab Jamahiriya', 'LI' => 'Liechtenstein', 'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'MO' => 'Macao', 'MK' => 'Macedonia', 'MG' => 'Madagascar', 'MW' => 'Malawi', 'MY' => 'Malaysia', 'MV' => 'Maldives', 'ML' => 'Mali', 'MT' => 'Malta', 'MH' => 'Marshall Islands', 'MQ' => 'Martinique', 'MR' => 'Mauritania', 'MU' => 'Mauritius', 'YT' => 'Mayotte', 'MX' => 'Mexico', 'FM' => 'Micronesia, Federated States Of', 'MD' => 'Moldova', 'MC' => 'Monaco', 'MN' => 'Mongolia', 'ME' => 'Montenegro', 'MS' => 'Montserrat', 'MA' => 'Morocco', 'MZ' => 'Mozambique', 'MM' => 'Myanmar', 'NA' => 'Namibia', 'NR' => 'Nauru', 'NP' => 'Nepal', 'NL' => 'Netherlands', 'AN' => 'Netherlands Antilles', 'NC' => 'New Caledonia', 'NZ' => 'New Zealand', 'NI' => 'Nicaragua', 'NE' => 'Niger', 'NG' => 'Nigeria', 'NU' => 'Niue', 'NF' => 'Norfolk Island', 'MP' => 'Northern Mariana Islands', 'NO' => 'Norway', 'OM' => 'Oman', 'PK' => 'Pakistan', 'PW' => 'Palau', 'PS' => 'Palestinian Territory, Occupied', 'PA' => 'Panama', 'PG' => 'Papua New Guinea', 'PY' => 'Paraguay', 'PE' => 'Peru', 'PH' => 'Philippines', 'PN' => 'Pitcairn', 'PL' => 'Poland', 'PT' => 'Portugal', 'PR' => 'Puerto Rico', 'QA' => 'Qatar', 'RE' => 'Reunion', 'RO' => 'Romania', 'RU' => 'Russian Federation', 'RW' => 'Rwanda', 'BL' => 'Saint Barthelemy', 'SH' => 'Saint Helena', 'KN' => 'Saint Kitts And Nevis', 'LC' => 'Saint Lucia', 'MF' => 'Saint Martin', 'PM' => 'Saint Pierre And Miquelon', 'VC' => 'Saint Vincent And Grenadines', 'WS' => 'Samoa', 'SM' => 'San Marino', 'ST' => 'Sao Tome And Principe', 'SA' => 'Saudi Arabia', 'SN' => 'Senegal', 'RS' => 'Serbia', 'SC' => 'Seychelles', 'SL' => 'Sierra Leone', 'SG' => 'Singapore', 'SK' => 'Slovakia', 'SI' => 'Slovenia', 'SB' => 'Solomon Islands', 'SO' => 'Somalia', 'ZA' => 'South Africa', 'GS' => 'South Georgia And Sandwich Isl.', 'ES' => 'Spain', 'LK' => 'Sri Lanka', 'SD' => 'Sudan', 'SR' => 'Suriname', 'SJ' => 'Svalbard And Jan Mayen', 'SZ' => 'Swaziland', 'SE' => 'Sweden', 'CH' => 'Switzerland', 'SY' => 'Syrian Arab Republic', 'TW' => 'Taiwan', 'TJ' => 'Tajikistan', 'TZ' => 'Tanzania', 'TH' => 'Thailand', 'TL' => 'Timor-Leste', 'TG' => 'Togo', 'TK' => 'Tokelau', 'TO' => 'Tonga', 'TT' => 'Trinidad And Tobago', 'TN' => 'Tunisia', 'TR' => 'Turkey', 'TM' => 'Turkmenistan', 'TC' => 'Turks And Caicos Islands', 'TV' => 'Tuvalu', 'UG' => 'Uganda', 'UA' => 'Ukraine', 'AE' => 'United Arab Emirates', 'GB' => 'UK', 'US' => 'USA', 'UM' => 'United States Outlying Islands', 'UY' => 'Uruguay', 'UZ' => 'Uzbekistan', 'VU' => 'Vanuatu', 'VE' => 'Venezuela', 'VN' => 'Viet Nam', 'VG' => 'Virgin Islands, British', 'VI' => 'Virgin Islands, U.S.', 'WF' => 'Wallis And Futuna', 'EH' => 'Western Sahara', 'YE' => 'Yemen', 'ZM' => 'Zambia', 'ZW' => 'Zimbabwe');
	foreach ($countries as $k => $v)
		$str[] = "$v:$v";

	return implode(";",$str);
}

// server-validation & custom events work on excel view, but only first (pk) and changed column is available
// $e["on_update"] = array("update_client", null, true);
// $g->set_events($e);

function update_client($data)
{
	/*
		These comments are just to show the input param format

		$data => Array
		(
			[id] => 2
			[params] => Array
				(
					[phone] => 400
				)

		)
	*/

	if (isset($data["params"]["phone"]))
	{
		if ($data["params"]["phone"] < 100)
			phpgrid_error("phone must be greater than 100");

		$str = "UPDATE customers SET phone={$data["params"]["phone"]}+10
						WHERE customerid = {$data["CustomerID"]}";

		global $g;
		$g->execute_query($str);
		die;
	}
}

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
	<script src="//cdnjs.cloudflare.com/ajax/libs/jquery.mask/0.9.0/jquery.mask.min.js"></script>
	
</head>
<body>
	<script type="text/javascript">
	function fix_textarea_enter()
	{
		$('textarea').keydown(
								function(event) 
								{ 
									if (!event.ctrlKey && event.keyCode == 13)
									{
										return true;
									}
									else
									{
										// if ctrl+enter
										if(event.keyCode == 13) 
										{
											event.stopImmediatePropagation();
											var $txt = jQuery(this);
											var caretPos = $txt[0].selectionStart;
											var textAreaTxt = $txt.val();
											var txtToAdd = "\n";
											$txt.val(textAreaTxt.substring(0, caretPos) + txtToAdd + textAreaTxt.substring(caretPos) );
											$txt[0].selectionStart = caretPos + txtToAdd.length;
											$txt[0].selectionEnd = caretPos + txtToAdd.length;
										}
									}
									
								}
							);
	}

	function get_countrycode(str)
	{
		var code = {};
		code["Afghanistan"] = "AF"; code["Aland Islands"] = "AX"; code["Albania"] = "AL"; code["Algeria"] = "DZ"; code["American Samoa"] = "AS"; code["Andorra"] = "AD"; code["Angola"] = "AO"; code["Anguilla"] = "AI"; code["Antarctica"] = "AQ"; code["Antigua And Barbuda"] = "AG"; code["Argentina"] = "AR"; code["Armenia"] = "AM"; code["Aruba"] = "AW"; code["Australia"] = "AU"; code["Austria"] = "AT"; code["Azerbaijan"] = "AZ"; code["Bahamas"] = "BS"; code["Bahrain"] = "BH"; code["Bangladesh"] = "BD"; code["Barbados"] = "BB"; code["Belarus"] = "BY"; code["Belgium"] = "BE"; code["Belize"] = "BZ"; code["Benin"] = "BJ"; code["Bermuda"] = "BM"; code["Bhutan"] = "BT"; code["Bolivia"] = "BO"; code["Bosnia And Herzegovina"] = "BA"; code["Botswana"] = "BW"; code["Bouvet Island"] = "BV"; code["Brazil"] = "BR"; code["British Indian Ocean Territory"] = "IO"; code["Brunei Darussalam"] = "BN"; code["Bulgaria"] = "BG"; code["Burkina Faso"] = "BF"; code["Burundi"] = "BI"; code["Cambodia"] = "KH"; code["Cameroon"] = "CM"; code["Canada"] = "CA"; code["Cape Verde"] = "CV"; code["Cayman Islands"] = "KY"; code["Central African Republic"] = "CF"; code["Chad"] = "TD"; code["Chile"] = "CL"; code["China"] = "CN"; code["Christmas Island"] = "CX"; code["Cocos (Keeling) Islands"] = "CC"; code["Colombia"] = "CO"; code["Comoros"] = "KM"; code["Congo"] = "CG"; code["Congo, Democratic Republic"] = "CD"; code["Cook Islands"] = "CK"; code["Costa Rica"] = "CR"; code["Cote D'Ivoire"] = "CI"; code["Croatia"] = "HR"; code["Cuba"] = "CU"; code["Cyprus"] = "CY"; code["Czech Republic"] = "CZ"; code["Denmark"] = "DK"; code["Djibouti"] = "DJ"; code["Dominica"] = "DM"; code["Dominican Republic"] = "DO"; code["Ecuador"] = "EC"; code["Egypt"] = "EG"; code["El Salvador"] = "SV"; code["Equatorial Guinea"] = "GQ"; code["Eritrea"] = "ER"; code["Estonia"] = "EE"; code["Ethiopia"] = "ET"; code["Falkland Islands (Malvinas)"] = "FK"; code["Faroe Islands"] = "FO"; code["Fiji"] = "FJ"; code["Finland"] = "FI"; code["France"] = "FR"; code["French Guiana"] = "GF"; code["French Polynesia"] = "PF"; code["French Southern Territories"] = "TF"; code["Gabon"] = "GA"; code["Gambia"] = "GM"; code["Georgia"] = "GE"; code["Germany"] = "DE"; code["Ghana"] = "GH"; code["Gibraltar"] = "GI"; code["Greece"] = "GR"; code["Greenland"] = "GL"; code["Grenada"] = "GD"; code["Guadeloupe"] = "GP"; code["Guam"] = "GU"; code["Guatemala"] = "GT"; code["Guernsey"] = "GG"; code["Guinea"] = "GN"; code["Guinea-Bissau"] = "GW"; code["Guyana"] = "GY"; code["Haiti"] = "HT"; code["Heard Island & Mcdonald Islands"] = "HM"; code["Holy See (Vatican City State)"] = "VA"; code["Honduras"] = "HN"; code["Hong Kong"] = "HK"; code["Hungary"] = "HU"; code["Iceland"] = "IS"; code["India"] = "IN"; code["Indonesia"] = "ID"; code["Iran, Islamic Republic Of"] = "IR"; code["Iraq"] = "IQ"; code["Ireland"] = "IE"; code["Isle Of Man"] = "IM"; code["Israel"] = "IL"; code["Italy"] = "IT"; code["Jamaica"] = "JM"; code["Japan"] = "JP"; code["Jersey"] = "JE"; code["Jordan"] = "JO"; code["Kazakhstan"] = "KZ"; code["Kenya"] = "KE"; code["Kiribati"] = "KI"; code["Korea"] = "KR"; code["Kuwait"] = "KW"; code["Kyrgyzstan"] = "KG"; code["Lao People's Democratic Republic"] = "LA"; code["Latvia"] = "LV"; code["Lebanon"] = "LB"; code["Lesotho"] = "LS"; code["Liberia"] = "LR"; code["Libyan Arab Jamahiriya"] = "LY"; code["Liechtenstein"] = "LI"; code["Lithuania"] = "LT"; code["Luxembourg"] = "LU"; code["Macao"] = "MO"; code["Macedonia"] = "MK"; code["Madagascar"] = "MG"; code["Malawi"] = "MW"; code["Malaysia"] = "MY"; code["Maldives"] = "MV"; code["Mali"] = "ML"; code["Malta"] = "MT"; code["Marshall Islands"] = "MH"; code["Martinique"] = "MQ"; code["Mauritania"] = "MR"; code["Mauritius"] = "MU"; code["Mayotte"] = "YT"; code["Mexico"] = "MX"; code["Micronesia, Federated States Of"] = "FM"; code["Moldova"] = "MD"; code["Monaco"] = "MC"; code["Mongolia"] = "MN"; code["Montenegro"] = "ME"; code["Montserrat"] = "MS"; code["Morocco"] = "MA"; code["Mozambique"] = "MZ"; code["Myanmar"] = "MM"; code["Namibia"] = "NA"; code["Nauru"] = "NR"; code["Nepal"] = "NP"; code["Netherlands"] = "NL"; code["Netherlands Antilles"] = "AN"; code["New Caledonia"] = "NC"; code["New Zealand"] = "NZ"; code["Nicaragua"] = "NI"; code["Niger"] = "NE"; code["Nigeria"] = "NG"; code["Niue"] = "NU"; code["Norfolk Island"] = "NF"; code["Northern Mariana Islands"] = "MP"; code["Norway"] = "NO"; code["Oman"] = "OM"; code["Pakistan"] = "PK"; code["Palau"] = "PW"; code["Palestinian Territory, Occupied"] = "PS"; code["Panama"] = "PA"; code["Papua New Guinea"] = "PG"; code["Paraguay"] = "PY"; code["Peru"] = "PE"; code["Philippines"] = "PH"; code["Pitcairn"] = "PN"; code["Poland"] = "PL"; code["Portugal"] = "PT"; code["Puerto Rico"] = "PR"; code["Qatar"] = "QA"; code["Reunion"] = "RE"; code["Romania"] = "RO"; code["Russian Federation"] = "RU"; code["Rwanda"] = "RW"; code["Saint Barthelemy"] = "BL"; code["Saint Helena"] = "SH"; code["Saint Kitts And Nevis"] = "KN"; code["Saint Lucia"] = "LC"; code["Saint Martin"] = "MF"; code["Saint Pierre And Miquelon"] = "PM"; code["Saint Vincent And Grenadines"] = "VC"; code["Samoa"] = "WS"; code["San Marino"] = "SM"; code["Sao Tome And Principe"] = "ST"; code["Saudi Arabia"] = "SA"; code["Senegal"] = "SN"; code["Serbia"] = "RS"; code["Seychelles"] = "SC"; code["Sierra Leone"] = "SL"; code["Singapore"] = "SG"; code["Slovakia"] = "SK"; code["Slovenia"] = "SI"; code["Solomon Islands"] = "SB"; code["Somalia"] = "SO"; code["South Africa"] = "ZA"; code["South Georgia And Sandwich Isl."] = "GS"; code["Spain"] = "ES"; code["Sri Lanka"] = "LK"; code["Sudan"] = "SD"; code["Suriname"] = "SR"; code["Svalbard And Jan Mayen"] = "SJ"; code["Swaziland"] = "SZ"; code["Sweden"] = "SE"; code["Switzerland"] = "CH"; code["Syrian Arab Republic"] = "SY"; code["Taiwan"] = "TW"; code["Tajikistan"] = "TJ"; code["Tanzania"] = "TZ"; code["Thailand"] = "TH"; code["Timor-Leste"] = "TL"; code["Togo"] = "TG"; code["Tokelau"] = "TK"; code["Tonga"] = "TO"; code["Trinidad And Tobago"] = "TT"; code["Tunisia"] = "TN"; code["Turkey"] = "TR"; code["Turkmenistan"] = "TM"; code["Turks And Caicos Islands"] = "TC"; code["Tuvalu"] = "TV"; code["Uganda"] = "UG"; code["Ukraine"] = "UA"; code["United Arab Emirates"] = "AE"; code["UK"] = "GB"; code["USA"] = "US"; code["United States Outlying Islands"] = "UM"; code["Uruguay"] = "UY"; code["Uzbekistan"] = "UZ"; code["Vanuatu"] = "VU"; code["Venezuela"] = "VE"; code["Viet Nam"] = "VN"; code["Virgin Islands, British"] = "VG"; code["Virgin Islands, U.S."] = "VI"; code["Wallis And Futuna"] = "WF"; code["Western Sahara"] = "EH"; code["Yemen"] = "YE"; code["Zambia"] = "ZM"; code["Zimbabwe"] = "ZW";
	
		str = jQuery.trim(str);
		if (code[str] != undefined)
			return code[str].toLowerCase();
		else
			return '';
	}
	
	// fix for datepicker JS error in excel view
	$.extend($.datepicker, {
	_doKeyDown: function(event) {}
	});
	</script>	

	<div style="margin:0px">
	<?php echo $out?>
	</div>
	
	<style>
	/* compact excel row height & highlight row option */
	/* .ui-jqgrid tr.jqgrow td { 
		height: 22px; 
		padding:2px 2px; 
		vertical-align:middle; 
	}
	.edit-cell.ui-state-highlight
	{
		background: #fbec88;
		padding:1px 2px !important; 
	}          
	*/
	</style>

</body>
</html>