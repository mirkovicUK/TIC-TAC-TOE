# Requirements Document

## Introduction

Continuous Deployment automates the redeploy loop that `docs/deploy-schedule-swap.md` currently documents as a manual sequence: open a Session Manager shell, `git pull`, `docker compose up -d --build`, then check `/health` by hand. The Application is already built, tested by a green CI workflow on every push, and running on one EC2 instance behind Caddy.

Three things change. The two production images stop being built on the instance and are built by CI and published to a container registry, tagged by commit. The instance runs a named tag rather than whatever it last built, which for the first time gives a rollback target — today each `--build` untags the previous image, so no previous version exists to return to. And a GitHub Actions workflow performs the deployment over AWS Systems Manager Run Command, checks that the Application reports itself healthy afterwards, and redeploys the previous tag if it does not.

This is deliberately a small deliverable for a single-instance, single-SQLite-file deployment. It is a build-publish-deploy-verify-or-revert line and nothing more. The requirements below are stated so that each one can be settled by reading the repository, reading the IAM policies, or reading one workflow run.

### Out of Scope

The following are explicit non-goals. They are recorded here so their absence is understood as a scoping decision rather than an omission.

- Zero-downtime deployment. Recreating the `app` container costs a few seconds during which Caddy answers 502, exactly as the manual loop does today; the Web_Client polls every 2 seconds and recovers on the next successful response.
- Blue/green or any parallel-stack deployment. One instance with 911 MiB of RAM cannot hold two copies of the stack, and one SQLite file cannot be written by two stacks at once.
- Canary or staged rollout. There is one instance and no traffic splitter.
- More than one instance, an autoscaling group, or a load balancer.
- **Database backup or restore. This was offered and declined.** The consequence is stated plainly rather than left implicit: nothing in this feature protects the contents of the Database_Volume. If the volume, the instance, or the root disk is lost, every Game, Move and Player_Session is lost with it and cannot be recovered, and a Fallback_Deployment cannot recover data that a migration or a defect has already altered. The existing 7-day retention window of Requirement 13 of the `remote-tic-tac-toe` spec already means stored Games are short-lived, which is the reason the risk is accepted.
- Monitoring, alerting, dashboards, or paging. A failed Deployment_Workflow run is the only notification, and it is visible only to somebody who looks at the Actions tab.
- A staging or pre-production environment.
- Automatic reversal of database migrations. Requirement 8 makes this a prohibition rather than a gap.
- Deployment of branches other than the deployment branch, deployment from a pull request, and deployment of a tag or a release.
- Rotation of App_Key, or any change to how secrets reach the container. `deploy/app.env` stays untracked and hand-made on the instance.
- Automated provisioning of the instance, the Elastic IP, the security group or the Deployment_Role. `docs/aws-infra.md` covers how the instance was built by hand, and the role is created the same way.

### Open question, stated once rather than absorbed into scope

**There is no protection against loss of the instance.** With no backup of the Database_Volume and no automated provisioning, recovery from a lost instance means rebuilding it by hand from `docs/aws-infra.md` and starting from an empty database — and re-obtaining the TLS certificate against the Let's Encrypt rate limit that ADR-009 records as shared with every other user of `sslip.io`, which is the part that may not succeed on the day it is needed. This feature narrows the blast radius of a *bad deploy*, which is the frequent failure. It does nothing about the rare one. Whether that is acceptable is a judgement for the operator, and it is recorded here so the judgement is made rather than assumed.

## Glossary

