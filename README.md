# KREATIV AI Content Optimizer

This build combines draft generation for new commercial font posts with the existing optimization workflows for older posts.

## Included modules
- `Generator`
- `Audit & Queue`
- `Suggestions`
- `Categories`
- `Tags`
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
  - post content
- creates WordPress draft posts only after review
- assigns the generated post into the `Fonts / Designer / Foundry / Font Style` category structure when those parent categories exist
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
  - verified details
- improved content template for stronger font-review style rewrites

## Automation
- scheduled audits for existing content using WordPress cron
- automation settings for:
  - frequency
  - post type
  - scan limit
  - fonts-only scope
  - issue filter
- can auto-generate AI for newly queued suggestions
- can auto-approve only high-confidence suggestions
- can auto-apply only higher-confidence approved old-post suggestions
- leaves lower-confidence items in the review queue as exceptions
- can process a generator URL inbox in batches
- can auto-create high-confidence drafts from inbox URLs
- can auto-schedule generated posts at fixed spacing intervals
- leaves weaker or failed generator previews in the generator review queue
- stores per-item automation logs and exposes a unified exception inbox
- does not auto-publish posts

## Notes
- The generator uses best-effort inference from the supplied URL, so every preview should be reviewed before creating drafts.
- This merged build reuses the optimizer's OpenAI settings. The old descriptor plugin's separate API settings are no longer needed if you move to this version.
