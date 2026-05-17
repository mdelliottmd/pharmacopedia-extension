<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\HTMLForm\HTMLForm;

class SpecialVerifyProvider extends SpecialPage {
    public function __construct() {
        parent::__construct( 'VerifyProvider' );
    }

    public function execute( $subPage ) {
        $this->setHeaders();
        $out = $this->getOutput();
        $out->setPageTitle( 'Provider verification' );

        $user = $this->getUser();
        if ( !$user->isRegistered() ) {
            $out->addWikiTextAsContent( 'You must be logged in to apply for provider verification. [[Special:UserLogin|Log in]]' );
            return;
        }

        $store = new ProviderAppStore();
        $latest = $store->getLatestForUser( $user->getId() );

        if ( $latest && (int)$latest->pa_status === ProviderAppStore::STATUS_PENDING ) {
            $out->addWikiTextAsContent(
                "== Application pending ==\n\n" .
                "Your provider verification application is being reviewed by an administrator.\n\n" .
                "Submitted: " . htmlspecialchars( wfTimestamp( TS_RFC2822, $latest->pa_submitted ) ) . "\n\n" .
                "You'll receive an update when an administrator reviews it. You cannot submit another application while this one is pending."
            );
            return;
        }
        if ( $latest && (int)$latest->pa_status === ProviderAppStore::STATUS_APPROVED ) {
            $out->addWikiTextAsContent(
                "== Verified provider ==\n\n" .
                "Your provider verification was approved. You can submit effect reports from the provider perspective on medicine pages.\n\n" .
                "Approved: " . htmlspecialchars( wfTimestamp( TS_RFC2822, $latest->pa_reviewed ) ) . "\n\n" .
                "''If your status changes (e.g., licensure changes), you may submit a new application below.''"
            );
            $this->renderForm();
            return;
        }
        if ( $latest && (int)$latest->pa_status === ProviderAppStore::STATUS_REJECTED ) {
            $reasonText = $latest->pa_admin_notes ? "''Reason given:'' " . htmlspecialchars( $latest->pa_admin_notes ) : '';
            $out->addWikiTextAsContent(
                "== Previous application was rejected ==\n\n" .
                "Your most recent application was not approved. You may submit a new one below.\n\n" .
                $reasonText
            );
        }
        $this->renderForm();
    }

    private function renderForm() {
        $out = $this->getOutput();
        $out->addWikiTextAsContent(
            "== Apply for provider verification ==\n\n" .
            "Provider status lets you submit observations from your clinical practice on medicine pages (the \"From my patients\" perspective on effect votes). It is granted only after manual review by an administrator.\n\n" .
            "'''Your real name and license number stay private''' — visible to administrators during review only. Uploaded documents are deleted immediately after a decision is made.\n\n" .
            "Your public profile name (whatever you display on the wiki) is unchanged by this process. You may remain pseudonymous on the wiki while being verified."
        );

        $formDescriptor = [
            'profession' => [
                'type'    => 'select',
                'label'   => 'Profession',
                'options' => [
                    'MD (allopathic physician)' => 'MD',
                    'DO (osteopathic physician)' => 'DO',
                    'PA (physician assistant)' => 'PA',
                    'NP (nurse practitioner)' => 'NP',
                    'RN (registered nurse)' => 'RN',
                    'PharmD (pharmacist)' => 'PharmD',
                    'DDS / DMD (dentist)' => 'DDS',
                    'DPM (podiatrist)' => 'DPM',
                    'Psychologist (PhD/PsyD)' => 'PSY',
                    'LCSW / LMFT / LPC (licensed therapist)' => 'LMH',
                    'Medical student / resident' => 'STU',
                    'Other (specify in notes)' => 'OTHER',
                ],
                'required' => true,
            ],
            'specialty' => [
                'type'  => 'text',
                'label' => 'Specialty / subspecialty (optional)',
                'maxlength' => 200,
            ],
            'jurisdiction' => [
                'type'  => 'text',
                'label' => 'License jurisdiction (e.g., California, USA)',
                'required' => true,
                'maxlength' => 200,
            ],
            'license_number' => [
                'type'  => 'text',
                'label' => 'License number (kept private, admin-eyes only)',
                'required' => true,
                'maxlength' => 200,
            ],
            'real_name' => [
                'type'  => 'text',
                'label' => 'Your name as it appears on the license / ID',
                'required' => true,
                'maxlength' => 200,
                'help'  => 'Used by the admin to verify against your uploaded documents. Not shown publicly.',
            ],
            'notes' => [
                'type'  => 'textarea',
                'label' => 'Anything else the admin should know (optional)',
                'rows'  => 4,
            ],
            'license_file' => [
                'type'  => 'file',
                'label' => 'License document (PDF, JPG, or PNG, max 10MB)',
                'required' => true,
            ],
            'id_file' => [
                'type'  => 'file',
                'label' => 'Government ID (PDF, JPG, or PNG, max 10MB)',
                'required' => true,
            ],
            'photo_widget' => [
                'type'  => 'info',
                'label' => 'Live photo of yourself',
                'raw'   => true,
                'help'  => 'Captured live with your device camera. Confirms identity at time of application. Stored privately and deleted after review.',
                'default' => '<div id="pcp-vp-photo-widget">' .
                    '<button type="button" id="pcp-vp-cam-start" class="mw-ui-button">Enable camera</button>' .
                    '<video id="pcp-vp-cam-video" playsinline autoplay style="display:none; max-width:320px; border-radius:6px; margin-top:0.6em;"></video>' .
                    '<canvas id="pcp-vp-cam-canvas" style="display:none;"></canvas>' .
                    '<div id="pcp-vp-cam-controls" style="display:none; margin-top:0.5em;">' .
                      '<button type="button" id="pcp-vp-cam-snap" class="mw-ui-button mw-ui-progressive">Capture</button> ' .
                      '<button type="button" id="pcp-vp-cam-retake" class="mw-ui-button" style="display:none;">Retake</button>' .
                    '</div>' .
                    '<img id="pcp-vp-cam-preview" alt="captured photo preview" style="display:none; max-width:320px; border-radius:6px; margin-top:0.6em;">' .
                    '<p id="pcp-vp-cam-status" style="font-size:0.9em; opacity:0.7;">Click "Enable camera" to start. Browser will ask for permission.</p>' .
                  '</div>',
            ],
            'photo_data' => [
                'type'    => 'hidden',
                'default' => '',
                'required' => true,
            ],
        ];

$this->getOutput()->addModules( ['ext.pharmacopedia'] );
        $htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
        $htmlForm->setSubmitText( 'Submit application' );
        $htmlForm->setSubmitCallback( [ $this, 'onFormSubmit' ] );
        $htmlForm->show();
    }