- **Application**: The complete deployed system, as defined in the Glossary of `.kiro/specs/remote-tic-tac-toe/requirements.md`.
- **CI_Workflow**: The existing continuous integration workflow defined by `.github/workflows/ci.yml`, comprising the `quality` job and the `browser` job.
- **Green_Build**: The state of a workflow run in which every job of that run has concluded with a success status.
- **Registry**: The GitHub Container Registry at `ghcr.io`, from which the Deployment_Target pulls images.
- **Release_Tag**: The full 40-character lowercase hexadecimal commit SHA of the commit whose source produced a given Image_Pair. A Release_Tag is used both as the image tag in the Registry and as the value that selects which images the Deployment_Target runs.
- **App_Image**: The container image built from the `app` stage of the repository's `Dockerfile`, running php-fpm and the application code.
- **Web_Image**: The container image built from the `web` stage of the repository's `Dockerfile`, running Caddy and carrying its own copy of `public/`.
- **Image_Pair**: An App_Image and a Web_Image published under the same Release_Tag. The two are only valid together: the Web_Image contains the content-hashed asset filenames produced by the build of that same commit (ADR-011).
- **Publication_Job**: The job of the CI_Workflow that builds the Image_Pair and pushes it to the Registry.
- **Deployment_Workflow**: The GitHub Actions workflow that deploys a Release_Tag to the Deployment_Target, applies the Health_Gate, and performs a Fallback_Deployment when the Health_Gate fails.
- **Deployment_Branch**: The single branch from which the Deployment_Workflow is permitted to deploy, being `main`.
- **Deployment_Target**: The single EC2 instance that runs the Application, being instance `i-0c6bab4bc4644e760` in region `eu-west-2` of account `811362454196`, holding the repository clone at `/srv/tic-tac-toe`.
- **Deployment_Role**: The AWS IAM role that the Deployment_Workflow assumes in order to act on the Deployment_Target.
- **Trust_Policy**: The IAM trust policy of the Deployment_Role, which states who may assume that role.
- **Permissions_Policy**: The IAM identity policy attached to the Deployment_Role, which states what that role may do.
- **Run_Command**: The AWS Systems Manager Run Command service, by which a document is executed on the Deployment_Target without any inbound network connection to that instance.
- **Long_Lived_Credential**: A credential that remains valid beyond the run that used it, including an AWS access key id and secret access key pair, an SSH private key, and a Registry access token.
- **Current_Release_Tag**: The Release_Tag that the stack running on the Deployment_Target is configured to use, recorded on the Deployment_Target.
- **Previous_Release_Tag**: The value that was the Current_Release_Tag immediately before the Deployment_Workflow last changed it.
- **Health_Endpoint**: The unauthenticated `GET /health` endpoint of the Application, as required by criteria 1 and 2 of Requirement 10 of `.kiro/specs/remote-tic-tac-toe/requirements.md`.
- **Healthy_Response**: A response from the Health_Endpoint carrying a success status and a body reporting the persistence layer as reachable.
- **Health_Gate**: The check the Deployment_Workflow performs after a deployment, in which the Deployment_Workflow polls the Health_Endpoint until two consecutive Healthy_Responses are received or the Health_Budget elapses.
- **Health_Budget**: The bounded period of 120 seconds within which two consecutive Healthy_Responses must be received for a Health_Gate to pass.
- **Fallback_Deployment**: The deployment of the Previous_Release_Tag that the Deployment_Workflow performs when a Health_Gate fails.
- **Additive_Schema_Change**: A database migration whose forward direction adds tables, columns or indexes only; that adds every new column either as nullable or with a default value; that neither drops nor renames any existing table or column; that does not tighten a constraint on an existing column, meaning it does not make an existing column non-nullable, does not add a uniqueness constraint or a foreign key over existing data, and does not narrow the type of an existing column; and that contains a data-modifying statement only as the backfill of a column the same migration adds.
- **Certificate_Volume**: The Docker volume declared `external: true` with the fixed name `caddy-data`, holding the issued TLS certificate, its private key and the ACME account state.
- **Database_Volume**: The project-scoped Docker volume `sqlite-data`, holding the SQLite database file and with it the `sessions` table.
- **App_Key**: The `APP_KEY` value that reaches the `app` container through the untracked `deploy/app.env`, and which encrypts the Player_Session cookie.

## Requirements

### Requirement 1: Publication of the Image_Pair to the Registry

**User Story:** As the engineer operating the hosted instance, I want CI to build and publish both production images tagged by commit, so that a named, immutable version of the Application exists to deploy and to return to.

#### Acceptance Criteria

