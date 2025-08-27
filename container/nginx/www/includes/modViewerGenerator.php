<?php



#### BEGIN: runningMod toolTip generator
function generateToolTip($pdo,$world) {

	$modsJson = getModViewerJsonForWorld($pdo,$world);
        $jsonIncoming = json_decode($modsJson,true);

        # sort the array by 'name' key in descending order
        # ensure array exists 
        $jsonIncoming = $jsonIncoming ?? [];

        usort(($jsonIncoming), fn($a, $b) => strtolower($b['name']) <=> strtolower($a['name']));

        $toolTipContent = '';

        foreach ($jsonIncoming as $arr) {
	        $runningModName = $arr['name'];
                $runningModUrl = $arr['url'];

                if (!empty($runningModName)) {
       		        $toolTipContent = "<tr><td style=\"\"><li><a target=\"_blank\" href=\"$runningModUrl\">$runningModName</a></li></td>$toolTipContent";
                }
        }
        return $toolTipContent;
}
#### END: runningMod toolTip generator









?>
