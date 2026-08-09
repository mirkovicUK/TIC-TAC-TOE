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

**The branch restriction is not in this file, and that is deliberate.** The two subject forms
are mutually exclusive: a job that targets an environment presents

```
repo:mirkovicUK/TIC-TAC-TOE:environment:production
```

and carries no `ref:` segment at all. So scoping here to the environment *moves* the branch
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

**The document ARN wildcards only the account segment, and here is why.** The service
authorization reference defines the `document` resource type as
`arn:${Partition}:ssm:${Region}:${Account}:document/${DocumentName}`. `AWS-RunShellScript` is an
AWS-managed document and is not owned by this account, and I could not establish from the
documentation which account segment IAM evaluates for a managed document. So the segment is
wildcarded while the region and the document name stay exact — one document, in one region,
rather than any document anywhere.

That is worth tightening once the pipeline has run: read the ARN CloudTrail records for the
first successful `SendCommand` and replace the wildcard with the literal value.

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
