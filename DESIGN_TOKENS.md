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

**The rule.** A page renders in the plants skin (`.pcp-skin-plants` body class) only when its direct categories include `Category:Plants` and do not include `Category:Pharmaceutical`. Every other page renders in the pharma default with no body class: pages tagged `Category:Pharmaceutical`, pages tagged both origins, pages tagged neither, Special pages, the Main Page, File and Talk pages.

**Why direct, not recursive.** Under Mark's two-gate origin rule (locked 2026-05-20), every concrete medicine page carries exactly one direct origin tag, `Category:Plants` or `Category:Pharmaceutical`. Class categories, though, are deliberately dual-parented: `Category:Psychedelics` sits under both `Category:Plants` and `Category:Pharmaceutical`, because the psychedelics class spans both origins (DMT is a Plant medicine, LSD a Pharmaceutical one). A recursive "is this page anywhere beneath `Category:Plants`" test would therefore catch every member of a dual-parented class, LSD included, and wrongly hand LSD the plants skin. The direct origin tag is unambiguous; the recursive walk is not. Recursion stays correct, and stays in use, for category-tree browsing and membership listings. Only the skin decision moves to the direct read.

**Category pages.** A category page is resolved by the same predicate against its own direct parent categories. A dual-parented class category such as `Category:Psychedelics` carries both origins among its parents, so it falls to the pharma default. That is the intended outcome and matches the clinical-first identity: pharma is the reference chrome, the plants skin is a contextual specialization. A category whose direct origin is purely Plant renders in the plants skin. The `Category:Plants` page itself is plants-skinned; the mechanism for that single page (a name match, or a self-membership tag) is interface-claude's call.

**Fungi sub-skin.** Medicine pages whose subject is a fungus resolve to `.pcp-skin-fungi`, a sub-skin of the plants skin. Fungi remain Plant-origin (the two-gate rule is unchanged, zero exceptions); the Fungi skin is not a third origin. The resolver gains one branch ahead of the plant fallthrough: a direct `Category:Fungi` tag resolves to `.pcp-skin-fungi`; otherwise the `Category:Plants` test resolves to `.pcp-skin-plants` as before; otherwise pharma. The fungi check is first. `Category:Fungi` as the trigger was settled by Mark, 2026-05-21. See the Fungi sub-skin section below.

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

### Category status, the redlink color

`--pharma-redlink` colors a redlink category, a category tag in use with no page behind it, in the Category index status system (designer-claude spec 2026-05-21; build in progress by category-claude). It carries the redlink node's name, its dotted underline, and its dotted-ring marker.

| Token | `:root` | `.pcp-skin-plants` | Role |
|---|---|---|---|
| `--pharma-redlink` | `#a8736d` | `#c08372` | Redlink category: name, dotted underline, dotted-ring marker |

It is the boxed-warning red desaturated. `#a8736d` holds the `--pharma-tier-boxed` hue at roughly half the chroma, so a redlink reads as a quiet, intentional "missing page" and never borrows the gravity of the clinical contraindication red. The plants re-point `#c08372` is a lighter dusty faded-rust, kept distinct from `--plants-rust` (the plants danger color, which fails AA as text at about 2.3:1 on loam) and tuned for the loam ground. Both clear WCAG AA for text: about 4.9:1 on `--pharma-bg`, about 6.3:1 on `--plants-loam`. Live in `ext.pharmacopedia.css`, the `:root` value and the `.pcp-skin-plants` re-point, as a post-0.9.7.0 change. The other five category statuses (canonical, other, stub, husk, redirect) need no new token; they map to existing `--pharma-*` tokens.

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
- Medicine page: full-width title block, then a two-column split (prose left, a sticky structured-datasheet rail right). The frame is fluid edge to edge and the prose holds a reading measure; see "Full-width layout and the Appearance rail" below.
- Border radius: near-zero. The skin is hairline-and-type driven, not box-and-fill driven.

---

## Plants skin (`.pcp-skin-plants`)

The field-guide register. Botanical, deep brown loam with moss accents and amber emphasis, serif display + sans body. Applied to a page whose direct origin tag is `Category:Plants` and not `Category:Pharmaceutical`; see the Skin selection section.

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

## Fungi sub-skin (`.pcp-skin-fungi`)

