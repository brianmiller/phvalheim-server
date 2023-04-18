containerName="37648-phvalheim2"

podman create \
       --name="$containerName" \
	       -p '8082:8080/tcp' \
	       -p '8083:8081/tcp' \
	       -p '25000-26000:25000-26000/udp' \
	       -e 'basePort'='25000' \
	       -e 'defaultSeed'='szN8qp2lBn' \
	       -e 'backupsToKeep'='10' \
	       -e 'phvalheimHost'='phvalheim-dev.phospher.com' \
	       -e 'gameDNS'='37648-dev1.phospher.com' \
	       -e 'steamAPIKey'="`cat ../not_git/steamAPIKey.txt`" \
	       -e 'phvalheimClientURL'='https://github.com/brianmiller/phvalheim-client/raw/master/published_build/phvalheim-client-installer.exe' \
	       -e 'TZ'='America/Los_Angeles' \
	       -v "/home/brian/docker_persistent/$containerName/":'/opt/stateful':Z \
	       -v "/home/brian/docker_persistent/$containerName/backups/":'/opt/stateful/backups':Z \
	        localhost/phvalheim2
