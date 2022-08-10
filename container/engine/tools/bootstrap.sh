#!/bin/bash

mkdir -p /opt/stateful/config
cp /opt/stateless/engine/includes/phvalheim.conf.example /opt/stateful/config/phvalheim-backend.conf
cp /opt/stateless/nginx/www/includes/config.php.example /opt/stateful/config/phvalheim-frontend.conf
chown -R phvalheim:phvalheim /opt/stateful/config
