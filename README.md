# KREATIV AI Content Optimizer

This build combines draft generation for new commercial font posts with the existing optimization workflows for older posts.

## Included modules
- `Generator`
- `Refresh`
- `Review`
- `Taxonomy`
- `Settings`

## Generator
- accepts one or more marketplace URLs
- uses the plugin's existing OpenAI settings
- generates draft previews for:
  - title
  - image URL
  - designer
  - foundry
  - font style
  - tags
  - page summary
  - post content
- creates WordPress draft or scheduled posts after review or automation
- assigns the generated post into the `Fonts / Designer / Foundry / Font Style / Font Mood / Font Use Case` category structure when those parent categories exist
- attempts to sideload the preview image and set it as featured image

## Editorial rewrite and optimization
- stronger editorial AI prompt for old font posts
- anti-filler writing guide
- structured output for:
  - title
  - intro
  - visual analysis
  - specific use cases
  - pairing notes
  - verified facts
- optimizer template now matches the generator's current HTML section structure

## Automation
- scheduled audits for existing content using WordPress cron
- can auto-generate AI for newly queued suggestions
- can auto-approve only high-confidence suggestions
- can auto-apply only higher-confidence approved old-post suggestions
- can process an automation queue of new marketplace URLs in batches
- can auto-create and auto-schedule high-confidence generated posts
- stores per-item automation logs and exposes a unified exception inbox
- diagnostics mode shows stage-specific failure data for generator and automation issues
- does not auto-publish posts

## Direct deployment from GitHub
This repo includes a GitHub Actions workflow that deploys the plugin to your WordPress server after each push to `main`, using the same `WP_*` secret pattern as your theme deployment.

### Required GitHub Actions secrets
- `WP_SSH_HOST`: SSH host for the web server
- `WP_SSH_PORT`: SSH port, usually `22`
- `WP_SSH_USER`: SSH user
- `WP_SSH_PASSWORD`: SSH password
- `WP_REMOTE_PATH`: full remote path to the live plugin directory

Example `WP_REMOTE_PATH`:
- `/srv/htdocs/wp-content/plugins/kreativ-ai-content-optimizer`

### How deploy works
1. GitHub Actions checks out the repo
2. validates the required WordPress deployment secrets
3. uploads the plugin repo contents over SFTP to the live plugin directory

This deploys the plugin folder directly instead of installing from ZIP.

### Local deploy helper
If you still want local deployment from your machine, the helper script remains available:

```bash
DEPLOY_HOST=example.com \
DEPLOY_USER=deploy \
DEPLOY_PORT=22 \
DEPLOY_PLUGIN_DIR=/srv/htdocs/wp-content/plugins/kreativ-ai-content-optimizer \
bash scripts/deploy-plugin.sh
```

Optional:
- `DEPLOY_SSH_KEY_PATH=/path/to/private/key`
