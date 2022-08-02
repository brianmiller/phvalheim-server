#!/bin/bash

source /opt/stateless/engine/phvalheim.conf

echo
SQL "SELECT * FROM worlds;"
SQL "SELECT * FROM system;"
