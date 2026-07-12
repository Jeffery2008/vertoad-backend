<?php

declare(strict_types=1);

namespace VertoAD\Domain\Assets;

enum AssetSnapshotStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
}