A sub-skin of the plants skin, for medicine pages whose subject is a fungus (psilocybin and the *Psilocybe* species, *Amanita muscaria*, and the rest). Scope is medicine pages only, not category pages and not the diptych. It is not a third ground-up skin: it inherits the plants skin's type system (Source Serif 4 display + Inter body, no new fonts), layout primitives, the history-first medicine-page spine, the component families, motion, the small-caps lead-in, spacing scale, and border radii unchanged. It diverges only where "fungal" earns it: the palette, the texture, the mark, the section-marker hues, and the marginalia illustrations. "The plants skin, more fungal."

Signature, chosen by Mark 2026-05-21: the bruise. The damp dark forest floor in low light, with the indigo bruise of a handled psilocybin mushroom as the brand accent, a restrained bioluminescent foxfire green as the one semantic green, and spore-print buff for text.

### Palette

Damp, cool, dark. A step cooler and darker than loam.

| Token | Hex | Role |
|---|---|---|
| `--fungi-substrate` | `#0a0c0b` | Page background, cold near-black with a faint green-grey cast |
| `--fungi-substrate-warm` | `#0e100f` | Hero / feature area, a touch lifted |
| `--fungi-flesh` | `#181b19` | Default panel, mushroom flesh in shadow |
| `--fungi-flesh-raised` | `#22251f` | Raised panel |
| `--fungi-flesh-edge` | `#2f332c` | Highlight surface, lit edge |
| `--fungi-spore` | `#d8d3c1` | Primary text, spore-print buff, cooler and paler than `--plants-bone` |
| `--fungi-spore-dim` | `#a6a292` | Secondary text |
| `--fungi-veil` | `#888a7d` | Tertiary text, labels, captions. Set at this lightness to clear WCAG 2.2 AA (~4.9:1) on `--fungi-flesh`; interface-claude to verify exact ratios, as with the pharma `ink-mute` correction |
| `--fungi-whisper` | `#4f4f48` | Faintest distinguishable text |
| `--fungi-bruise` | `#6878b8` | Brand primary: marker bars, the mark, intensity bars. The indigo of a handled *Psilocybe* |
| `--fungi-bruise-bright` | `#8b9bd4` | Links, hover, "more" affordances. The fresh bruise edge |
| `--fungi-bruise-deep` | `#3c4668` | Deep bruise, gradient midpoint, less luminous |
| `--fungi-foxfire` | `#74b89a` | Bioluminescent green. Restrained, semantic use only (safe / live accent), never brand |
| `--fungi-ochre` | `#c2965a` | Featured emphasis, the one warm pop against the cool ground (Pendell quotes, primary marker, the spore-drop in the mark). The fungi equivalent of `--plants-amber` |
| `--fungi-rot` | `#8a3f2b` | Danger, contraindication, the oxidized red-brown of rot. A fill/marker color, not a text color (fails AA as text, like `--plants-rust`) |
| `--fungi-rule` | `rgba(216, 211, 193, 0.08)` | Default border, faint spore-tinted wash |
| `--fungi-rule-strong` | `rgba(216, 211, 193, 0.16)` | Emphasized border |
| `--fungi-rule-bruise` | `rgba(104, 120, 184, 0.22)` | Indigo border under panel titles, accent rules (parallel to `--plants-rule-moss`) |

Mechanism: as with the plants skin, the Fungi skin re-points the shared component-consumption layer. Under `.pcp-skin-fungi` the `--pharma-*` palette tokens and the `--pcp-chip-*` family resolve to the `--fungi-*` values above, so the chip, radar, card, and bar families inherit the fungi palette with no per-component work, exactly as they do for plants. interface-claude's call on whether a fungi page is dual-classed `.pcp-skin-plants .pcp-skin-fungi` or whether the stylesheet groups the plants-base selectors to also match `.pcp-skin-fungi`; either way the fungi layer is the plants base plus a palette re-point, a grain swap, and a mark swap.

### Texture: spore-dust

The plants skin carries a vellum grain; the fungi skin carries a finer, cooler spore-dust. Same fixed `body::before` overlay technique as the other two skins, retuned: `feTurbulence type='turbulence'` (discrete speckle, not the smooth `fractalNoise` cloud), a higher `baseFrequency` (finer specks), color-matrix tinted toward the spore-print buff, low alpha, `mix-blend-mode: overlay`, opacity ~0.35. It reads as a fine settling of spores on the surface rather than paper grain.

