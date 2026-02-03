<?php
include '../includes/git.php';
include '../includes/phvalheim-frontend-config.php';


function populateDownloadMenu($operatingSystem,$phValheimClientGitRepo,$clientVersionsToRender) {
	if($operatingSystem == "Windows"){
		$downloadHeaderTitle = "<b class='client_download_tooltip'>PhValheim Client for Windows</b>";
	}

        if($operatingSystem == "Linux"){
                $downloadHeaderTitle = "<b class='client_download_tooltip'>PhValheim Client for Linux</b>";
        }


	function populateDownloadLinks($operatingSystem,$phValheimClientGitRepo,$clientVersionsToRender) {
		$phValheimClientGitReleases = getGitReleases($phValheimClientGitRepo,$clientVersionsToRender);

		foreach ($phValheimClientGitReleases as $release) {
			if(!empty($release)) {


				if($operatingSystem == "Windows"){
					echo "
						<div class='client_download_os_icon $release'>
							<td>
								<a class='client_download_os_icon' target='_blank' href='$phValheimClientGitRepo/raw/master/builds/phvalheim-client-$release-x86_64.msi'>
									<img class='client_download_link_colorizer' src='../images/win11.png'>
									<p class='versionLabel'>Download</p>
								</a>
							</td>
						</div>
					";
				}


                                if($operatingSystem == "Linux"){
                                        echo "
                                                <div class='client_download_os_icon $release'>
                                                        <td>
                                                                <a class='client_download_os_icon' target='_blank' href='$phValheimClientGitRepo/raw/master/builds/phvalheim-client-$release-universal-x86_64.tar.gz'>
                                                                        <img class='client_download_link_colorizer' src='../images/linux.png'>
                                                                        <p class='versionLabel'>Download</p>
                                                                </a>
                                                        </td>
                                                        <td>
                                                                <a class='client_download_os_icon' target='_blank' href='$phValheimClientGitRepo/raw/master/builds/phvalheim-client-$release-x86_64.deb'>
                                                                        <img class='client_download_link_colorizer' src='../images/ubuntu.png'>
                                                                        <p class='versionLabel'>Download</p>
                                                                </a>
                                                        </td>
                                                        <td>
                                                                <a class='client_download_os_icon' target='_blank' href='$phValheimClientGitRepo/raw/master/builds/phvalheim-client-$release-x86_64.rpm'>
                                                                        <img class='client_download_link_colorizer' src='../images/fedora.png'>
                                                                        <p class='versionLabel'>Download</p>
                                                                </a>
                                                        </td>
                                                </div>
                                        ";
                                }
			}


		}
	}

	echo "
        <button type=\"button\" class=\"btn btn-sm btn-outline-download client_download_button_font\" data-bs-trigger=\"click\" data-bs-toggle=\"popover\" data-bs-placement=\"bottom\" data-bs-offset=\"-30,10\" data-bs-title=\"$downloadHeaderTitle\" data-bs-html=\"true\"
        data-bs-content=\"
			<table class='center' border=0 style='width:100%;'>
	";
	
						populateDownloadLinks($operatingSystem,$phValheimClientGitRepo,$clientVersionsToRender);
	
	echo "
			</table>

			<table class='center top_line' border=0 style='width:100%;'>
						<td><p class='client_download_tooltip_otherbuilds'><a class='client_download_tooltip_otherbuilds' target='_blank' href='$phValheimClientGitRepo/tree/master/builds'>looking other builds?</a></p></td>

			</table>
	
	\">Download PhValheim Client</button>";
}

?>

















