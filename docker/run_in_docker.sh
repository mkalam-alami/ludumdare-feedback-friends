#!/bin/bash

cd "$(dirname "$0")/.."

docker run -p 127.0.0.1:8080:80 -v mysql:/var/lib/mysql -v $PWD:/var/www/html thomastc/ldff
