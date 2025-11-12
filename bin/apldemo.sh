#!/usr/bin/env bash

INPUT_COMMAND=$1
printf "Using command: %s\n" "$INPUT_COMMAND"
COMMAND_RUN="run"
COMMAND_BUILD="build"
COMMAND_RESTART="restart"
COMMAND_STOP="stop"
COMMAND_IMPORT="import"
COMMAND_IMPORT_CERTS="certs"
COMMAND_IMPORT_DOMAIN_VERIFICATION="domain-verification"
# make sure everything is set up first

# .env.local present?
if [[ ! -f ./app/.env.local ]]
then
  echo "Missing file app/.env.local with payone api data. Creating file..."
  cp ./app/.env  ./app/.env.local
  echo "File created."
  printf "Please setup payone-merchant-data and apple data into file located at: %s/../app/.env.local" "$(pwd)"
  printf "before next run \n"
  exit 1
fi

# .env present?
if [[ ! -f .env ]]
then
  echo "Missing file .env with app data. Creating file..."
  cp .env.example  .env
  echo "File created."
  printf "Please setup app data into file located at: %s/../.env" "$(pwd)"
  printf "before next run \n"
  exit 1
fi

source .env

# docker-compose.override.yml present?
COMPOSE_YAML="./docker-compose.override.yml"
if [[ ! -f $COMPOSE_YAML ]]
then
  echo "Missing file docker-compose.override.yml in project root directory."
  printf "Using default docker-compose.yml \n"
  COMPOSE_YAML="./docker-compose.yml"
fi

# make sure well-known folder exists and
mkdir -p ./app/public/.well-known/
chmod 777 ./app/public/.well-known

# go for commands
if [[ $INPUT_COMMAND = "$COMMAND_BUILD" ]]
then
  docker compose -f $COMPOSE_YAML down && docker compose -f $COMPOSE_YAML build --no-cache
  exit 0
fi

if [[ $INPUT_COMMAND = "$COMMAND_RUN" ]]
then
  docker compose -f $COMPOSE_YAML up -d
  docker exec "apldemo_php_$CONTAINER_NAME_SUFFIX" php /app/composer.phar install --working-dir=/app
  exit 0
fi

if [[ $INPUT_COMMAND = "$COMMAND_RESTART" ]]
then
  docker compose -f $COMPOSE_YAML restart
  exit 0
fi

if [[ $INPUT_COMMAND = "$COMMAND_STOP" ]]
then
  docker compose -f $COMPOSE_YAML stop
  exit 0
fi

if [[ $INPUT_COMMAND = "$COMMAND_IMPORT" ]]
then
  IMPORT_COMMAND=$2
  if [ -z ${IMPORT_COMMAND+x} ];
  then
    printf "Missing import command as second param. \n"
    printf "Please provide either '%s' or '%s' \n" "$COMMAND_IMPORT_CERTS" "$COMMAND_IMPORT_DOMAIN_VERIFICATION"
    exit 1
  fi;

  if [[ $IMPORT_COMMAND = "$COMMAND_IMPORT_CERTS" ]]
  then
    cp -v ./import/merchant_id.key ./app/cert/merchant_id.key
    cp -v ./import/merchant_id.pem ./app/cert/merchant_id.pem
  fi

  if [[ $IMPORT_COMMAND = "$COMMAND_IMPORT_DOMAIN_VERIFICATION" ]]
  then
    cp -v ./import/apple-developer-merchantid-domain-association.txt ./app/public/.well-known/apple-developer-merchantid-domain-association.txt
  fi

  exit 0
fi

printf "No matching command found. Please provide '%s', '%s', '%s' or '%s' command as first parameter. \n" "$COMMAND_RUN" "$COMMAND_RESTART" "$COMMAND_STOP" "$COMMAND_IMPORT"
exit 1
