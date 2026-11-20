<?php

namespace App\Services\WhatsApp;

use RuntimeException;

/**
 * A change to the production identifiers was refused while production mode is in
 * force with the database source active (REQ-WA-15, AC-WA-10). Nothing was written.
 */
class ProductionIdentifiersFrozenException extends RuntimeException
{
    public const REASON = 'production_identifiers_frozen';

    /**
     * @param  list<string>  $fields  The frozen fields the request tried to change (names only, never values).
     */
    public function __construct(public readonly array $fields)
    {
        parent::__construct('The production identifiers cannot change while production mode is in force: '.implode(', ', $fields).'.');
    }
}
