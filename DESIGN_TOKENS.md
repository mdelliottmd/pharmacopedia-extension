# Pharmacopedia design tokens

Single source of truth for the visual language of the Pharmacopedia wiki, across both skins.

This file is the contract between designer-claude (visual decisions) and interface-claude (CSS implementation). When tokens change here, they cascade everywhere. When you see a hex literal in production CSS that should be a token, lift it into this file first, then refactor.

Status: v2.1, locked 2026-05-20. This file, at `/var/www/mediawiki/extensions/Pharmacopedia/DESIGN_TOKENS.md`, is the canonical copy: group-writable, edited in place by designer-claude.

---

## How to use

1. **Reading from this file.** Every color, type size, spacing value, and structural primitive should be referenced by token name, not by literal value. Production CSS exposes these as CSS custom properties on `:root` (pharma, default) with skin-scoped overrides under `.pcp-skin-plants`.
2. **Changing a token.** Edit here first. Then update the matching custom property in the CSS layer. Then sweep any literal occurrences. designer-claude proposes; interface-claude implements.
3. **Adding a new token.** Justify it. Tokens proliferate; resist. If a one-off color is genuinely a one-off, leave it as a literal with a comment. If it's a third occurrence of the same value, it earns a token.
4. **Skin selection.** The plants skin (`.pcp-skin-plants`) is decided by a page's own direct origin category tag, never a recursive category walk. See the Skin selection section below for the full rule and rationale.

---

## Skin selection

Which skin a page renders in is decided by the page's OWN DIRECT category memberships, read once, with no recursive parent-chain walk.

**The rule.** A page renders in the plants skin (`.pcp-skin-plants` body class) only when its direct categories include `Category:Plant` and do not include `Category:Pharmaceutical`. Every other page renders in the pharma default with no body class: pages tagged `Category:Pharmaceutical`, pages tagged both origins, pages tagged neither, Special pages, the Main Page, File and Talk pages.

**Why direct, not recursive.** Under Mark's two-gate origin rule (locked 2026-05-20), every concrete medicine page carries exactly one direct origin tag, `Category:Plant` or `Category:Pharmaceutical`. Class categories, though, are deliberately dual-parented: `Category:Psychedelics` sits under both `Category:Plant` and `Category:Pharmaceutical`, because the psychedelics class spans both origins (DMT is a Plant medicine, LSD a Pharmaceutical one). A recursive "is this page anywhere beneath `Category:Plant`" test would therefore catch every member of a dual-parented class, LSD included, and wrongly hand LSD the plants skin. The direct origin tag is unambiguous; the recursive walk is not. Recursion stays correct, and stays in use, for category-tree browsing and membership listings. Only the skin decision moves to the direct read.

**Category pages.** A category page is resolved by the same predicate against its own direct parent categories. A dual-parented class category such as `Category:Psychedelics` carries both origins among its parents, so it falls to the pharma default. That is the intended outcome and matches the clinical-first identity: pharma is the reference chrome, the plants skin is a contextual specialization. A category whose direct origin is purely Plant renders in the plants skin. The `Category:Plant` page itself is plants-skinned; the mechanism for that single page (a name match, or a self-membership tag) is interface-claude's call.

**Mechanism.** An MW BeforePageDisplay hook, owned by interface-claude, reading the page's direct `categorylinks`. No recursion on the skin path. This change must land before the LSD page goes live, since LSD is the first real test of the origin split.

---

## Pharmaceutical skin — current production (OUTGOING)

The clinical-clean register currently live: geometric, purple-on-near-black, single sans-serif family. **Superseded by the C/Specimen redesign in the next section.** Retained as the record of the outgoing skin, and because component-family tokens still reference these `--pharma-*` names. The redesign re-points the values; the names persist.

### Palette (de-facto tokens, from grep frequency in `ext.pharmacopedia.css`)

Counts are real usage in production CSS as of 2026-05-20 (inventory by interface-claude). Tokens earned their place by repetition.

| Token | Hex | Uses | Role |
|---|---|---|---|
| `--pharma-violet-soft` | `#c4b5fd` | 172 | Brand secondary, bright violet (chip text, radar dot, link) |
| `--pharma-violet` | `#7c3aed` | 133 | Brand primary, deep violet (bars, chip default border) |
| `--pharma-surface` | `#1a1a1a` | 57 | Card background (UserProfile cards, PGx rows) |
| `--pharma-rule` | `#2a2a2a` | 44 | Default rule, section dividers |
| `--pharma-text` | `#ede9fe` | 31 | Text on dark (chip text, card text) |
| `--pharma-violet-mid` | `#5d3b8e` | 31 | Gradient midpoint |
| `--pharma-text-mute` | `#cbd5e1` | 24 | Secondary card text |
| `--pharma-violet-banner` | `#b599e0` | 20 | Section banners |
| `--pharma-tier1-deep` | `#b91c1c` | 19 | PGx FDA Boxed dark red |
| `--pharma-teal` | `#0d9488` | 18 | Link, info accent |
| `--pharma-surface-2` | `#1f1f1f` | 15 | Card background alt (PGx row alt) |
| `--pharma-danger` | `#dc2626` | 13 | Red, severe interaction |
| `--pharma-lichen` | `#6b7280` | 11 | Muted UI |
| `--pharma-ring` | `#3a3a3a` | 10 | Radar background rings |
| `--pharma-track` | `#2d2d2d` | 9 | Intensity/bar track background |
| `--pharma-info` | `#0891b2` | 9 | Info semantic accent |
| `--pharma-bone` | `#e5e7eb` | 8 | Card body text |
| `--pharma-tier1-red` | `#ef4444` | 7 | PGx FDA Boxed accent |
| `--pharma-violet-light` | `#a78bfa` | 7 | Radar ref polygon stroke |
| `--pharma-blue` | `#2563eb` | 7 | Semantic blue |
| `--pharma-green` | `#16a34a` | 7 | Safe accent |
| `--pharma-bg` | `#0f0f0f` | various | Page background |
| `--pharma-bg-deep` | `#000` | various | Deepest panel |
| `--pharma-amber` | `#c89968` | new | Featured emphasis (chip primary marker etc.) |
| `--pharma-custom-accent` | `#d97757` | new | User-typed coral (chip custom variant) |

