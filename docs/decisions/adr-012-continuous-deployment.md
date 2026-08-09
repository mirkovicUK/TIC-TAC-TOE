# ADR-012: Continuous deployment through GHCR and SSM Run Command

Bears on Requirements 1 to 9 of the `continuous-deployment` spec.

## Decision

CI builds both images and pushes them to GitHub Container Registry tagged with the commit SHA.
`compose.yaml` names those images rather than building them. Deployment is one Systems Manager
document, invoked by a GitHub Actions job that assumes an AWS role by OIDC. A health gate on the
runner decides whether the deployment stands, and a failed gate redeploys the previously recorded
tag.

No credential to AWS is stored anywhere. No credential to GHCR exists on the instance, because both
packages are public.

## Alternatives considered

| Alternative | Why not |
| --- | --- |
| SSH key in repository secrets | A durable, exfiltratable credential to production |
| `ssm:SendCommand` on `AWS-RunShellScript` | The caller supplies the commands, so it is arbitrary root execution. Scoping the resource constrains *where* a command runs, not *what* it is |
| Building on the instance, as before | A `t3.micro` with 911 MiB needed a swap file to survive `npm run build`, and what shipped was never the artefact CI tested |
| `latest` as well as the SHA tag | A moving tag makes "what is deployed" unanswerable and gives a mistyped variable somewhere to land |
| Blue/green or a second instance | An ALB and two instances, for a demonstration project on one box |
| Snapshotting the database before each deploy | Declined explicitly. Recorded in the requirements with its consequence: there is no backup, and losing the volume loses every game |
