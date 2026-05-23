# Changelog

All notable changes to the Pharmacopedia extension are recorded here.
Version is also reflected in `extension.json` (`version` field) and on
the live wiki at `About:Pharmacopedia.ext`.

Format roughly follows [Keep a Changelog](https://keepachangelog.com/).
Dates are UTC.

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