### Tri-color section headers (MedTemplate, protected)

| Token | Hex | Section |
|---|---|---|
| `--pharma-header-summary` | `#2563eb` | Summary |
| `--pharma-header-pharmacy` | `~#16a34a` | Pharmacy (verify exact value with interface-claude) |
| `--pharma-header-pharmacology` | `#7c3aed` | Pharmacology |

---

## Pharmaceutical skin — C/Specimen redesign (INCOMING, 2026-05-20)

The new default skin. Direction locked as "Specimen": curated-collection register, dense editorial layout, structure from typography and hairline rules rather than boxes. Typeface system locked as Geist (UI, labels, data, nav) plus Newsreader (display, prose, medicine names, section content). No decorative italics. Dark only.

This redesign **re-points the `--pharma-*` token values** below. Token names persist so component families (chip, radar, pgx, card, bar) inherit the redesign automatically when interface-claude updates the values. New tokens are added for surfaces the outgoing skin did not have (three-channel markers, PGx tier severity, the `ink` text ramp).

Reference mockups, all publicly served:
- `https://pharmacopedia.wiki/design/pharma_C2_specimen.html` — Main Page
- `https://pharmacopedia.wiki/design/pharma_fluoxetine.html` — medicine page + PGx tier render
- `https://pharmacopedia.wiki/design/pharma_font_platter.html` — the typeface comparison that selected Geist + Newsreader
- `https://pharmacopedia.wiki/design/pharma_A_lattice.html`, `pharma_B_aurora.html`, `pharma_C_specimen.html` — the three-direction comparison (A and B rejected, C selected)

### Palette

| Token | Hex | Role |
|---|---|---|
| `--pharma-bg` | `#0e0d0f` | Page background |
| `--pharma-bg-2` | `#131216` | Raised surface, dose-card headers |
| `--pharma-bg-3` | `#18171c` | Highest surface |
| `--pharma-ink` | `#ece9f2` | Primary text |
| `--pharma-ink-dim` | `#a29eae` | Secondary text, prose body, lede |
| `--pharma-ink-mute` | `#94909c` | Tertiary text, labels, captions. CORRECTED 2026-05-20: was `#6c6877`, which gave only 3.05-3.42:1 on dark surfaces and failed WCAG 2.2 AA (needs 4.5:1 for normal text). `#94909c` clears ~5.5:1 on the lightest surface it appears on. The muted tier sits closer to `--pharma-ink-dim` now; that compression is unavoidable for AA on a dark skin, and the labels stay distinct by also being small, tracked, and uppercased. |
| `--pharma-violet` | `#8b5cf6` | Brand primary, marker bars, intensity bars |
| `--pharma-violet-bright` | `#a78bfa` | Eyebrows, section numerals, mark color |
| `--pharma-violet-ink` | `#b9a4f0` | Links, hover, "more" affordances |
| `--pharma-line` | `#232230` | Default hairline, module borders |
| `--pharma-line-faint` | `#1a1922` | Faint hairline, in-module row dividers |

### Three-channel section markers (MedTemplate, redesigned)

The protected tri-color logic survives. Execution changes from saturated solid signage bands to typographic marker bars (a short colored vertical bar + section name + label/value rows). The three semantic colors are retained, desaturated to sit on the darker editorial ground.

| Token | Hex | Section |
|---|---|---|
| `--pharma-ch-summary` | `#5b7fc7` | Summary (blue) |
| `--pharma-ch-pharmacy` | `#57a065` | Pharmacy (green) |
| `--pharma-ch-pharmacology` | `#9b7ae0` | Pharmacology (violet) |

### PGx interaction tier severity

Four-tier interaction render. Each tier carries a colored marker; the Derived tier is intentionally the quietest (the inference is named, not hidden).

| Token | Hex | Tier |
|---|---|---|
| `--pharma-tier-boxed` | `#c25a52` | Contraindicated / FDA Boxed (red) |
| `--pharma-tier-strong` | `#c7884a` | Strong caution (amber, not yellow) |
| `--pharma-tier-primary` | `#8b5cf6` | Primary pharmacology (violet) |
| `--pharma-tier-derived` | `#6c6877` | Derived, inferred (grey, de-emphasized) |

The kinetics chip uses `--pharma-tier-strong` for mechanism-based ("persists ~N weeks after stopping") and `--pharma-ink-mute` for reversible ("resolves within ~5 half-lives").

### Evidence-provenance chips (separate axis from tier severity)

Tier severity says "how bad"; the evidence chip says "who established this and how well." Four visual classes; every evidence code maps to one by its nature. Chip text carries the specific body and level.

| Class | Treatment | Codes (full vocab, 13) |
|---|---|---|
| Regulatory | Filled chip, `--pharma-tier-boxed` ground, white text | `fda_box`, `fda_label` |
| Guideline body | Bordered chip, `--pharma-ink-mute` border, `--pharma-ink-dim` text. SOLID border: `cpic_A`, `cpic_B`, `cpic_C`, `cpic_D`, `cpic_strong`, `cpic_optional`, `cpic_moderate`, `ema_hmpc`, `who_monograph`. DASHED border: `dpwg`. | CPIC levels + strengths, DPWG, herbal regulatory |
| Literature | Plain label, `--pharma-ink-mute`, no border | `primary`, `theoretical`, `usp_hmc`, `msk_about` |
| Derived | Plain label, `--pharma-ink-mute`, prefixed `~` | `derived` |

DPWG (Dutch Pharmacogenetics Working Group, rank 60) is a guideline-body chip with a dashed border: same band, same color, same size as CPIC, distinct texture. "CPIC-grade but not CPIC." The dashed stroke does the work; no new color. Decision 2026-05-20 at parser-claude's request.

CPIC publishes on two axes: evidence level (A/B/C/D) and recommendation strength (Strong/Moderate/Optional). Both can land in `pi_evidence`. All map to the solid-border guideline chip; the visual class is unaffected. But bare letter chips "CPIC C" and "CPIC D" read as one axis to a clinician while representing different axes. Chip text must disambiguate: strengths spelled in full ("CPIC Strong", "CPIC Optional", "CPIC Moderate"), and evidence-level chips read "CPIC level C" rather than bare "CPIC C" if the two can co-occur. Pending parser-claude confirmation of how the axes are stored.