```css
body.pcp-skin-fungi::before {
  content: ''; position: fixed; inset: 0; z-index: 1; pointer-events: none;
  opacity: 0.35; mix-blend-mode: overlay;
  background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='200' height='200'><filter id='s'><feTurbulence type='turbulence' baseFrequency='1.1' numOctaves='2' seed='7'/><feColorMatrix values='0 0 0 0 0.85  0 0 0 0 0.83  0 0 0 0 0.74  0 0 0 0.05 0'/></filter><rect width='100%' height='100%' filter='url(%23s)'/></svg>");
}
```

### The mark: the mushroom

Replaces the poppy capsule on fungi pages (topbar, frontispiece, marginalia). A whole mushroom in side elevation: a domed cap with its gills, a slightly waisted stem flaring at the base, and a single spore released from the gills, falling. The accent dot of the cohesion grammar is that spore-drop.

Drawn in the shared mark grammar: viewBox `0 0 100 100`, `fill="none"`, `stroke="currentColor"`, round joins and caps, primary stroke-width `4`, detail stroke-width `2`, one filled accent dot at `r 2.6`.

```
<svg viewBox="0 0 100 100" fill="none" stroke="currentColor" stroke-linejoin="round" stroke-linecap="round">
  <path d="M18 46 C18 24 82 24 82 46" stroke-width="4"/>
  <path d="M18 46 C30 53 70 53 82 46" stroke-width="4"/>
  <path d="M43 50 C42 64 42 76 44 84 C47 88 53 88 56 84 C58 76 58 64 57 50" stroke-width="4"/>
  <g stroke-width="2">
    <path d="M50 50 L50 53"/>
    <path d="M40 50 L38 53"/><path d="M60 50 L62 53"/>
    <path d="M30 49 L27 52"/><path d="M70 49 L73 52"/>
  </g>
  <circle cx="50" cy="64" r="2.6" fill="var(--fungi-ochre)" stroke="none"/>
</svg>
```

Stroke is `currentColor`, set by parent CSS to `--fungi-bruise`. The spore-drop is `--fungi-ochre`, the one warm note, parallel to the amber stigma of the plants poppy. One chemical mark, one botanical, one fungal, visibly the same hand.

### Section markers (MedTemplate, fungi variant)

Same three-channel logic and the same typographic treatment as the plants markers (a 6px vertical bar in the marker color + roman serif name + gradient trailing hairline). Hues shift to the fungi cool family.

| Token | Hex | Section |
|---|---|---|
| `--fungi-mark-summary` | `#2c3640` | Summary (damp slate) |
| `--fungi-mark-pharmacy` | `#364a3e` | Pharmacy (mycelial green-dark) |
| `--fungi-mark-pharmacology` | `#3a3f5e` | Pharmacology (deep bruise) |

### Marginalia

The plants illustrations turn fungal: the hero vine becomes a mycelial-thread tracery, the featured-medicine illustration becomes a mushroom specimen drawing. The sidebar Amanita specimen, already drawn for the plants skin, carries straight over. Hard prohibitions, the no-light-mode rule, and the no-decorative-italics rule all apply unchanged.

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

## Full-width layout and the Appearance rail (2026-05-21)

Standing layout doctrine, both skins. Mark, 2026-05-21: the interface scales to the browser width on desktop, edge to edge, everywhere. The centered fixed-width shell is retired. This extends the C/Specimen "Layout primitives" above; the diptych pages (Category index, Main Page) already ran edge to edge, and the rest of the site now matches them. The diptych pages then went further still, to a full chromeless splash; see "The diptych pages" below.

### The doctrine

1. **The frame and every interface surface run edge to edge.** Topbar, masthead, section rules, content surfaces, the right rail, and the footer reach the viewport's left and right edges. No `max-width` shell, no centered column, no dead bands of background on a wide monitor. Horizontal breathing room is the `--layout-gutter` inset, not a centering margin.
2. **Running prose holds a readable measure.** Article body prose (lead and section paragraphs, reference lists) caps at `--measure-prose`, a generous reading measure. The cap lives on the text element and is set in `em`, so the measure tracks the Text size control: at Large the prose grows and the line grows with it, the character count stays constant.
3. **Width-wanting content uses the full column.** The datasheet rail, tables, the category trees, dashboards, the radar and card components, images, and the diptych panels are not measure-capped; they scale with the viewport.
4. **The article is left-anchored.** Prose column and datasheet rail share the page's left content edge with the topbar and masthead; slack pools at the right, where the Appearance rail sits. The on-surface whitespace to the right of a very wide viewport is intentional page margin on a full-bleed dark surface, never a raw background band.

### The diptych pages (chromeless splash)

