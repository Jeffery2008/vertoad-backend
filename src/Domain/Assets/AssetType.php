<?php

declare(strict_types=1);

namespace VertoAD\Domain\Assets;

enum AssetType: string
{
    case Image = 'image';
    case Video = 'video';
    case FabricSnapshot = 'fabric_snapshot';
    case Text = 'text';
}