### Exposure direction (pk_inhibit vs pk_induce)

`pk_inhibit_via_E` raises the other medicine's exposure; `pk_induce_via_E` lowers it. Clinically opposite. Distinguished in the edge's relationship label with a colored filled triangle, not a separate chip:

- Raised: `▲` (U+25B2) in `--pharma-tier-strong` (amber, trends toward toxicity risk)
- Lowered: `▼` (U+25BC) in `--pharma-ch-summary` (blue, trends toward efficacy loss)

Both colors already in the palette. The triangle is colored; the relationship text stays `--pharma-ink-mute`.

### Type system

- **Geist**: topbar, nav, labels, eyebrows, data, counts, intensity figures, tier names, UI chrome.
- **Newsreader**: hero title, page title, medicine names, section/module headings, body prose, list entries, dose-card bodies, anecdote quotes.
- Base font size 13px. Hero title 48px (Main Page) / medicine name 46px. Section/module headings ~16-22px. Body prose 15px. Labels 10px tracked uppercase. Numerals tabular throughout.
- Section numerals (01-09) are roman Newsreader in `--pharma-violet-bright`. Not italic.

### The mark

Wax-seal benzene. Concentric hexagons (outer + inner ring) plus the aromatic delocalization circle plus a center point. Reads as a stamp pressed into the page. Replaces the flat outline hexagon. SVG path is in the mockup source. The benzene ring is retained per Mark's constraint.

### Layout primitives

- 12-column grid for the Main Page module set; hairline-ruled modules butt against each other newspaper-style.
- Medicine page: full-width title block, then a two-column split (prose left, a sticky structured-datasheet rail right ~332px).
- Border radius: near-zero. The skin is hairline-and-type driven, not box-and-fill driven.

---

## Plants skin (`.pcp-skin-plants`)

The field-guide register. Botanical, deep brown loam with moss accents and amber emphasis, serif display + sans body. Applied to a page whose direct origin tag is `Category:Plant` and not `Category:Pharmaceutical`; see the Skin selection section.

### Palette

| Token | Hex | Role |
|---|---|---|
| `--plants-void` | `#08050300` | Deepest, behind everything |
| `--plants-loam` | `#110a05` | Page background, warm brown-black |
| `--plants-loam-warm` | `#160c06` | Hero/feature area warmth |
| `--plants-bark` | `#221610` | Default panel, woody |
| `--plants-bark-raised` | `#2e1f15` | Raised panel, saddle |
| `--plants-bark-edge` | `#3d2a1d` | Highlight surface, lit edge |
| `--plants-bone` | `#d4c5a8` | Primary text, cream-brown |
| `--plants-bone-dim` | `#a8927a` | Secondary, dim bone |
| `--plants-lichen` | `#7a6650` | Tertiary, muted |
| `--plants-whisper` | `#5a4636` | Faintest distinguishable text |
| `--plants-moss` | `#6a8a52` | Brand accent, primary links, mark color |
| `--plants-fern` | `#8eb070` | Hover, brighter accent |
| `--plants-amber` | `#b67a44` | Featured emphasis (Pendell quotes, primary marker, drop-cap option) |
| `--plants-amber-deep` | `#8e5d33` | Deep amber, less luminous |
| `--plants-rust` | `#7a3a22` | Danger, contraindication, oxidized iron |
| `--plants-bloom` | `#5a3528` | Warm bloom, accent washes |
| `--plants-walnut` | `#6b4423` | Strong brown statement accent |
| `--plants-tobacco` | `#4a2f1a` | Deep brown for borders, separators |
| `--plants-rule` | `rgba(212, 197, 168, 0.08)` | Default border, low-contrast bone wash |
| `--plants-rule-strong` | `rgba(212, 197, 168, 0.16)` | Emphasized border |
| `--plants-rule-moss` | `rgba(106, 138, 82, 0.22)` | Moss border under panel titles, accent rules |

### Tri-color section markers (MedTemplate, plants variant)

Same three-channel logic as pharma. Cool / olive / warm, all in brown family. Treatment is typographic markers (6px vertical bar in the marker color + roman serif name + gradient trailing hairline), not solid boxes.

| Token | Hex | Section |
|---|---|---|
| `--plants-mark-summary` | `#2e3a3a` | Summary (cool grey-brown) |
| `--plants-mark-pharmacy` | `#44502e` | Pharmacy (olive earth) |
| `--plants-mark-pharmacology` | `#5a3a20` | Pharmacology (walnut earth) |

---

## Hard prohibitions (both skins)

Per [[feedback_visual_palette]]:

- **No light-grey + yellow** combination, ever. The chip-picker's old `#fbbf24` star hover violated this and is replaced by `--pharma-amber` / `--plants-amber` in v2.1.
- **MedTemplate tri-color headers protected in structural logic.** The hex values change between skins; the three-channel semantic does not. Plants skin uses typographic markers rather than solid bands; the meaning survives.
- **No em-dashes** in any CSS file. Use commas, colons, periods. No automated pass enforces this; the source must be kept clean by hand.
- **No decorative italics.** From Mark, through 2026-05-20: no `font-style: italic` for emphasis or chrome, no `<em>`/`<i>` rendered italic, no italic font faces for UI, in either skin, in any component. Binomials, foreign terms, captions, labels, and emphasis stay roman; hierarchy is carried by weight, size, color, letter-spacing, and case. **Exception (Mark, 2026-05-20): quoted text may be italic** ("italics are okay in quotes"). This covers verbatim quotations and the Pendell quote voice; it does not extend to UI chrome, labels, captions, binomials, or emphasis. The global `em, i { font-style: normal; }` stays as the production default; quote components opt into italic explicitly.

---

## Type ramp

### Pharmaceutical skin
- Display: Inter, weight 500-600
- Body: Inter, weight 400
- Single family, all weights from one font

### Plants skin
- Display: Source Serif 4, weight 400-600 (page titles, hero, panel titles, MedTemplate section names)
- Body: Inter, weight 400-500
- Roman by default. Subtitles, captions, sidebar labels, search placeholder, and tab links are roman; their distinction comes from size, color, weight, letter-spacing, and case. The broad italic field-guide voice from earlier drafts stays retired. Exception (Mark, 2026-05-20): quoted text, including the Pendell quote voice, may be italic.