1. WHEN a change is pushed to the Deployment_Branch and the `quality` job and the `browser` job of that run have both concluded with a success status, THE Publication_Job SHALL build the App_Image and the Web_Image from the `Dockerfile` of the pushed commit for the `linux/amd64` platform and for no other platform, that being the architecture of the Deployment_Target, and SHALL push both to the Registry.
2. WHEN the Publication_Job pushes an image to the Registry, THE Publication_Job SHALL tag that image with the Release_Tag of the pushed commit, and SHALL form the Registry reference of that image from lowercase literals, being `ghcr.io/mirkovicuk/...`, rather than from the name of the repository. This criterion exists because the repository is named `mirkovicUK/TIC-TAC-TOE` and the Registry refuses a reference containing an uppercase character; forming the reference from literals is also what makes the published reference and the reference in `compose.yaml` the same string.
3. IF the `quality` job or the `browser` job of a run concludes with any status other than success, THEN THE Publication_Job SHALL push no image to the Registry.
4. WHEN a change is pushed to a branch other than the Deployment_Branch, or a pull request is opened, THE Publication_Job SHALL push no image to the Registry, and THE CI_Workflow SHALL run the `quality` job and the `browser` job as it does today.
5. THE Publication_Job SHALL push the App_Image and the Web_Image to repositories of the Registry that are readable without authentication, so that the Deployment_Target holds no Registry credential.
6. THE Publication_Job SHALL authenticate to the Registry using the token issued to that workflow run, and SHALL use no Long_Lived_Credential to do so.
7. IF the build or the push of either image fails, THEN THE Publication_Job SHALL report a failure status and SHALL delete no image that the same run has already published. This criterion states the tolerated non-atomic outcome plainly: a Release_Tag may exist in the Registry as one image rather than as an Image_Pair, and no compensating delete is attempted, because criterion 4 of Requirement 2 and criterion 4 of Requirement 7 catch a partial Release_Tag at deploy time.
8. WHEN the Publication_Job builds the App_Image and the Web_Image, THE Publication_Job SHALL set on each of those two images the OCI annotation `org.opencontainers.image.revision` carrying the Release_Tag of the pushed commit, so that the Release_Tag is recorded inside each image by the build that produced it and criterion 5 of Requirement 7 can read back what was built rather than what was requested.

### Requirement 2: The deployed artefact is the tested artefact

**User Story:** As the engineer operating the hosted instance, I want the instance to run exactly the images CI built and tested, so that what is verified and what is served are the same thing.

#### Acceptance Criteria

1. THE repository's `compose.yaml` SHALL select the image for the `app` service and the image for the `web` service by an `image:` reference naming the Registry and a Release_Tag supplied from the environment, SHALL declare no default value and no fallback expression for that Release_Tag variable, so that an unset or empty variable cannot resolve to `latest` or to an untagged reference, and SHALL declare no `build:` block for either service.
2. WHEN the Deployment_Workflow deploys a Release_Tag, THE Deployment_Workflow SHALL cause the Deployment_Target to pull the App_Image and the Web_Image carrying that Release_Tag from the Registry even where an image carrying that Release_Tag is already present on the Deployment_Target, so that a local image is never preferred to the published one, and SHALL cause no image build on the Deployment_Target.
3. THE Deployment_Workflow SHALL deploy the Release_Tag of the commit whose CI_Workflow run published the Image_Pair, and SHALL derive that Release_Tag from that commit rather than from any value supplied by a person.
4. IF either image of the Image_Pair for a Release_Tag is absent from the Registry, THEN THE Deployment_Workflow SHALL report a failure status and SHALL leave the Current_Release_Tag of the Deployment_Target unchanged.
5. WHEN the Deployment_Workflow deploys a Release_Tag, THE Deployment_Workflow SHALL record that Release_Tag as the Current_Release_Tag in one plain-text environment file held in the deployment directory of the Deployment_Target, that file being both the record of the Current_Release_Tag and the source from which `docker compose` reads the Release_Tag variable of criterion 1 of this requirement, and SHALL leave that file in place after the workflow run concludes. The file persists rather than being written for the duration of the run because `restart: unless-stopped` brings the stack up again after a reboot of the Deployment_Target without the Deployment_Workflow being involved, and it must then come up on the same Release_Tag rather than on an empty or a stale value; the same file makes the version being served discoverable on the instance without reference to a workflow run.
6. IF the environment file required by criterion 5 of this requirement is absent from the Deployment_Target, or the Release_Tag it carries is empty, THEN THE Deployment_Target SHALL start no container of the stack and SHALL leave a stack already running unchanged, so that a missing Release_Tag fails visibly rather than resolving to an unintended image.

### Requirement 3: Deployment authentication without long-lived credentials

**User Story:** As the engineer operating the hosted instance, I want the pipeline to hold no durable credential to production, so that the repository's secrets are not a standing route into the running system.

#### Acceptance Criteria

