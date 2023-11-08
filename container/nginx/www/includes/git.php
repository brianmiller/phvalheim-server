<?php
function getGitRelease($gitRepo) {
	#$git = "/usr/bin/git";
	#$gitReleases = shell_exec("$git ls-remote --refs --sort='version:refname' --tags $gitRepo | cut -d/ -f3- | sort -r | head -$clientVersionsToRender | tr '\n' ' '");
	#$gitReleases = explode(" ", $gitReleases);
	#return $gitReleases;
	$latestRelease = shell_exec("curl -s -I $gitRepo/releases/latest|awk -F '/' '/^location/ {print  substr(\$NF, 1, length(\$NF)-1)}'");
	return $latestRelease;

}

?>
