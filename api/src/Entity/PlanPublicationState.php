<?php

declare(strict_types=1);

namespace App\Entity;

enum PlanPublicationState: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