1. THE Deployment_Workflow SHALL obtain AWS credentials by presenting the OpenID Connect token issued to that workflow run and assuming the Deployment_Role, SHALL obtain them by no other means, and SHALL request a session lasting no more than 3600 seconds, so that the credentials of a run cease to be usable within one hour of that run.
2. THE repository SHALL hold no Long_Lived_Credential for the Deployment_Target or for the AWS account in its secrets, its variables, or its tracked files.
3. THE Trust_Policy SHALL permit assumption of the Deployment_Role only by the GitHub OpenID Connect provider registered in account `811362454196`, SHALL condition that assumption on an exact string match of the subject claim of the presented token against the single value `repo:mirkovicUK/TIC-TAC-TOE:ref:refs/heads/main`, and SHALL contain that subject condition with no wildcard character in its value, so that a run on any branch other than the Deployment_Branch, a run in any other repository, and a run of a pull request from a fork are all refused.
4. THE Trust_Policy SHALL condition assumption of the Deployment_Role on an exact string match of the audience claim of the presented token against the single value that identifies AWS Security Token Service, being `sts.amazonaws.com`.
5. THE Permissions_Policy SHALL grant only the Systems Manager actions the Deployment_Workflow performs, being the action that executes a document on an instance and the action that reads the status and output of an invocation of that document; SHALL restrict the action that executes a document to the Deployment_Target by its instance identifier `i-0c6bab4bc4644e760` in region `eu-west-2` of account `811362454196`; SHALL restrict that action to the single Run_Command document the Deployment_Workflow uses; SHALL grant no action against any other instance; and SHALL grant no action against any AWS service other than Systems Manager.
6. THE Deployment_Workflow SHALL request the `id-token: write` permission and read-only repository permissions, and SHALL request no permission that allows it to write to the repository.
7. THE repository SHALL contain the Trust_Policy document and the Permissions_Policy document as tracked files, so that criteria 3, 4 and 5 of this requirement are settled by reading the repository rather than by holding AWS credentials.
8. THE Deployment_Target SHALL reach the Registry and Systems Manager using its existing instance profile `tic-tac-toe-ssm`, and SHALL hold no credential belonging to the Deployment_Workflow.
9. IF the assumption of the Deployment_Role does not succeed, THEN THE Deployment_Workflow SHALL report a failure status indicating that credentials were not obtained, SHALL execute no document on the Deployment_Target, and SHALL leave the Current_Release_Tag of the Deployment_Target unchanged.

### Requirement 4: Deployment mechanism, and the instance invariants it must preserve

**User Story:** As the engineer operating the hosted instance, I want the deployment driven through Systems Manager, so that automating it opens no inbound access and disturbs none of the properties the running deployment already depends on.

#### Acceptance Criteria

1. WHEN the Deployment_Workflow deploys a Release_Tag, THE Deployment_Workflow SHALL execute the deployment on the Deployment_Target by Run_Command, and SHALL open no inbound network connection to the Deployment_Target.
2. THE Deployment_Workflow SHALL require no SSH key pair and no inbound access on port 22 to the Deployment_Target, so that the security group continues to allow no inbound connection on port 22.
3. WHEN a deployment or a Fallback_Deployment brings the stack up on the Deployment_Target, THE Deployment_Target SHALL leave port 9000 of the `app` service unpublished. This criterion exists because `TrustProxies` is configured with `*`: the Application believes `X-Forwarded-For` from any peer that can open a FastCGI connection, and that is safe only while the `web` container is the sole such peer. A published 9000 would allow the IP-keyed half of the Rate_Limit_Subject to be spoofed away.
4. THE Deployment_Workflow SHALL bring the stack down without removing volumes, and SHALL remove neither the Certificate_Volume nor the Database_Volume. This criterion exists because the Certificate_Volume holds a certificate whose replacement depends on a Let's Encrypt issuance limit shared with every other user of `sslip.io` (ADR-009).
5. THE Deployment_Workflow SHALL make no change to `deploy/app.env` and no change to App_Key. This criterion exists because a Player_Token lives only in the server-side session and there are no accounts: a changed App_Key locks every player out of every Game in progress, unrecoverably (ADR-005).
6. WHEN a deployment concludes with a Healthy_Response, THE Deployment_Workflow SHALL remove from the Deployment_Target the images that carry neither the Current_Release_Tag nor the Previous_Release_Tag, and SHALL retain the Image_Pair carrying the Previous_Release_Tag so that the Fallback_Deployment required by Requirement 6 remains possible.
7. THE Deployment_Workflow SHALL bound each Run_Command invocation by a maximum execution time, and SHALL report a failure status if that invocation concludes with any status other than success, including the case of that invocation reaching the maximum execution time. The bound exists because the Image_Pair is roughly 1 GB: a pull that hangs would otherwise occupy the invocation indefinitely rather than failing the run.
8. WHEN the Deployment_Workflow completes, THE Deployment_Workflow SHALL make the output of the Run_Command invocation available in the log of that workflow run, so that a failed deployment is diagnosable without opening a shell on the Deployment_Target.
9. WHILE a Run_Command invocation of the Deployment_Workflow is in progress against the Deployment_Target, THE Deployment_Workflow SHALL issue no second invocation against the Deployment_Target, SHALL cause a run triggered in the meantime to wait until the invocation in progress has concluded, and SHALL hold no more than one run waiting. This criterion exists because two pushes in quick succession would otherwise bring the same stack down and up concurrently on one instance holding one SQLite file.
10. WHERE the deployment executed by Run_Command performs an operation on the repository clone at `/srv/tic-tac-toe`, THE Deployment_Target SHALL perform that operation as the user `ssm-user`. This criterion exists because Run Command executes as root while `/srv/tic-tac-toe` is owned by `ssm-user`, and a `git` invocation as root in a directory owned by another user fails with a dubious-ownership error that reads as a repository fault rather than as the permissions one it is. Only `compose.yaml` and the untracked `deploy/app.env` are now needed from that clone, together with the environment file required by criterion 5 of Requirement 2, because both images come from the Registry rather than from the source in that clone.

