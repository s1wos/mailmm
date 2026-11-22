#!/bin/sh
set -eu
check_intake_limit() {
  docker compose run --rm --no-deps -T --entrypoint php smtp artisan mail:parse --check-limit
}
case "${1:-help}" in
  init)
    docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/project" -w /project php:8.4-cli php deploy/init-env.php
    docker compose build && check_intake_limit && docker compose up -d ;;
  build) docker compose build && check_intake_limit && docker compose up -d ;;
  up) check_intake_limit && docker compose up -d ;;
  down) docker compose down ;;
  restart) docker compose restart ;;
  logs|ps|exec) docker compose "$@" ;;
  *) echo "sh d init | up | build | down | restart | logs [service] | ps | exec <service> <command>" ;;
esac
