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
        $parser->setHook( 'pharmaLiterature', [ LiteratureTag::class, 'render' ] );
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
     * loads the plants-skin ResourceModule when the current page's
     * category chain resolves to Category:Plant (or descendant).
     *
     * Locked decisions 2026-05-20:
     *   - Overlay only (body-class layer, not standalone MW skin)
     *   - Multi-membership: Plant wins over Pharmaceutical when both reachable
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

        $ns = $title->getNamespace();
        // Only NS_MAIN and NS_CATEGORY participate in skin selection.
        if ( $ns !== NS_MAIN && $ns !== NS_CATEGORY ) return;

        // Main_Page stays pharma default (clinical-first per Mark 2026-05-20).
        if ( $ns === NS_MAIN && $title->getDBkey() === 'Main_Page' ) return;

        // Resolve the skin by walking the category chain. The walk is a
        // handful of indexed queries (8-hop cap, small category tree),
        // cheap enough to run per pageview. No ParserOutput caching:
        // OutputPage does not expose a singular ParserOutput in MW 1.46.
        $resolved = self::resolvePcpSkin( $title );

        if ( $resolved === 'plants' ) {
            $out->addBodyClasses( [ 'pcp-skin-plants' ] );
            $out->addModuleStyles( [ 'ext.pharmacopedia.skin.plants' ] );
        }
    }

    /**
     * Walk the category-parent chain of $title up to $maxDepth levels
     * to find the first reachable skin-root in [Plant, Pharmaceutical].
     *
     * Multi-membership rule (i): Plant wins over Pharmaceutical when
     * both are reachable, regardless of which is found at a shallower
     * depth. We BFS, return immediately on first Plant hit, and only
     * fall back to Pharmaceutical if Plant is never found within depth.
     *
     * @param \MediaWiki\Title\Title $title
     * @param int $maxDepth
     * @return string|null 'plants' / 'pharma' / null
     */
    public static function resolvePcpSkin( $title, $maxDepth = 8 ) {
        // If the page IS a category, check whether it itself is a skin root.
        if ( $title->getNamespace() === NS_CATEGORY ) {
            $dbKey = $title->getDBkey();
            if ( $dbKey === 'Plant' || $dbKey === 'Plant_Medicines' ) return 'plants';
            if ( $dbKey === 'Pharmaceutical' ) return 'pharma';
        }

        // BFS frontier: pairs of [ db_title, namespace ].
        $visited = [];
        $current = [ [ $title->getDBkey(), $title->getNamespace() ] ];
        $pharmaSeen = false;

        for ( $depth = 0; $depth < $maxDepth && !empty( $current ); $depth++ ) {
            $next = [];
            foreach ( $current as $pair ) {
                $catKey = $pair[0];
                $ns = (int)$pair[1];
                $visKey = $ns . ':' . $catKey;
                if ( isset( $visited[ $visKey ] ) ) continue;
                $visited[ $visKey ] = true;

                $parents = self::pcpFetchParentCategories( $catKey, $ns );
                foreach ( $parents as $p ) {
                    if ( $p === 'Plant' || $p === 'Plant_Medicines' ) {
                        return 'plants'; // Plant wins; bail immediately
                    }
                    if ( $p === 'Pharmaceutical' ) {
                        $pharmaSeen = true;
                        // Keep walking; Plant might be deeper in another branch
                    }
                    $next[] = [ $p, NS_CATEGORY ];
                }
            }
            $current = $next;
        }

        return $pharmaSeen ? 'pharma' : null;
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
