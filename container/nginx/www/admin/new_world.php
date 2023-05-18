<?php

include '/opt/stateless/nginx/www/includes/config_env_puller.php';
include '/opt/stateless/nginx/www/includes/phvalheim-frontend-config.php';
include '../includes/db_sets.php';
include '../includes/db_gets.php';


function populateModList($pdo,$getAllModsLatestVersion) {
        foreach ($getAllModsLatestVersion as $row) {
                $modUUID = $row['moduuid'];
                $modName = $row['name'];
		$modName = substr($modName,0,64);
                $modNameLen = strlen($modName);

                if ($modNameLen == 64) {
                        $modName = $modName . "...";
                }

                $modOwner = $row['owner'];
		$modURL = $row['url'];

		$modLastUpdated = $row['version_date_created'];

                $modVersion = $row['version'];
                $modVersion = str_replace("\"","",$modVersion);

                print "<tr>\n";
                print "<td style='width:1px;'><input name='thunderstore_mods[]' value='" . $modUUID . "' type='checkbox' /></input></td>\n";
                print "<td style='padding-right:15px;'><a target='_blank' href='$modURL'>$modName</a></td>\n";
                print "<td>$modOwner</td>\n";
		print "<td>$modLastUpdated</td>\n";
                print "<td>$modVersion</td>\n";
	}
}


if (!empty($_POST)) {
  $world = $_POST['world'];

  if (!empty($_POST['seed'])) {
        $seed = $_POST['seed'];
  } else {
        $seed = $defaultSeed;
  }

  # add new world to database
  $addWorld = addWorld($pdo,$world,$gameDNS,$seed);
  if ($addWorld == 0) {
        $msg = "<label style='font-size: 16px;font-style:italic;'>World '$world' created...</label>";
  

	# add mods, if any are selected
	if (!empty($_POST['thunderstore_mods'])) {
  	  $thunderstore_mods = $_POST['thunderstore_mods'];
          foreach ($thunderstore_mods as $mod) {
                  addModToWorld($pdo,$world,$mod);
          }
	}

        # go back to admin home after creation 
        header("Location: index.php");
  }

  if ($addWorld == 2) {
        $msg = "<label style='font-size: 16px;font-style:italic;'>World '$world' already exists...</label>";
  }

}

$getAllModsLatestVersion = getAllModsLatestVersion($pdo);

?>

<!DOCTYPE HTML>
<html>
	<head>
                <link rel="stylesheet" type="text/css" href="/css/jquery.dataTables.css?refreshcss=<?php echo rand(100, 1000)?>">
                <link rel="stylesheet" type="text/css" href="/css/phvalheimStyles.css?refreshcss=<?php echo rand(100, 1000)?>">
		<script type="text/javascript" charset="utf8" src="/js/jquery-3.6.0.js"></script>
		<script type="text/javascript" charset="utf8" src="/js/jquery.dataTables.js"></script>
		<link rel="stylesheet" type="text/css" href="/css/multicheckbox.css?refreshcss=<?php echo rand(100, 1000)?>">
                <script>
                        $(document).ready( function () {
                                $('#modtable').DataTable({

                                        "rowCallback": function( row, data, index ) {
                                            if(index%2 == 0){
                                                $(row).removeClass('myodd myeven');
                                                $(row).addClass('myodd');
                                            }else{
                                                $(row).removeClass('myodd myeven');
                                                $(row).addClass('myeven');
                                            }
                                          },

                                        lengthMenu: [
                                            [20, 50, 75, -1],
                                            [20, 50, 75, 'All'],
                                        ],

                                        columnDefs: [
                                         { orderable: false, targets: [ 0 ] },
                                        ],
                                });
                        });

                        function fixer() {
                                $('#modtable').DataTable().page.len('-1').draw();
				$('#modtable').DataTable().search( '' ).draw();
                        }

                        // only allow the form to be submitted once per page load
                        var form_enabled = true;  
                        $().ready(function(){     
                               $('#new_world').on('submit', function(){
                                       if (form_enabled) {
                                               form_enabled = false;
                                               return true;
                                       }
        
                                       return false;
                                });
                        });

                </script>

	</head>

	<body>
	      <div>
		<form id="new_world" name="new_world" method="post" action="new_world.php" onSubmit="fixer()">

		      <div style="padding-top:10px;" class="">
                        <table class="outline" style="width:auto;margin-left:auto;margin-right:auto;vertical-align:middle;border-collapse:collapse;" border=0>
                                <th class="bottom_line alt-color" colspan="8">New World</th>
				<tr>
				<td style="padding-top:5px;" colspan="8"></td>
				<tr>
                                <td style="width:2px;"></td> <!-- left spacer -->
				<td class="align-left" style="">World Name:</td>
				<td class="align-left" style="width:auto;"><input type="text" maxlength="30" name="world" id="world" required></td>
                                <td class="center highlight-color" style="width:50px;">|</td> <!-- middle spacer -->
				<td class="align-left" style="margin-left:50px;">World Seed:</td>
                                <td class="align-left"><input type="text" name="seed" id="seed" maxlength="10" placeholder="<?php echo $defaultSeed ?>"></td>
                                <td style="width:2px;"></td> <!-- right spacer -->
				<tr>
				<td style="padding-top:5px;" colspan="8"></td>
				<tr>
				<th class="pri-color" colspan="8"><?php echo $msg ?></th>
                        </table>
		      </div>

		      <div style="max-width:1600px;margin:auto;padding:10px;" class="">
			<table id="modtable" style="margin-top:45px !important;width:100%;" align=center border=0 class="display outline">
				<thead>
					<th class="alt-color">Toggle</th>
					<th class="alt-color">Name</th>
					<th class="alt-color">Author</th>
					<th class="alt-color">Last Updated</th>
					<th class="alt-color">Version</th>
				</thead>
				<tbody>
        	                        <?php populateModList($pdo,$getAllModsLatestVersion); ?>
				</tbody>
			</table>
		      </div>
			<table class="center">
				<td colspan=5 align=center>
					<a href='index.php'><button class="sm-bttn" type="button">Back</button></a>
					<button id='submit_button' name='submit' class="sm-bttn" type="submit">Create</button>
				</td>
			</table>

		</form>
	      </div>

		<script>
                        /* restrict special chars in input field */
                        $('#world').bind('input', function() {
                          var c = this.selectionStart,
                              r = /[^a-zA-Z0-9\\s]/gi,
                              v = $(this).val();
                          if(r.test(v)) {
                            $(this).val(v.replace(r, ''));
                            c--;
                          }
                          this.setSelectionRange(c, c);
                        });

                        $('#seed').bind('input', function() {
                          var c = this.selectionStart,
                              r = /[^a-zA-Z0-9\\s]/gi,
                              v = $(this).val();
                          if(r.test(v)) {
                            $(this).val(v.replace(r, ''));
                            c--;
                          }
                          this.setSelectionRange(c, c);
                        });

		</script>
	</body>
</html>