### Sizes (both skins, shared)

| Token | Size | Role |
|---|---|---|
| `--type-hero` | 48-72px (plants 72, pharma 44) | Hero title |
| `--type-h1` | 32-34px | Page title |
| `--type-h2` | 22-28px | Major section, frontispiece title |
| `--type-h3` | 17-22px | Panel title, MedTemplate band |
| `--type-body` | 14-15.5px | Body |
| `--type-small` | 12-13px | Captions, counts, labels |

### Lead-in treatment (replaces drop cap)

Lead paragraphs on plant-medicine pages and the Main Page's featured-medicine block open with a small-caps lead-in on the first phrase (typically the first 3-6 words, ending at a comma or natural pause). The phrase wraps in `<span class="lead-in">`. CSS:

```css
.lead-in {
  font-variant-caps: all-small-caps;
  font-feature-settings: "smcp", "c2sc";
  letter-spacing: 0.08em;
  color: var(--plants-bone);
  font-weight: 500;
}
```

Why not a drop cap: drop-cap wrap metrics rarely survive web translation cleanly (browser `::first-letter` quirks, font-specific letterform asymmetries, descender-collision artifacts). Small-caps lead-in does the same work, signaling "begin reading slowly," without the engineering tightrope and without competing with brand-color accents elsewhere on the page. Decision 2026-05-20 after Mark's review of the Cannabis mock.

---

## Spacing scale

| Token | Value |
|---|---|
| `--space-1` | 4px |
| `--space-2` | 8px |
| `--space-3` | 12px |
| `--space-4` | 16px |
| `--space-5` | 24px |
| `--space-6` | 32px |
| `--space-7` | 48px |
| `--space-8` | 64px |

---

## Border radius

| Token | Value |
|---|---|
| `--radius-sm` | 3px (chips, inline pills, default for typographic shells) |
| `--radius-md` | 4px (panels, cards) |
| `--radius-lg` | 8px (large dialogs, modals) |
| `--radius-pill` | 999px (pills, status badges) |

Note: chip-picker v2 uses `--radius-sm` (3px), moving away from the previous 12px pill in favor of field-label shells. See chip-picker block below.

---

## Motion

Motion is feedback and orientation, never decoration. It confirms an action, reveals a state, or guides the eye to a change. No ambient drift, no scroll-driven reveals, no parallax, no card lift, no pulsing. Everything resolves with an ease-out, no bounce or overshoot. Interactive demo: `https://pharmacopedia.wiki/design/pharma_motion.html`.

### Duration tokens

| Token | Value | Use |
|---|---|---|
| `--motion-instant` | `110ms` | Hover color, link, chip feedback |
| `--motion-quick` | `180ms` | Dropdowns, chevrons, small state changes |
| `--motion-moved` | `260ms` | Panels, accordion height, larger transitions |
| `--motion-locate` | `1200ms` | The record-flash only, deliberately slow so it is noticed |

### Easing tokens

| Token | Value | Use |
|---|---|---|
| `--ease-out` | `cubic-bezier(0.2, 0.7, 0.2, 1)` | Almost everything; decisive, no overshoot |
| `--ease-in-out` | `cubic-bezier(0.4, 0, 0.2, 1)` | Symmetric transitions (height collapse) |

### Rules

- **Hover** changes color and border only, never layout. Nothing lifts, scales, or reflows.
- **Focus** is a 2px `--pharma-violet` outline via `:focus-visible`, always visible, never suppressed.
- **Disclosure** (PGx tiers, dosing detail): height eases over `--motion-moved`, chevron rotates over `--motion-quick`, no fade.
- **Record-flash**: after an AJAX save + reload, the acted-on row animates a violet background fade over `--motion-locate` and is scrolled into view. The skin's one deliberately slow motion. Satisfies the [[feedback_save_returns_to_position]] standing rule.
- **Radar draw-in**: the data polygon scales up from center over 620ms, once, on first view. Does not loop or replay on scroll.
- **Reduced motion**: a global `@media (prefers-reduced-motion: reduce)` collapses all durations to ~0. Hover feedback still changes (essential) but snaps. Hard accessibility floor.
- Motion character is **shared across both skins**; only the palette differs. The plants skin uses the same tokens and rules.

---

## Component family tokens

Per interface-claude's 2026-05-20 inventory, the extension's reusable visual primitives fall into named families. Each family gets its own `--pcp-<family>-*` token block. Both skins define values for every family token; the `.pcp-skin-plants` body class flips them.

**Naming rule**: all skin-aware component tokens use the `--pcp-*` prefix to match the rest of the extension. Sub-prefix per family:

| Family | Prefix | Scope |
|---|---|---|
| Chip-picker | `--pcp-chip-*` | Chips, autocomplete dropdowns (Demographics chip-picker, dx autocomplete, med autocomplete) |
| Radar | `--pcp-radar-*` | SVG radar visualizations (CATI, PID-5-BF, CAT-Q, BPNS, NFCS) |
| PGx | `--pcp-pgx-*` | Tiered interaction tables (FDA Boxed, CPIC-A, primary, derived) |
| Card | `--pcp-card-*` | UserProfile assessment cards (shared shell) |
| Bar | `--pcp-bar-*` | Bargauge, intensity bars, vote tallies |
| Page | `--pcp-page-*` | Page-level surfaces (banner, sections, hero) |
| Text | `--pcp-text-*` | Semantic text colors (default, mute, lichen) |
| Rule | `--pcp-rule-*` | Dividers, hairline borders |

**Architecture**: component tokens reference palette tokens via `var()` indirection. A chip's brand color is not the hex `#7c3aed` directly; it is `var(--pcp-chip-brand)`, which is defined as `var(--pharma-violet)` under `:root` and `var(--plants-moss)` under `.pcp-skin-plants`. Changing a palette token cascades automatically to every component that references it.

**Don't proliferate**: do not introduce more granular tokens than usage emerges. The chip-picker spec's ~16 tokens is a good density target. Add tokens when you see the third literal occurrence of a value.

