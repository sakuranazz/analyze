<?php
declare(strict_types=1);

namespace App\Domain;

enum Rounding
{
    /** 0 方向へ切り捨て（「円未満切り捨て」） */
    case Down;
    /** 負の無限大方向へ切り捨て */
    case Floor;
    /** 四捨五入（0 から遠い方へ） */
    case HalfUp;
}
