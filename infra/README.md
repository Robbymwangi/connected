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
   Manager, so no SSH port is open) to unpack it into `/var/www/connected/releases/<commit>-<run>-<attempt>`
   and run `deploy/release.sh`. That script writes `.env` from Parameter Store and the
   database secret, runs the migrations and caches, installs the nginx site and the queue
   worker, then switches `current` to the new release in one rename and checks `/up`. A
   failure before the switch leaves the previous release serving.
2. **PWA.** Once the API is live, it builds the frontend and uploads it: the hashed files
   under `assets/` cached for a year, everything else revalidated on every visit. Then it
   clears CloudFront's cache.

To roll back, re-run the `deploy` workflow of an earlier merge from the Actions tab (possible for
30 days after that run), or revert the merge on `main`, which deploys like any other merge.

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

**Saving money for a few days:** stop both, start both before you need them. RDS starts
itself again after 7 days stopped; for longer, take production down (below).

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

## Taking production down, and bringing it back

Production costs roughly $40 to $50 a month. When it is not needed (no credits, no pilot yet),
take it down rather than stopping it: a stopped database restarts itself after 7 days, and a
taken-down production costs only cents (the state bucket, and one database snapshot). The
bootstrap stays: the state bucket, GitHub's sign-in, and the two roles cost nothing.

Everything in production is deleted except a final database snapshot, `connected-final`. Do it
only when nothing in production needs keeping.

**1. Switch off the two workflows** in GitHub, so a merge neither deploys to a host that is not
there nor rebuilds production: Actions, then **deploy**, then the menu, then **Disable workflow**;
the same for **infra**.

**2. Lift the safeguards, then destroy**, from `infra/production` on your machine, signed in to
AWS (`aws sso login`), with the `terraform.tfvars` from the first setup:

```bash
export AWS_PROFILE=<your profile>
terraform init -backend-config="bucket=<the state_bucket output>"
terraform apply  -var allow_destroy=true   # expect only the database and the two buckets to change
terraform destroy -var allow_destroy=true  # everything; CloudFront takes about 15 minutes
```

`allow_destroy` is off in every other apply, CI's included, so the safeguards come back on the
next normal one.

**To bring it back:**

1. Delete the old snapshot first, or the next take-down will refuse to reuse its name:
   `aws rds delete-db-snapshot --db-snapshot-identifier connected-final` (or keep it under
   another name with `copy-db-snapshot` if its data matters).
2. Enable the **infra** workflow, then run it by hand: Actions, **infra**, **Run workflow** on
   `main`. It applies `main` as it stands and creates everything again (about 20 minutes). The
   app address changes: CloudFront issues a new one, shown as `app_url` at the end of the apply.
3. Enable the **deploy** workflow and run it by hand the same way. It deploys `main` to the new
   host, once `check` has passed on that commit.

Run them by hand rather than re-running an old run: GitHub allows a re-run for only 30 days, and
a re-run replays that run's own commit, not `main`.
4. Provision the school again (`connected:provision-school`, as in the first setup) and set it
   up in the admin panel.
