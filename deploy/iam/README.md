# The deployment role's two policies

JSON carries no comments, so the reasoning lives here. These two files are the whole of what
authorises GitHub Actions to deploy, and Requirement 3.7 of the continuous-deployment spec asks
for them to be tracked precisely so that the scoping can be checked by reading the repository
rather than by holding AWS credentials.

They are **inputs to a command you run by hand** — step 3 of `docs/cd.md` — not something the
pipeline applies. Provisioning is out of scope for the feature, so changing a file here changes
nothing until the role is updated from it.

## `deployment-role-trust-policy.json` — who may assume the role

This is the security boundary. Everything else is consequence.

**Why the subject names an environment and not a branch.** GitHub's OIDC issuer,
`token.actions.githubusercontent.com`, is shared by every GitHub account — it has no tenancy
segment, unlike Vercel's `oidc.vercel.com/<team>`. A per-tenant issuer URL exists but is a
GitHub Enterprise Cloud feature. So on this plan the entire tenancy boundary is the `sub`
condition below, and nothing else.

AWS treats that as a known hazard rather than leaving it to the operator. It classifies GitHub
Actions as a *shared* OIDC provider whose tenancy claim is `sub`, refuses to create or update a
trust policy that omits `token.actions.githubusercontent.com:sub` (failing with
`MalformedPolicyDocument`), and refuses a `sub` whose value is only a wildcard. Its own guidance
then recommends scoping to a GitHub environment with protection rules in preference to a bare
branch condition.

**The subject carries immutable numeric IDs, and every guide including AWS's own says it does
not.** The documented form is `repo:<owner>/<repo>:environment:<name>`. What this repository's
tokens actually present is

```
repo:mirkovicUK@105384880/TIC-TAC-TOE@1325118189:environment:production
```

`105384880` is the account id and `1325118189` the repository id, both confirmed against the
GitHub API. This is GitHub's
[immutable subject claims](https://github.blog/changelog/2026-04-23-immutable-subject-claims-for-github-actions-oidc-tokens/)
change: new repositories get identifiers that are assigned once and never reused embedded in the
default `sub`, so renaming or transferring the repository no longer changes what a trust policy
matches. (Content rephrased for compliance with licensing restrictions.)

It is a better boundary than the name form, which is presumably the point — a name can be
released and re-registered by somebody else, an id cannot. But it is a silent break: a policy
written from the documentation is accepted by AWS and then denies every assumption with
`Not authorized to perform sts:AssumeRoleWithWebIdentity`, which reads as a missing permission
rather than a claim mismatch.

**Diagnose it from CloudTrail, not from the workflow log.** The runner cannot show you the
token, but a denied `AssumeRoleWithWebIdentity` is logged with the presented subject in
`userIdentity.userName`:

```bash
aws cloudtrail lookup-events \
  --lookup-attributes AttributeKey=EventName,AttributeValue=AssumeRoleWithWebIdentity \
  --max-results 1 \
  --query 'Events[0].CloudTrailEvent' --output text | jq -r '.userIdentity.userName'
```

That prints the exact string the condition has to equal. It settled this in one call after the
retry loop in the workflow log said only "not authorized" twelve times.

**The branch restriction is not in this file, and that is deliberate.** The two subject forms
are mutually exclusive: a job that targets an environment carries no `ref:` segment at all. So
scoping here to the environment *moves* the branch
restriction out of AWS and into the `production` environment's **deployment branches** rule in
GitHub, which is set to `main` and nothing else. That rule is the whole branch boundary — remove
it and any branch targeting the environment could assume this role. GitHub applies it before
issuing a token, which is why it is stronger than the AWS-side condition it replaces.

**The mistake to avoid.** `StringLike` with `repo:mirkovicUK/TIC-TAC-TOE:*` would be accepted by
AWS and would let a pull request from a fork assume this role and deploy. AWS only rejects a
`sub` that is *solely* a wildcard, so a merely-too-broad condition passes validation. Both
conditions here are `StringEquals`, and neither value contains `*`.

## `deployment-role-permissions-policy.json` — what the role may do

Two statements, and one of them is broader than I would like.

**`ssm:SendCommand` needs both resource types listed or it is denied.** The document and the
instance are separate resource types in the same statement: omit the instance and the role could
target anything in the account; omit the document and the call fails.

**The document is one we own, so its ARN is exact.** `DeployTicTacToe` is registered in this
account from `deploy/ssm/DeployTicTacToe.json`, so the ARN carries the account id with no
wildcard in any segment. An earlier version of this policy named `AWS-RunShellScript` and had to
wildcard the account segment, because an AWS-managed document is not owned by this account and
the documentation does not say which account IAM evaluates. Naming our own document retired that
uncertainty as a side effect.

**Why a custom document at all, which is the substantive change here.** `ssm:SendCommand` on
`AWS-RunShellScript` is arbitrary command execution as root — the caller supplies the commands.
Scoping the resource to one document and one instance constrains *where* a command runs, not
*what* it is, so that policy read as least privilege while granting a root shell. `DeployTicTacToe`
fixes its own command steps and accepts only a `ReleaseTag` matching `^[0-9a-f]{40}$` and a `Mode`
of `deploy` or `fallback`.

**No document-write actions are granted, deliberately.** No `ssm:CreateDocument`,
`ssm:UpdateDocument` or `ssm:DeleteDocument`. A role that can rewrite the document it is
restricted to is restricted by nothing. The consequence is that changing the deploy script means
running `aws ssm update-document` by hand, and that is the price of the constraint rather than an
oversight.

**`ssm:GetCommandInvocation` is granted on `*` because it cannot be scoped.** This is not
laziness — the resource-types table of the service authorization reference defines no `command`
resource type at all, so there is no ARN to name. Every AWS example grants it broadly for the
same reason. It reads the status and output of a command invocation and can perform no action,
which is what makes the breadth tolerable. `ListCommandInvocations` is included for the same
purpose and has the same limitation.

**What is deliberately absent.** No `ssm:StartSession`, so this role cannot open a shell. No EC2
actions, so it cannot stop, start or terminate the instance. No IAM actions, so it cannot widen
its own permissions. Nothing outside Systems Manager at all.

## Checking these files without AWS access

```bash
python3 -m json.tool deploy/iam/deployment-role-trust-policy.json > /dev/null
grep -c '\*' deploy/iam/deployment-role-trust-policy.json   # must be 0
grep 'sub"' deploy/iam/deployment-role-trust-policy.json    # must be StringEquals, environment:production
```

The first confirms the JSON parses. The second is the one that matters: **no asterisk may appear
anywhere in the trust policy.** The third confirms the subject is the environment form the
`production` environment will actually present.

With AWS access, the check worth having is that the ids in the subject are still this
repository's — they are stable, so a mismatch means the file was copied from somewhere else:

```bash
curl -sS https://api.github.com/repos/mirkovicUK/TIC-TAC-TOE | jq -r '"\(.owner.id) \(.id)"'
grep -o '@[0-9]*' deploy/iam/deployment-role-trust-policy.json
```
