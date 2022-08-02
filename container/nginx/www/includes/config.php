<?php

#Phvalheim database
$pdo = new PDO('mysql:host=localhost;dbname=phvalheim', 'phvalheim_user', 'phvalheim_secretpassword');

#Global configs
$phvalheimHost = "37648-dev1.phospher.com:8080";
$basePort = 4000; //the port we start incrementing from
$alias = "37648-dev1"; //this is DNS CNAME record that will be our external endpoint (outside interface of inbound NAT, multi-level NATs are ok.)
$domain = "phospher.com"; //our domain

#Log configs
#Log exclusions: insensitive
$logExclusions = array(
	"Fallback handler could not load library",
	"Load DLL",
	"Base:",
	"ERROR: Shader",
	"WARNING: Shader",
	"Redirecting to",
	"Convex Mesh",
	"The shader",
	"Main Camera",
	"HDR Render Texture",
	"Only custom filters can be played.",
	"GfxDevice",
	"Audioman already exist",
	"unused Assets to reduce memory usage",
	"CreateObjectMapping",
	"Discovering subsystems at path",
	"Renderer: Null Device",
	"NULL"
);

#highlighter: "keyword_to_highlight" => "alert type" --keywords are insensitive, types=error,warn,notice,
#highlighter colors
$logHighlightError = "#ffbaba";
$logHighlightErrorDarker = "#e95358";
$logHighlightWarn = "#ffefb3";
$logHighlightWarnDarker = "#bd8e3d";
$logHighlightNotice = "#bde5f9";
$logHighlightNoticeDarker = "#3c81b9";
$logHighlightGreen = "#dff2bf";
$logHighlightGreenDarker = "#75a540";



#highlighter keyword array
$logHighlight = array(
	"error" => "error",
	"fail" => "error",
	"warning" => "warn",
	"This is a success message" => "notice",
	"Loaded locations" => "notice",
	"plugins to load" => "notice",
	"World saved" => "notice",
	"Setting -savedir to:" => "notice",
	"isModded:" => "notice",
	"notice" => "notice"
);

?>
