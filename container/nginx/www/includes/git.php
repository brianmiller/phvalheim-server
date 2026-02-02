<?php
function getGitReleases($gitRepo,$clientVersionsToRender) {
	$git = "/usr/bin/git";
	$gitReleases = shell_exec("$git ls-remote --refs --sort='version:refname' --tags $gitRepo | cut -d/ -f3- | sort -r | head -$clientVersionsToRender | tr '\n' ' '");
	$gitReleases = explode(" ", $gitReleases);
	return $gitReleases;

}

?>
