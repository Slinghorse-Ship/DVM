#!/usr/bin/env bash
# Ephemeral Actions databases only; never stops or replaces an existing container.
set -euo pipefail
if [[ ${GITHUB_ACTIONS:-} != true || ! ${GITHUB_RUN_ID:-} =~ ^[0-9]+$ ]]; then
  echo 'Only an explicit ephemeral GitHub Actions run may start CI databases.' >&2
  exit 1
fi
task_digest=sha256:6ea90827b1100f8f2ae306a539f86d2c264a26ed435a2a9f75551dd5c3aeb242
task_image=''
for task_registry in mirror.gcr.io/library/mysql public.ecr.aws/docker/library/mysql docker.io/library/mysql; do
  task_reference="$task_registry:8.4@$task_digest"
  if timeout 120 docker pull "$task_reference"; then
    task_image=$task_reference
    break
  fi
  echo "::warning::Pinned MySQL unavailable from $task_registry; trying the next registry."
done
if [[ -z $task_image ]]; then
  echo '::error::The pinned official MySQL image is unavailable from all three registries.' >&2
  exit 1
fi
for task_name in kh-native-ci-mysql kh-calendar-ci-mysql; do
  if docker container inspect "$task_name" >/dev/null 2>&1; then
    echo "::error::CI database name already occupied: $task_name. Existing container preserved." >&2
    exit 1
  fi
done
docker run -d --name kh-native-ci-mysql --label "com.kulturhus.ci.run=$GITHUB_RUN_ID" \
  -p 127.0.0.1:13371:3306 -e MYSQL_ROOT_PASSWORD=synthetic-ci-only -e MYSQL_ROOT_HOST=% \
  --health-cmd='mysqladmin ping --silent' --health-interval=5s --health-timeout=5s --health-retries=30 "$task_image" --binlog-expire-logs-seconds=3600 --max-binlog-size=104857600
docker run -d --name kh-calendar-ci-mysql --label "com.kulturhus.ci.run=$GITHUB_RUN_ID" \
  -p 127.0.0.1:13372:3306 -e MYSQL_ROOT_PASSWORD=isolated-regression-only -e MYSQL_ROOT_HOST=% \
  --health-cmd='mysqladmin ping --silent' --health-interval=5s --health-timeout=5s --health-retries=30 "$task_image" --binlog-expire-logs-seconds=3600 --max-binlog-size=104857600
for task_attempt in $(seq 1 75); do
  task_native=$(docker inspect --format '{{.State.Health.Status}}' kh-native-ci-mysql)
  task_calendar=$(docker inspect --format '{{.State.Health.Status}}' kh-calendar-ci-mysql)
  if [[ $task_native == healthy && $task_calendar == healthy ]]; then
    echo 'Both isolated pinned MySQL databases are healthy.'
    exit 0
  fi
  if [[ $task_native == unhealthy || $task_calendar == unhealthy ]]; then break; fi
  sleep 2
done
echo '::error::Isolated MySQL databases did not become healthy.' >&2
docker logs --tail 30 kh-native-ci-mysql >&2
docker logs --tail 30 kh-calendar-ci-mysql >&2
exit 1