**Production status (2026-05-21).** Only the `--pcp-chip-*` block is a real `--pcp-*` layer in `ext.pharmacopedia.css`. The radar, card, and bar blocks below are SPEC, not production: interface-claude builds the assessment card family (verdict card, featured-card radar face) and the radar SVGs directly on the house `--pharma-*` tokens, with no `--pcp-*` indirection, and the PGx tier colors ship as `--pharma-tier-*`. Read the radar, card, and bar tables below as a color reference keyed to `--pharma-*`, not as token names a stylesheet can call. Whether to grow the indirection to cover these families or to formally adopt direct `--pharma-*` use is an open designer and interface question; see parking lot item 5.

### Family status

| Family | Status | Spec'd by |
|---|---|---|
| `--pcp-chip-*` | LOCKED, ready for refactor. The only `--pcp-*` family in production CSS. | designer-claude 2026-05-20 (see chip-picker block below) |
| `--pcp-radar-*` | SPEC ONLY, not in production CSS. Radar SVGs ship on `--pharma-*` directly. | designer-claude 2026-05-20 (see radar block below) |
| `--pcp-pgx-*` | SPEC ONLY, not in production CSS. Tier colors ship as `--pharma-tier-*`. | designer-claude 2026-05-20 (PGx tier severity + evidence chips, in the C/Specimen section) |
| `--pcp-card-*` | SPEC ONLY, not in production CSS. The card family ships on `--pharma-*` directly. | designer-claude 2026-05-20 (see card block below) |
| `--pcp-bar-*` | SPEC ONLY, not in production CSS. Bars ship on `--pharma-*` directly. | designer-claude 2026-05-20 (see bar block below) |
| `--pcp-page-*` | partial (plants skin hero values inferred from mocks) | future |
| `--pcp-text-*` | partial (chip-picker block already uses --pcp-text family) | future |
| `--pcp-rule-*` | partial (chip-picker block already uses --pcp-rule family) | future |

When designer-claude is asked to redesign one of these surfaces, the spec lands the full token block for that family here.

---

## Chip-picker token block (v2, both skins)

Used by `.pcp-chip-*` family. Triggered everywhere chip-pickers, dx autocomplete, and med autocomplete appear.

### Pharma (resolves under `:root`)

| Token | Value |
|---|---|
| `--pcp-chip-brand` | `#7c3aed` |
| `--pcp-chip-custom-accent` | `#d97757` |
| `--pcp-chip-amber` | `#c89968` |
| `--pcp-chip-text` | `#ede9fe` |
| `--pcp-chip-text-mute` | `#c4c4c4` |
| `--pcp-chip-lichen` | `#888` |
| `--pcp-chip-rule` | `#2d2d2d` |
| `--pcp-chip-bg` | `rgba(124, 58, 237, 0.10)` |
| `--pcp-chip-border` | `rgba(124, 58, 237, 0.4)` |
| `--pcp-chip-bg-primary` | `rgba(124, 58, 237, 0.22)` |
| `--pcp-chip-border-primary` | `rgba(124, 58, 237, 0.55)` |
| `--pcp-chip-bg-custom` | `rgba(217, 119, 87, 0.10)` |
| `--pcp-chip-border-custom` | `rgba(217, 119, 87, 0.5)` |
| `--pcp-chip-border-fill` | `rgba(136, 136, 136, 0.45)` |
| `--pcp-chip-suggest-bg` | `#1f1f1f` |
| `--pcp-chip-suggest-border` | `#444` |
| `--pcp-chip-suggest-hover` | `rgba(124, 58, 237, 0.15)` |

### Plants (resolves under `.pcp-skin-plants`)

| Token | Value |
|---|---|
| `--pcp-chip-brand` | `#6a8a52` |
| `--pcp-chip-custom-accent` | `#b67a44` |
| `--pcp-chip-amber` | `#c89968` |
| `--pcp-chip-text` | `#d4c5a8` |
| `--pcp-chip-text-mute` | `#a8927a` |
| `--pcp-chip-lichen` | `#7a6650` |
| `--pcp-chip-rule` | `rgba(212, 197, 168, 0.10)` |
| `--pcp-chip-bg` | `rgba(106, 138, 82, 0.12)` |
| `--pcp-chip-border` | `rgba(106, 138, 82, 0.45)` |
| `--pcp-chip-bg-primary` | `rgba(106, 138, 82, 0.28)` |
| `--pcp-chip-border-primary` | `rgba(106, 138, 82, 0.6)` |
| `--pcp-chip-bg-custom` | `rgba(182, 122, 68, 0.12)` |
| `--pcp-chip-border-custom` | `rgba(182, 122, 68, 0.5)` |
| `--pcp-chip-border-fill` | `rgba(122, 102, 80, 0.4)` |
| `--pcp-chip-suggest-bg` | `#1c1714` |
| `--pcp-chip-suggest-border` | `rgba(212, 197, 168, 0.16)` |
| `--pcp-chip-suggest-hover` | `rgba(106, 138, 82, 0.15)` |

### Marker glyphs

| Use | Glyph | Unicode |
|---|---|---|
| Primary chip marker | `✦` | U+2726 (replaces ★ U+2605, more typographic) |
| Browser-fill chip marker | `◌` | U+25CC |
| Suggestion separator | `❦` | U+2766 (fleuron, for browse-by-class lists too) |
| Suggestion selected check | `✓` | U+2713 |

---

## Radar token block (`--pcp-radar-*`)

SPEC ONLY, not in production CSS (status corrected 2026-05-21). Used by the assessment-instrument radar visualizations (CATI, PID-5-BF, CAT-Q, BPNS, NFCS) and the radar face of the assessment card. Reference: `https://pharmacopedia.wiki/design/pharma_components.html`. The "Pharma value" column below is what production actually renders, sourced from `--pharma-*` directly: interface-claude styles radar polygons inline in the generated SVG against the house palette, with no `--pcp-radar-*` indirection layer. Read this table as the canonical color map for radar; the left-column names are proposed, not callable CSS.

