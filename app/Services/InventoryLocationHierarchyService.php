<?php

namespace App\Services;

use InvalidArgumentException;

class InventoryLocationHierarchyService
{
    public function assertParentLevel(string $type, ?string $parentType): void
    {
        $expectedParent = ['zone' => null, 'rack' => 'zone', 'shelf' => 'rack', 'bin' => 'shelf'][$type] ?? null;

        if ($expectedParent !== $parentType) {
            throw new InvalidArgumentException('The parent location must be the preceding level in the warehouse hierarchy.');
        }
    }
}
