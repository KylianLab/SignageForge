<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How the display is mounted, compared with the picture its device sends.
 * The player rotates its content so it reads the right way up. Values fit
 * the existing 16-character column, so no migration is needed.
 */
enum Orientation: string
{
    case Landscape = 'LANDSCAPE';
    /** Turned a quarter clockwise. */
    case Portrait = 'PORTRAIT';
    case UpsideDown = 'UPSIDE_DOWN';
    /** Turned a quarter counter-clockwise. */
    case PortraitLeft = 'PORTRAIT_LEFT';

    /** Clockwise rotation the player applies, in degrees. */
    public function rotation(): int
    {
        return match ($this) {
            self::Landscape => 0,
            self::Portrait => 90,
            self::UpsideDown => 180,
            self::PortraitLeft => 270,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Landscape => 'Landscape',
            self::Portrait => 'Portrait (turned right)',
            self::UpsideDown => 'Landscape, upside down',
            self::PortraitLeft => 'Portrait (turned left)',
        };
    }
}
