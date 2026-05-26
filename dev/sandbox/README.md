# Phase B sandbox: bar-assistant-flavor-sandbox

Files to stand up an isolated BA backend on the NAS for Phase B development.
Distinct from production (Stack 1 `bar-assistant-api`) — separate image, port,
and SQLite DB. Lets us iterate on the flavor-matching schema/endpoints without
risk to Erik's actual bar.

## Files

- **`Dockerfile.sandbox`** — mirror of the production Dockerfile but keeps
  composer dev deps for tinker/factories, plus `sed` patches on the init
  script to skip Meilisearch/Scout commands (sandbox has no Meilisearch
  alongside) and make `storage:link` idempotent across restarts.
- **`stack-bar-assistant-sandbox.yml`** — docker-compose YAML used to create
  Portainer Stack 8 (`bar-assistant-sandbox`). Maps host port 3002 → container
  8080; SQLite persisted under `/volume1/Docker/bar-assistant-sandbox/storage`.
- **`seed.php`** — one-shot seeder: creates a smoke test user, bar,
  membership, one ingredient (Plymouth Navy Strength), category axes, and a
  flavor profile. Outputs the API token to stdout.

## Quick start

```bash
# From workstation
rsync -avz --delete -e "ssh -p 223" \
  --exclude='.git' --exclude='vendor' --exclude='node_modules' \
  --exclude='storage/bar-assistant/*' --exclude='resources/data' \
  --exclude='.env' --exclude='*.log' \
  /home/erik/bar-assistant/ talvola@192.168.1.64:/volume1/Docker/bar-assistant-build/

# On NAS — build sandbox image
ssh -p 223 talvola@192.168.1.64 'cd /volume1/Docker/bar-assistant-build && \
  tar -cf /tmp/ba-sandbox.tar . && \
  curl -sk -X POST -H "X-API-Key: $(cat /tmp/ptr-token.txt)" \
    -H "Content-Type: application/x-tar" --data-binary @/tmp/ba-sandbox.tar \
    "https://localhost:19943/api/endpoints/3/docker/build?t=bar-assistant:flavor-sandbox&dockerfile=dev/sandbox/Dockerfile.sandbox"'

# Stack 8 already exists; re-PUT to recreate container with new image.

# Seed via Portainer exec:
#   tar+upload seed.php → exec `php /tmp/seed.php`
# Or just docker cp / portainer archive endpoint.
```

After seed, the API responds at `http://192.168.1.64:3002/api/` with the token
the seeder printed. See `/api/flavor/categories` and
`/api/ingredients/{id}/flavor-profile` for Phase B Slice 1 endpoints.

## Slice 1 smoke test (2026-05-26 — confirmed passing)

```
$ curl -H "Authorization: Bearer $TOKEN" -H "Bar-Assistant-Bar-Id: 1" \
    http://192.168.1.64:3002/api/flavor/categories
{"data":[{"category":"gin","axes":["juniper","citrus","floral","heat","spice","herbal","fruited"]}]}

$ curl -H "Authorization: Bearer $TOKEN" -H "Bar-Assistant-Bar-Id: 1" \
    http://192.168.1.64:3002/api/ingredients/1/flavor-profile
{"data":{"ingredient_id":1,"category":"gin","profile":{...},"source":"tgii", ...}}
```
