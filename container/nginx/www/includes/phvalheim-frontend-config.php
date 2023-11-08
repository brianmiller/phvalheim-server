<?php


#Phvalheim database
$pdo = new PDO('mysql:host=localhost;dbname=phvalheim', 'phvalheim_user', 'phvalheim_secretpassword');

#How many versions to display in client download window. Leave this at 1 for now.
#The download tooltip doesn't handle more than 1 very well.  I'll make this better
#if folks want it. Feel free to play with it. 
$clientVersionsToRender = 1;

#Git repos to use for client version checking and downloads
$phValheimClientGitRepo = "https://github.com/brianmiller/phvalheim-client";

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
	"Tried to access Steam interface",
	"Failed to load steamconsole.so",
	"lc-messages-dir",
	"dlmopen steamservice.so failed: steamservice.so:",
	"failed to load",
	"shader",
	"no mesh data",
	"No override",
	"no texture"
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
$logHighlightMagenta = "#000000";
$logHighlightMagentaDarker = "#FF00FF";

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
        "notice" => "notice",
	"Get create world" => "notice",
	"PhValheim Companion:" => "notice",
	"Seed:" => "notice",
	"Valheim version:" => "notice",
	"BepInExPack Valheim version" => "notice",
	"Detected Unity version:" => "notice",
	" BepInEx] Loading [" => "magenta"
);

?>
