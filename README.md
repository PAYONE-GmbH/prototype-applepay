# Demo- and testing app for applepay payments

This app enables you to set up an app to place applepay payments in combination with a configured payone apple-pay

## What do I need to run this

First you need a host with installed `docker`, `docker-compose` and a `bash`.

Next you need to have a proper configured Payone merchant account as described in https://docs.payone.com/display/public/PLATFORM/Special+Remarks+-+Apple+Pay#SpecialRemarksApplePay-Prerequisites.
You 

Consequentially you should own the following files

`merchant_id.key`,
`merchant_id.pem`,
`apple-developer-merchantid-domain-association.txt`

## Steps to make this run

1. Clone this repository into a dedicated folder on your host-system.
2. Place `merchant_id.key`, `merchant_id.pem` and `apple-developer-merchantid-domain-association.txt` into folder `import`
3. From project root folder run `bash bin/apldemo.sh import certs`
4. From project root folder run `bash bin/apldemo.sh import domain-verification`
5. Configure Payone merchant-data by creating a file `app/.env.local` in the app-directory of the project folder (/app), filled with data like in this example: 
    ```dotenv
    PAYONE_API_ENDPOINT=https://api.pay1.de/post-gateway/
    PAYONE_MODE=test
    PAYONE_MERCHANT_ID=12345
    PAYONE_PORTAL_ID=1234567
    PAYONE_SUB_ACCOUNT_ID=54321
    PAYONE_KEY=secret
    ```  
6. From project root folder run `bash bin/apldemo.sh build`
7. From project root folder run `bash bin/apldemo.sh run`

## Use custom docker-compose.yml

It is recommended to create a file called `docker-compose.override.yml` into the root of project folder with a custom configuration matching your hosts needs.

As an example you can see the contents of a configuration extension that is used on azec-host to use app with traefik edge router:
```yaml
version: "3.7"

services:
   apldemo:
      container_name: apldemo_app
      build:
         context: .
         dockerfile: ./Dockerfile
      image: apldemo
      restart: unless-stopped
      labels:
         - "traefik.enable=true"
         - "traefik.http.routers.apldemo.entrypoints=websecure"
         - "traefik.http.routers.apldemo.rule=Host(`apldemo.patsy.dev`)"
         - "traefik.http.routers.apldemo.tls=true"
         - "traefik.http.routers.apldemo.tls.certresolver=cloudflare"
         - "traefik.http.routers.apldemo.tls.options=default"
         - "traefik.http.services.apldemo.loadbalancer.server.port=80"
         - "traefik.http.routers.apldemo.middlewares=appleauth@file, gzip@file, secHeaders@file"
      volumes:
         - ./app:/app
      networks:
         - default
         - traefik
      depends_on:
         - apldemo_php

networks:
   traefik:
      external: true
```