# Changelog

All notable changes to the Pharmacopedia extension are recorded here.
Version is also reflected in `extension.json` (`version` field) and on
the live wiki at `About:Pharmacopedia.ext`.

Format roughly follows [Keep a Changelog](https://keepachangelog.com/).
Dates are UTC.

## [0.9.8.7] - 2026-05-23

A privacy-honest, audit-follow-up release. Perspective-invite
tokens migrate from cleartext to hashed-at-rest with dual-write
+ hash-first read; the cleartext column itself comes out in
0.9.8.8 once the dual-write window cycles through prod. The
single-assessment ESS card stops fataling on the MyProfile
render. iOS / password-manager autofill works on
Special:UserLogin and Special:CreateAccount. About:Privacy and
Oyami PRIVACY.md adopt empire-parity wording for the
backup-rolloff posture, now naming both the 14-day
active-storage window and the up-to-180-day off-host
deletion-recovery layer honestly (server-claude's Dropbox
finding; durable fix via the Hetzner BX11 migration is queued
in [L1] for 0.9.8.8). The Category index plant column gains
a new "Herbal medicines" sub-section organising plant pages by
source tradition (Ayurveda / Native American / TCM / Unani /
Western clinical), distinct from the Pendell entheogenic axis.

### Added
- **Perspective-invite token hashing (M3 server-claude audit
  follow-up).** New `pcp_perspective_invite.pvi_token_hash`
  column (`BINARY(32)`, `UNIQUE`), backfilled from existing
  rows by `maintenance/BackfillPerspectiveInviteTokenHash.php`
  (idempotent, dry-run flag, per-row WHERE re-check for
  concurrent-write safety). `PerspectiveStore::mintInvite()`
  dual-writes cleartext + hash; `::resolveToken()` looks up
  by hash first, falling back to the cleartext column for the
  deploy edge. AdminCrypto::hashInviteToken reused as the
  canonical SHA-256 helper. Cleartext `pvi_token` column +
  fallback branch drop in 0.9.8.8.
- **iOS / password-manager autofill on auth forms.** New
  `Hooks::onAuthChangeFormFields()` adds `autocomplete`
  attributes to Special:UserLogin and Special:CreateAccount:
  `username`, `current-password`/`new-password`,
  `new-password` on retype, `email`. iOS Safari and password
  managers now offer the right credentials on the right
  field.
- **Herbal-medicines axis in the Category index plant
  column.** `CategoryIndexTag::HERBAL_TRADITIONS` lists five
  source-tradition subcategories (Ayurvedic herbs, Native
  American herbs, TCM herbs, Unani herbs, Western clinical
  herbs), alphabetical, rendered as a fourth section in the
  plant column distinct from the three Pendell volumes. Live
  member counts queried per-tradition.

### Fixed
- **L2: `Ess::SUBSCALES` undefined constant.** Same pattern
  as the Hyd fix shipped in 0.9.8.6. Single-line const add
  (`public const SUBSCALES = [];`) so
  `renderInlineAssessment` finds the empty subscale list on
  single-scale instruments instead of fataling. ESS-PCP card
  on Special:MyProfile no longer 500s.

### Changed
- **About:Pharmacopedia.ext Security & encryption section
  refresh** (per standing close-out routine). Server-claude
  A-L inventory rolled in verbatim where appropriate. New
  ==== Perspective-invite tokens ==== subsection under
  Application-layer cryptography documents the M3 hashing
  posture + the 0.9.8.8 cleartext-column drop. Backups
  subsection updates `REMOTE_KEEP_DAYS` from 60 to 14 and
  names the active-vs-deletion-recovery distinction honestly.
  Honest limitations rewrites the backup-lag and
  perspective-invite items to reflect the post-0.9.8.7 state.
- **About:Privacy backup-rolloff wording.** Adopts empire-
  wide language identical to Oyami PRIVACY.md v0.1: "removed
  from active storage after 14 days; the off-site provider
  keeps deleted files in a recovery layer for up to 180
  additional days, during which the encrypted bundle may
  remain recoverable by the account operator; after that
  window the bundle is permanently deleted. The backup is
  GPG-AES256 encrypted at all times; the off-site provider
  cannot read it." Reverts to clean "up to 14 days" once
  server-claude's Hetzner BX11 migration lands (queued).
- **Backup rotation flipped to 14 days off-host.**
  `REMOTE_KEEP_DAYS` in `/usr/local/bin/pharmacopedia-backup.sh`
  changed from 60 to 14; local `LOCAL_KEEP_DAYS` unchanged
  at 7. Operational truth now matches the privacy-page claim
  for active storage.

### Empire / process
- **Empire-wide timestamp rule changed to PT.** ISO 8601
  with seconds in Pacific Time (offset suffix), e.g.
  `2026-05-23T18:12:32-0700`, replaces the prior UTC-Z form
  in handoff Date: lines, edit summaries, CHANGELOG dates,
  and other timestamped output. Relay-time linter accepts
  PT (preferred) and warns on UTC Z (transition tolerance).
- **Close-out routine transfers to boss-claude.** 0.9.8.7
  is the last close-out interface-claude runs; the empire
  PM (boss-claude) inherits the routine starting 0.9.8.8
  for all three empire sides (PCP / Oyami / Trykl). Full
  playbook handed over at
  `/tmp/handoff_2026-05-24_close-out-playbook-for-boss.md`.

### Audit posture
- C1 (Dropbox deletion-recovery surfacing): A-text shipped
  this release on both PCP About:Privacy and Oyami
  PRIVACY.md; B-text (Hetzner BX11 migration) queued for
  0.9.8.8 server-claude lane.
- M1 (typeahead /api.php rate-limit): deferred to 0.9.8.8.
- M3 (perspective-invite hashing): half-shipped this
  release (dual-write + hash-first read live); cleartext-
  column drop in 0.9.8.8.
- L1 (minors-policy registration gate): deferred per Mark.
- L2 (Ess::SUBSCALES): closed this release.


## [0.9.8.6] - 2026-05-23

A surface-expansion release: every Problem and every Effect gets its
own wiki page in a new dedicated namespace, with auto-generated
medicines lists; every medicine page's problem-cards and effect-labels
now link there directly. The diptych topnav loses the dead Assessments
link and gains a live typeahead search with case-insensitive fallback.
Special:MyProfile drops its redundant Save button now that every block
autosaves; the autosave dot follows the touched control. About:Privacy
is published. One mid-flight fatal in the autosave save-handler is
fixed.

### Added
- **NS_PROBLEM (3008) + NS_EFFECT (3010)** with talk pages, registered
  as content namespaces, searchable by default, under FlaggedRevs (via
  `$wgExtensionFunctions` for correct registration ordering). Pattern
  mirrors the existing Enzyme / Receptor / Phenotype / USLegal block.
- **170 Problem stubs + 288 Effect stubs** auto-created via a new
  `maintenance/migrateProblemEffectStubs.php` CLI script. Each stub
  carries a one-line "Stub" header, the canonical description if any,
  an auto-generated medicines section, and the sentinel
  `Category:Problem stubs` / `Category:Effect stubs` for the buildout
  queue. Idempotent re-run; backfills `p_page_id` / `e_page_id`.
- **`<problemMedicines slug="..." />`** and
  **`<effectMedicines slug="..." />`** parser tags: auto-generated
  "Medicines used for this" / "Medicines that may cause this" lists.
  Queries `pcp_votable_elements` joined to `page`, distincts, sorts
  by title; uses parameterized QueryBuilder.
- **Schema**: `pcp_problem.p_page_id` (INT UNSIGNED NULL) and
  `pcp_effects.e_page_id` (INT UNSIGNED NULL), indexed, link the
  canonical DB row to its wiki page id.
- **Topnav live search**: 180ms-debounced typeahead with an 8-result
  dropdown rendered as an ARIA combobox; arrow / Enter / Escape
  keyboard nav; click-outside to close. Hits the eight content
  namespaces (Main, Category, Enzyme, Receptor, Phenotype, USLegal,
  Problem, Effect). Lives in `ext.pharmacopedia.appearance.js`; uses
  `action=opensearch` first (fast prefix), with an automatic
  `action=query&list=search` fallback on zero hits (catches all-caps
  titles like LSD that the opensearch prefix index cannot
  case-insensitively match). Pinned to the leftmost slot of the
  right-side `.topnav` cluster as a soft-violet input that lifts to
  violet-bright on focus.
- **About:Privacy** wiki page: brief plain-language policy covering
  what the site collects, third parties (Cloudflare Turnstile, Gmail
  SMTP, Dropbox-as-encrypted-backup-sub-processor), cookies,
  retention windows, encryption (Let's Encrypt TLS, PBKDF2-SHA512
  passwords, OATHAuth 2FA, AdminCrypto X25519 sealed-box + AES-256-
  GCM with passphrase / managed modes, OAuth 2.0 + PKCE for the iOS
  app, GPG-AES256 backups), and the manual-today deletion path with
  the up-to-60-day backup-lag disclosure.

### Changed
- **Medicine page link surface**: every `<problem ref="...">` card title
  now links to `Problem:<Name>`; every `<effect ref="...">` label
  links to `Effect:<Name>`. The sidebar Common-uses list links the
  same way. `Special:Problem/<slug>` auto-redirects to the matching
  NS page when `p_page_id` is set (legacy aggregate stays as
  fallback). Special-page fallback paths preserved for any link that
  predates the migration.
- **Diptych topnav**: removed the dead "Assessments" link. Search
  rebuilt as a live input + dropdown (was a static link to
  Special:Search). Browse / Categories / Log in stay quiet dim links
  on the right; Search sits as the leftmost item in the right cluster
  as the prominent action.
- **Special:MyProfile**: removed the bottom "Save profile" submit
  button. Every editable block (identity, demographics, ocean,
  bfi10, mbti, enneagram, every picked inline assessment, diagnoses,
  meds) autosaves via `blocksave.js` 800ms after the last change.
  Stale "click Save profile" copy on the inline-assessment helper
  paragraph updated to autosave language; corresponding PHP doc-
  comments corrected.
- **Blocksave save-status dot**: now pins to the top-right of the
  control that was last manipulated, via absolute positioning at
  `getBoundingClientRect()` coordinates with the chip reparented to
  `document.body` and a scroll/resize repin handler. The bottom-of-
  block chip is retired in favor of this single follow-the-cursor
  indicator. Final size 13px with 0.8 opacity on active states; pin
  offset 4px right, 11px up to sit just outside the control corner.

### Fixed
- **Special:SaveProfileBlock** rejected 6 of the 12 picker-eligible
  assessment cards with `{ok:false, error:"unknown block: assessment-<key>"}`,
  so edits never persisted. Added missing switch cases for `asrs`,
  `amaas`, `hyd`, `bsl23`, `ess`, `ocipcp`; all 12 now save via the
  same `saveAssessment()` dispatch.
- **Blocksave dot rendering**: the `.pcp-block-save-dot` rule's
  `font-size: 0` collapsed every em-sized `width` / `height` to zero
  px; only the 1-2px `box-shadow` ring rendered, making every size
  adjustment look identical. Switched the size declarations to
  absolute px so the dot renders at its intended dimensions
  regardless of the rule's font-size reset.
- **Topnav search case-sensitivity**: lowercase queries against
  all-caps titles (e.g., `lsd` against `LSD`) returned "No matches"
  via `opensearch` alone. The automatic `list=search` fallback
  introduced this release catches them. Same hybrid pattern was
  handed off to app-claude for the iOS app's typeahead.

### Audit follow-ups (2026-05-22 sweeps)
Closed in this tag:
- **L3**: `$wgEnableUploads` deduped in LocalSettings.php; live value
  (= true) unchanged, dead earlier `= false` removed. (Config-only,
  not in the extension repo.)
- **Post-push surface audit** (server-claude, 2026-05-23): no
  critical, no high; all six pre-flighted questions cleared
  (parser-tag XSS, SpecialProblem redirect ACL, migration script
  attribution, namespace lockdown, schema parameterization,
  redirect trust model).

Deferred to the next release (0.9.8.7):
- **M3**: `pcp_perspective_invite.pvi_token` cleartext at rest.
  Schema migration + dual-write rotation, ~half day, parser-claude
  (schema) + interface-claude (code).
- **OAuth2 JWT signing keypair**: install
  `$wgOAuth2PrivateKey` / `$wgOAuth2PublicKey` so
  `/rest.php/oauth2/authorize` and `/oauth2/access_token` stop
  emitting `LogicException: Invalid key supplied`. Launch-blocker
  for the iOS app's first sign-in. ~30 min, server-claude.
- **M1 typeahead rate-limit**: the new live search is a fresh
  `/api.php` amplification surface with no server-side cap.
  Recommended `$wgRateLimits['searchrequest']` + fail2ban filter
  on `/api.php?action=opensearch|query&list=search`. server-claude.

Monitoring (no action this tag):
- **L1** privacy-policy minors-language flagged by the audit reads as
  a discrepancy in the audit output, not in the published page; the
  live `About:Privacy` is as drafted (no age restriction per Mark's
  call). Carried as a policy-side polish item.

### Deploy notes
- Migration script attribution: `EDIT_INTERNAL` flag intentionally
  skips AbuseFilter + captcha + rate limits per MW convention for
  ops migrations. All 458 created pages credit MDElliottMD; expect a
  one-time contribution-volume spike on Special:Contributions.
- Page-id range from this run: 1367-1824 (170 problems + 288
  effects). Range may differ on staging; the migration backfills
  `p_page_id` / `e_page_id` to the actual ids regardless.
- iOS app search typeahead was reported case-sensitive too;
  handoff to app-claude carried the same opensearch + list=search
  hybrid pattern (handoff dated 2026-05-22).

## [0.9.8.5] - 2026-05-22

A consolidation release on a busy day. The 0-5 mouseover rating
widget replaces the legacy slider on problem cards and lands on
COMMON USES rows. OAuth 2.0 and PharmaAppSync go live on prod,
unblocking the iOS app. The WAVE accessibility audit closes its
first full pass: 18 hard contrast fails fixed, three sitewide
fieldset gaps closed, Vector's native client-preferences picker
removed (which restored three locked house rules at once), and
a 12px chrome text floor enforced across the site. A new seat,
a11y-claude, opens for continuous WCAG monitoring. About:Privacy
is published. One in-flight fatal on Special:MyProfile is fixed.

### Added
- `.pcp-rate` widget: a single 0-5 component that is both display
  (aggregate fill + 0-5 number) and input (mouseover, keyboard
  arrows step 0.1, touch press-and-drag, click to commit). Used
  on problem cards (`ProblemTag`) and on COMMON USES sidebar rows
  (`CommonUsesTag`). New shared renderer `RateWidget.php`. Commits
  via `action=pharmacopedialikert`. JS interaction lives in
  `ext.pharmacopedia.js`.
- COMMON USES rows now sort by aggregate rating descending and
  each row carries the inline rate widget.
- OAuth 2.0 provider (MWOAuth REL1_46) loaded on prod against
  MediaWiki 1.46.0-beta. Special pages live, consumer registration
  works, mobile consumer for the iOS app proposed and approved.
  Authorization-code + refresh-token grants, basic scope. Per-page
  settings: `$wgMWOAuthSharedUserIDs = true`,
  `$wgMWOAuthSharedUserSource = 'local'`. The `authorize` and
  `token` endpoints await JWT signing keypair installation (see
  Audit follow-ups below) before a sign-in round-trip works
  end-to-end.
- PharmaAppSync extension live on prod: four `pharma_*` tables
  (favorites, recently-viewed, annotations, widget-responses)
  serving the iOS app over eight REST routes under
  `/rest.php/pharmacopedia/v1/`. SyncHandler joins `page` for
  `page_title` so the app keys annotations by title.
- HTML+JS OAuth callback bridge served at
  `https://pharmacopedia.wiki/app/oauth-callback`, forwarding
  `?code=` + `?state=` to the `pharmacopedia://oauth` deep-link
  scheme registered by the iOS app.
- Sidebar entry "My Assessments" links to
  `Special:MyProfile#pcp-assessments` and an on-load /
  on-`hashchange` JS hook auto-expands the targeted
  `.pcp-prof-section` fieldset.
- About:Privacy: a brief, plain-language privacy policy covering
  the website and the iOS app. Encryption, retention, third
  parties, deletion, no age restriction.
- a11y-claude seat (the eighth claudefam seat): continuous WCAG /
  WAVE monitoring of the site. Brief at
  `/home/claude/a11y_claude_seat.md`, WAVE API key in
  `/home/claude/wave_api_key` (mode 600), sweep workspace at
  `/home/claude/a11y_sweeps/`.

### Changed
- Star rating component re-unit from 0-100 to 0-5. The aggregate
  `mean` returned by `LikertStore::getAggregates()` is now a 0-5
  value; existing scores rescaled in place. Old per-skin
  `.pcp-rate-empty` color literals dropped in favor of a uniform
  `var(--pharma-ink-mute)` cascade.
- `--pharma-ink-mute` re-points under `.pcp-skin-plants` to
  `#9c958a` (warm-loam local muted) and under
  `body.pcp-skin-fungi` to `#888a7d` (cool-flesh local muted).
  Every muted-text consumer picks up the skin-local hue
  automatically.
- Main Page: text in both origin columns centered. Plant header
  centered as a group via `justify-content`. The large
  "Pharmaceutical" and "Plant" origin titles now link to
  `Category:Pharmaceutical` and `Category:Plants` respectively.
- Main Page Self-assessments portal rewritten: blurb is now
  "Enneagram, MBTI, and many more, stored with top-tier
  encryption only you can access." The "Take an assessment ->"
  link points to `Special:MyProfile#pcp-assessments` (auto-opens
  the assessments section via the hash hook).
- Vector's native client-preferences picker is hidden site-wide
  (`#vector-appearance-dropdown`, `#vector-appearance-pinned-container`,
  `#vector-appearance-unpinned-container`,
  `.vector-appearance-landmark` to `display: none`). This restores
  three locked house rules in one move: no light modes, no width
  toggle, extension owns the text-size control via its own
  Appearance rail.

### Accessibility (WAVE pass 1)
- All 18 hard AA contrast fails fixed: `.pcp-rate-empty` (5 on
  Fluoxetine), `.pcp-up-card-date` (11 on Special:UserProfile),
  `.mbti-axis-strength.is-balanced` (2 on Special:UserProfile)
  all to `var(--pharma-ink-mute)`.
- Chrome 12px text floor enforced site-wide: 21 sub-12px font-size
  declarations in `ext.pharmacopedia.frontpage.css` lifted to 12px;
  five named SocialProfile selectors lifted via skin-layer override;
  universal inline-style sweep catches `style="font-size:Npx"` for
  N in 7..11.
- Pendell-axis Main Page card disambiguated to
  `Category_index#pendell-axis` to clear the WAVE redundant-link
  alert.
- `pcpA11yNitpicks` universal JS pass (runs on every page from
  `ext.pharmacopedia.appearance.js`): strips `accesskey` attributes
  (Vector ships 13), strips `title="X"` attributes when they equal
  the link text (clears the footer Pharmacopedia:Copyrights and
  similar), removes empty `<label for=X>` elements when the target
  input has another accessible-name source, normalizes positive
  `tabindex` values to 0 (Special:CreateAccount ships 6), adds
  `role="heading" aria-level="2"` to SocialProfile's `#profile-title`
  on Special:UserProfile, and wraps the three Vector Appearance
  picker radio groups in `<fieldset>` + `<legend
  class="visuallyhidden">` (idempotent via `.pcp-a11y-fs-wrap`
  marker; soft-no-op since the picker itself is now hidden).
- Special:CreateAccount "Username available" message reads as a
  positive confirmation (Codex `--success` styling) instead of a
  warning chip.

### Fixed
- Special:MyProfile rendered only the first three sections,
  cutting off the Save button and everything after Personality/
  Assessments. Root cause: `Assessments\Hyd` (shipped same day)
  was missing the `SUBSCALES` const that the shared
  `renderInlineAssessment` iterates. Added
  `public const SUBSCALES = []` (HYD-PCP has no multi-item
  subscales, mirrors Asrs). Also made
  `SpecialMyProfile::renderInlineAssessment` (and the parallel
  block in `renderAssessments`) tolerate an array-shaped
  `interpret()` return; `Hyd::interpret()` returns
  `[ 'overall' => str, 'low_domains' => str[] ]` while every
  other assessment returns string.
- WAVE "very small text" reports on Main Page were stale-cache
  artifacts after the chrome floor lift; verified zero sub-12px
  in the live DOM via Chrome MCP.

### Audit follow-ups (2026-05-22 sweep)

Closed in this tag:
- **H1**: `$wgUpgradeKey` rotated from 16 hex chars (64 bits) to
  64 hex chars. The `/mw-config/` 403 at vhost layer was already
  closing the realistic attack path; this hardens the key itself.
- **M2**: `/var/log/mediawiki/` tightened to 750 with files
  0640 and group `adm`, matching the Apache log pattern. Two
  human seat accounts (debian, claude) lose tail access; sysop
  reads continue via sudo.
- **L3**: `$wgEnableUploads` dedup'd in LocalSettings.php. The
  earlier `= false` assignment was dead (line 171 `= true` won);
  earlier line removed so the live value reads truthfully in
  one place.

Deferred to the next release (0.9.8.6):
- **M3**: `pcp_perspective_invite.pvi_token` cleartext at rest.
  Schema migration (add `pvi_token_hash binary(32) UNI`, dual-
  write through one rotation, drop the cleartext column),
  ~half a day, parser-claude (schema) + interface-claude (code).
  Severity caveat: an attacker with DB read can submit
  perspectives under a planted invite identity; not access to
  medical data, but worth fixing on its own tag.
- **OAuth2 JWT signing keypair**: install
  `$wgOAuth2PrivateKey` / `$wgOAuth2PublicKey` (or the default
  `/var/lib/mwoauth2/oauth-{public,private}.key` paths), private
  key 600 www-data:www-data, restart FPM, exercise the
  authorize+token round-trip. ~30 min, server-claude. Launch-
  blocker for the iOS app sign-in; surfaced by server-claude's
  post-upgrade log review (two `LogicException: Invalid key
  supplied` at /rest.php/oauth2/authorize from 19:35 PDT today,
  predating the audit upgrades). Loading the extension and
  registering the consumer never exercises this code path; the
  first sign-in attempt does.

Monitoring (no action this tag):
- **L1**: a single `ClientEntityInterface not found` exception
  fired at 01:19:43 UTC during the OAuth2 rollout; League is
  installed and OAuth endpoints respond healthily now. Almost
  certainly a stale opcache snapshot mid-deploy. If it recurs,
  force opcache reset post-deploy.
- **L2**: AdminCrypto `master.key` absent on disk is the
  correct lazy state (Mode A users only today; directory exists
  with 700 perms ready for the first Mode B write).
- **M1**: 9 packages upgradable (libgcrypt, krb5 set, php-apcu,
  libgd3, three oldstable-security). `apt full-upgrade` waits
  on a maintenance window; tracked separately, not extension
  scope.

### Deploy notes
- Empirical OAuth test (path C): MWOAuth REL1_46's loose
  `"requires": ">= 1.46"` accepts the 1.46.0-beta core; no core
  upgrade required. Verified clean on staging, then on prod.
- League OAuth2 server Composer dependency only loads on actual
  propose-submit, not on page render. Staging smoke that only
  checks renders will miss the missing dependency; running
  `composer install` inside `extensions/OAuth/` is required as
  part of the prod cutover. Logged for future deploys.
- PharmaAppSync's REST routes declare three `*ItemHandler` classes
  bundled inside their plural sibling files; the v1 upload was
  bounced back to app-claude for PSR-4-compliant splitting before
  the prod load. Lesson: declaring an autoload namespace does not
  validate the class-per-file shape; check file layout on extension
  intake.
- OAuth 2.0 callback URLs are required to be HTTPS by MWOAuth core;
  the `pharmacopedia://` scheme is reached via the
  `/app/oauth-callback` HTML+JS bridge. Apple Universal Link is a
  future migration; the registered callback URL does not change.

## [0.9.8.0] - 2026-05-22

A feature release: the "Administer to others" subsystem, the
two-origin diptych front-of-house, the full-width layout with the
Appearance rail, and the fungi sub-skin. Major items below; see
`git log` for file-level detail.

### Added
- Administer to others: a registered user (the owner) can send any
  of the twelve registered assessment scales to people outside the
  wiki by a one-time link, collect their results, and follow them
  over time, with no account required of the respondent. Owner hub
  `Special:AdministerAssessments`, respondent take-flow
  `Special:RespondToAssessment`. `AssessmentRegistry` is the single
  source of truth for the twelve instruments. `AdminCrypto` provides
  per-owner X25519 keypairs, `crypto_box_seal` result encryption,
  and two protection modes: managed (a server master key, seamless)
  and an opt-in zero-knowledge passphrase mode (Argon2id, AES-256-GCM
  key wrapping, unrecoverable by design). A decoupled, de-identified
  research pool with no link back to the owner. New module
  `ext.pharmacopedia.administer`.
- The two-origin diptych: the Main Page and the Category index
  rebuilt as chromeless full-viewport splashes giving the
  pharmaceutical and plant origins equal face. Parser tags
  `<frontpage>` (`FrontPageTag`) and `<categoryindex>`
  (`CategoryIndexTag`); `DiptychChrome` for the shared topbar and
  footer; a `body.pcp-diptych-page` chromeless mode that hides the
  Vector chrome; a full-height two-origin split. New modules
  `ext.pharmacopedia.frontpage` and `ext.pharmacopedia.categoryindex`.
- Full-width layout: an edge-to-edge content frame with the prose
  held to a readable measure, plus a collapsible Appearance rail
  carrying a reader text-size control. New module
  `ext.pharmacopedia.appearance`.
- Fungi sub-skin (`.pcp-skin-fungi`): a specialization of the plants
  skin for fungal medicine pages, resolved by a direct
  `Category:Fungi` tag (checked ahead of the plant test). A damp
  cool-dark palette, a spore-dust grain, a mushroom mark, and fungi
  section-marker hues; everything else inherits from the plants
  skin. New module `ext.pharmacopedia.skin.fungi`.
- HYD-PCP ("How Ya Doin?"): an original wellbeing check-in. Eight
  everyday domains (mood, functioning, sleep, movement, social
  connection, eating, energy, stress), each one bipolar slider,
  scored as the mean of the domains answered and built to be
  re-taken over time. An inline test on `Special:MyProfile`, a
  report at `Special:MyAssessment/hyd`, and a `Special:UserProfile`
  card. A locally authored instrument, not validated.
- New parser tags `<classGrid>` and `<classTree>` for medicine-class
  category overviews, and `<pharmaLiterature>` for the per-medicine
  literature section.
- `Special:PCPCtrls`: an "Administer to others" section and a "Wiki
  front-of-house" section; section rows may now point at a content
  page, not only a Special: page.

### Changed
- Administer: the Mode A passphrase encryption was hardened. The
  Argon2id KDF is raised to MODERATE limits via a scheme-v2 design
  (`uk_scheme_version`); an owner created under the earlier scheme is
  transparently re-wrapped on their next unlock.
- The thirteen assessment scorers are wired through
  `AssessmentRegistry` (radio / slider / bipolar response models) so
  any of them can be administered to outside respondents; added a
  server-side `Ocean` (BFI-10) scorer.
- `Hooks::resolvePcpSkin` gains the fungi branch ahead of the plant
  test; the docblock origin-tag example corrected to the canonical
  `Category:Plants`.
- The Administer surfaces refined per design review: the "Not sure"
  dim scoped to the answer content, an explicit slider track and
  thumb, a quiet per-invite delete, hover and focus states, and a
  narrow-viewport slider-row wrap.
- The Main Page masthead lines render in lifted pharma-to-plant
  gradient containers.
- About:Pharmacopedia.ext reconciled to the current surface (this
  resolves the documentation follow-up flagged at the 0.9.7.0 cut).

### Schema
- New tables: `pcp_administer_respondents`, `pcp_administer_invites`,
  `pcp_administer_assessments`, `pcp_administer_userkey`,
  `pcp_administer_research`.
- `pcp_administer_assessments.aa_respondent_enc`: the respondent's
  own copy of each result, sealed to a key derived from the invite
  token.

### Fixed
- The effect-card valence slider stayed interactive before the
  effect was marked experienced; it is now disabled until "Yes".
- The Appearance-rail text-size control had no visible effect.
- Security audit M4: the de-identified research row was inserted
  unconditionally, so a resubmit or replica-lag race duplicated it;
  it is now written only when the submission completed a scale.
- Security audit L4: `Special:MyPerspectives` no longer creates a
  profile row on a plain page view (`getOrCreateForUser` moved to
  the invite-minting POST; new read-only `getForUser`).

## [0.9.7.0] - 2026-05-21

Catch-up release: the repository had fallen behind the live extension,
so 0.9.7.0 commits a broad backlog of subsystem work in one cut.
Major items below; see `git log` for file-level detail.

### Added
- Perspective subsystem: per-axis perspective records with their own
  store / registry / type-handler layer, Special:Perspective and
  Special:MyPerspectives, and a share-invite flow. New tables
  `pcp_perspective`, `pcp_perspective_invite`.
- Formal testing: a standardized-test score log on the user profile.
  `FormalTestStore` plus the `pharmacopediaformaltest` API, the
  "Formal testing" section on Special:MyProfile (add / edit / delete)
  and a read-only render on Special:UserProfile. New tables
  `pcp_formal_tests` (catalog) and `pcp_user_test_scores`.
- Per-field visibility for formal-testing scores: separate privacy
  for raw score, percentile and pass/fail (`uts_vis_raw`,
  `uts_vis_pct`, `uts_vis_passfail`), each gated independently on the
  public profile; three privacy toggles in the editor, per-field
  badges on the card.
- ASRS adult-ADHD screener: inline assessment, and a verdict-card
  render on Special:UserProfile (binary screen result with a
  cardinal-item strip).
- AMAAS-SR experimental attention self-report: inline assessment, and
  a 3-axis radar featured card on Special:UserProfile (Inattention /
  Hyperactivity / Impulsivity subscales, with an explicitly arbitrary
  66.66% experimental threshold labelled as not a validated cutoff).
- Assessment card family in the stylesheet: the verdict card
  (`.verdict` / `.v-*`) and the featured-card radar face
  (`.fc` / `.fc-*` / `.viz-cap` / `.fact`).
- PhenotypeResolver: a 16-gene diplotype-to-phenotype resolver, plus
  maintenance ingest scripts for CPIC alleles / diplotypes /
  pair-levels / recommendations, DPWG guidelines, and the FDA
  pharmacogenomic-biomarker and CYP drug-interaction tables.
- Interaction-flag voting: an `InteractionFlagApi` and the granular
  PGx interaction voting UI. New table `pcp_interaction_flags`.
- Template:MedTemplate optional `history` rendered parameter (a
  "History" section after the intro, before "Experience").
- Shulgin's Corner template CSS component (`.pcp-shulgin*`), on the
  pharma and plants skins.
- Plants skin: an earth-toned dark skin, with self-hosted Geist,
  Newsreader and Source Serif web fonts (`resources/fonts/`,
  `resources/skins/`).
- Herbal-medicine schema scaffolding (`pcp_herbal_dose`,
  `pcp_herbal_preparation`, `pcp_herbal_use`).
- Maintenance / audit scripts: orphan-category audit, PMID-against-
  eutils verification, PGx-invariant validation, slug canonicalization.

### Changed
- WCAG S1 heading remediation: neutralised the border-bottom and
  font-weight carried by relabelled module and section headings so
  they keep their visual treatment without the heading regressions.
- Pharma stylesheet corrections; Main Page module centering;
  `--pharma-ink-mute` recoloured for contrast.
- The Pendell quote renders in italic again, under the verbatim-quote
  exception to the no-decorative-italics rule.
- The `.pcp-vis-toggle` visibility-cycle handler is now delegated, so
  the toggle binds regardless of when its element enters the DOM.

### Schema
- New tables: `pcp_perspective`, `pcp_perspective_invite`,
  `pcp_formal_tests`, `pcp_user_test_scores`, `pcp_interaction_flags`,
  plus the PGx allele / diplotype and herbal tables.
- `pcp_user_test_scores`: `uts_raw_is_estimate` and
  `uts_pct_is_estimate` estimate flags; `uts_vis_raw`, `uts_vis_pct`,
  `uts_vis_passfail` per-field visibility, backfilled from the
  record-level `uts_vis`.
- `pcp_interactions`: added `pi_ingestion_id`; `pi_mechanism` widened.

## [0.9.6.9] - 2026-05-19

### Added
- Pharmacogenomic + mechanism rendering inside `<pharmaInteractions/>`:
  tiered display (FDA Boxed Warning → CPIC pharmacogenomic recommendations →
  Pharmacokinetic mechanism → Inferred from pharmacokinetic data), with
  the existing patient-experience flow preserved as a sub-section below.
- Per-row PGx render: counterparty link (Enzyme:/Phenotype:/Transporter:/
  Variant: prefix-in-title under NS_MAIN), relationship chip, evidence chip,
  intensity bar (0-100), mechanism prose, kinetics annotation with
  decay-half-life note when `pi_kinetics='mechanism_based'`, provenance
  footer for derived edges parsed from `pk_via_<ENZYME>` relationship.
- Static phenotype-slug → human-readable label lookup
  (`cyp2d6_pm` → "CYP2D6 poor metaboliser", etc.).
- Static kinetics decay-phrase lookup (reversible_competitive,
  mechanism_based, irreversible_covalent, allosteric, time_dependent,
  unknown).
- Demographics fetch in `SpecialUserProfile::renderAssessments()`
  (`sex_at_birth` + `gender_identity`, respecting viewer visibility
  clearance) so CATI radar picks the gender-matched non-autistic
  reference group from `CatiNorms::NORMS`.

### Changed
- PID-5-BF UserProfile card: bargauge replaced by 5-axis radar with
  dashed-white cutoff ring at the 2.0 elevation threshold + named-
  constellation caption (Internalizing / Externalizing / Schizotypal /
  Broad personality pathology / Mixed elevation / Sub-threshold).
- CATI UserProfile card: bargauge replaced by 6-axis hexagon radar
  with irregular dashed-white cutoff polygon at the 75th non-autistic
  percentile per subscale (gender-matched). Caption leads with
  qualitative level (Sub-threshold / Moderate / Elevated / Strong)
  rather than a binary above/below count.
- CAT-Q UserProfile card: bargauge replaced by triangle radar with
  closed cutoff polygon (MSK vertex pinned at outer ring to signal
  "no published cutoff"). Cutoff corner dots: solid white for CO/ASS,
  hollow ring for MSK. Caption leads with qualitative level +
  dominant strategy ("Moderate-high camouflaging / primary strategy:
  Assimilation").
- BPNS UserProfile card: `meanHeadline` replaced by triangle radar
  with single dashed-white low-need-threshold ring + C3
  phenomenological caption ("Feeling autonomous and capable / but
  less connected to others"). Subscales inside the ring (< 4.0)
  flagged as unmet.
- All radar-card captions across CATI / PID-5-BF / CAT-Q / BPNS
  unified at 0.9em font-size for consistency.

### Schema (parser-claude side)
- `pcp_interactions`: added `pi_relationship VARBINARY(32) NOT NULL
  DEFAULT 'unspecified'`, `pi_intensity TINYINT UNSIGNED NULL` (0-100),
  `pi_evidence VARBINARY(16) NULL`, `pi_mechanism VARCHAR(255) NULL`,
  `pi_kinetics VARBINARY(32) NULL`. Unique key extended to include
  `pi_relationship` so the same two endpoints can carry multiple
  semantically-distinct edges. New index `pi_rel`.
- New table `pcp_ingestion_log` for audit trail of CPIC API + FDA
  Table of Pharmacogenomic Biomarkers ingestion runs.

### Data
- 11 curated codeine pharmacogenomic edges (4 CYP2D6 phenotype +
  3 enzyme substrate + 4 derived medicine-medicine via the inference
  engine).
- 643 PGx-typed interaction rows total ingested from the CPIC
  consolidated API (`api.cpicpgx.org`), the FDA Table of
  Pharmacogenomic Biomarkers, and the substrate × inhibitor inference
  engine. Evidence distribution: cpic_A 357, fda_label 92, cpic_B 62,
  cpic_strong 40, cpic_C 39, cpic_moderate 22, fda_box 20, primary 7,
  derived 4, theoretical 3.

## [0.9.6] - 2026-05-18

### Changed
- CAT-Q and PID-5-BF rich-report pages on `Special:MyAssessment`
  modernized to match the CATI rich-report aesthetic: header card,
  score bars with above-cutoff chips, pill chips, collapsible
  subscale narratives, dark top-item cards, methodology card.
- WHOQOL-BREF UserProfile card: shipped ranked-bar visualization
  (4 domains sorted ascending; lowest 2 highlighted in color tiers
  as "most impacted"; legend strip).
- MBTI UserProfile card: typecode pill + 4 bipolar axis bars
  (E↔I, S↔N, T↔F, J↔P) with center mark, position dot, and strength
  label per axis; cognitive function stack (Dom/Aux/Tert/Inf) with
  full function names.

## Earlier versions

For changes prior to 0.9.6, see the git history:

```
git log --pretty=format:'%h %ad %s' --date=short extension.json
```

The version field in `extension.json` has been tracked since 0.9.0;
notable earlier milestones documented in `About:Pharmacopedia.ext` on
the live wiki include:

- 0.9.5: WHOQOL-BREF + BPNS assessments; NFCS radar UserProfile card;
  Enneagram custom 9-bar UserProfile card.
- 0.9.4: research_id backfill (stable 10-char hex, opaque, per-user);
  CATI/CAT-Q/PID-5-BF assessment-report system; Life-story observations
  + episodes + visual timeline (vis-timeline 7.7.3 vendored); per-record
  sharing subsystem (rule types: public/private/users/cohort/link_token/
  reciprocal; audit log); ClamAV gate on every file upload.
- 0.9.3 and earlier: foundational extension surface (parser tags
  `<vote>`, `<effect>`, `<problem>`, `<titration>`, `<anecdote>`,
  `<pharmaInteractions/>`, `<pharmaExperience/>`; UserProfile editor;
  diagnosis autocomplete; chip-picker UI framework).
