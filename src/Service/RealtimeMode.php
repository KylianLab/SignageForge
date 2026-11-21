<?php

declare(strict_types=1);

namespace App\Service;

/** How screens learn that their content changed. */
enum RealtimeMode: string
{
    /** Push through a Mercure hub (near-instant). */
    case Mercure = 'mercure';
    /** Screens re-check every few seconds; works on any PHP hosting. */
    case Polling = 'polling';
}
