###############################################################################
# * Do the thing
# * 
# * Wraps some stuff.
#
# Written and maintained by:
#  * Brian Miller (brian@phospher.com) 
###############################################################################

$PhValheim = $(Invoke-RestMethod https://files.phospher.com/valheim/PhValheim.ps1)
Invoke-Expression $PhValheim
Start-Sleep -s 5


