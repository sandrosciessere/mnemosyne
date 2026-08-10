<?php

namespace App\Enums;

/**
 * Status shared by Work and Edition rows: provisional records are created
 * automatically during ingestion reconciliation and can be revised or
 * merged by administrators later; confirmed records were human-reviewed.
 */
enum BibliographicStatus: string
{
    case Provisional = 'provisional';
    case Confirmed = 'confirmed';
}
