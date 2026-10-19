# Infrastructure

Production on AWS, as Terraform (ADR 0015). Two stacks:

- `bootstrap/`: the state bucket, GitHub's sign-in to AWS (OIDC), and the plan and apply
  roles. Applied **once**, from your machine.
- `production/`: everything the app runs on. The network rules, the API host (EC2), Postgres
  (RDS), the two S3 buckets, CloudFront, the settings in Parameter Store, and a budget alarm.
  Planned on merge requests and applied on merge by `.github/workflows/infra.yml`.

Region: `af-south-1` (Cape Town). Rough cost: $35 to $50 a month.

## What you need

- The AWS CLI signed in through IAM Identity Center, with the profile `ConnectED`:
  `aws sts get-caller-identity --profile ConnectED` shows your account. When the sign-in
  expires, run `aws sso login --profile ConnectED`.
- Terraform 1.10 or later (`terraform version`).
- `export AWS_PROFILE=ConnectED`, so Terraform uses that sign-in.

## First time

### 1. Bootstrap (once, from your machine)

```bash
cd infra/bootstrap
terraform init
terraform apply          # review the plan, then type yes
terraform output
```

The state of this stack stays in `infra/bootstrap/terraform.tfstate` on your machine (never
committed). Keep a copy somewhere safe. Losing it stops nothing running; its resources can be
imported into a fresh state.

### 2. Tell GitHub (the repository owner does this)

GitHub → the repository → **Settings** → **Secrets and variables** → **Actions** →
**Variables** tab → **New repository variable**, once for each:

| Name | Value |
|---|---|
| `TF_STATE_BUCKET` | the `state_bucket` output |
| `AWS_PLAN_ROLE_ARN` | the `plan_role_arn` output |
| `AWS_APPLY_ROLE_ARN` | the `apply_role_arn` output |
| `ALERT_EMAIL` | where the budget alarm emails go |

These are names, not secrets: no AWS key is stored in GitHub. Until they are set, the `infra`
workflow is skipped.

### 3. Production (by CI)

Re-run the `infra` check on the open merge request (or push to it). It posts a plan listing
what will be created. Merging the merge request applies it. The first apply takes about 15 to
20 minutes, mostly the database and CloudFront.

No more variables are needed. The `deploy` workflow finds the host by its `connected-app` tag,
the buckets by their names, and the CloudFront distribution by its `ConnectED` comment.

### 4. Deploys (by CI, on every merge)

When `check` passes on `main`, `.github/workflows/deploy.yml` deploys that commit with the
apply role:

1. **API.** It installs the production dependencies, packs `api/` and `deploy/` into one file,
   puts it in the reports bucket under `_releases/`, and asks the host (through Systems
   Manager, so no SSH port is open) to unpack it into `/var/www/connected/releases/<commit>-<run>`
   and run `deploy/release.sh`. That script writes `.env` from Parameter Store and the
   database secret, runs the migrations and caches, installs the nginx site and the queue
   worker, then switches `current` to the new release in one rename and checks `/up`. A
   failure before the switch leaves the previous release serving.
2. **PWA.** Once the API is live, it builds the frontend and uploads it: the hashed files
   under `assets/` cached for a year, everything else revalidated on every visit. Then it
   clears CloudFront's cache.

To roll back, re-run the `deploy` workflow of an earlier merge from the Actions tab.

The first deploy needs production to exist, so merge nothing that triggers it before step 3
has finished.

To read production's outputs or plan from your machine:

```bash
cd infra/production
terraform init -backend-config="bucket=<the state_bucket output>"
echo 'alert_email = "you@example.com"' > terraform.tfvars   # never committed
terraform output
```

Do not `terraform apply` production from your machine after the first time; changes go
through a merge request, so they are reviewed and recorded.

## Day to day

**A shell on the host** (no SSH; needs the Session Manager plugin for the AWS CLI):

```bash
aws ssm start-session --target <APP_INSTANCE_ID>
```

**Saving money when nobody is testing:** stop both, start both before you need them. RDS
starts itself again after 7 days stopped.

```bash
aws ec2 stop-instances --instance-ids <APP_INSTANCE_ID>
aws rds stop-db-instance --db-instance-identifier connected
# and later
aws rds start-db-instance --db-instance-identifier connected
aws ec2 start-instances --instance-ids <APP_INSTANCE_ID>
```

**The report assistant's model key**, once the privacy advisory allows it (ADR 0013). Never in
chat, a commit, or GitHub:

```bash
aws ssm put-parameter --name /connected/production/ANTHROPIC_API_KEY --type SecureString --value '<key>'
```

The next deploy writes it into the host's `.env`.