### Requirement 5: Health gating of a deployment

**User Story:** As the engineer operating the hosted instance, I want the pipeline to confirm the Application answers after a deploy, so that a deployment that starts but does not serve is caught by the pipeline rather than by a player.

#### Acceptance Criteria

1. WHEN a deployment has brought the stack up on the Deployment_Target, THE Deployment_Workflow SHALL poll the Health_Endpoint from the workflow runner over the public HTTPS URL of the Application at intervals of no more than 10 seconds until two consecutive Healthy_Responses no less than 5 seconds apart are received or the Health_Budget elapses, and SHALL validate the TLS certificate presented by that URL rather than disabling or bypassing that validation. The poll is made from the runner over the public URL because that also establishes that TLS terminates and that the `web` service reaches the Application, which a probe made over the local FastCGI socket on the Deployment_Target does not.
2. WHEN the Deployment_Workflow receives two consecutive Healthy_Responses within the Health_Budget, THE Deployment_Workflow SHALL treat the Health_Gate as passed and SHALL report a success status. Two are required rather than one because the container being replaced may still answer for a short period during recreation, so a single Healthy_Response could have come from the version being replaced.
3. IF the Health_Budget elapses without two consecutive Healthy_Responses having been received, THEN THE Deployment_Workflow SHALL treat the Health_Gate as failed and SHALL perform the Fallback_Deployment required by Requirement 6.
4. THE Deployment_Workflow SHALL treat as a Healthy_Response only a response carrying a success status and a body reporting the persistence layer as reachable, and SHALL treat a response carrying a failure status, a response reporting the persistence layer as unreachable, and the absence of a response as interrupting the sequence of consecutive Healthy_Responses and as leaving the Health_Gate unsatisfied.
5. THE Deployment_Workflow SHALL record in the log of that workflow run the number of polls made and the status of the final response, and WHERE the Health_Gate fails SHALL also record the health status Compose reports for the `app` service, so that a Health_Gate outcome is settled by reading the run.

### Requirement 6: Fallback to the Previous_Release_Tag

**User Story:** As the engineer operating the hosted instance, I want a failed deployment to put the working version back on its own, so that a bad commit costs a few minutes of degraded service rather than a manual recovery.

#### Acceptance Criteria

1. WHEN the Deployment_Workflow is about to change the Current_Release_Tag of the Deployment_Target as part of a deployment, and the Release_Tag being deployed differs from the Current_Release_Tag, THE Deployment_Workflow SHALL record the existing Current_Release_Tag as the Previous_Release_Tag on the Deployment_Target before making that change. This applies to deployments only and not to a Fallback_Deployment, because a Fallback_Deployment would otherwise overwrite the Previous_Release_Tag with the Release_Tag whose Health_Gate has just failed, leaving a second failure with no last known good version to return to. It is conditioned on the two tags differing so that redeploying the Release_Tag already running cannot make the fallback target equal to the current one.
2. WHEN a Health_Gate fails, THE Deployment_Workflow SHALL confirm that both images of the Image_Pair carrying the Previous_Release_Tag are present on the Deployment_Target before the failing deployment is disturbed, and SHALL then deploy that Image_Pair to the Deployment_Target, so that a Fallback_Deployment cannot bring the stack down and find nothing to bring up.
3. WHEN a Fallback_Deployment has brought the stack up, THE Deployment_Workflow SHALL apply a Health_Gate to that Fallback_Deployment.
4. WHEN the Deployment_Workflow performs a Fallback_Deployment, THE Deployment_Workflow SHALL report a failure status for that workflow run, irrespective of whether the Health_Gate applied to that Fallback_Deployment passes.
5. WHEN the Deployment_Workflow performs a Fallback_Deployment, THE Deployment_Workflow SHALL record in the log of that workflow run the Release_Tag whose Health_Gate failed, the Previous_Release_Tag deployed in its place, and the outcome of the Health_Gate applied to that Fallback_Deployment.
6. IF the Health_Gate applied to a Fallback_Deployment also fails, THEN THE Deployment_Workflow SHALL leave the Fallback_Deployment in place, SHALL report a failure status, and SHALL attempt no further deployment, so that a workflow run performs at most one Fallback_Deployment.
7. IF no Previous_Release_Tag is recorded on the Deployment_Target when a Health_Gate fails, or either image of the Image_Pair carrying the Previous_Release_Tag is absent from the Deployment_Target, THEN THE Deployment_Workflow SHALL report a failure status stating that no Fallback_Deployment was possible, and SHALL leave the failed deployment in place.
8. THE Deployment_Workflow SHALL treat a Fallback_Deployment as restoring the Image_Pair alone, and SHALL reverse no database migration as part of that Fallback_Deployment, as required by Requirement 8.