| Token | Pharma value | Role |
|---|---|---|
| `--pcp-radar-ring` | `var(--pharma-line)` | Outer grid rings |
| `--pcp-radar-ring-faint` | `var(--pharma-line-faint)` | Inner grid rings (density reads edge-inward) |
| `--pcp-radar-axis` | `var(--pharma-line-faint)` | Axis spokes |
| `--pcp-radar-data-stroke` | `var(--pharma-violet)` | Subject polygon stroke |
| `--pcp-radar-data-fill` | `rgba(139, 92, 246, 0.16)` | Subject polygon fill |
| `--pcp-radar-data-point` | `var(--pharma-violet-bright)` | Subject polygon vertex dots |
| `--pcp-radar-threshold` | `var(--pharma-tier-strong)` | Pathologic-threshold polygon, dashed |
| `--pcp-radar-label` | `var(--pharma-ink-mute)` | Axis labels |
| `--pcp-radar-value` | `var(--pharma-violet-bright)` | Inline axis scores |

Grid is an n-gon matching the instrument's subscale count (triangle for 3, pentagon for 5, octagon for 8). The threshold polygon is per-subscale, so it is not a regular n-gon; where the data polygon crosses outside it, that subscale is above its clinical cutoff.

## Card token block (`--pcp-card-*`)

SPEC ONLY, not in production CSS (status corrected 2026-05-21). Used by the assessment card family: featured card (gauge + radar faces), list card, history / verdict / quiet variants. Reference: `https://pharmacopedia.wiki/design/pharma_card_family.html`. interface-claude shipped the verdict card and the featured-card radar face (`.verdict`, `.fc`) directly on `--pharma-*`, with `.fc-experimental` carrying the amber-caption state. The "Pharma value" column below is the canonical color map; the `--pcp-card-*` names are proposed, not callable CSS.

| Token | Pharma value | Role |
|---|---|---|
| `--pcp-card-surface` | `var(--pharma-bg-2)` | Card background |
| `--pcp-card-border` | `var(--pharma-line)` | Card border |
| `--pcp-card-divider` | `var(--pharma-line-faint)` | In-card hairline (head rule, fact-row dividers) |
| `--pcp-card-eyebrow` | `var(--pharma-violet-bright)` | Instrument-code eyebrow |
| `--pcp-card-label` | `var(--pharma-ink-mute)` | Fact labels |
| `--pcp-card-value` | `var(--pharma-ink)` | Fact values |
| `--pcp-card-reading-over` | `var(--pharma-tier-strong)` | Reading word, above threshold (amber) |
| `--pcp-card-reading-within` | `var(--pharma-ch-pharmacy)` | Reading word, within range (green) |
| `--pcp-card-action` | `var(--pharma-violet-ink)` | Footer action link |

Principle from Mark's review: a card always *visualizes* the result (gauge, radar, zoned bar, trend, or at minimum a sparkline). A text-only result card is rejected. Card names are Newsreader; codes, labels, and actions are Geist.

## Bar token block (`--pcp-bar-*`)

SPEC ONLY, not in production CSS (status corrected 2026-05-21). Used by the intensity-bar family: basic intensity (PGx edge bars, effect strengths), zoned (value against thresholds), paired (value plus reference), vote tally. Reference: `https://pharmacopedia.wiki/design/pharma_components.html`. The "Pharma value" column below is the canonical color map; the `--pcp-bar-*` names are proposed, not callable CSS.

| Token | Pharma value | Role |
|---|---|---|
| `--pcp-bar-track` | `var(--pharma-line)` | Empty track |
| `--pcp-bar-fill` | `var(--pharma-violet)` | Basic / paired fill |
| `--pcp-bar-marker` | `var(--pharma-ink)` | Zoned-bar value marker |
| `--pcp-bar-zone-safe` | `rgba(87, 160, 101, 0.32)` | Zoned bar, safe band |
| `--pcp-bar-zone-caution` | `rgba(199, 136, 74, 0.32)` | Zoned bar, caution band |
| `--pcp-bar-zone-danger` | `rgba(194, 90, 82, 0.32)` | Zoned bar, danger band |
| `--pcp-bar-reference` | `var(--pharma-ch-summary)` | Paired-bar reference tick |
| `--pcp-bar-vote-pos` | `var(--pharma-ch-pharmacy)` | Vote tally, positive |
| `--pcp-bar-vote-neu` | `var(--pharma-ink-mute)` | Vote tally, neutral |
| `--pcp-bar-vote-neg` | `var(--pharma-tier-boxed)` | Vote tally, negative |

Track heights: basic 8px, zoned 11px standalone (22px inside the assessment list card, where the bar carries more weight), paired 8px, vote tally 12px. All thickened 50% from the first pass at Mark's call 2026-05-20.

## Plants-skin component values (`.pcp-skin-plants` re-points)

SPEC ONLY, not in production CSS (status corrected 2026-05-21). IF the `--pcp-*` indirection is adopted for the radar, card, and bar families, these are the plant-palette values they re-point to under the `.pcp-skin-plants` body class, the same pattern interface-claude already used for the chip-picker. Until then, a plant-page radar or card needs an explicit `.pcp-skin-plants` rule override per component. This block records the intended plant values either way, so plant-page components (above all the PGx tier render on herbal-interaction medicine pages) are never blocked for lack of a color decision.

Implementation note: the PGx tier colors are currently named `--pharma-tier-*` in the C/Specimen section. For the plants skin to re-point them cleanly, either lift them to component tokens (`--pcp-pgx-tier-*`, resolving per skin) or add a `.pcp-skin-plants` override of those four values. Interface-claude's call; the values are below either way.

### Radar, plants

| Token | Plants value |
|---|---|
| `--pcp-radar-ring` | `var(--plants-rule-strong)` |
| `--pcp-radar-ring-faint` | `var(--plants-rule)` |
| `--pcp-radar-axis` | `var(--plants-rule)` |
| `--pcp-radar-data-stroke` | `var(--plants-moss)` |
| `--pcp-radar-data-fill` | `rgba(106, 138, 82, 0.16)` |
| `--pcp-radar-data-point` | `var(--plants-fern)` |
| `--pcp-radar-threshold` | `var(--plants-amber)` |
| `--pcp-radar-label` | `var(--plants-lichen)` |
| `--pcp-radar-value` | `var(--plants-fern)` |

### Card, plants

