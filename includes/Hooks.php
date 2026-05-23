<?php
/**
 * Pharmacopedia — a MediaWiki extension supporting pharmacopedia.wiki.
 *
 * Copyright (C) 2024-2026 Mark Elliott and the Pharmacopedia contributors.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace MediaWiki\Extension\Pharmacopedia;

class Hooks {
    public static function onParserFirstCallInit( $parser ) {
        $parser->setHook( 'vote',          [ VoteTag::class,          'render' ] );
        $parser->setHook( 'effect',        [ EffectTag::class,        'render' ] );
        $parser->setHook( 'discuss',       [ CommentTag::class,       'render' ] );
        $parser->setHook( 'effectsummary', [ EffectSummaryTag::class, 'render' ] );
        $parser->setHook( 'titration',     [ TitrationTag::class,     'render' ] );
        $parser->setHook( 'anecdote',      [ AnecdoteTag::class,      'render' ] );
        $parser->setHook( 'problem',       [ ProblemTag::class,       'render' ] );
        $parser->setHook( 'pharmaInteractions', [ InteractionTag::class, 'render' ] );
        $parser->setHook( 'pharmaExperience',    [ ExperienceTag::class,    'render' ] );
        $parser->setHook( 'classGrid',     [ ClassGridTag::class,     'render' ] );
        $parser->setHook( 'classTree',     [ ClassTreeTag::class,     'render' ] );
        $parser->setHook( 'categoryindex', [ CategoryIndexTag::class, 'render' ] );
        $parser->setHook( 'frontpage', [ FrontPageTag::class, 'render' ] );
        $parser->setHook( 'pharmaLiterature', [ LiteratureTag::class, 'render' ] );
        $parser->setHook( 'pharmaCommonUses', [ CommonUsesTag::class, 'render' ] );
    }

    public static function onLoadExtensionSchemaUpdates( $updater ) {
        $dir = dirname( __DIR__ ) . '/sql';
        // Feature Requests (v1.0)
        $updater->addExtensionTable( 'pcp_feature_request',            "$dir/feature_requests.sql" );
        $updater->addExtensionTable( 'pcp_feature_request_attachment', "$dir/feature_request_attachment.sql" );
        $updater->addExtensionTable( 'pcp_feature_request_comment',    "$dir/feature_request_comment.sql" );
        $updater->addExtensionTable( 'pcp_votable_elements', "$dir/votable_elements.sql" );
        $updater->addExtensionField( 'pcp_votable_elements', 've_open_ended',  "$dir/patch-open_ended.sql" );
        $updater->addExtensionField( 'pcp_votable_elements', 've_max_options', "$dir/patch-open_ended.sql" );
        $updater->addExtensionTable( 'pcp_votes',            "$dir/votes.sql" );
        $updater->addExtensionTable( 'pcp_effect_reports',   "$dir/effect_reports.sql" );
        $updater->addExtensionTable( 'pcp_comments',         "$dir/comments.sql" );
        $updater->addExtensionTable( 'pcp_provider_apps',    "$dir/provider_apps.sql" );
        $updater->addExtensionTable( 'pcp_effects',          "$dir/effects.sql" );
        $updater->addExtensionTable( 'pcp_likert_reports',   "$dir/likert_reports.sql" );
        // v0.15: Problems repository (now standalone; legacy pcp_indications dropped in 5b)
        $updater->addExtensionTable( 'pcp_problem',           "$dir/problems.sql" );
        $updater->addExtensionTable( 'pcp_problem_alias',     "$dir/problem_alias.sql" );
        $updater->addExtensionTable( 'pcp_interactions',        "$dir/interactions.sql" );
        $updater->addExtensionTable( 'pcp_interaction_reports', "$dir/interaction_reports.sql" );
        $updater->addExtensionTable( 'pcp_experience_reports',   "$dir/experience_reports.sql" );
        $updater->addExtensionField( 'pcp_experience_reports', 'xr_dose_mg', "$dir/patch-xr-dose-mg.sql" );

        // v0.5: perspective
        $updater->addExtensionField( 'pcp_effect_reports', 'er_perspective',
            "$dir/patch-er_perspective.sql" );
        $updater->dropExtensionIndex( 'pcp_effect_reports', 'er_element_user',
            "$dir/patch-er_drop_old_index.sql" );
        // Renamed from er_element_user_persp during anonymization (er_user_id → er_voter_hash).
        $updater->addExtensionIndex( 'pcp_effect_reports', 'er_element_hash_persp',
            "$dir/patch-er_new_index.sql" );

        // v0.6: provider frequency
        $updater->addExtensionField( 'pcp_effect_reports', 'er_frequency_pct',
            "$dir/patch-er_frequency.sql" );

        // v0.8: relevant literature
        $updater->addExtensionTable( 'pcp_literature', "$dir/literature.sql" );

        // v0.9: user profile system (demographics, OCEAN, diagnoses, meds, assessments)
        $updater->addExtensionTable( 'pcp_user_profiles',           "$dir/user_profiles.sql" );
        $updater->addExtensionTable( 'pcp_formal_tests',           "$dir/formal_tests.sql" );
        $updater->addExtensionTable( 'pcp_user_test_scores',       "$dir/user_test_scores.sql" );
        $updater->addExtensionField( 'pcp_user_test_scores', 'uts_raw_is_estimate', "$dir/patch-uts-estimate.sql" );
        $updater->addExtensionField( 'pcp_user_test_scores', 'uts_vis_raw', "$dir/patch-uts-vis-fields.sql" );
        $updater->addExtensionField( 'pcp_user_profiles', 'prof_research_id', "$dir/patch-research_id.sql" );
        $updater->addExtensionTable( 'pcp_profile_fields',          "$dir/profile_fields.sql" );
        $updater->addExtensionTable( 'pcp_profile_diagnoses',       "$dir/profile_diagnoses.sql" );
        $updater->addExtensionTable( 'pcp_user_meds',               "$dir/user_meds.sql" );
        $updater->addExtensionTable( 'pcp_diagnosis_abbreviations', "$dir/diagnosis_abbreviations.sql" );

        // v0.9.1: opt-in XR aggregation on Special:UserProfile
        $updater->addExtensionField( 'pcp_user_profiles', 'prof_show_xr_on_profile',
            "$dir/patch-prof_show_xr_on_profile.sql" );

        // v0.10: Life Story timeline
        $updater->addExtensionTable( 'pcp_life_events', "$dir/life_events.sql" );
        $updater->addExtensionTable( 'pcp_life_images', "$dir/life_images.sql" );
        $updater->addExtensionTable( 'pcp_life_traits', "$dir/life_traits.sql" );

        // Observations + episodes refs (le_type=3, le_type=4)
        $updater->addExtensionTable( 'pcp_life_event_refs',     "$dir/observations.sql" );

        // Per-record sharing subsystem
        $updater->addExtensionTable( 'pcp_visibility_rules',    "$dir/visibility_rules.sql" );
        $updater->addExtensionTable( 'pcp_cohorts',             "$dir/visibility_rules.sql" );
        $updater->addExtensionTable( 'pcp_cohort_members',      "$dir/visibility_rules.sql" );
        $updater->addExtensionTable( 'pcp_visibility_view_log', "$dir/visibility_rules.sql" );

        // v0.11: structured date payload from the pcp-date-input widget
        $updater->addExtensionField( 'pcp_life_events', 'le_date_struct',
            "$dir/patch-le_date_struct.sql" );

        // Phase 1 of Story-as-wiki-page: back-pointer to the canonical wiki
        // page (NS_STORY=1500) that holds the Story body content.
        $updater->addExtensionField( 'pcp_life_events', 'le_page_id',
            "$dir/patch-le-page-id.sql" );

        // v0.12: structured "when first noticed" payload for diagnoses
        $updater->addExtensionField( 'pcp_profile_diagnoses', 'pd_date_struct',
            "$dir/patch-pd_date_struct.sql" );

        // v0.12.2: secondary professional-diagnosis date (used when pd_origin = 3 Both)
        $updater->addExtensionField( 'pcp_profile_diagnoses', 'pd_date_struct_pro',
            "$dir/patch-pd_date_struct_pro.sql" );

        // v0.13: structured start/stop dates for meds
        $updater->addExtensionField( 'pcp_user_meds', 'um_start_struct',
            "$dir/patch-um_date_structs.sql" );
        $updater->addExtensionField( 'pcp_user_meds', 'um_stop_struct',
            "$dir/patch-um_date_structs.sql" );

        // v0.14: structured periods-of-use (JSON list of ranges)
        $updater->addExtensionField( 'pcp_user_meds', 'um_periods',
            "$dir/patch-um_periods.sql" );

        // Pharmacogenomics Phase 1: extend pcp_interactions with
        // relationship/intensity/evidence/mechanism/kinetics + replace the
        // unique key. We register one of the new columns as the field
        // anchor; the patch performs all six DDL changes atomically.
        $updater->addExtensionField( 'pcp_interactions', 'pi_relationship',
            "$dir/patch-pi-pgx.sql" );
        $updater->addExtensionField( 'pcp_interactions', 'pi_intensity',
            "$dir/patch-pi-pgx.sql" );
        $updater->addExtensionField( 'pcp_interactions', 'pi_evidence',
            "$dir/patch-pi-pgx.sql" );
        $updater->addExtensionField( 'pcp_interactions', 'pi_mechanism',
            "$dir/patch-pi-pgx.sql" );
        $updater->addExtensionField( 'pcp_interactions', 'pi_kinetics',
            "$dir/patch-pi-pgx.sql" );

        // Audit log for any pcp_interactions ingestion run (CPIC, FDA,
        // inference engine, hand-curated sandbox seeds).
        $updater->addExtensionTable( 'pcp_ingestion_log', "$dir/ingestion_log.sql" );

        // Provenance: each pcp_interactions row carries an immutable FK
        // back to the pcp_ingestion_log row that created it. Renderer uses
        // this for the row-level "where did this edge come from?" popover.
        $updater->addExtensionField( 'pcp_interactions', 'pi_ingestion_id',
            "$dir/patch-pi-ingestion-id.sql" );

        // 2026-05-19: widen pi_mechanism from VARCHAR(255) -> VARCHAR(2048).
        // 255 was truncating CPIC + FDA + pair-level enrichment strings
        // mid-PMID, creating fake citations. modifyExtensionField re-runs
        // the ALTER even though the column already exists.
        if ( method_exists( $updater, 'modifyExtensionField' ) ) {
            $updater->modifyExtensionField( 'pcp_interactions', 'pi_mechanism',
                "$dir/patch-pi-mechanism-widen.sql" );
        } else {
            $updater->modifyField( 'pcp_interactions', 'pi_mechanism',
                "$dir/patch-pi-mechanism-widen.sql" );
        }

        // Phase 4 herbal medicines: preparation / dose-range / use tables.
        $updater->addExtensionTable( 'pcp_preparation',  "$dir/herbal_preparation.sql" );
        $updater->addExtensionTable( 'pcp_herbal_doses', "$dir/herbal_doses.sql" );
        $updater->addExtensionTable( 'pcp_herbal_use',   "$dir/herbal_use.sql" );

        // Genotype-anchor layer: the CPIC star-allele catalog.
        $updater->addExtensionTable( 'pcp_pgx_allele', "$dir/pgx_allele.sql" );

        // Resolver v2: CPIC diplotype -> phenotype lookup table.
        $updater->addExtensionTable( 'pcp_pgx_diplotype', "$dir/pgx_diplotype.sql" );

        // Perspective subsystem: invites + submitted perspectives.
        $updater->addExtensionTable( 'pcp_perspective_invite', "$dir/perspective_invite.sql" );
        $updater->addExtensionTable( 'pcp_perspective',        "$dir/perspective.sql" );

        // Granular PGx interaction-voting flags.
        $updater->addExtensionTable( 'pcp_interaction_flags', "$dir/interaction_flags.sql" );

        // "Administer to others": send assessment scales to outside respondents.
        $updater->addExtensionTable( 'pcp_administer_respondents', "$dir/administer_respondents.sql" );
        $updater->addExtensionTable( 'pcp_administer_invites',     "$dir/administer_invites.sql" );
        $updater->addExtensionTable( 'pcp_administer_assessments', "$dir/administer_assessments.sql" );
        $updater->addExtensionTable( 'pcp_administer_userkey',     "$dir/administer_userkey.sql" );
        $updater->addExtensionTable( 'pcp_administer_research',    "$dir/administer_research.sql" );
        $updater->addExtensionField( 'pcp_administer_assessments', 'aa_respondent_enc',
            "$dir/patch-administer-respondent_enc.sql" );

        // Make pcp_likert_reports.pl_value nullable: NULL is the Don't-know
        // abstention, replacing the old -1 sentinel.
        $updater->modifyExtensionField( 'pcp_likert_reports', 'pl_value',
            "$dir/patch-likert-pl_value-nullable.sql" );

        // Rescale pl_value from 0-100 to 0-5 (DECIMAL(3,2)); Mark consolidated
        // the rating to a single 0-5 scale.
        $updater->modifyExtensionField( 'pcp_likert_reports', 'pl_value',
            "$dir/patch-likert-pl_value-0to5.sql" );
    }

    /**
     * Phase 4: Story-as-wiki-page read protection.
     * Pages in NS_STORY are private-by-default; access is gated by the owner's
     * pcp_visibility_rules row for vr_namespace='story' + vr_key=<page_id>.
     * Owner sees their own story; sysops bypass; everyone else needs an
     * explicit share rule (or a valid ?pcpshare=<token> in URL for link_token).
     */
    public static function onGetUserPermissionsErrors( $title, $user, $action, &$result ) {
        if ( !defined( 'NS_STORY' ) || $title->getNamespace() !== NS_STORY ) return true;
        if ( !$title->exists() ) return true;
        $pageId = (int)$title->getArticleID();
        if ( $pageId <= 0 ) return true;

        // Sysops bypass for moderation. MW 1.46 routes group queries through
        // UserGroupManager (User::getEffectiveGroups was removed).
        $services = \MediaWiki\MediaWikiServices::getInstance();
        $groups = $services->getUserGroupManager()->getUserEffectiveGroups( $user );
        if ( in_array( 'sysop', $groups, true ) ) return true;

        $dbr = $services->getConnectionProvider()->getReplicaDatabase();
        $row = $dbr->newSelectQueryBuilder()
            ->select( [ 'le_profile_id' ] )
            ->from( 'pcp_life_events' )
            ->where( [ 'le_page_id' => $pageId ] )
            ->caller( __METHOD__ )
            ->fetchRow();
        // Page with no linked card: legacy edge case; allow (no owner to gate by).
        if ( !$row ) return true;

        $ownerProfileId = (int)$row->le_profile_id;
        $request = \RequestContext::getMain()->getRequest();
        $linkToken = trim( (string)$request->getVal( 'pcpshare', '' ) );

        $allowed = \MediaWiki\Extension\Pharmacopedia\VisibilityResolver::canViewByRule(
            $ownerProfileId,
            (int)$user->getId(),
            'story',
            (string)$pageId,
            $linkToken !== '' ? $linkToken : null
        );
        if ( $allowed ) return true;

        $result = [ 'badaccess-group0' ];
        return false;
    }

    /**
     * Plants-skin trigger. Assigns the .pcp-skin-plants body class and
     * loads the plants-skin ResourceModule when the page's origin
     * resolves to Plant (see resolvePcpSkin for the rule).
     *
     * Locked decisions 2026-05-20:
     *   - Overlay only (body-class layer, not standalone MW skin)
     *   - Origin is each page's DIRECT category tag, not a recursive
     *     walk (two-gate rule, Mark 2026-05-20); ambiguity is pharma
     *   - Plant + Plant_Medicines as root categories are skinned themselves
     *   - Main_Page stays pharma default (clinical-first identity)
     *   - Only NS_MAIN + NS_CATEGORY get skin treatment;
     *     Special/Help/File/User/Talk pages stay pharma always
     *
     * @param \OutputPage $out
     * @param \Skin $skin
     * @return void
     */
    public static function onBeforePageDisplay( $out, $skin ) {
        $title = $out->getTitle();
        if ( !$title ) return;

        // C/Specimen redesign: the redesign CSS layer (chrome, tokens,
        // page styling) is the default skin and must load on EVERY page,
        // not only pages that use a pharma feature. ext.pharmacopedia.styles
        // is otherwise pulled in only by the parser tags, so tag-free
        // pages (Main Page, Special pages) get no redesign CSS at all.
        $out->addModuleStyles( [ 'ext.pharmacopedia.styles' ] );
        // Styled delete confirmation: ext.pharmacopedia.confirmdelete
        // replaces native confirm() for destructive actions site-wide.
        $out->addModules( [ 'ext.pharmacopedia.confirmdelete', 'ext.pharmacopedia.appearance' ] );

        // Diptych splash pages (Main Page, Category index): chromeless,
        // full-viewport. The pcp-diptych-page body class drives the
        // chrome-hiding CSS in ext.pharmacopedia.css.
        if ( $title->inNamespace( NS_MAIN )
            && in_array( $title->getDBkey(), [ 'Main_Page', 'Category_index' ], true )
        ) {
            $out->addBodyClasses( [ 'pcp-diptych-page' ] );
        }

        $ns = $title->getNamespace();

        // The per-page origin skin: resolvePcpSkin runs for content and
        // category pages (Main_Page stays the pharma default); every
        // other page is pharma. The walk is a handful of indexed
        // queries, cheap enough per pageview.
        $resolved = 'pharma';
        if ( ( $ns === NS_MAIN || $ns === NS_CATEGORY )
            && !( $ns === NS_MAIN && $title->getDBkey() === 'Main_Page' )
        ) {
            $r = self::resolvePcpSkin( $title );
            if ( $r === 'plants' || $r === 'fungi' ) {
                $resolved = $r;
            }
        }
        // The Appearance rail's Skin switch reports this on its
        // Automatic row ("Follows the page, now Plants").
        $out->addJsConfigVars( [ 'pcpResolvedSkin' => $resolved ] );

        // A per-browser Skin-switch override (the pcp-skin-override
        // cookie, set by the Appearance rail) wins over the resolver,
        // on every page. Read server-side so the override is applied
        // before first paint, with no flash.
        $override = $out->getRequest()->getCookie( 'pcp-skin-override', '', '' );
        $effective = in_array( $override, [ 'pharma', 'plants', 'fungi' ], true )
            ? $override : $resolved;

        if ( $effective === 'fungi' ) {
            // Fungi is a sub-skin of plants: the body carries both
            // classes, and the fungi module loads after the plants
            // base so its overrides win.
            $out->addBodyClasses( [ 'pcp-skin-plants', 'pcp-skin-fungi' ] );
            $out->addModuleStyles( [ 'ext.pharmacopedia.skin.plants', 'ext.pharmacopedia.skin.fungi' ] );
        } elseif ( $effective === 'plants' ) {
            $out->addBodyClasses( [ 'pcp-skin-plants' ] );
            $out->addModuleStyles( [ 'ext.pharmacopedia.skin.plants' ] );
        }
    }

    /**
     * Resolve which skin a page gets: 'plants', 'pharma', or null.
     *
     * Two-gate origin rule (standing rule, Mark 2026-05-20): every
     * medicine page carries exactly ONE direct origin category,
     * Category:Plants or Category:Pharmaceutical. For a content page the
     * skin is read from that DIRECT tag only, never a recursive category
     * walk: class categories are deliberately dual-parented (e.g.
     * Category:Psychedelics sits under BOTH Plant and Pharmaceutical), so
     * a recursive "is this under Plant?" test would mis-skin pages such
     * as LSD. A content page gets the plants skin only on an unambiguous
     * direct Plant tag; anything else is pharma.
     *
     * A CATEGORY page has no single direct origin, so it is resolved by
     * its category chain (see pcpResolveCategoryChain): plants only when
     * the chain is purely Plant.
     *
     * @param \MediaWiki\Title\Title $title
     * @param int $maxDepth category-chain hop cap for CATEGORY pages
     * @return string|null 'plants' / 'pharma' / null
     */
    public static function resolvePcpSkin( $title, $maxDepth = 8 ) {
        $ns = $title->getNamespace();

        // A CATEGORY page may itself be an origin root; otherwise it is
        // resolved by walking its category chain.
        if ( $ns === NS_CATEGORY ) {
            $dbKey = $title->getDBkey();
            if ( $dbKey === 'Fungi' ) return 'fungi';
            if ( $dbKey === 'Plant' || $dbKey === 'Plants' || $dbKey === 'Plant_Medicines' ) return 'plants';
            if ( $dbKey === 'Pharmaceutical' ) return 'pharma';
            return self::pcpResolveCategoryChain( $title, $maxDepth );
        }

        // A content (medicine) page: read its OWN DIRECT origin tag only.
        // No recursion, so a page sitting in a dual-parented class
        // category is skinned by its own origin, not by its class.
        $direct = self::pcpFetchParentCategories( $title->getDBkey(), $ns );
        // Fungi sub-skin: a direct Category:Fungi tag wins, checked
        // ahead of the Plant test (a fungus page carries both the
        // Plants origin tag and the Fungi kingdom tag).
        if ( in_array( 'Fungi', $direct, true ) ) {
            return 'fungi';
        }
        $hasPlant = in_array( 'Plant', $direct, true )
            || in_array( 'Plants', $direct, true )
            || in_array( 'Plant_Medicines', $direct, true );
        $hasPharma = in_array( 'Pharmaceutical', $direct, true );

        // Plants skin only on an unambiguous direct Plant tag. A page
        // tagged both (a tagging error under the two-gate rule) falls
        // through to pharma, the clinical-first default.
        if ( $hasPlant && !$hasPharma ) return 'plants';
        if ( $hasPharma ) return 'pharma';
        return null;
    }

    /**
     * Resolve the skin for a non-root CATEGORY page by walking its
     * category chain up to $maxDepth hops. A category is given the
     * plants skin only when it is purely Plant: Plant (or
     * Plant_Medicines) is reachable and Pharmaceutical is not. A
     * category that reaches Pharmaceutical, including a dual-parented
     * class category such as Psychedelics, defaults to pharma
     * (clinical-first identity).
     *
     * @param \MediaWiki\Title\Title $title
     * @param int $maxDepth
     * @return string|null 'plants' / 'pharma' / null
     */
    private static function pcpResolveCategoryChain( $title, $maxDepth ) {
        $visited = [];
        $current = [ [ $title->getDBkey(), $title->getNamespace() ] ];
        $plantSeen = false;
        $pharmaSeen = false;

        for ( $depth = 0; $depth < $maxDepth && !empty( $current ); $depth++ ) {
            $next = [];
            foreach ( $current as $pair ) {
                $catKey = $pair[0];
                $cns = (int)$pair[1];
                $visKey = $cns . ':' . $catKey;
                if ( isset( $visited[ $visKey ] ) ) continue;
                $visited[ $visKey ] = true;

                $parents = self::pcpFetchParentCategories( $catKey, $cns );
                foreach ( $parents as $p ) {
                    if ( $p === 'Plant' || $p === 'Plants' || $p === 'Plant_Medicines' ) {
                        $plantSeen = true;
                    } elseif ( $p === 'Pharmaceutical' ) {
                        $pharmaSeen = true;
                    }
                    $next[] = [ $p, NS_CATEGORY ];
                }
            }
            $current = $next;
        }

        if ( $plantSeen && !$pharmaSeen ) return 'plants';
        if ( $pharmaSeen ) return 'pharma';
        return null;
    }

    /**
     * Fetch direct parent categories of ($catKey, $ns) via the MW 1.46
     * categorylinks -> linktarget schema (cl_target_id -> lt_id).
     *
     * Returns an array of bare category DB-key strings (no Category: prefix).
     *
     * @param string $catKey
     * @param int $ns
     * @return string[]
     */
    private static function pcpFetchParentCategories( $catKey, $ns ) {
        $dbr = \MediaWiki\MediaWikiServices::getInstance()
            ->getConnectionProvider()->getReplicaDatabase();
        $rows = $dbr->newSelectQueryBuilder()
            ->select( [ 'lt_title' ] )
            ->from( 'categorylinks' )
            ->join( 'linktarget', null, 'cl_target_id = lt_id' )
            ->join( 'page', null, 'cl_from = page_id' )
            ->where( [
                'page_title' => $catKey,
                'page_namespace' => $ns,
                'lt_namespace' => NS_CATEGORY,
            ] )
            ->caller( __METHOD__ )
            ->fetchResultSet();
        $out = [];
        foreach ( $rows as $r ) {
            $out[] = (string)$r->lt_title;
        }
        return $out;
    }

}