### Requirement 7: The Image_Pair deploys and rolls back together

**User Story:** As the engineer operating the hosted instance, I want the two images always moved as a matched set, so that Caddy never serves asset filenames the running application does not reference.

#### Acceptance Criteria

1. WHEN the Deployment_Workflow deploys a Release_Tag, THE Deployment_Workflow SHALL set the image of the `app` service and the image of the `web` service to that same Release_Tag. This criterion exists because the Web_Image carries its own copy of `public/`, including Vite's content-hashed asset filenames, which ADR-011 records as a deliberate copy-rather-than-share decision; the two images are therefore valid only as the pair produced by one commit.
2. WHEN the Deployment_Workflow performs a Fallback_Deployment, THE Deployment_Workflow SHALL set the image of the `app` service and the image of the `web` service to the Previous_Release_Tag.
3. THE repository's `compose.yaml` SHALL derive the image reference of the `app` service and the image reference of the `web` service from a single Release_Tag value, so that the two services cannot be given different Release_Tags by editing one line.
4. IF the App_Image and the Web_Image carrying a Release_Tag are not both present on the Deployment_Target after the pull and before either the `app` container or the `web` container is recreated, THEN THE Deployment_Workflow SHALL report a failure status and SHALL leave the running stack unchanged, so that a pull succeeding for one image and failing for the other cannot recreate one container against a pair that does not exist.
5. WHEN a deployment or a Fallback_Deployment has brought the stack up, THE Deployment_Workflow SHALL read the Release_Tag reported by the running `app` container and the Release_Tag reported by the running `web` container from the `org.opencontainers.image.revision` label of the image each of those containers runs, that label being set at build time by the Publication_Job as criterion 8 of Requirement 1 requires, SHALL record both values and the Release_Tag being deployed in the log of that workflow run, and SHALL report a failure status if the three values are not all equal, without performing the Fallback_Deployment required by Requirement 6. The comparison is three-way — the label of the `app` container, the label of the `web` container, and the Release_Tag being deployed — and the value must come from the image rather than from the deployment environment, because criterion 3 of this requirement requires both services to derive their image reference from a single Release_Tag variable: two values read from that variable, or from the image reference Compose resolved, cannot differ, so a comparison of them would be incapable of failing. The label is written into the image by the build, so reading it back is independent evidence of what was actually built. The mismatch this criterion detects therefore arises from the Registry rather than from Compose: criterion 7 of Requirement 1 tolerates a Release_Tag existing as one image rather than as an Image_Pair, and a tag may resolve to content other than what the commit bearing that Release_Tag produced. This criterion exists because a mismatched pair degrades rather than fails: the page loads with no CSS and no JavaScript, because the Web_Image serves the content-hashed asset filenames of a different commit than the one the running application references, and the Health_Gate still passes, because the Health_Endpoint returns JSON that needs no assets. Reading the two tags back is the only point in the pipeline at which the condition is detectable.

### Requirement 8: Forward-only schema, and additive schema changes

**User Story:** As the engineer operating the hosted instance, I want it stated and enforced that rolling an image back does not roll the database back, so that a fallback cannot leave the schema and the code disagreeing in a way that loses data.

#### Acceptance Criteria

