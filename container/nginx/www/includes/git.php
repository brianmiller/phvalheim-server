<?php
function getGitReleases($gitRepo,$clientVersionsToRender) {
	$git = "/usr/bin/git";
	$gitReleases = shell_exec("$git ls-remote --refs --tags $gitRepo | cut -d/ -f3- | sort -V -r | head -$clientVersionsToRender | tr '\n' ' '");
	$gitReleases = explode(" ", $gitReleases);
	return $gitReleases;

}

?>
