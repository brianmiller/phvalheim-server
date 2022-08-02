#!/bin/bash

ID=$(podman ps|grep mbbsemu|cut -d " " -f1)
podman logs $ID