1. WHEN the `app` container starts, THE Application SHALL apply the migrations absent from the `migrations` table and SHALL apply them in the forward direction only, as `docker/app-entrypoint.sh` does today.
2. THE Deployment_Workflow SHALL reverse no applied migration, and SHALL invoke no migration rollback command, in either a deployment or a Fallback_Deployment.
3. THE repository SHALL contain only migrations whose forward direction is an Additive_Schema_Change, so that the schema produced by a Release_Tag is readable by the code of the Release_Tag preceding it, and THE `quality` job SHALL conclude with a failure status if the forward direction of a migration in the repository is not an Additive_Schema_Change or if a migration in the repository makes more than the one schema change that criterion 8 of this requirement permits.
4. WHERE a column or a table is to be removed, THE repository SHALL separate that removal into a later commit than the commit that stops the code using it, so that no single Release_Tag both stops using a column and drops it.
5. WHEN a Fallback_Deployment restores the Previous_Release_Tag, THE Deployment_Target SHALL retain the Database_Volume and its contents unchanged by that Fallback_Deployment.
6. THE Deployment_Workflow SHALL make no attempt to restore, copy, or replace the contents of the Database_Volume, consistent with the backup non-goal recorded in Out of Scope.
7. IF a migration applied at the start of the `app` container fails, THEN THE `app` container SHALL start no php-fpm process, so that no request is served against a half-migrated database. The `set -e` of `docker/app-entrypoint.sh` gives this today: the container refuses to start, the Health_Gate of Requirement 5 therefore fails, and the Fallback_Deployment of Requirement 6 restores the Previous_Release_Tag against the schema as the failed migration left it.
8. THE repository SHALL contain only migrations that each make exactly one schema change, being one change to one table, so that a migration either applies or does not apply and no partly-applied state is representable. A backfill of a column the same migration adds, which the definition of Additive_Schema_Change permits, counts as part of that one change. This criterion exists because Laravel opens no transaction for a SQLite migration, as the finding below records: a migration holding one change makes the absence of that transaction harmless, and that guarantee is cheaper than any retry or repair mechanism the Deployment_Workflow would otherwise need to carry.

*Finding, from verifying what was previously recorded here as an assumption:* Laravel does not run a SQLite migration in a transaction. `Illuminate\Database\Migrations\Migrator::runMigration()` wraps a migration in a transaction only where `$this->getSchemaGrammar($connection)->supportsSchemaTransactions() && $migration->withinTransaction`. `Illuminate\Database\Schema\Grammars\SQLiteGrammar` does not override the `$transactions` property, so it inherits `protected $transactions = false;` from `Illuminate\Database\Schema\Grammars\Grammar` and `supportsSchemaTransactions()` returns false — unlike `PostgresGrammar` and `SqlServerGrammar`, each of which sets that property to true. No transaction is therefore opened, and each statement of a migration auto-commits on its own. SQLite the engine does support transactional DDL; Laravel does not use it here, so the assumption was about the wrong layer.

The operational consequence is not data loss, and is worth stating exactly. If a migration makes several schema changes and one fails, the earlier ones have committed, but no row was inserted into the `migrations` table. The next deployment therefore runs that same migration from its beginning, its first statement fails because the change it makes already exists, and every subsequent deployment fails the same way until a person intervenes. Criterion 7 of this requirement, the Health_Gate of Requirement 5 and the Fallback_Deployment of Requirement 6 hold service up throughout — the Application keeps serving the Previous_Release_Tag — so what is blocked is deploying anything new. Criterion 8 of this requirement removes the condition at its source by making a partly-applied migration unrepresentable.

### Requirement 9: Documentation and decision records

**User Story:** As a reviewer, I want the deployment contract written down where somebody about to write a migration will see it, so that the one rule that makes rollback safe is not held only in the pipeline's behaviour.

#### Acceptance Criteria

