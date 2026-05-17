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

        // v0.11: structured date payload from the pcp-date-input widget
        $updater->addExtensionField( 'pcp_life_events', 'le_date_struct',
            "$dir/patch-le_date_struct.sql" );

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
    }
}
