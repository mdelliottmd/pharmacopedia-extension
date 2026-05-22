<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

class SpecialPCPCtrls extends SpecialPage {

    public function __construct() {
        // Access gated via $wgSpecialPageLockdown.
        parent::__construct( 'PCPCtrls' );
    }

    public function execute( $par ) {
        $this->setHeaders();
        $out = $this->getOutput();
        $out->setPageTitle( 'Pharmacopedia controls' );
        $out->addModuleStyles( [ 'ext.pharmacopedia.styles' ] );

        // ---- Pull pending counts up front ----
        $expCount  = 0;
        $provCount = 0;
        $litCount  = 0;
        try { $expCount  = ( new ExperienceStore() )->countPending(); }  catch ( \Throwable $e ) {}
        try { $provCount = ( new ProviderAppStore() )->countPending(); } catch ( \Throwable $e ) {}
        try { $litCount  = ( new LiteratureStore() )->countPending(); }  catch ( \Throwable $e ) {}
        $totalPending = $expCount + $provCount + $litCount;

        $queues = [
            [ 'ReviewExperience',     'Experience submissions',  'Moderate pending user experience reports.',         $expCount  ],
            [ 'ProviderApplications', 'Provider applications',   'Review pending provider verification applications.', $provCount ],
            [ 'LiteratureQueue',      'Literature submissions',  'Approve or reject submitted references/PDFs.',       $litCount  ],
        ];

        $sections = [
            'Activity & accounts' => [
                [ 'NewUsers',              'New users',              'Recently registered accounts (for vetting).' ],
                [ 'PharmacopediaActivity', 'Activity log',           'Full activity feed across the wiki.' ],
            ],
            'Curated catalogs' => [
                [ 'ManageEffects',         'Manage effects',         'Global catalog of effects.' ],
                [ 'ManageProblems',        'Manage Problems',        'Global catalog of Problems (formerly indications).' ],
                [ 'ManageInteractions',    'Manage interactions',    'Medicine–medicine interaction catalog.' ],
            ],
            'User data tools (visit while logged in as the target user)' => [
                [ 'MyProfile',         'My profile',             'User-facing edit form (demographics, assessments, diagnoses, meds). Now hosts 🔗 Share chips per section + a Privacy mode toggle.' ],
                [ 'MyAssessment',      'My assessments',         'Owner + shared-view rich reports for CATI/PID-5-BF/OCEAN/CAT-Q/MBTI/Enneagram. 🔗 Share chip per test.' ],
                [ 'MyLifeStory',       'My life story',          'Owner-facing life-events editor. 🔗 Share chip in subtitle.' ],
                [ 'MyCohorts',         'My cohorts',             'NEW (Phase 4): owner-managed groups of users to share visibility scopes with in one click.' ],
                [ 'MyShareLog',        'Who has viewed my shared content', 'NEW (Phase 5): audit log of who viewed which shared scope, when, via which rule. Anonymous viewer IPs are masked.' ],
            ],
            'User submission forms' => [
                [ 'SuggestEffect',         'Suggest an effect',      'Form for suggesting a new effect.' ],
                [ 'SuggestProblem',        'Suggest a Problem',      'Form for suggesting a new Problem (formerly indication).' ],
                [ 'SuggestTitration',      'Suggest a titration',    'Form for suggesting a new titration regimen.' ],
                [ 'SuggestAnecdote',       'Suggest an anecdote',    'Form for submitting an anecdote.' ],
            ],
            'Administer to others (new)' => [
                [ 'AdministerAssessments', 'Administer assessments', 'NEW (2026-05-21): any logged-in user sends assessment scales to outside respondents (no account needed) and tracks their results over time. Managed-key encryption by default, optional zero-knowledge passphrase. The respondent take-flow is Special:RespondToAssessment/<token>, reached only by a one-time invite link.' ],
            ],
            'Wiki front-of-house (new)' => [
                [ 'Category index', 'Category index', 'NEW (2026-05-21): the two-origin category diptych, the 23 pharmaceutical classes and the Pendell plant axis. A chromeless full-viewport splash.', NS_MAIN ],
                [ 'Main Page', 'Main Page (diptych)', 'NEW (2026-05-21): rebuilt as the two-origin diptych splash, chromeless and full-bleed. Also shipped today: the sitewide edge-to-edge full-width layout and the collapsible Appearance rail (Text size control).', NS_MAIN ],
            ],
            'Destructive' => [
                [ 'DeletePharmaElement',   'Delete element',         'Permanently remove a Problem / effect / interaction element.' ],
            ],
            'Other' => [
                [ 'AdminCtrls',            'Legacy admin controls',  'Wiki-content-editable controls page (MediaWiki:Adminctrls-body).' ],
            ],
        ];

        $out->addHTML( '<style>
.pcp-ctrls h3 { margin-top: 1.4em; padding-bottom: 0.2em; border-bottom: 1px solid #ddd; }
.pcp-ctrls ul { margin: 0.4em 0 1em 1em; padding-left: 0.5em; }
.pcp-ctrls li { margin: 0.45em 0; list-style: none; }
.pcp-ctrls .pcp-desc { color: #555; font-size: 0.92em; }
.pcp-badge {
    display: inline-block;
    min-width: 1.6em;
    padding: 0.05em 0.55em;
    margin-left: 0.5em;
    background: #c62828;
    color: #fff;
    border-radius: 999px;
    font-size: 0.82em;
    font-weight: 700;
    text-align: center;
    vertical-align: 1px;
}
.pcp-badge.zero { background: #9e9e9e; }
.pcp-queue-row { padding: 0.15em 0; }
</style>' );
        $out->addHTML( '<div class="pcp-ctrls">' );

        // ---- Banner: unified pcp-banner style ----
        if ( $totalPending > 0 ) {
            $titleText = (int)$totalPending . ' pending submission' . ( $totalPending === 1 ? '' : 's' );
            $out->addHTML(
                '<div class="pcp-banner">'
                . '<span class="pcp-banner__title">' . htmlspecialchars( $titleText ) . '</span>'
                . '<span class="pcp-banner__meta">'
                . (int)$expCount  . ' experience &middot; '
                . (int)$provCount . ' provider &middot; '
                . (int)$litCount  . ' literature'
                . '</span>'
                . '</div>'
            );
        } else {
            $out->addHTML(
                '<div class="pcp-banner">'
                . '<span class="pcp-banner__title">All caught up</span>'
                . '<span class="pcp-banner__meta">No pending submissions to review.</span>'
                . '</div>'
            );
        }

        // ---- Moderation queue (with badges) ----
        $out->addHTML( '<h3>Moderation queue</h3><ul>' );
        foreach ( $queues as [ $page, $label, $desc, $count ] ) {
            $title = Title::makeTitle( NS_SPECIAL, $page );
            $url = htmlspecialchars( $title->getLocalURL() );
            $badgeClass = $count > 0 ? 'pcp-badge' : 'pcp-badge zero';
            $out->addHTML(
                '<li class="pcp-queue-row">&rsaquo; <a href="' . $url . '"><strong>'
                . htmlspecialchars( $label ) . '</strong></a>'
                . '<span class="' . $badgeClass . '">' . (int)$count . '</span>'
                . ' <span class="pcp-desc">— ' . htmlspecialchars( $desc ) . '</span></li>'
            );
        }
        $out->addHTML( '</ul>' );

        foreach ( $sections as $heading => $rows ) {
            $out->addHTML( '<h3>' . htmlspecialchars( $heading ) . '</h3><ul>' );
            foreach ( $rows as $row ) {
                $page = $row[0]; $label = $row[1]; $desc = $row[2];
                $title = Title::makeTitle( $row[3] ?? NS_SPECIAL, $page );
                $url = htmlspecialchars( $title->getLocalURL() );
                $out->addHTML(
                    '<li>&rsaquo; <a href="' . $url . '"><strong>' . htmlspecialchars( $label ) . '</strong></a>'
                    . ' <span class="pcp-desc">— ' . htmlspecialchars( $desc ) . '</span></li>'
                );
            }
            $out->addHTML( '</ul>' );
        }
        $out->addHTML( '</div>' );
    }

    public function doesWrites() { return false; }
}
