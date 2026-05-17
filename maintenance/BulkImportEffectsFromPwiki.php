<?php
/**
 * Bulk-import the PsychonautWiki Subjective Effect Index into pcp_effects.
 * Sources name from each PWiki page title; description seeded with provenance link.
 *
 * Usage:
 *   php run.php /var/www/mediawiki/extensions/Pharmacopedia/maintenance/BulkImportEffectsFromPwiki.php \
 *       --username=MDElliottMD [--dry-run]
 */

$IP = getenv( 'MW_INSTALL_PATH' ) ?: '/var/www/mediawiki';
require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\MediaWikiServices;
use MediaWiki\Extension\Pharmacopedia\GlobalEffectStore;

class BulkImportEffectsFromPwiki extends Maintenance {

    public function __construct() {
        parent::__construct();
        $this->addOption( 'username', 'User to credit as creator', true, true );
        $this->addOption( 'dry-run', 'Preview without writing', false, false );
        $this->requireExtension( 'Pharmacopedia' );
    }

    public function execute() {
        $userName = $this->getOption( 'username' );
        $dryRun = $this->hasOption( 'dry-run' );
        $user = MediaWikiServices::getInstance()->getUserFactory()->newFromName( $userName );
        if ( !$user || !$user->isRegistered() ) {
            $this->fatalError( "User '$userName' not found." );
        }

        $store = new GlobalEffectStore();

        $effects = self::EFFECT_NAMES;
        $aliasUpdates = self::ALIAS_UPDATES;

        $created = 0; $existed = 0; $aliased = 0;

        // 1) Bulk-create new effects
        foreach ( $effects as $name ) {
            $slug = GlobalEffectStore::normalizeSlug( $name );
            if ( $slug === '' ) { continue; }
            $existing = $store->getBySlug( $slug );
            if ( $existing ) {
                $existed++;
                $this->output( "  (exists) $name [$slug]\n" );
                continue;
            }
            $pwikiUrl = 'https://psychonautwiki.org/wiki/' . str_replace( ' ', '_', $name );
            $desc = "Source: $pwikiUrl";
            if ( $dryRun ) {
                $this->output( "  + $name [$slug]\n" );
            } else {
                $store->create( $slug, $name, $desc, /* aliases */ '', $user->getId() );
                $this->output( "  + $name [$slug]\n" );
            }
            $created++;
        }

        // 2) Append aliases on existing rows for the true duplicates
        foreach ( $aliasUpdates as $targetSlug => $newAliases ) {
            $row = $store->getBySlug( $targetSlug );
            if ( !$row ) {
                $this->output( "  WARN: alias target '$targetSlug' not found\n" );
                continue;
            }
            $current = trim( (string)$row->e_aliases );
            $list = $current !== '' ? array_map( 'trim', explode( ',', $current ) ) : [];
            $added = [];
            foreach ( $newAliases as $a ) {
                if ( !in_array( $a, $list, true ) ) {
                    $list[] = $a;
                    $added[] = $a;
                }
            }
            if ( $added ) {
                if ( $dryRun ) {
                    $this->output( "  ~ alias on '$targetSlug' += " . implode( ', ', $added ) . "\n" );
                } else {
                    $store->update( (int)$row->e_id, [ 'e_aliases' => implode( ', ', $list ) ] );
                    $this->output( "  ~ alias on '$targetSlug' += " . implode( ', ', $added ) . "\n" );
                }
                $aliased += count( $added );
            }
        }

        $this->output(
            "\nSummary: $created new effect(s)" .
            ( $dryRun ? ' (dry-run)' : '' ) .
            ", $existed already existed, $aliased alias(es) appended.\n"
        );
    }

    /** Targets keyed by canonical slug; arrays of new aliases to append. */
    const ALIAS_UPDATES = [
        'somnolence-sedation'    => [ 'Sedation' ],
        'suicidality'            => [ 'Suicidal ideation' ],
        'jaw-clenching-bruxism'  => [ 'Teeth grinding' ],
    ];

