<?php

declare(strict_types=1);

namespace App\Enum;

enum Orientation: string
{
    case Landscape = 'LANDSCAPE';
    case Portrait = 'PORTRAIT';
}
