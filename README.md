### NOTICE: 
<br>
PhValheim is undergoing a complete redesign to include more elaborate platform capabilities and error handling. The features listed below will be archived to "PhValheim_legacy" and will no longer be maintained.
<br>


## PhValheim
This script automatically creates and manages local contexts of Valheim plugin directories that are tied to specific worlds. All plugins will be kept in-sync between your local context and our remote world servers.
<br>
## Windows

<h3>Note! Your PowerShell execution policy must be set to "bypass" to allow PowerShell scripts to run on your system.</h3>

1. Open a PowerShell terminal as administrator "Start > PowerShell (right-click, run as Administrator)
2. Type "Set-ExecutionPolicy bypass"
3. Type "A" to anwer "Yes to All"

![image](https://user-images.githubusercontent.com/342276/153093624-c7515d18-c29b-48ba-a34b-bcc462a139ac.png)
<br>

<strong>Simple steps to get it running: DO NOT RUN AS ADMINISTRATOR!</strong>
1. Download [PhValheim_wrapper.ps1](https://raw.githubusercontent.com/brianmiller/PhValheim/main/PhValheim_wrapper.ps1) (right-click, Save link as...)
2. Right-click on the downloaded PhValheim_wrapper.ps1 file and select "Run with PowerShell"
<br>

<strong>If you want to simply double click on the script as if it were an application, you can change the default application used when opening PowerShell scripts:</strong>

1. Right-click on PhValheim_wrapper.ps1 and click "Properties"
2. Click the "General" tab and click the Opens With "Change" button
3. Select "More Apps" and scroll down.
4. Select "Look for another app on this PC"
5. Find and select Powershell.exe. It's usually located at "%SystemRoot%\system32\WindowsPowerShell\v1.0\powershell.exe"
6. Double-click on PhValheim_wrapper.ps1

<br>
Here's a screenshot of the script running on Windows:

![image](https://user-images.githubusercontent.com/342276/152061803-5f2c1a68-ce02-45dc-826c-9c63905c044b.png)
<br>

## Linux
<strong>Simple steps to get it running:</strong>
1. Download [PhValheim_wrapper.sh](https://raw.githubusercontent.com/brianmiller/PhValheim/main/PhValheim_wrapper.sh) (right-click, Save link as...)
2. Run it from a terminal

<br>
Here's a screenshot of the script running on Linux:

![image](https://user-images.githubusercontent.com/342276/153524468-dd62e0a3-640a-4905-ac2d-ff3f6177297f.png)
<br>
