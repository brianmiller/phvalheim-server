#!/bin/bash

sudo podman rmi -f $(sudo podman images -a -q)
podman rmi -f $(podman images -a -q)