    public function onFormSubmit( $data, $form ) {
        $user = $this->getUser();
        $store = new ProviderAppStore();
        $request = $this->getRequest();

        try {
            $uploadedPaths = [];
            foreach ( [ 'wplicense_file' => 'License document', 'wpid_file' => 'Government ID' ] as $field => $label ) {
                $file = $request->getUpload( $field );
                $errCode = isset( $_FILES[ $field ] ) ? (int)$_FILES[ $field ]['error'] : UPLOAD_ERR_NO_FILE;
                if ( !$file || !$file->exists() ) {
                    $errMap = [
                        UPLOAD_ERR_INI_SIZE   => 'exceeds upload_max_filesize',
                        UPLOAD_ERR_FORM_SIZE  => 'exceeds form MAX_FILE_SIZE',
                        UPLOAD_ERR_PARTIAL    => 'partial upload',
                        UPLOAD_ERR_NO_FILE    => 'no file selected',
                        UPLOAD_ERR_NO_TMP_DIR => 'no PHP tmp dir',
                        UPLOAD_ERR_CANT_WRITE => 'cannot write to disk',
                        UPLOAD_ERR_EXTENSION  => 'blocked by PHP extension',
                    ];
                    $reason = $errMap[ $errCode ] ?? ( 'error code ' . $errCode );
                    throw new \RuntimeException( "$label upload failed: $reason" );
                }
                $tmp = $file->getTempName();
                $name = $file->getName();
                $mime = mime_content_type( $tmp ) ?: 'application/octet-stream';
                $uploadedPaths[] = $store->saveUploadedFile( $tmp, $name, $mime );
            }

            // Decode the webcam-captured photo (base64 data URL) and persist.
            $photoData = (string)$request->getVal( 'wpphoto_data', '' );
            if ( $photoData !== '' && preg_match( '#^data:image/(jpeg|jpg|png);base64,(.+)$#i', $photoData, $pm ) ) {
                $ext = strtolower( $pm[1] === 'jpeg' ? 'jpg' : $pm[1] );
                $bytes = base64_decode( $pm[2], true );
                if ( $bytes !== false && strlen( $bytes ) > 0 ) {
                    $mime = ( $ext === 'png' ) ? 'image/png' : 'image/jpeg';
                    $uploadedPaths[] = $store->saveBytes( $bytes, $ext, $mime );
                }
            } else {
                throw new \RuntimeException( 'A live photo capture is required' );
            }

            $store->create( $user->getId(), [
                'profession'     => $data['profession'],
                'specialty'      => $data['specialty'],
                'jurisdiction'   => $data['jurisdiction'],
                'license_number' => $data['license_number'],
                'real_name'      => $data['real_name'],
                'notes'          => $data['notes'],
            ], $uploadedPaths );

            $this->getOutput()->addWikiTextAsContent(
                "== Application submitted ==\n\n" .
                "Your provider verification application has been submitted for review. An administrator will review it and update your status."
            );
            return true;
        } catch ( \Throwable $e ) {
            return $e->getMessage();
        }
    }

    protected function getGroupName() { return 'users'; }
}
