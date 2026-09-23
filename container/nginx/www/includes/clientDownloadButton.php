<?php
include '../includes/git.php';
include '../includes/phvalheim-frontend-config.php';

// The Flatpak bundle first appears in phvalheim-client 2.0.13. Every earlier tag still has a
// .msi, a .tar.gz, a .deb and a .rpm in builds/, but no .flatpak -- so an ungated link would
// 404 for anyone rendering an older release. $clientVersionsToRender is 1 by default, which
// means only the newest tag is ever shown, but it is a setting and raising it is the whole
// point of it; the gate is what makes that safe.
if (!defined('PHVALHEIM_CLIENT_FIRST_FLATPAK')) {
	define('PHVALHEIM_CLIENT_FIRST_FLATPAK', '2.0.13');
}

function populateDownloadMenu($operatingSystem,$phValheimClientGitRepo,$clientVersionsToRender) {
	if($operatingSystem == "Windows"){
		$downloadHeaderTitle = "<b class='client_download_tooltip'>PhValheim Client for Windows</b>";
	}

        if($operatingSystem == "Linux"){
                $downloadHeaderTitle = "<b class='client_download_tooltip'>PhValheim Client for Linux</b>";
        }

        if($operatingSystem == "Mac"){
                $downloadHeaderTitle = "<b class='client_download_tooltip'>PhValheim Client for macOS</b>";
        }


	function populateDownloadLinks($operatingSystem,$phValheimClientGitRepo,$clientVersionsToRender) {
		$phValheimClientGitReleases = getGitReleases($phValheimClientGitRepo,$clientVersionsToRender);

		foreach ($phValheimClientGitReleases as $release) {
			if(!empty($release)) {


				if($operatingSystem == "Windows"){
					echo "
						<div class='client_download_os_icon $release'>
							<td class='client_download_cell'>
								<a class='client_download_os_icon' target='_blank' href='$phValheimClientGitRepo/raw/master/builds/phvalheim-client-$release-x86_64.msi'>
									<img class='client_download_link_colorizer' src='../images/win11.png'>
									<p class='versionLabel'>Windows</p>
								</a>
							</td>
						</div>
					";
				}


                                if($operatingSystem == "Linux"){
                                        echo "
                                                <div class='client_download_os_icon $release'>
                                                        <td class='client_download_cell'>
                                                                <a class='client_download_os_icon' target='_blank' href='$phValheimClientGitRepo/raw/master/builds/phvalheim-client-$release-universal-x86_64.tar.gz'>
                                                                        <img class='client_download_link_colorizer' src='../images/linux.png'>
                                                                        <p class='versionLabel'>Universal</p>
                                                                </a>
                                                        </td>
                                                        <td class='client_download_cell'>
                                                                <a class='client_download_os_icon' target='_blank' href='$phValheimClientGitRepo/raw/master/builds/phvalheim-client-$release-x86_64.deb'>
                                                                        <img class='client_download_link_colorizer' src='../images/ubuntu.png'>
                                                                        <p class='versionLabel'>Ubuntu</p>
                                                                </a>
                                                        </td>
                                                        <td class='client_download_cell'>
                                                                <a class='client_download_os_icon' target='_blank' href='$phValheimClientGitRepo/raw/master/builds/phvalheim-client-$release-x86_64.rpm'>
                                                                        <img class='client_download_link_colorizer' src='../images/fedora.png'>
                                                                        <p class='versionLabel'>Fedora</p>
                                                                </a>
                                                        </td>
                                        ";

                                        // Flatpak: the only one of the four that installs on an immutable
                                        // system (SteamOS, Bazzite), where there is nowhere to put a .deb or
                                        // a .rpm. Older client tags have no .flatpak to link to.
                                        //
                                        // Unlike the other three this one does NOT download on click -- a
                                        // Flatpak bundle is useless without the install command, and on a bare
                                        // window manager it is useless without the XDG_DATA_DIRS step too. The
                                        // click opens the instructions modal, which carries the download button.
                                        // href and target stay real so ctrl-click and Save Link As still work,
                                        // and openFlatpakInstall() reads the URL back off this anchor -- the
                                        // modal is never told a version, so it cannot disagree with the link.
                                        if (version_compare($release, PHVALHEIM_CLIENT_FIRST_FLATPAK, '>=')) {
                                                echo "
                                                        <td class='client_download_cell'>
                                                                <a class='client_download_os_icon' target='_blank' rel='noopener' onclick='return openFlatpakInstall(this);' href='$phValheimClientGitRepo/raw/master/builds/phvalheim-client-$release-x86_64.flatpak'>
                                                                        <img class='client_download_link_colorizer' src='../images/flatpak.svg'>
                                                                        <p class='versionLabel'>Flatpak</p>
                                                                </a>
                                                        </td>
                                                ";
                                        }

                                        echo "
                                                </div>
                                        ";
                                }
			}


		}
	}

	// macOS gets a modal instead of the popover
	if($operatingSystem == "Mac"){
		echo "<button type=\"button\" class=\"btn btn-sm btn-outline-download client_download_button_font\" data-bs-toggle=\"modal\" data-bs-target=\"#macInstallModal\">Download PhValheim Client</button>";
	} else {
		echo "
        <button type=\"button\" class=\"btn btn-sm btn-outline-download client_download_button_font\" tabindex=\"0\" data-bs-trigger=\"focus\" data-bs-toggle=\"popover\" data-bs-placement=\"bottom\" data-bs-offset=\"-30,10\" data-bs-title=\"$downloadHeaderTitle\" data-bs-html=\"true\"
        data-bs-content=\"
			<table class='center' border=0 style='width:100%;'>
	";

						populateDownloadLinks($operatingSystem,$phValheimClientGitRepo,$clientVersionsToRender);

	echo "
			</table>

			<table class='center' border=0 style='width:100%;'>
						<td style='text-align:center; color:#34e2e2 !important; padding-top: 0px;'>---------------------------</td>
			</table>
			<table class='center' border=0 style='width:100%;'>
						<td><p class='client_download_tooltip_otherbuilds'><a class='client_download_tooltip_otherbuilds' target='_blank' href='$phValheimClientGitRepo/tree/master/builds'>looking for other builds?</a></p></td>

			</table>

	\">Download PhValheim Client</button>";
	}
}

?>

