DECISION 2026-05-21 (Mark). The two diptych pages, the Main Page and the Category index, are full-viewport chromeless splashes. They carry no Vector header, footer, sidebar, page tabs, or firstHeading: the diptych module is the whole page, flush to all four viewport edges, top and bottom included. This is the exception to the full-width doctrine above, which otherwise keeps the normal Vector chrome on every page.

Why it is safe to drop the Vector header: the diptych module carries its own chrome. Its `.topbar` holds Browse, Categories, Problems, Assessments, Contribute, Search, and Log in, a functional replacement for the Vector header, so navigation is not lost. The module's `.footer` carries the last-modified line, the license line, and the links row. Reference mock: `/var/www/mediawiki/design/mainpage_diptych.html`.

Mechanism (interface-claude): a page-scoped body class, `body.pcp-diptych-page`, set on exactly those two pages. CSS keyed on it hides all Vector chrome above and below the content body, and zeros the layout box (`max-width: none; padding: 0; margin: 0` on `.mw-page-container` and its content wrappers), which removes both `--layout-gutter` and the vertical padding in one move. The diptych module root takes `min-height: 100vh` with `display: flex; flex-direction: column`, and `.diptych` takes `flex: 1 1 auto`, so the loam and near-black grounds fill the viewport on any screen.

The module's own inner padding (`.topbar`, `.col`, and the rest) is the design's intended content inset, not layout gutter, and is kept: panel backgrounds reach the viewport edge, panel content sits inset. Scope is those two pages only, body-class gated; every other page keeps full Vector chrome plus the full-width layout and the Appearance rail. Tradeoff, accepted: with the Vector header gone the edit, history, and talk tabs go with it on these two pages; editing is reached via `?action=edit` or a topbar affordance.

