ARG VERSION
ARG NPM

ARG BASE=reg.liubit.cloud/mednet/php
ARG BASE_NPM=node

FROM scratch as code
COPY --chown=1000:1000 ./ /app/
USER 1000:1000
WORKDIR /app

FROM ${BASE_NPM}:$NPM as node
WORKDIR /app
ARG ENV_TOKEN
ARG ENV_SOURCE

COPY --from=code /app/ /app/

ENV APP_ENV=production

RUN --mount=type=cache,target=/app/.npm \
    set -ex;\
    npm install;\
    [ -z "$ENV_SOURCE" ] || curl -Lo .env ${ENV_TOKEN:+-H "Authorization: Bearer $ENV_TOKEN"} "$ENV_SOURCE";\
    npm run build;\
    ln -sf /shared/.env /app/.env


FROM $BASE:$VERSION as php
WORKDIR /app
ENV DEBIAN_FRONTEND noninteractive

RUN --mount=type=cache,target=/var/cache/apt \
    set -ex;\
    apt update;\
    apt upgrade -y;\
    apt install -y --fix-missing less rsync jq

COPY --from=node /app/ /app/

USER 1000:1000

RUN --mount=type=cache,target=/app/.composer \
    composer install --prefer-dist --no-interaction --no-scripts
