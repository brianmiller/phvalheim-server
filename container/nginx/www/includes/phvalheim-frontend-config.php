<?php


#Phvalheim database
$pdo = new PDO('mysql:host=localhost;dbname=phvalheim', 'phvalheim_user', 'phvalheim_secretpassword');


#Log configs
#Log exclusions are insensitive
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
	"NULL",
	"Failed to place",
	"CreateDirectory",
	"Tried to access Steam interface"
);


#highlighter: "keyword_to_highlight" => "alert type" --keywords are insensitive, types=error,warn,notice,
#highlighter colors
#$logHighlightError = "#ffbaba";
#$logHighlightErrorDarker = "#BB4347";
#$logHighlightWarn = "#ffefb3";
#$logHighlightWarnDarker = "#805F26";
#$logHighlightNotice = "#bde5f9";
#$logHighlightNoticeDarker = "#0e5da2";
#$logHighlightGreen = "#dff2bf";
#$logHighlightGreenDarker = "#486F1E";
$logHighlightError = "#000000";
$logHighlightErrorDarker = "#FF0000";
$logHighlightWarn = "#000000";
$logHighlightWarnDarker = "#FFCD00";
$logHighlightNotice = "#000000";
$logHighlightNoticeDarker = "#00F7FF";
$logHighlightGreen = "#000000";
$logHighlightGreenDarker = "#13FF00";


#highlighter keyword array
$logHighlight = array(
        "error" => "error",
        "fail" => "error",
        "warning" => "warn",
        "warn" => "warn",
        "This is a success message" => "notice",
        "Loaded locations" => "notice",
        "plugins to load" => "notice",
        "World saved" => "notice",
        "Setting -savedir to:" => "notice",
        "isModded:" => "notice",
        "notice" => "notice"
);

?>