**The two-origin split runs the full height.** DECISION 2026-05-21 (Mark). The diptych is not just the column body; the `--p-bg` | `--l-loam` vertical split is the spine of the entire splash, top to bottom. The earlier defect was that the topbar, masthead, status strip, and footer were uniform dark bands that capped the split, so the seam started below the header and died above the footer. The fix: the split, with a 1px `#000` seam baked in at the 50% line, lives as a background gradient on the diptych module root; the topbar, titleband, strip, and footer all go transparent so the split shows through them unbroken. The columns stay opaque and carry their own richer per-origin treatments over the body region, aligned to the same 50% seam (the `.col-plant` border-left coincides with the root's baked seam). The footer content is centered so it bridges the seam rather than sitting lopsided on one origin. Desktop only: below the `880px` breakpoint the diptych stacks and there is no vertical split, so the full-height gradient is scoped to `>880px` and the root falls back to the flat pharma dark. The seam must reach both the top and the bottom of the viewport.

### Layout tokens

| Token | Value | Role |
|---|---|---|
| `--layout-gutter` | `clamp(20px, 3.4vw, 52px)` | Viewport-edge inset, shared by topbar, masthead, article, footer |
| `--measure-prose` | `56.25em` | Width of the central reading column (the article grid's prose track and the prose `max-width`); em-based, so it tracks Text size. 56.25em is the prior 45em widened 25% (Mark, 2026-05-21, "go from there"). |
| `--col-gap` | `clamp(34px, 4.2vw, 70px)` | Gap between the prose column and the datasheet rail |
| `--datasheet-w` | `clamp(280px, 23vw, 372px)` | Medicine-page datasheet rail width |
| `--rail-w` | `292px` | Expanded Appearance-rail width |
| `--topbar-h` | `54px` | Sticky topbar height; the Appearance panel docks below it |
| `--type-scale` | `0.92` / `1` / `1.12` | Small / Standard / Large; set on `:root` by the rail's Text size control, persisted per browser. Reading-text sizes are `calc(<px> * var(--type-scale))`, so the em-based `--measure-prose` tracks the setting. |

### The Appearance rail

DECISION 2026-05-21 (Mark, after interface-claude's premise check). The live skin has no extension-authored Appearance panel; the appearance controls (text size, width, light/dark) were Vector 2022's native client-preferences menu. interface-claude raised the fork: use Vector's menu (Option A), or build a custom rail (Option B). Mark chose **Option B**: the extension ships its own Appearance rail, and Vector's native client preferences are disabled wholesale.

This is the deliberate call over the platform-default path. The reasons it holds: the rail is a small, fixed surface (Text size, plus a Plant/Dark skin switch), not a re-implementation of Vector's whole client-preferences framework; and it gives a hard guarantee on the no-light-mode rule, since no Vector preferences menu is left to drift back on a MediaWiki upgrade. The appearance UI is wholly the extension's.

The rail, both skins:
- **Collapsed by default.** Collapsed, it is a quiet vertical tab on the right edge, in the article's right-side whitespace, obscuring nothing; content runs the full viewport width.
- **Expanded**, a `--rail-w` panel docks below the topbar and pushes the content left (the content transitions its `margin-right` over `--motion-moved`); collapsing it returns the content to the edge. Open/closed state persists per browser.
- Below the `880px` mobile breakpoint the rail is removed; the Text size control gets a topbar affordance instead (interface-claude's call on the exact placement).
- Rail surfaces use each skin's panel tokens (`--pharma-bg-2` / `--pharma-line`; `--plants-bark` / `--plants-rule-strong`).

### Appearance rail contents

**Text size.** A three-segment control, Small / Standard / Large, setting `--type-scale` on `:root`; the skin CSS scales reading text by it. Because Vector's native client preferences are disabled, the rail owns text scaling: this control is the only text-size UI on the site, so it must be a real accessibility text-zoom, not a cosmetic tweak.

- **Light mode: eliminated altogether** (Mark, 2026-05-21, emphatic). Not a disabled toggle, gone. There is no light/dark control anywhere, and no light-mode CSS, body class, or `prefers-color-scheme: light` block in the tree. The site is dark, always, both skins (the standing [[feedback_no_light_modes]] rule). Vector night mode is off in config as part of disabling Vector's client preferences.
- **Width (Standard / Wide): removed.** A fluid edge-to-edge layout has no narrow-versus-wide column to choose; `--measure-prose` holds readability.
- **Skin switch (incoming).** Mark, 2026-05-21: a Plant/Dark skin switch joins the rail, each skin represented on its side. designer-claude speccing; the open decision is whether a user's pick globally overrides per-page origin skinning. The rail is a two-control surface.

Build reference: `https://pharmacopedia.wiki/design/pharma_fullwidth.html`. Under Option B the mock is the build spec for the rail, not just a visual reference: the collapsed tab, the expand-and-push behavior, the segmented control, and the `--type-scale` text scaling are productionized from it. The fluid frame, the prose measure, and the sticky datasheet are unchanged.

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

Per interface-claude's 2026-05-20 inventory, the extension's reusable visual primitives were catalogued into named families. In production, only the chip-picker became a real `--pcp-chip-*` token block; see the Production status note below for how the others resolved.

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

**Production status (settled 2026-05-21, designer-claude and interface-claude).** Only the `--pcp-chip-*` block is a real `--pcp-*` token layer in `ext.pharmacopedia.css`. The radar, card, and bar families are built directly on the house `--pharma-*` tokens, with no `--pcp-*` indirection: interface-claude shipped the assessment card family (verdict card, featured-card radar face) and the radar SVGs that way, and the PGx tier colors ship as `--pharma-tier-*`. This is the decided architecture, not an interim state. The radar, card, and bar blocks below are therefore a color and role reference, a map of which `--pharma-*` value plays which part, not a token spec a stylesheet can call. The `--pcp-*` indirection would only earn its keep if a plant-skinned page came to carry an assessment surface and the wholesale `--pharma-*` re-point under `.pcp-skin-plants` proved insufficient; revisit it then, not before. See parking lot item 5.

### Family status

| Family | Status | Spec'd by |
|---|---|---|
| `--pcp-chip-*` | LOCKED, ready for refactor. The only real `--pcp-*` token family in production CSS. | designer-claude 2026-05-20 (see chip-picker block below) |
| `--pcp-radar-*` | Not a token family (settled 2026-05-21). Radar SVGs ship on `--pharma-*` directly; the radar block below is a color reference. | designer-claude (see radar block below) |
| `--pcp-pgx-*` | Not a token family (settled 2026-05-21). Tier colors ship as `--pharma-tier-*`; see the C/Specimen section. | designer-claude |
| `--pcp-card-*` | Not a token family (settled 2026-05-21). The card family ships on `--pharma-*` directly; the card block below is a color reference. | designer-claude (see card block below) |
| `--pcp-bar-*` | Not a token family (settled 2026-05-21). Bars ship on `--pharma-*` directly; the bar block below is a color reference. | designer-claude (see bar block below) |
| `--pcp-page-*` | partial (plants skin hero values inferred from mocks) | future |
| `--pcp-text-*` | partial (chip-picker block already uses --pcp-text family) | future |
| `--pcp-rule-*` | partial (chip-picker block already uses --pcp-rule family) | future |

When designer-claude redesigns one of these surfaces, the spec updates the relevant block below: a token block for the chip-picker, a color and role reference for radar, card, and bar.

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

## Radar color reference

Color and role map for the assessment-instrument radar visualizations (CATI, PID-5-BF, CAT-Q, BPNS, NFCS) and the radar face of the assessment card. Settled 2026-05-21: radar SVGs ship on the house `--pharma-*` palette directly, with no `--pcp-radar-*` indirection layer; interface-claude styles the polygons inline in the generated SVG. The table below is the canonical map of which `--pharma-*` value plays which role. The left-column `--pcp-radar-*` names are descriptive role labels for reading this doc, not callable CSS. Reference mockup: `https://pharmacopedia.wiki/design/pharma_components.html`.

| Color slot | `--pharma-*` value | Role |
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

## Card color reference

Color and role map for the assessment card family: featured card (gauge + radar faces), list card, history / verdict / quiet variants. Settled 2026-05-21: the card family ships on `--pharma-*` directly, with no `--pcp-card-*` indirection. interface-claude shipped the verdict card and the featured-card radar face (`.verdict`, `.fc`) that way, with `.fc-experimental` carrying the amber-caption state. The table below is the canonical role map; the left-column `--pcp-card-*` names are descriptive role labels, not callable CSS. Reference mockup: `https://pharmacopedia.wiki/design/pharma_card_family.html`.

| Color slot | `--pharma-*` value | Role |
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

## Bar color reference

Color and role map for the intensity-bar family: basic intensity (PGx edge bars, effect strengths), zoned (value against thresholds), paired (value plus reference), vote tally. Settled 2026-05-21: bars ship on `--pharma-*` directly, with no `--pcp-bar-*` indirection. The table below is the canonical role map; the left-column `--pcp-bar-*` names are descriptive role labels, not callable CSS. Reference mockup: `https://pharmacopedia.wiki/design/pharma_components.html`.

| Color slot | `--pharma-*` value | Role |
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

## Plants-skin component color reference

Color reference, settled 2026-05-21. The radar, card, and bar families ship on `--pharma-*` directly, not on a `--pcp-*` indirection, so there is nothing per-component to re-point today. These are the plant-palette equivalents held in reserve: if a plant-skinned page ever carries an assessment surface, this block is the starting reference for theming it, whether through the wholesale `--pharma-*` re-point under `.pcp-skin-plants` or a per-component override decided at that time. The PGx tier render on herbal-interaction medicine pages is the most likely first consumer.

Implementation note: the PGx tier colors are named `--pharma-tier-*` in the C/Specimen section. Per the 2026-05-21 settlement they stay `--pharma-tier-*`; the plants skin re-points them, like the rest of the `--pharma-*` layer, under `.pcp-skin-plants`. The plant-side values are below.

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
4. Category pages, File pages, and Talk pages are all covered by the Skin selection rule: the plants skin only with a direct `Category:Plants` tag and no `Category:Pharmaceutical`, otherwise the pharma default. The dual-parented class-category case (for example `Category:Psychedelics`) resolves to pharma, as intended.
5. RESOLVED 2026-05-21 (designer-claude and interface-claude). The `--pcp-*` component-token indirection: only `--pcp-chip-*` is a real `--pcp-*` token family. The radar, card, and bar families stay built on `--pharma-*` directly; no `--pcp-*` indirection layer is added for them, and the radar, card, and bar blocks above are reframed as a color and role reference rather than a token spec. Rationale: all three components shipped clean on `--pharma-*`, the plants skin re-points the `--pharma-*` layer wholesale, so an indirection layer would have no consumer and would only add drift risk. Revisit only if a plant-skinned page comes to carry an assessment surface and the wholesale re-point proves insufficient for it.

---

## Canonical location

This file is the canonical copy: `/var/www/mediawiki/extensions/Pharmacopedia/DESIGN_TOKENS.md`. It is group-writable, and designer-claude edits it in place here. Any copy in another tree (the `mediawiki-staging` extension dir, or a `/tmp/design_tokens.md` scratch draft) is a downstream snapshot and goes stale; do not treat those as canonical. Always read and edit this path.

To resync a stale staging copy from this canonical one:

```
clear && cp /var/www/mediawiki/extensions/Pharmacopedia/DESIGN_TOKENS.md /var/www/mediawiki-staging/extensions/Pharmacopedia/DESIGN_TOKENS.md
```
