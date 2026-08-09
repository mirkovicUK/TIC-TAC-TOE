#!/usr/bin/env bash
#
# Poll the health endpoint from wherever this runs until the gate passes or the budget
# elapses. Exits 0 on pass, 1 on fail (Req 5.1 to 5.5).
#
#   HEALTH_URL=https://host/health scripts/health-gate.sh
#
# Run from the RUNNER and over the public HTTPS URL, not from the instance and not over
# the FastCGI socket. That is what also establishes that TLS terminates and that `web`
# reaches `app` — a local probe proves neither. Certificate validation is left ON; there
# is no `-k` here and there must not be, because an expired certificate is a real outage.
#
# TWO consecutive successes at least HEALTH_GAP seconds apart, not one. During recreation
# the OUTGOING container can still answer, so a single healthy response may have come from
# the version being replaced. The gap is measured between the two responses rather than
# assumed from the sleep interval, so scheduling jitter cannot let a 4-second pair count.
#
# A non-200, a body reporting persistence unreachable, and no response at all are treated
# alike: each clears the streak (Req 5.4). `/health` returns 503 with
# `"persistence":"unreachable"` when the database cannot be read, so the body is checked
# as well as the status — a 200 is not on its own evidence the application works.
set -uo pipefail

URL=${HEALTH_URL:?HEALTH_URL must be set}
BUDGET=${HEALTH_BUDGET:-120}
INTERVAL=${HEALTH_INTERVAL:-5}
GAP=${HEALTH_GAP:-5}

echo "gate: url=$URL budget=${BUDGET}s interval=${INTERVAL}s gap=${GAP}s"

deadline=$((SECONDS + BUDGET))
polls=0
first=0
final='no response'

while ((SECONDS < deadline)); do
    polls=$((polls + 1))

    # `--max-time` so one hung connection cannot consume the whole budget. On any curl
    # failure the substitution below leaves `code` as 000, which is not 200.
    raw=$(curl -sS --max-time 10 -w $'\n%{http_code}' "$URL" 2>/dev/null) || raw=$'\n000'
    code=${raw##*$'\n'}
    body=${raw%$'\n'*}
    # Capped, and on one line. `/health` answers with about 45 bytes of JSON, but a
    # misrouted probe gets Laravel's HTML error page — 8 kB of inlined CSS repeated once
    # per poll, which buries the actual result in the run log.
    final="HTTP $code $(printf '%s' "${body:0:160}" | tr -d '\n')"

    if [[ "$code" == 200 && "$body" == *'"persistence":"reachable"'* ]]; then
        if ((first == 0)); then
            first=$SECONDS
            echo "poll $polls: healthy (first of two)"
        elif ((SECONDS - first >= GAP)); then
            echo "poll $polls: healthy, $((SECONDS - first))s after the first"
            echo "polls=$polls final=$final"
            echo 'gate=pass'
            exit 0
        else
            echo "poll $polls: healthy, only $((SECONDS - first))s after the first; waiting for the gap"
        fi
    else
        [[ $first -ne 0 ]] && echo "poll $polls: streak broken by $final"
        first=0
    fi

    sleep "$INTERVAL"
done

echo "polls=$polls final=$final"
echo 'gate=fail'
exit 1