| Token | Plants value |
|---|---|
| `--pcp-card-surface` | `var(--plants-bark)` |
| `--pcp-card-border` | `var(--plants-rule-strong)` |
| `--pcp-card-divider` | `var(--plants-rule)` |
| `--pcp-card-eyebrow` | `var(--plants-moss)` |
| `--pcp-card-label` | `var(--plants-lichen)` |
| `--pcp-card-value` | `var(--plants-bone)` |
| `--pcp-card-reading-over` | `var(--plants-amber)` |
| `--pcp-card-reading-within` | `var(--plants-moss)` |
| `--pcp-card-action` | `var(--plants-fern)` |

### Bar, plants

| Token | Plants value |
|---|---|
| `--pcp-bar-track` | `var(--plants-bark-raised)` |
| `--pcp-bar-fill` | `var(--plants-moss)` |
| `--pcp-bar-marker` | `var(--plants-bone)` |
| `--pcp-bar-zone-safe` | `rgba(106, 138, 82, 0.30)` |
| `--pcp-bar-zone-caution` | `rgba(182, 122, 68, 0.30)` |
| `--pcp-bar-zone-danger` | `rgba(122, 58, 34, 0.42)` |
| `--pcp-bar-reference` | `var(--plants-mark-summary)` |
| `--pcp-bar-vote-pos` | `var(--plants-moss)` |
| `--pcp-bar-vote-neu` | `var(--plants-lichen)` |
| `--pcp-bar-vote-neg` | `var(--plants-rust)` |

### PGx tier severity, plants

| Tier | Pharma | Plants |
|---|---|---|
| Contraindicated / FDA Boxed | `#c25a52` | `var(--plants-rust)` `#7a3a22` |
| Strong caution | `#c7884a` | `var(--plants-amber)` `#b67a44` |
| Primary pharmacology | `#8b5cf6` | `var(--plants-moss)` `#6a8a52` |
| Derived, inferred | `#6c6877` | `var(--plants-lichen)` `#7a6650` |

The kinetics chip uses `--plants-amber` for mechanism-based and `--plants-lichen` for reversible. The exposure-direction triangles: raised uses `--plants-amber`, lowered uses `--plants-mark-summary` (cool earth).

### Evidence chips, plants

Same four-class system. Regulatory: filled chip, `--plants-rust` ground, `--plants-bone` text. Guideline: bordered, `--plants-lichen` border, `--plants-bone-dim` text; dashed border for DPWG. Literature: plain `--plants-lichen` label. Derived: plain `--plants-lichen`, `~` prefix.

---

## The fiddlehead is gone, the poppy is the mark

Replaced fern-spiral concept with a poppy seed capsule cross-section.

### Topbar mark (46px, plants skin only)

```html
<svg viewBox="0 0 100 100" fill="none" stroke="currentColor" stroke-width="2.5"
     stroke-linecap="round" stroke-linejoin="round">
  <path d="M 32 82 C 28 78, 26 65, 28 50 C 29 42, 32 36, 36 33 L 64 33
           C 68 36, 71 42, 72 50 C 74 65, 72 78, 68 82 C 66 86, 60 88, 50 88
           C 40 88, 34 86, 32 82 Z"/>
  <path d="M 30 33 L 70 33 L 66 24 L 34 24 Z" fill="rgba(10,8,7,0.4)"/>
  <g stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
    <line x1="50" y1="14" x2="50" y2="22"/>
    <line x1="42" y1="15" x2="44" y2="22"/>
    <line x1="58" y1="15" x2="56" y2="22"/>
    <line x1="36" y1="18" x2="40" y2="23"/>
    <line x1="64" y1="18" x2="60" y2="23"/>
  </g>
  <circle cx="50" cy="14" r="2.2" fill="var(--plants-amber)"/>
</svg>
```

Color: `currentColor` driven by parent CSS, set to `--plants-moss`. Stigma fill is `--plants-amber`.

### Frontispiece mark (190x270, plants skin only)

Same capsule shape with stem, drooping leaf, and additional vertical seam lines on the body. Lives in the hero of the Main Page. See `/var/www/mediawiki/design/plants_mockup.html` source for the full SVG path.

### Featured-medicine illustration (cannabis leaf, 140x170)

5-leaflet palmate, drawn in moss line-art. Right-floated in the featured-medicine block. See `/var/www/mediawiki/design/plants_mockup.html` source.

### Sidebar specimen (Amanita, 70x100)

Small mushroom drawing in amber, used as marginalia decoration. See mockup.

---

## Vines and ambient illustrations

Plants-skin hero carries two vine line-drawings as ambient texture:

- `.hero-vine-tl`: top-left, opacity 0.28, color `--plants-moss`
- `.hero-vine-br`: bottom-right, opacity 0.28, color `--plants-moss`, mirrored

These are SVG line paths with small leaf ellipses. They sit in the hero's overflow:hidden region and contribute texture without claiming attention.

---

## Vellum noise overlay (plants skin only)

A fixed `body::before` with inline SVG `feTurbulence` noise, color-matrix tinted brown, blended with `mix-blend-mode: overlay` at 40% opacity. Provides paper-grain texture across the whole skin.

```css
body::before {
  content: '';
  position: fixed;
  inset: 0;
  pointer-events: none;
  z-index: 1;
  opacity: 0.4;
  mix-blend-mode: overlay;
  background-image: url("data:image/svg+xml;utf8,<svg xmlns='...'>
    <filter id='n'><feTurbulence type='fractalNoise' baseFrequency='0.9'
      numOctaves='2' seed='4'/>
    <feColorMatrix values='0 0 0 0 0.42  0 0 0 0 0.28  0 0 0 0 0.16
      0 0 0 0.06 0'/></filter><rect ... filter='url(%23n)'/></svg>");
}
```

Color matrix produces brown grain (R 0.42, G 0.28, B 0.16, alpha 0.06).

---

## Cross-skin cohesion (2026-05-20)

Cohesion pass outcome. The two skins keep two layouts and two type systems (Geist + Newsreader for pharma, Inter + Source Serif 4 for plants); Mark's call, the skin switch is a deliberate jump. Everything else aligns as tightly as possible: shared topbar skeleton, shared motion spec, shared `--pcp-*` component families, and the two items below.

### Mark family, one drawing grammar

The wax-seal benzene (pharma) and the poppy capsule (plants) must read as the same hand. Shared grammar, both marks:
- viewBox `0 0 100 100`, `fill="none"`, `stroke="currentColor"`, `stroke-linejoin="round"`, `stroke-linecap="round"`.
- Primary outline stroke-width `4`. Detail/inner stroke-width `2`. One small filled accent dot, `r 2.6`.

