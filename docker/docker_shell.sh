#!/bin/bash

docker exec -i -t $(docker ps -q | head -n1) /bin/bash
