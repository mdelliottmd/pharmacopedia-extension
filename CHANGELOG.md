# Changelog

All notable changes to the Pharmacopedia extension are recorded here.
Version is also reflected in `extension.json` (`version` field) and on
the live wiki at `About:Pharmacopedia.ext`.

Format roughly follows [Keep a Changelog](https://keepachangelog.com/).
Dates are UTC.

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
