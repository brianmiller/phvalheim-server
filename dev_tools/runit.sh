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
	       -p '4000-5000:4000-5000/udp' \
	       -v '/home/brian/Development/docker/phvalheim-server/running_container/':'/opt/stateful':Z \
	        localhost/phvalheim
