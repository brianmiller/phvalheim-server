<?php
include '../includes/git.php';
include '../includes/phvalheim-frontend-config.php';


function populateDownloadMenu($operatingSystem,$phValheimClientGitRepo) {
	if($operatingSystem == "Windows"){
		$downloadHeaderTitle = "<b class='client_download_tooltip'>PhValheim Client for Windows</b>";
	}

        if($operatingSystem == "Linux"){
                $downloadHeaderTitle = "<b class='client_download_tooltip'>PhValheim Client for Linux</b>";
        }


	function populateDownloadLinks($operatingSystem,$phValheimClientGitRepo) {
		$release = getGitRelease($phValheimClientGitRepo);

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

	echo "
        <button type=\"button\" class=\"btn btn-sm btn-outline-download client_download_button_font\" data-trigger=\"focus\" data-toggle=\"popover\" data-placement=\"bottom\" title=\"$downloadHeaderTitle\" data-html=\"true\" 
        data-content=\"
			<table class='center' border=0 style='width:100%;'>
	";
	
						populateDownloadLinks($operatingSystem,$phValheimClientGitRepo);
	
	echo "
			</table>

			<table class='center top_line' border=0 style='width:100%;'>
						<td><p class='client_download_tooltip_otherbuilds'><a class='client_download_tooltip_otherbuilds' target='_blank' href='$phValheimClientGitRepo/tree/master/builds'>looking for other builds?</a></p></td>

			</table>
	
	\">Download PhValheim Client</button>";
}

?>
