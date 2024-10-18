podman run --rm -it --name phvalheim \
	-v '/home/brian/Development/docker/docker-phvalheim/running_container':'/config':Z \
	-v '/home/brian/Development/docker/docker-phvalheim/running_container/nginx/conf.d':'/etc/nginx/conf.d':Z \
	-v '/home/brian/Development/docker/docker-phvalheim/running_container/nginx/sites-enabled':'/etc/nginx/sites-enabled':Z \
	localhost/phvalheim \
	/bin/bash
#podman run --rm -it --name valheim localhost/valheim /bin/bash