1. THE README SHALL state that a database schema change must be an Additive_Schema_Change, and SHALL state the expand-and-contract sequence that criterion 4 of Requirement 8 requires for a removal.
2. THE README SHALL state that schema changes move forward only, and SHALL state that rolling an image back does not reverse a migration, so that a Fallback_Deployment restores the code and not the schema. THE repository SHALL carry this statement and the statement required by criterion 1 of this requirement in the `database/migrations` directory as well as in the README, so that a person writing a migration meets both rules where the migration is written rather than only in the README.
3. THE README SHALL state how a deployment is triggered, where the outcome of a deployment run is observed, and that a failed Health_Gate causes the Previous_Release_Tag to be redeployed.
4. THE README SHALL state that no backup of the Database_Volume exists and that loss of the volume or of the instance loses every stored Game, as recorded in Out of Scope.
5. THE Application repository SHALL contain a decision record for the continuous deployment mechanism, stating the decision, the alternatives considered, and the reason for the choice, in the form the existing records under `docs/decisions/` use, and SHALL list that record in the index at `docs/decisions/README.md`.
6. THE decision record required by criterion 5 of this requirement SHALL state that it supersedes the section of ADR-009 titled "No continuous deployment, deliberately", and SHALL state which parts of that section are now built and which parts still hold.
7. THE Application repository SHALL contain the requirements, design and task documents for this feature.
8. THE `docs/deploy-schedule-swap.md` document SHALL document deploying a named Release_Tag to the Deployment_Target by hand and restoring the Previous_Release_Tag by hand, as the path for use when the Deployment_Workflow itself is broken, and SHALL state no sequence that builds an image on the Deployment_Target, so that the document reached for during an incident does not describe a loop the stack no longer supports.
9. THE header comment of `compose.yaml`, the Deployment section of `.kiro/specs/remote-tic-tac-toe/design.md`, that same document's summary of ADR-009, and the comment on the `permissions` block of `.github/workflows/ci.yml` SHALL each state the arrangement this feature builds, in place of the claims that the image is built on the instance, that there is no registry and no CD pipeline, and that nothing in the CI_Workflow publishes anything.

## Conflicts with existing records

Four places in the repository state, as settled fact, the opposite of what this feature builds. Criteria 6, 8 and 9 of Requirement 9 now oblige the corrections; they are still listed here because each needs a deliberate decision about whether to amend, supersede, or rewrite, and because nothing outside `.kiro/specs/continuous-deployment/` is edited by this phase.

1. **`docs/decisions/adr-009-ec2-compose-caddy.md`, the section "No continuous deployment, deliberately".** It records that deploys are manual over a Session Manager shell, and then describes the shape this feature builds — GitHub's OIDC provider assuming an AWS role, driving the deploy through SSM Run Command — as the hypothetical that would apply "if CD were in scope". Its rejection of the SSH-private-key variant still holds and is preserved by criterion 2 of Requirement 3. Its decision does not. Criterion 6 of Requirement 9 is the obligation this creates.

2. **`docs/decisions/adr-009-ec2-compose-caddy.md`, the Decision section.** It states "the image built on the box" as part of the decision, and its Alternatives table dismisses ECS and Fargate partly for needing "a registry". Requirement 1 introduces a registry and Requirement 2 removes the build from the box, so the Decision section is affected and not only the CD section.

3. **`compose.yaml`, the file header.** It states "Deployed by `docker compose up -d --build` on the instance" and "there is no registry and no CD pipeline, which ADR-009 records as a scoping decision rather than an omission". Requirement 2 criterion 1 replaces both `build:` blocks with `image:` references, so this comment describes a file that will no longer exist in that form.

4. **`.kiro/specs/remote-tic-tac-toe/design.md`, the Deployment section (around line 1157).** It states "The image is built on the instance by `docker compose up -d --build`; there is no registry and no CD pipeline, which is a scoping decision recorded in ADR-009." The same sentence appears in condensed form in that document's ADR-009 summary at around line 1253.

Two smaller ones, noted because they are load-bearing in an argument rather than merely descriptive:

5. **`Dockerfile`, the `app` stage.** The decision to leave `libicu-dev` in the image, costing about 90 MB, is justified partly by "a deployment of one instance with no registry to push through". There is now a registry to push through, and every deployment pulls that 90 MB over the network. The conclusion may well still hold — the pull is cached per layer and happens rarely — but the stated reason no longer applies as written.

6. **`.github/workflows/ci.yml`, the `permissions` block.** It declares `contents: read` with the comment "nothing here writes to the repository or publishes anything". Requirement 1 adds a job that publishes to the Registry, which needs `packages: write`, and Requirement 3 criterion 6 adds `id-token: write` in the Deployment_Workflow. The comment becomes false for the CI_Workflow as a whole. This one is now resolved: criterion 9 of Requirement 9 includes it.

Separately, `docs/deploy-schedule-swap.md` documents the manual redeploy loop this feature automates, including the "What actually needs a rebuild" table and the `docker image prune -f` step. It is not contradicted so much as superseded in part, and criterion 8 of Requirement 9 settles what it becomes: the by-hand path for deploying a named Release_Tag and restoring the Previous_Release_Tag when the pipeline itself is broken. The rebuild table goes with the build.
