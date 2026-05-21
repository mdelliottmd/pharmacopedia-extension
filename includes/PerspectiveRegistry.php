<?php
namespace MediaWiki\Extension\Pharmacopedia;

/**
 * PerspectiveRegistry: the object-type and perspective-type registries
 * for the Perspective subsystem.
 *
 * Deliberately minimal. It grows one entry at a time as new consumers
 * land; do not pre-register a type with no consumer. See
 * perspective_subsystem_spec.md sections 2 and 3.
 */
class PerspectiveRegistry {

    /**
     * Registered object types. Each carries a label and the
     * perspective-types it accepts. For 'profile', pvi_object_id is the
     * owner's profile id and the owner (pvi_owner_id) is that same
     * profile.
     */
    private const OBJECT_TYPES = [
        'profile' => [
            'label'   => 'User profile',
            'accepts' => [ 'amaas_or' ],
        ],
    ];

    /**
     * Registered perspective-types: type key => handler class. Each
     * class implements PerspectiveTypeHandler.
     */
    private const HANDLERS = [
        'amaas_or' => AmaasObserverHandler::class,
    ];

    public static function isObjectType( string $type ): bool {
        return isset( self::OBJECT_TYPES[ $type ] );
    }

    public static function objectLabel( string $type ): ?string {
        return self::OBJECT_TYPES[ $type ]['label'] ?? null;
    }

    public static function isPerspectiveType( string $type ): bool {
        return isset( self::HANDLERS[ $type ] );
    }

    /** True if $objectType is registered and accepts $perspectiveType. */
    public static function accepts( string $objectType, string $perspectiveType ): bool {
        $def = self::OBJECT_TYPES[ $objectType ] ?? null;
        return $def !== null && in_array( $perspectiveType, $def['accepts'], true );
    }

    /** A handler instance for a perspective-type, or null if unregistered. */
    public static function handler( string $perspectiveType ): ?PerspectiveTypeHandler {
        $cls = self::HANDLERS[ $perspectiveType ] ?? null;
        if ( $cls === null ) {
            return null;
        }
        return new $cls();
    }

    /** All registered perspective-type keys. */
    public static function perspectiveTypes(): array {
        return array_keys( self::HANDLERS );
    }
}
