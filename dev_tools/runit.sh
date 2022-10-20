#podman run --rm -d \
#	--name='37648-phvalheim1' \
#	-p '8080:8888/tcp' \
#	-p '4000-5000:4000-5000/udp' \
#	-v '/home/brian/Development/docker/docker-phvalheim/running_container/':'/opt/stateful':Z \
#        localhost/phvalheim

podman create \
       --name='37648-phvalheim1' \
	       -p '8080:8888/tcp' \
	       -p '7777:9001/tcp' \
	       -p '25000-26000:25000-26000/udp' \
	       -e 'basePort'='25000' \
	       -e 'defaultSeed'='szN8qp2lBn' \
	       -e 'backupsToKeep'='10' \
	       -e 'phvalheimHost'='phvalheim-dev.phospher.com' \
	       -e 'gameDNS'='37648-dev1.phospher.com' \
	       -e 'steamAPIKey'="`cat dev_tools/steamAPIKey.txt`" \
	       -v '/home/brian/Development/docker/phvalheim-server/running_container/':'/opt/stateful':Z \
	       -v '/tmp/test_backups/':'/opt/stateful/backups':Z \
	        localhost/phvalheim
