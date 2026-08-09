#!/usr/bin/env bash
#
# Send one invocation of the `DeployTicTacToe` SSM document and wait for it.
#
#   scripts/ssm-run.sh <deploy|fallback|diagnose> <40-char-sha>
#
# Requires INSTANCE_ID in the environment and AWS credentials already configured.
#
# Passes no commands. The document holds the script; this passes two parameters, which is
# the whole point of the custom document (ADR-012) — the deployment role cannot run
# `AWS-RunShellScript`, so there is no route from here to an arbitrary shell.
#
# In `fallback` mode the tag argument is IGNORED by the document, which reads
# `PREVIOUS_RELEASE_TAG` off the instance instead. It is still required because SSM
# schema 2.2 has no conditional parameters, so pass the current SHA.
#
# Exits 0 only when the invocation reports `Success`. Both streams are printed whatever
# the status (Req 4.8), and the document's response code is the diagnosis:
#
#   64 tag not a SHA · 65 release.env missing · 66 out of disk · 67 revision mismatch
#   68 service not running · 69 jq missing · 70 lock held · 71 no previous tag
#
# When run under Actions it also writes `status` and `rc` to $GITHUB_OUTPUT so the
# workflow can branch on them without re-parsing this output.
set -uo pipefail

MODE=${1:?usage: ssm-run.sh <deploy|fallback|diagnose> <sha>}
TAG=${2:?usage: ssm-run.sh <deploy|fallback|diagnose> <sha>}
: "${INSTANCE_ID:?INSTANCE_ID must be set}"

# Matches the document's own `timeoutSeconds`, so the poll cannot outlive the thing it is
# polling: 120 iterations at 5s.
POLL_INTERVAL=${POLL_INTERVAL:-5}
POLL_LIMIT=${POLL_LIMIT:-120}

emit() {
    [[ -n "${GITHUB_OUTPUT:-}" ]] && printf '%s\n' "$1" >>"$GITHUB_OUTPUT"
    return 0
}

CMD=$(aws ssm send-command \
    --document-name DeployTicTacToe \
    --instance-ids "$INSTANCE_ID" \
    --timeout-seconds 600 \
    --parameters "ReleaseTag=$TAG,Mode=$MODE" \
    --query 'Command.CommandId' --output text) || {
    echo "send-command failed; the document may not be registered, or the role may not be permitted to run it"
    emit 'status=SendFailed'
    emit 'rc=-1'
    exit 1
}

echo "mode=$MODE tag=$TAG command-id=$CMD"

# `--timeout-seconds` bounds how long SSM waits for the agent to PICK the command up, not
# how long the script may run, so the terminal status has to be waited for here.
STATUS=Pending
for _ in $(seq 1 "$POLL_LIMIT"); do
    sleep "$POLL_INTERVAL"
    STATUS=$(aws ssm get-command-invocation \
        --command-id "$CMD" --instance-id "$INSTANCE_ID" \
        --query 'Status' --output text 2>/dev/null) || STATUS=Unknown
    case "$STATUS" in
        Pending | InProgress | Delayed) ;;
        *) break ;;
    esac
done

INV=$(aws ssm get-command-invocation --command-id "$CMD" --instance-id "$INSTANCE_ID" 2>/dev/null) || INV='{}'
RC=$(jq -r '.ResponseCode // -1' <<<"$INV")

echo "--- stdout"
jq -r '.StandardOutputContent // ""' <<<"$INV"
echo "--- stderr"
jq -r '.StandardErrorContent // ""' <<<"$INV"
echo "--- status=$STATUS response-code=$RC"

emit "status=$STATUS"
emit "rc=$RC"

[[ "$STATUS" == Success ]]