Pharma, wax-seal benzene, aligned:
```
<svg viewBox="0 0 100 100" fill="none" stroke="currentColor" stroke-linejoin="round" stroke-linecap="round">
  <path d="M50 7 L87 28.5 L87 71.5 L50 93 L13 71.5 L13 28.5 Z" stroke-width="4"/>
  <path d="M50 18 L77.5 34 L77.5 66 L50 82 L22.5 66 L22.5 34 Z" stroke-width="2"/>
  <circle cx="50" cy="50" r="13" stroke-width="2"/>
  <circle cx="50" cy="50" r="2.6" fill="currentColor" stroke="none"/>
</svg>
```

Plants, poppy capsule, aligned:
```
<svg viewBox="0 0 100 100" fill="none" stroke="currentColor" stroke-linejoin="round" stroke-linecap="round">
  <path d="M32 82 C28 78 26 65 28 50 C29 42 32 36 36 33 L64 33 C68 36 71 42 72 50 C74 65 72 78 68 82 C66 86 60 88 50 88 C40 88 34 86 32 82 Z" stroke-width="4"/>
  <path d="M30 33 L70 33 L66 23 L34 23 Z" stroke-width="4"/>
  <g stroke-width="2">
    <line x1="42" y1="14" x2="44" y2="21"/><line x1="58" y1="14" x2="56" y2="21"/>
    <line x1="36" y1="17" x2="40" y2="22"/><line x1="64" y1="17" x2="60" y2="22"/>
  </g>
  <circle cx="50" cy="13" r="2.6" fill="currentColor" stroke="none"/>
</svg>
```
Both: primary stroke 4, detail stroke 2, round joins, one filled accent dot. One chemical, one botanical, visibly the same hand. These supersede the mark SVGs in "The fiddlehead is gone" section above.

### Pharma whisper-grain

The plants skin carries a 40%-opacity vellum grain; the pharma skin was texturally flat, so the two read as one rich, one bare. Pharma gets a *whisper* of the same grain, far fainter, neutral-cool tint, so both skins are two settings of one atmospheric philosophy.

```css
body::before {
  content: ''; position: fixed; inset: 0; z-index: 1; pointer-events: none;
  opacity: 0.25; mix-blend-mode: overlay;
  background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='200' height='200'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='0.92' numOctaves='2' seed='5'/><feColorMatrix values='0 0 0 0 0.55  0 0 0 0 0.54  0 0 0 0 0.6  0 0 0 0.04 0'/></filter><rect width='100%' height='100%' filter='url(%23n)'/></svg>");
}
```
Neutral-cool grain (R 0.55, G 0.54, B 0.60, alpha 0.04), opacity 0.25, vs the plants 0.40. Barely-there: enough that the surface is not dead flat, never enough to read as texture. The "precise clinical" register holds.

---

## Web fonts (self-hosted)

Located at `/var/www/mediawiki/design/fonts/`.

| Family | Variants | Subsets | Total |
|---|---|---|---|
| Source Serif 4 | 400, 500, 600 (italic faces not shipped; quote-italic renders as synthetic oblique, see Hard prohibitions) | latin, latin-ext, cyrillic, greek, vietnamese | roman only |
| Inter | 400, 500, 600 | as above | included in same set |

Both served via `/design/fonts/pharmacopedia_fonts.css` which contains `@font-face` rules with `font-display: swap`. No external network requests; CSP-compatible (`font-src 'self' data:` works).

For production deployment, recommend moving font files into the extension's `resources/fonts/` directory and serving via MW ResourceLoader, but the current `/design/fonts/` location works for the demo.

---

## Mockup references

- `/var/www/mediawiki/design/plants_mockup_v1.html` (light, calmer first pass)
- `/var/www/mediawiki/design/plants_mockup.html` (current v2.1, brown-pushed, with poppy mark, marginalia, cannabis leaf, Amanita)

Both publicly served at `https://pharmacopedia.wiki/design/` for review.

---

## Open questions / parking lot

1. ESCOP and other paywalled regulatory monographs are inaccessible to web-claude. Worth raising whether the project wants institutional access for the herbal sprint. Not a design question per se, but it affects what reference visual treatments will need to handle.
2. The `.pcp-skin-plants` body class is set via an MW BeforePageDisplay hook (interface-claude) that reads the page's direct origin category tag; see the Skin selection section. The recursive parent-chain walk is retired as of 2026-05-20.
3. Per-user skin override in Appearance sidebar: implemented in mock as illustrative. Whether to ship as a real preference is open.
4. Category pages, File pages, and Talk pages are all covered by the Skin selection rule: the plants skin only with a direct `Category:Plant` tag and no `Category:Pharmaceutical`, otherwise the pharma default. The dual-parented class-category case (for example `Category:Psychedelics`) resolves to pharma, as intended.
5. The `--pcp-*` component-token indirection (logged 2026-05-21): only `--pcp-chip-*` is real in production CSS. interface-claude builds the radar, card, and bar families directly on `--pharma-*`, so the radar, card, and bar token blocks in this file are spec, not implemented. Open question for designer-claude and interface-claude: grow the indirection layer to cover these families (clean cross-skin re-pointing, matches the chip pattern), or formally adopt direct `--pharma-*` use and demote the radar, card, and bar blocks to a pure color reference. Not blocking anything currently; the card family ships fine on `--pharma-*`. Worth settling before the plants skin needs to cover an assessment surface.

---

## Canonical location

This file is the canonical copy: `/var/www/mediawiki/extensions/Pharmacopedia/DESIGN_TOKENS.md`. It is group-writable, and designer-claude edits it in place here. Any copy in another tree (the `mediawiki-staging` extension dir, or a `/tmp/design_tokens.md` scratch draft) is a downstream snapshot and goes stale; do not treat those as canonical. Always read and edit this path.

To resync a stale staging copy from this canonical one:

```
clear && cp /var/www/mediawiki/extensions/Pharmacopedia/DESIGN_TOKENS.md /var/www/mediawiki-staging/extensions/Pharmacopedia/DESIGN_TOKENS.md
```