    const EFFECT_NAMES = [
        'Color enhancement', 'Magnification', 'Pattern recognition enhancement',
        'Visual acuity enhancement', 'Visual processing acceleration', 'Color depression',
        'Double vision', 'Pattern recognition suppression',
        'Peripheral information misinterpretation', 'Visual acuity suppression',
        'Visual processing deceleration', 'After images', 'Brightness alteration',
        'Color replacement', 'Color shifting', 'Color tinting',
        'Depth perception distortions', 'Diffraction', 'Drifting',
        'Environmental cubism', 'Environmental patterning', 'Environmental orbism',
        'Object alteration', 'Perspective distortion', 'Recursion', 'Scenery slicing',
        'Symmetrical texture repetition', 'Texture liquidation', 'Tracers',
        'Visual flipping', 'Visual haze', 'Visual stretching', 'Geometry',
        'External hallucination', 'Internal hallucination', 'Object activation',
        'Perspective hallucination', 'Shadow people', 'Transformations',
        'Unspeakable horrors', 'Auditory acuity enhancement', 'Auditory acuity suppression',
        'Auditory distortion', 'Auditory hallucination', 'Auditory misinterpretation',
        'Temporal scaling', 'Spontaneous bodily sensations', 'Tactile hallucination',
        'Tactile intensification', 'Tactile suppression', 'Cognitive disconnection',
        'Déjà vu', 'Detachment plateaus', 'Physical disconnection', 'Visual disconnection',
        'Gustatory depression', 'Gustatory hallucination', 'Gustatory intensification',
        'Olfactory depression', 'Olfactory hallucination', 'Olfactory intensification',
        'Component controllability', 'Dosage independent intensity', 'Machinescapes',
        'Memory replays', 'Scenarios and plots', 'Spatial disorientation',
        'Spontaneous physical movements', 'Synaesthesia', 'Creativity enhancement',
        'Increased music appreciation', 'Increased sense of humor', 'Memory enhancement',
        'Motivation enhancement', 'Novelty enhancement', 'Thought connectivity',
        'Thought organization', 'Analysis depression', 'Cognitive fatigue', 'Confusion',
        'Delirium', 'Creativity depression', 'Language depression', 'Motivation depression',
        'Sociability depression', 'Thought disorganization', 'Dream potentiation',
        'Ego inflation', 'Emotion intensification', 'Focus intensification',
        'Immersion intensification', 'Personal meaning intensification',
        'Suggestibility intensification', 'Thought acceleration', 'Anxiety suppression',
        'Disinhibition', 'Dream suppression', 'Emotion suppression', 'Focus suppression',
        'Memory suppression', 'Personal bias suppression', 'Sleepiness',
        'Suggestibility suppression', 'Thought deceleration', 'Cognitive euphoria',
        'Compulsive redosing', 'Conceptual thinking', 'Glossolalia',
        'Multiple thought streams', 'Simultaneous emotions', 'Thought loop',
        'Time distortion', 'Delusion', 'Depersonalization', 'Derealization',
        'Depression', 'Depression reduction', 'Ego replacement',
        'Feelings of impending doom', 'Increased introspection', 'Mindfulness',
        'Panic attack', 'Paranoia', 'Personality regression', 'Rejuvenation',
        'Existential self-realization', 'Identity alteration',
        'Perceived exposure to inner mechanics of consciousness',
        'Perception of eternalism', 'Perception of interdependent opposites',
        'Perception of predeterminism', 'Perception of self-design',
        'Spirituality intensification', 'Unity and interconnectedness',
        'Bodily control enhancement', 'Increased libido', 'Increased respiration',
        'Increased salivation', 'Stimulation', 'Cough suppression', 'Decreased libido',
        'Motor control loss', 'Nausea suppression', 'Orgasm depression', 'Pain relief',
        'Seizure suppression', 'Bronchodilation', 'Changes in felt bodily form',
        'Changes in felt gravity', 'Excessive yawning', 'Laughter fits', 'Mouth numbing',
        'Muscle relaxation', 'Perception of bodily heaviness',
        'Perception of bodily lightness', 'Physical autonomy', 'Physical euphoria',
        'Pupil constriction', 'Pupil dilation', 'Decreased blood pressure',
        'Decreased heart rate', 'Increased blood pressure', 'Increased heart rate',
        'Vasoconstriction', 'Vasodilation', 'Dizziness', 'Increased bodily temperature',
        'Seizure', 'Temperature regulation suppression', 'Bodily pressures',
        'Constipation', 'Dehydration', 'Difficulty urinating', 'Frequent urination',
        'Increased perspiration', 'Increased phlegm production', 'Itchiness',
        'Muscle contractions', 'Muscle twitching', 'Muscle tension', 'Optical sliding',
        'Photophobia', 'Physical fatigue', 'Respiratory depression', 'Restless legs',
        'Runny nose', 'Skin flushing', 'Stomach bloating', 'Stomach cramp',
        'Temporary erectile dysfunction', 'Vibrating vision', 'Watery eyes',
    ];
}

$maintClass = BulkImportEffectsFromPwiki::class;
require_once RUN_MAINTENANCE_IF_MAIN;
