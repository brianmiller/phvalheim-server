<!-- Original Classic login layout; authentication remains in public/index.php. -->
<div class="login_header">Welcome to PhValheim</div>

<div class="user-modal is-visible">
		<div class="user-modal-container">
			<ul class="switcher">
				<li><a class="selected" href="#login">Sign in</a></li>
				<li><a href="#signup">About</a></li>
			</ul>

			<div id="login" class="is-selected">

				<p class="center pri-color" style="width:auto !important;">Welcome to PhValheim's World Dashboard</p>

				<form class="form" id="authenticated_form" method="post" action="https://steamcommunity.com/openid/login">
					<p class="center alt-color" style="width:auto !important;">Click below to sign in with Steam</p>
<?php if (($_GET['auth'] ?? '') === 'failed') { ?>
					<p class="center" id="steamAuthFailed" role="alert" style="width:auto !important;color:#e06666;">Steam did not confirm that sign-in. Please try again.</p>
<?php } ?>
					<div class="centered" id="steamSignInButton">
						<input type="hidden" name="openid.identity" value="http://specs.openid.net/auth/2.0/identifier_select">
						<input type="hidden" name="openid.claimed_id" value="http://specs.openid.net/auth/2.0/identifier_select">
						<input type="hidden" name="openid.ns" value="http://specs.openid.net/auth/2.0">
						<input type="hidden" name="openid.mode" value="checkid_setup">
						<input type="hidden" name="openid.realm" value="<?php echo htmlspecialchars($steamRealm, ENT_QUOTES, 'UTF-8')?>">
						<input type="hidden" name="openid.return_to" value="<?php echo htmlspecialchars($steamRealm, ENT_QUOTES, 'UTF-8')?>/authenticated.php">

						<input type="image" name="submit" src="https://steamcdn-a.akamaihd.net/steamcommunity/public/images/steamworks_docs/english/sits_large_border.png" border="0" alt="Sign in through Steam">

					</div>
				</form>


			</div>

			<div id="signup">
				<form class="form">

					<p>
						<label class="full-width alt-color">What is PhValheim?</label>
					</p>

                                        <p>
                                                <label class="full-width">PhValheim is a server+client combination that keeps remote Valheim worlds in-sync with remote clients, ensuring all players have a controlled and identical experience with any combination of mods.</label>
                                        </p>

                                        <p>
                                                <label class="full-width">For more information, visit PhValheim's <a target="_blank" rel="noopener noreferrer" href="https://github.com/brianmiller/phvalheim-server">GitHub page</a> and <a target="_blank" rel="noopener noreferrer" href="https://discord.gg/8RMMrJVQgy">Discord community</a>.</label>
                                        </p>

				</form>

			</div>

		</div>
	</div>
<noscript><style>.classic-login #signup { display:block; }</style></noscript>
