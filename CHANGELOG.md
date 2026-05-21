# Changelog

All notable changes to the Pharmacopedia extension are recorded here.
Version is also reflected in `extension.json` (`version` field) and on
the live wiki at `About:Pharmacopedia.ext`.

Format roughly follows [Keep a Changelog](https://keepachangelog.com/).
Dates are UTC.

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
